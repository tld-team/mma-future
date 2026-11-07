<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Operations\Readiness;
use MMAF\DataEngine\Services\RegularImport\{Repository, Transaction, WriteGuard};

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Separates a public fighter profile from their present or permanent ranking
 * disposition.  The decision only uses accepted professional-MMA evidence.
 */
final class RankingDispositionService {
	public const RULE_VERSION = 'ranking-disposition-v2';
	public const PERMANENT = 'permanent_exclusion';
	public const TEMPORARY = 'temporary_exclusion';
	public const CANDIDATE = 'candidate';
	public const UNRESOLVED = 'unresolved';

	public function classify( array $fighter, ?string $reference_date = null, ?array $record_override = null ): array {
		$reference_date = $reference_date ?: current_time( 'Y-m-d' );
		$formula = \MMAF\DataEngine\Services\Formula\FormulaRegistry::current_config();
		$config = $formula['eligibility'];
		$id = (int) ( $fighter['id'] ?? 0 );
		$records = $id ? ( new \MMAF\DataEngine\Services\Scoring\CareerRecords() )->for_fighters( array( $id ) ) : array();
		$record = $record_override ?? ( $records[ $id ] ?? null );
		$accepted = $record && ( $record['status'] ?? '' ) === 'accepted' && is_array( $record['record'] ?? null );
		$reasons = array(); $evidence = array();

		if ( ! empty( $fighter['deleted_soft'] ) || in_array( (string) ( $fighter['status'] ?? '' ), array( 'hidden','merged','retired','deleted_soft' ), true ) ) {
			return $this->result( self::UNRESOLVED, array( 'excluded_identity' ), $reference_date, $formula, $record, $evidence );
		}
		if ( $id && $this->identity_conflict( $id ) ) {
			return $this->result( self::UNRESOLVED, array( 'identity_conflict' ), $reference_date, $formula, $record, $evidence );
		}
		if ( 1 === (int) ( $fighter['in_ufc'] ?? 0 ) ) {
			$reasons[] = 'confirmed_ufc_signing'; $evidence['in_ufc'] = 1;
		}
		$age = $this->exact_age( (string) ( $fighter['date_of_birth'] ?? '' ), $reference_date );
		$age_resolved = null !== $age;
		if ( null !== $age && $age > (int) $config['max_age'] ) {
			$reasons[] = 'age_over_' . (int) $config['max_age'];
			$evidence['age'] = array( 'value' => $age, 'method' => 'date_of_birth', 'date_of_birth' => $fighter['date_of_birth'] );
		} elseif ( null === $age ) {
			$year = (int) ( $fighter['birth_year'] ?? 0 ); $reference_year = (int) substr( $reference_date, 0, 4 );
			if ( $year > 0 && $reference_year - $year >= (int) $config['max_age'] + 2 ) {
				$reasons[] = 'age_over_' . (int) $config['max_age'];
				$evidence['age'] = array( 'minimum' => $reference_year - $year - 1, 'method' => 'birth_year_certain', 'birth_year' => $year );
				$age_resolved = true;
			} elseif ( $year > 0 && $reference_year - $year <= (int) $config['max_age'] ) {
				$age_resolved = true;
				$evidence['age'] = array( 'maximum' => $reference_year - $year, 'method' => 'birth_year_certain', 'birth_year' => $year );
			}
		}
		if ( $accepted && (int) ( $record['record']['pro_fights_count'] ?? 0 ) > (int) $config['max_pro_fights_count'] ) {
			$reasons[] = 'confirmed_pro_fights_over_' . (int) $config['max_pro_fights_count'];
			$evidence['career_record'] = $record['record'];
		}
		if ( $reasons ) { return $this->result( self::PERMANENT, $reasons, $reference_date, $formula, $record, $evidence ); }

		if ( ! $accepted ) {
			return $this->result( self::UNRESOLVED, array( 'professional_record_unconfirmed' ), $reference_date, $formula, $record, $evidence );
		}
		$temporary = array();
		$wins = (int) $record['record']['wins']; $losses = (int) $record['record']['losses'];
		$allowed = $this->loss_limit( $wins, (array) ( $config['loss_limit_by_wins'] ?? array() ) );
		if ( null !== $allowed && $losses > $allowed ) {
			$temporary[] = 'loss_limit'; $evidence['record'] = array( 'wins' => $wins, 'losses' => $losses, 'allowed_losses' => $allowed );
		}
		$activity = $this->latest_professional_fight_evidence( $id );
		if ( ! empty( $activity['verified'] ) && ! empty( $activity['date'] ) ) {
			$evidence['last_fight'] = $activity;
			if ( $this->inactive( $activity['date'], $reference_date, (int) $config['inactivity_months'] ) ) { $temporary[] = 'inactive_' . (int) $config['inactivity_months'] . '_months'; }
		}
		if ( $temporary ) { return $this->result( self::TEMPORARY, $temporary, $reference_date, $formula, $record, $evidence ); }
		$unresolved = array();
		if ( ! $age_resolved ) { $unresolved[] = 'age_unresolved'; }
		if ( empty( $activity['verified'] ) || empty( $activity['date'] ) ) { $unresolved[] = 'last_professional_fight_unconfirmed'; }
		return $this->result( $unresolved ? self::UNRESOLVED : self::CANDIDATE, $unresolved, $reference_date, $formula, $record, $evidence );
	}

	/** Read-only import-plan assessment using the incoming profile aggregate. */
	public function preview_profile( array $fighter, array $profile, ?string $reference_date = null ): array {
		$record = null;
		if ( ( $profile['status'] ?? '' ) === 'success' && \MMAF\DataEngine\Services\Scoring\CareerRecords::scope_verified( $profile ) ) {
			$counts = \MMAF\DataEngine\Services\Scoring\CareerRecords::counts( (array) ( $profile['record_summary'] ?? array() ) );
			if ( $counts ) {
				$record = array( 'status' => 'accepted', 'record' => $counts, 'source_url' => $profile['source_url'] ?? null, 'observed_at' => ProfileStore::date( $profile['scraped_at'] ?? null ) );
			}
		}
		return $this->classify( $fighter, $reference_date, $record );
	}

	public function can_publish_basic_profile( array $fighter, array $classification ): bool {
		if ( ! in_array( $classification['outcome'] ?? '', array( self::PERMANENT, self::TEMPORARY ), true ) ) { return false; }
		if ( empty( $fighter['display_name'] ) || ! empty( $fighter['deleted_soft'] ) || in_array( (string) ( $fighter['status'] ?? '' ), array( 'hidden','merged','retired','deleted_soft' ), true ) || $this->publication_blocked( $fighter ) ) { return false; }
		$id = (int) ( $fighter['id'] ?? 0 ); if ( ! $id || $this->source_count( $id ) !== 1 ) { return false; }
		$profile = ( new ProfileStore() )->for_fighter( $id );
		if ( ! $profile || ( $profile['profile']['status'] ?? '' ) !== 'success' ) { return false; }
		$record = $classification['career_record']['record'] ?? null;
		// Publishing a basic profile does not require a complete career aggregate.
		// Either an accepted positive Pro MMA aggregate or one verified held Pro MMA
		// appearance is enough. Ranking still requires the stricter full evidence.
		return ( is_array( $record ) && (int) ( $record['pro_fights_count'] ?? 0 ) > 0 )
			|| $this->verified_professional_appearance( (array) $profile['profile'] );
	}

	/** Apply a fresh decision. Existing permanent decisions never return to normal here. */
	public function apply( int $fighter_id, int $actor = 0, ?string $reference_date = null, bool $request_refresh = true ): array {
		WriteGuard::assertAllowed();
		$fighters = new FighterRepository(); $before = $fighters->find( $fighter_id );
		if ( ! $before ) { throw new \RuntimeException( 'Fighter not found.' ); }
		$decision = $this->classify( $before, $reference_date );
		if ( self::UNRESOLVED === $decision['outcome'] ) { return $decision + array( 'applied' => false, 'published' => false ); }
		if ( TrackingService::OPPONENT_ONLY === TrackingService::mode( $before ) && self::PERMANENT !== $decision['outcome'] ) {
			return $decision + array( 'applied' => false, 'published' => false, 'requires_reconsideration' => true );
		}
		$mode = self::PERMANENT === $decision['outcome'] ? TrackingService::OPPONENT_ONLY : ( self::TEMPORARY === $decision['outcome'] ? TrackingService::PAUSED : TrackingService::NORMAL );
		$status = self::PERMANENT === $decision['outcome'] ? $this->permanent_status( $decision['reasons'] ) : ( self::TEMPORARY === $decision['outcome'] ? $this->temporary_status( $decision['reasons'] ) : 'pending_review' );
		$publish = $this->can_publish_basic_profile( $before, $decision );
		if ( self::CANDIDATE === $decision['outcome'] && ( TrackingService::PAUSED !== TrackingService::mode( $before ) || ( empty( $before['tracking_return_candidate_at'] ) && ! ( new TrackingService() )->recheck_due( $fighter_id ) ) ) ) {
			return $decision + array( 'applied' => false, 'published' => false );
		}
		$values = array(
			'tracking_mode' => $mode, 'tracking_reason' => implode( '_', $decision['reasons'] ?: array( $mode ) ), 'tracking_source' => 'ranking_disposition',
			'tracking_return_candidate_at' => null, 'is_rankable' => 0, 'rankability_status' => $status,
		);
		if ( $publish ) { $values['status'] = 'verified'; $values['is_public'] = 1; }
		$changed = false; foreach ( $values as $field => $value ) { if ( (string) ( $before[ $field ] ?? '' ) !== (string) $value ) { $changed = true; break; } }
		if ( ! $changed ) {
			// A prior import may already have set the tracking fields while leaving
			// a stale preparation/review request behind. Closing that request is an
			// idempotent part of the exclusion, not a new editorial change.
			if ( in_array( $decision['outcome'], array( self::PERMANENT, self::TEMPORARY ), true ) ) {
				Transaction::run( function() use ( $fighter_id, $decision ) { $this->close_ranking_preparation( $fighter_id, $decision ); Readiness::invalidate( $fighter_id ); } );
			}
			return $decision + array( 'applied' => false, 'published' => (bool) $before['is_public'] );
		}
		$values['tracking_changed_at'] = gmdate( 'Y-m-d H:i:s' ); $values['tracking_revision'] = (int) ( $before['tracking_revision'] ?? 0 ) + 1; $values['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		Transaction::run( function() use ( $fighters, $fighter_id, $values, $before, $actor, $decision ) {
			$fighters->update( $fighter_id, $values ); $after = $fighters->find( $fighter_id ) ?: array_merge( $before, $values );
			( new AuditLogService() )->write( 'ranking_disposition_applied', 'fighter', $fighter_id, $this->audit_state( $before ), $this->audit_state( $after ), wp_json_encode( $decision ), $actor );
			$this->close_ranking_preparation( $fighter_id, $decision );
			FighterLifecycleService::changed( $fighter_id, $actor, $before, $after, true, false ); Readiness::invalidate( $fighter_id );
		} );
		if ( $request_refresh ) { \MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh( $actor, true ); }
		return $decision + array( 'applied' => true, 'published' => $publish );
	}

	/**
	 * Continue the one-time existing-data reconciliation in small durable batches.
	 * Calling it with $apply=false never changes a fighter or the saved cursor.
	 */
	public function backfill_step( int $actor = 0, int $limit = 100, bool $apply = false, bool $reset = false ): array {
		$limit = min( 500, max( 1, $limit ) );
		$option = 'mmaf_ranking_disposition_backfill_v2';
		if ( $reset && $apply ) { delete_option( $option ); }
		$state = (array) get_option( $option, array() );
		if ( $apply && ! empty( $state['completed_at'] ) ) {
			return array( 'completed' => true, 'cursor' => (int) ( $state['cursor'] ?? 0 ), 'items' => array(), 'counts' => array(), 'message' => 'Backfill is already complete; use --reset to deliberately run it again.' );
		}
		$cursor = $apply ? (int) ( $state['cursor'] ?? 0 ) : 0;
		global $wpdb; $table = Schema::table_names()['fighters'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE deleted_soft=0 AND id>%d ORDER BY id ASC LIMIT %d", $cursor, $limit ), ARRAY_A ) ?: array();
		$counts = array_fill_keys( array( self::PERMANENT, self::TEMPORARY, self::CANDIDATE, self::UNRESOLVED, 'profile_observed', 'published', 'applied', 'reconsideration_required' ), 0 );
		$items = array(); $last = $cursor;
		foreach ( $rows as $fighter ) {
			$last = (int) $fighter['id'];
			if ( $apply && $this->observe_saved_profile( $last, $actor ) ) { ++$counts['profile_observed']; }
			$decision = $this->classify( $fighter );
			$outcome = (string) $decision['outcome']; ++$counts[ $outcome ];
			$item = array( 'fighter_id' => $last, 'display_name' => $fighter['display_name'], 'outcome' => $outcome, 'reasons' => $decision['reasons'], 'would_publish' => $this->can_publish_basic_profile( $fighter, $decision ) );
			if ( $apply && in_array( $outcome, array( self::PERMANENT, self::TEMPORARY ), true ) ) {
				$applied = $this->apply( $last, $actor, null, false );
				$item['applied'] = ! empty( $applied['applied'] ); $item['published'] = ! empty( $applied['published'] );
				if ( $item['applied'] ) { ++$counts['applied']; }
				if ( $item['published'] ) { ++$counts['published']; }
				if ( ! empty( $applied['requires_reconsideration'] ) ) { ++$counts['reconsideration_required']; }
			}
			$items[] = $item;
		}
		$completed = count( $rows ) < $limit;
		if ( $apply ) {
			$cumulative = array_merge( array_fill_keys( array_keys( $counts ), 0 ), (array) ( $state['counts'] ?? array() ) );
			foreach ( $counts as $key => $value ) { $cumulative[ $key ] = (int) ( $cumulative[ $key ] ?? 0 ) + (int) $value; }
			update_option( $option, array( 'cursor' => $last, 'started_at' => $state['started_at'] ?? gmdate( 'c' ), 'updated_at' => gmdate( 'c' ), 'completed_at' => $completed ? gmdate( 'c' ) : null, 'counts' => $cumulative, 'last_counts' => $counts ), false );
			if ( $counts['applied'] ) { \MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh( $actor, true ); }
		}
		return array( 'completed' => $completed, 'cursor' => $last, 'items' => $items, 'counts' => $counts, 'dry_run' => ! $apply );
	}

	/** Check a small rotating slice for age/inactivity transitions without scraping. */
	public function refresh_time_rules_step( int $actor = 0, int $limit = 100, ?string $reference_date = null ): array {
		$reference_date = $reference_date ?: current_time( 'Y-m-d' ); $limit = min( 500, max( 1, $limit ) );
		$option = 'mmaf_ranking_disposition_time_cursor_v1'; $state = (array) get_option( $option, array() );
		$cursor = ( $state['reference_date'] ?? '' ) === $reference_date ? (int) ( $state['cursor'] ?? 0 ) : 0;
		global $wpdb; $table = Schema::table_names()['fighters'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM $table WHERE deleted_soft=0 AND id>%d ORDER BY id ASC LIMIT %d", $cursor, $limit ), ARRAY_A ) ?: array();
		$changed = 0; $last = $cursor;
		foreach ( $rows as $row ) { $last = (int) $row['id']; $result = $this->apply( $last, $actor, $reference_date, false ); if ( ! empty( $result['applied'] ) ) { ++$changed; } }
		$completed = count( $rows ) < $limit;
		update_option( $option, array( 'reference_date' => $completed ? null : $reference_date, 'cursor' => $completed ? 0 : $last, 'checked_at' => gmdate( 'c' ), 'completed_at' => $completed ? gmdate( 'c' ) : null ), false );
		if ( $changed ) { \MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh( $actor, true ); }
		return array( 'checked' => count( $rows ), 'changed' => $changed, 'completed' => $completed, 'cursor' => $last );
	}

	public function observe_saved_profile( int $fighter_id, int $actor = 0 ): bool {
		$profile = ( new ProfileStore() )->for_fighter( $fighter_id );
		if ( ! $profile || empty( $profile['content_hash'] ) || ! is_array( $profile['profile'] ?? null ) ) { return false; }
		$records = new \MMAF\DataEngine\Services\Scoring\CareerRecords();
		$before = $records->for_fighters( array( $fighter_id ) )[ $fighter_id ] ?? null;
		$records->observe( $fighter_id, $profile['profile'], $profile['content_hash'], $actor );
		$after = $records->for_fighters( array( $fighter_id ) )[ $fighter_id ] ?? null;
		return (int) ( $before['observation_id'] ?? 0 ) !== (int) ( $after['observation_id'] ?? 0 );
	}
	private function result( string $outcome, array $reasons, string $date, array $formula, ?array $record, array $evidence ): array {
		$career = $record ? array_intersect_key( $record, array_flip( array( 'status','record','observation_id','observed_at','source_url','profile_hash','protected','reason' ) ) ) : null;
		return array( 'outcome' => $outcome, 'reasons' => array_values( array_unique( $reasons ) ), 'reference_date' => $date, 'rule_version' => self::RULE_VERSION, 'formula_version' => $formula['formula_version'] ?? null, 'career_record' => $career, 'evidence' => $evidence );
	}
	private function source_count( int $fighter_id ): int {
		global $wpdb;
		$t = Schema::table_names()['fighter_sources'];
		// Import-created identities begin unverified until an editor touches them.
		// A single canonical source ID is already sufficient here; duplicate/source
		// conflicts are rejected separately by identity_conflict().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE fighter_id=%d AND source_type='tapology' AND source_fighter_id IS NOT NULL AND source_fighter_id<>''", $fighter_id ) );
	}
	private function verified_professional_appearance( array $profile ): bool {
		if ( ( $profile['history_scope'] ?? '' ) !== 'professional_mma' || empty( $profile['history_scope_verified'] ) ) { return false; }
		foreach ( (array) ( $profile['fight_history'] ?? array() ) as $fight ) {
			if ( ! empty( $fight['is_professional'] ) && in_array( (string) ( $fight['result'] ?? '' ), array( 'win','loss','draw','no_contest' ), true ) ) { return true; }
		}
		return false;
	}
	private function exact_age( string $dob, string $date ): ?int { if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $dob ) ) { return null; } try { return ( new \DateTimeImmutable( $dob ) )->diff( new \DateTimeImmutable( $date ) )->y; } catch ( \Throwable $e ) { return null; } }
	private function inactive( string $last, string $date, int $months ): bool { try { return new \DateTimeImmutable( $date ) >= ( new \DateTimeImmutable( $last ) )->modify( '+' . $months . ' months' ); } catch ( \Throwable $e ) { return false; } }
	private function latest_professional_fight_evidence( int $fighter_id ): array {
		global $wpdb; $t = Schema::table_names();
		$coverage = $wpdb->get_row( $wpdb->prepare( "SELECT status,source_fetched_at FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d", $fighter_id ), ARRAY_A );
		if ( $coverage && 'complete' === (string) $coverage['status'] ) {
			$date = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(e.event_date) FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id=%d AND b.deleted_soft=0 AND e.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest') AND e.event_date<=UTC_DATE()", $fighter_id ) );
			if ( is_string( $date ) && '' !== $date ) { return array( 'verified' => true, 'date' => $date, 'source' => 'complete_canonical_career', 'verified_at' => $coverage['source_fetched_at'] ?? null ); }
		}
		// A fully verified Tapology profile is sufficient before its rows have
		// entered the canonical career import. This prevents a valid newly saved
		// profile from becoming an artificial review blocker solely due to queue order.
		$stored = ( new ProfileStore() )->for_fighter( $fighter_id ); $profile = $stored['profile'] ?? null;
		$evidence = is_array( $profile ) ? (array) ( $profile['career_history_evidence'] ?? array() ) : array();
		if ( ! is_array( $profile ) || ( $profile['history_scope'] ?? '' ) !== 'professional_mma' || empty( $profile['history_scope_verified'] ) || empty( $profile['history_exhausted'] ) || empty( $evidence['complete'] ) ) { return array( 'verified' => false, 'date' => null, 'source' => 'career_coverage' ); }
		$latest = null;
		foreach ( (array) ( $profile['fight_history'] ?? array() ) as $fight ) {
			if ( empty( $fight['is_professional'] ) || ! in_array( (string) ( $fight['result'] ?? '' ), array( 'win','loss','draw','no_contest' ), true ) ) { continue; }
			$date = (string) ( $fight['fight_date'] ?? $fight['event_date'] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) && $date <= gmdate( 'Y-m-d' ) && ( null === $latest || $date > $latest ) ) { $latest = $date; }
		}
		return array( 'verified' => null !== $latest, 'date' => $latest, 'source' => 'complete_stored_profile', 'verified_at' => $stored['source_fetched_at'] ?? null );
	}
	private function identity_conflict( int $fighter_id ): bool {
		global $wpdb; $table = Schema::table_names()['fighter_sources'];
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT other.fighter_id) FROM $table mine JOIN $table other ON other.source_type=mine.source_type AND other.fighter_id<>mine.fighter_id AND ((mine.source_fighter_id IS NOT NULL AND mine.source_fighter_id<>'' AND other.source_fighter_id=mine.source_fighter_id) OR (mine.source_url IS NOT NULL AND mine.source_url<>'' AND other.source_url=mine.source_url)) WHERE mine.fighter_id=%d", $fighter_id ) );
		return (int) $count > 0;
	}
	private function publication_blocked( array $fighter ): bool { return empty( $fighter['is_public'] ) && 'not_public' === (string) ( $fighter['rankability_status'] ?? '' ); }
	private function close_ranking_preparation( int $fighter_id, array $decision ): void {
		if ( ! in_array( $decision['outcome'] ?? '', array( self::PERMANENT, self::TEMPORARY ), true ) ) { return; }
		global $wpdb; $queue = \MMAF\DataEngine\Services\DataPipeline\Schema::tables()['queue']; $operations = \MMAF\DataEngine\Services\Operations\Schema::tables();
		$summary = wp_json_encode( array( 'excluded' => true, 'ranking_disposition' => $decision ) );
		Repository::check( $wpdb->query( $wpdb->prepare( "UPDATE $queue SET status='excluded',summary_json=%s,error_message=NULL,retry_at=NULL,finished_at=%s WHERE fighter_id=%d AND status IN ('pending','review','failed','completed')", $summary, gmdate( 'Y-m-d H:i:s' ), $fighter_id ) ) );
		// Do not erase an identity/facts review. Only open ranking preparation and
		// the older, explicitly ranking-ineligible review may be closed here.
		Repository::check( $wpdb->query( $wpdb->prepare( "UPDATE {$operations['items']} i JOIN {$operations['jobs']} j ON j.id=i.operation_id SET i.status='excluded',i.reason_code=%s,i.updated_at=%s WHERE i.fighter_id=%d AND j.kind='fighters' AND j.status IN ('previewing','ready','running','paused') AND (i.status IN ('pending','preparing','prepared') OR (i.status='review' AND i.reason_code='ranking_ineligible'))", (string) ( $decision['reasons'][0] ?? 'ranking_excluded' ), gmdate( 'Y-m-d H:i:s' ), $fighter_id ) ) );
	}
	private function loss_limit( int $wins, array $limits ): ?int { krsort($limits,SORT_NUMERIC); foreach ( $limits as $minimum => $maximum ) { if ( $wins >= (int) $minimum ) { return (int) $maximum; } } return null; }
	private function permanent_status( array $reasons ): string { if ( in_array( 'confirmed_ufc_signing', $reasons, true ) ) { return 'ineligible_ufc'; } if ( array_filter( $reasons, static fn( $r ) => str_starts_with( $r, 'age_over_' ) ) ) { return 'ineligible_age'; } return 'ineligible_too_many_fights'; }
	private function temporary_status( array $reasons ): string { return in_array( 'loss_limit', $reasons, true ) ? 'ineligible_loss_limit' : 'ineligible_inactive'; }
	private function audit_state( array $fighter ): array { return array_intersect_key( $fighter, array_flip( array( 'status','is_public','is_rankable','rankability_status','tracking_mode','tracking_reason','tracking_source','tracking_revision' ) ) ); }
}
