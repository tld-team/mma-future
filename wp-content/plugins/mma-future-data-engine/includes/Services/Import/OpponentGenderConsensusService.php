<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Services\FighterLifecycleService;
use MMAF\DataEngine\Services\GenderSource;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Services\RegularImport\WriteGuard;
use MMAF\DataEngine\Support\DateTime;
use MMAF\DataEngine\Support\WeightClassResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fallback for fighters whose Tapology profile states no gender (unranked, pre-debut, major
 * organization): MMA bouts are single-sex, so opponents of a trusted gender decide it.
 *
 * Only genders a person set or a profile confirms count as evidence. An inferred gender is never
 * passed on, so one wrong inference cannot spread through the bout graph.
 */
final class OpponentGenderConsensusService {
	public const MIN_OPPONENTS = 2;

	private const IGNORED_BOUT_STATUSES = array( 'excluded_cancelled', 'pending_result_review' );

	private array $tables;
	private FieldProvenanceService $provenance;

	public function __construct() {
		$this->tables     = Schema::table_names();
		$this->provenance = new FieldProvenanceService();
	}

	/**
	 * @param string[] $genders Trusted genders of distinct opponents.
	 * @return array{gender:?string,reason:string}
	 */
	public static function decide( array $genders ): array {
		$genders = array_values( array_filter( $genders, static fn( $gender ): bool => in_array( $gender, array( 'male', 'female' ), true ) ) );
		if ( count( $genders ) < self::MIN_OPPONENTS ) {
			return array( 'gender' => null, 'reason' => 'too_few_trusted_opponents' );
		}
		if ( count( array_unique( $genders ) ) > 1 ) {
			return array( 'gender' => null, 'reason' => 'opponents_disagree' );
		}

		return array( 'gender' => $genders[0], 'reason' => 'opponents_agree' );
	}

	/** Fighters without a gender, each with what their trusted opponents say. */
	public function candidates(): array {
		$rows = $this->opponent_rows( "(f.gender IS NULL OR f.gender IN ('','unknown'))" );
		$out  = array();
		foreach ( $rows as $fighter_id => $opponents ) {
			$decision = self::decide( array_values( $opponents ) );
			$out[]    = array(
				'fighter_id'   => $fighter_id,
				'gender'       => $decision['gender'],
				'reason'       => $decision['reason'],
				'opponent_ids' => array_keys( $opponents ),
			);
		}

		return $out;
	}

	/** Fighters whose stored gender differs from that of a trusted opponent: one of the two is wrong. */
	public function mismatches(): array {
		$rows = $this->opponent_rows( "f.gender IN ('male','female') AND o.gender <> f.gender" );
		$out  = array();
		foreach ( $rows as $fighter_id => $opponents ) {
			$out[] = array(
				'fighter_id'   => $fighter_id,
				'opponent_ids' => array_keys( $opponents ),
			);
		}

		return $out;
	}

	/** Writes the gender where at least MIN_OPPONENTS trusted opponents agree. */
	public function apply( int $actor, bool $apply ): array {
		global $wpdb;

		$report = array( 'candidates' => 0, 'decided' => 0, 'applied' => 0, 'opponents_disagree' => array(), 'skipped_protected' => 0 );
		foreach ( $this->candidates() as $candidate ) {
			++$report['candidates'];
			if ( 'opponents_disagree' === $candidate['reason'] ) {
				$report['opponents_disagree'][] = $candidate['fighter_id'];
			}
			if ( null === $candidate['gender'] ) {
				continue;
			}

			$fighter_id = (int) $candidate['fighter_id'];
			// An admin who deliberately cleared the gender has a manual record; it stays empty.
			if ( null !== $this->provenance->protected_reason( 'fighter', $fighter_id, 'gender' ) ) {
				++$report['skipped_protected'];
				continue;
			}

			++$report['decided'];
			if ( ! $apply ) {
				continue;
			}

			WriteGuard::assertAllowed();
			$before = $this->fighter( $fighter_id );
			if ( ! $before || in_array( strtolower( trim( (string) ( $before['gender'] ?? '' ) ) ), array( 'male', 'female' ), true ) ) {
				continue;
			}

			$update       = array( 'gender' => $candidate['gender'], 'updated_at' => DateTime::mysql_now() );
			$weight_class = (string) ( $before['weight_class'] ?? '' );
			if ( ! WeightClassResolver::is_compatible( $candidate['gender'], $weight_class ) && null === $this->provenance->protected_reason( 'fighter', $fighter_id, 'weight_class' ) ) {
				$realigned = WeightClassResolver::realign( $weight_class, $candidate['gender'] );
				if ( null !== $realigned ) {
					$update['weight_class'] = $realigned;
				}
			}

			Transaction::begin();
			try {
				if ( false === $wpdb->update( $this->tables['fighters'], $update, array( 'id' => $fighter_id ), null, array( '%d' ) ) ) {
					throw new \RuntimeException( 'Could not write the inferred gender: ' . $wpdb->last_error );
				}
				$source_id = substr( 'opponents:' . implode( ',', array_slice( $candidate['opponent_ids'], 0, 8 ) ), 0, 120 );
				$this->provenance->upsert_source( 'fighter', $fighter_id, 'gender', $candidate['gender'], GenderInferenceService::SOURCE_OPPONENTS, $source_id, $actor );
				$after = $this->fighter( $fighter_id ) ?: array();
				( new AuditLogService() )->write(
					'fighter_gender_inferred_from_opponents',
					'fighter',
					$fighter_id,
					array( 'gender' => $before['gender'] ?? null, 'weight_class' => $before['weight_class'] ?? null ),
					array( 'gender' => $after['gender'] ?? null, 'weight_class' => $after['weight_class'] ?? null, 'opponent_ids' => $candidate['opponent_ids'] ),
					'Gender taken from opponents whose gender is confirmed; the Tapology profile states none.',
					$actor
				);
				Transaction::commit();
			} catch ( \Throwable $error ) {
				Transaction::rollback();
				throw $error;
			}

			FighterLifecycleService::changed( $fighter_id, $actor, $before, $after );
			++$report['applied'];
		}

		return $report;
	}

	/**
	 * Re-applies the stored Tapology profile of fighters whose gender is empty or only inferred,
	 * so evidence already imported takes effect without another scrape.
	 */
	public function apply_stored_profiles( int $actor, bool $apply ): array {
		global $wpdb;

		$weak = GenderSource::filter_sql( 'inferred', $this->tables['field_provenance'] );
		$ids  = $wpdb->get_col( "SELECT f.id FROM {$this->tables['fighters']} f WHERE (f.gender IS NULL OR f.gender IN ('','unknown')) OR ({$weak}) ORDER BY f.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$report  = array( 'fighters' => count( $ids ), 'with_explicit_evidence' => 0, 'applied' => 0, 'failed' => 0 );
		$store   = new ProfileStore();
		$service = new GenderInferenceService();
		foreach ( $ids as $id ) {
			$saved = $store->for_fighter( (int) $id );
			if ( ! $saved ) {
				continue;
			}
			$raw     = $saved['profile'];
			$summary = isset( $raw['profile_summary'] ) && is_array( $raw['profile_summary'] ) ? $raw['profile_summary'] : array();
			if ( null === $service->explicit_from_summary( $summary )['gender'] ) {
				continue;
			}
			++$report['with_explicit_evidence'];
			if ( ! $apply ) {
				continue;
			}

			$raw['scraped_at'] = $saved['source_fetched_at'];
			$before            = $this->fighter( (int) $id );
			$result            = ( new FighterProfileEnrichmentApplyService() )->apply_all_safe_json_string(
				wp_json_encode( array( 'schema_version' => 'tapology_fighter_profiles_v0_1', 'source' => 'tapology', 'profiles' => array( $raw ), 'profiles_total' => 1 ) ),
				'Gender reconcile: stored source profile',
				$actor,
				true
			);
			if ( ! empty( $result['summary']['profiles_failed'] ) ) {
				++$report['failed'];
				continue;
			}
			$after = $this->fighter( (int) $id );
			if ( $before && $after && ( $before['gender'] ?? null ) !== ( $after['gender'] ?? null ) ) {
				FighterLifecycleService::changed( (int) $id, $actor, $before, $after );
				++$report['applied'];
			}
		}

		return $report;
	}

	/**
	 * Bouts imported as "women_strawweight" only because the source said Strawweight, although a
	 * participant is a confirmed man. The label is cleared; the bout weight in pounds is kept.
	 */
	public function fix_strawweight_bout_labels( bool $apply ): array {
		global $wpdb;

		$trusted = GenderSource::trusted_sql( 'o', $this->tables['field_provenance'] );
		$ids     = array_map(
			'intval',
			$wpdb->get_col( "SELECT DISTINCT b.id FROM {$this->tables['bouts']} b JOIN {$this->tables['bout_participants']} bp ON bp.bout_id = b.id JOIN {$this->tables['fighters']} o ON o.id = bp.fighter_id WHERE b.weight_class = 'women_strawweight' AND o.gender = 'male' AND {$trusted} ORDER BY b.id" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $apply && $ids ) {
			WriteGuard::assertAllowed();
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$wpdb->query( "UPDATE {$this->tables['bouts']} SET weight_class = 'unknown', updated_at = '" . esc_sql( DateTime::mysql_now() ) . "' WHERE id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return array( 'bouts' => count( $ids ), 'applied' => $apply ? count( $ids ) : 0, 'bout_ids' => array_slice( $ids, 0, 50 ) );
	}

	/** @return array<int,array<int,string>> fighter id => [opponent id => opponent gender] */
	private function opponent_rows( string $fighter_condition ): array {
		global $wpdb;

		$trusted = GenderSource::trusted_sql( 'o', $this->tables['field_provenance'] );
		$ignored = "'" . implode( "','", self::IGNORED_BOUT_STATUSES ) . "'";
		$rows    = $wpdb->get_results(
			"SELECT DISTINCT bp.fighter_id, o.id AS opponent_id, o.gender
			FROM {$this->tables['bout_participants']} bp
			JOIN {$this->tables['bouts']} b ON b.id = bp.bout_id AND b.deleted_soft = 0 AND b.status NOT IN ({$ignored})
			JOIN {$this->tables['bout_participants']} obp ON obp.bout_id = bp.bout_id AND obp.fighter_id IS NOT NULL AND obp.fighter_id <> bp.fighter_id
			JOIN {$this->tables['fighters']} f ON f.id = bp.fighter_id
			JOIN {$this->tables['fighters']} o ON o.id = obp.fighter_id
			WHERE {$fighter_condition} AND {$trusted}
			ORDER BY bp.fighter_id, o.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['fighter_id'] ][ (int) $row['opponent_id'] ] = (string) $row['gender'];
		}

		return $out;
	}

	private function fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['fighters']} WHERE id = %d LIMIT 1", $fighter_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}
}
