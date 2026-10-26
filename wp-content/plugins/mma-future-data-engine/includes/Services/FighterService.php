<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\FighterAliasRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Repositories\FighterStatsRepository;
use MMAF\DataEngine\Support\Sanitizer;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterService {
	private const INELIGIBLE_STATUSES = array(
		'ineligible_age',
		'ineligible_inactive',
		'ineligible_too_many_fights',
		'ineligible_ufc',
		'ineligible_loss_limit',
	);

	private const MALE_WEIGHT_CLASSES = array(
		'flyweight',
		'bantamweight',
		'featherweight',
		'lightweight',
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
	);

	private const FEMALE_WEIGHT_CLASSES = array(
		'women_strawweight',
		'women_flyweight',
		'women_bantamweight',
		'women_featherweight',
	);

	public const FACT_FIELDS = array(
		'display_name',
		'nickname',
		'gender',
		'date_of_birth',
		'birth_year',
		'nationality',
		'weight_class',
		'height',
		'height_cm',
		'reach_cm',
		'stance',
		'birthplace',
		'fighting_out_of',
		'team_name',
		'coach_name',
		'last_weigh_in',
		'in_ufc',
	);
	private const DECISION_FIELDS = array(
		'status'=>'provisional','rankability_status'=>'pending_review','is_public'=>0,'is_rankable'=>0,'deleted_soft'=>0,
		'tracking_mode'=>'normal','tracking_reason'=>null,'tracking_source'=>null,'tracking_evidence_url'=>null,
		'tracking_changed_at'=>null,'tracking_revision'=>0,'tracking_last_checked_at'=>null,
		'tracking_last_checked_bout_id'=>null,'tracking_return_candidate_at'=>null,
	);
	private const SPORTS_FIELDS = array('gender','date_of_birth','birth_year','weight_class','in_ufc');

	private FighterRepository $fighters;
	private FighterSourceRepository $sources;
	private FighterAliasRepository $aliases;
	private FighterStatsRepository $stats;
	private FighterPostSyncService $post_sync;
	private FieldProvenanceService $provenance;
	private AuditLogService $audit_log;
	private array $last_notices = array();

	public function __construct() {
		$this->fighters   = new FighterRepository();
		$this->sources    = new FighterSourceRepository();
		$this->aliases    = new FighterAliasRepository();
		$this->stats      = new FighterStatsRepository();
		$this->post_sync  = new FighterPostSyncService( $this->fighters );
		$this->provenance = new FieldProvenanceService();
		$this->audit_log  = new AuditLogService();
	}

	public function create( array $input, int $user_id ): array {
        \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();
		global $wpdb;

		$payload            = $this->prepare_payload( $input, true, 0 );
		$this->last_notices = $payload['notices'];

		\MMAF\DataEngine\Services\RegularImport\Transaction::begin();

		try {
			$fighter_id = $this->fighters->insert( $payload['fighter'] );
			$fighter    = $this->fighters->find( $fighter_id );

			if ( ! $fighter ) {
				throw new \RuntimeException( __( 'Could not load the created fighter.', 'mma-future-data-engine' ) );
			}

			$post_id                 = $this->post_sync->sync( $fighter_id, $fighter );
			$fighter['wp_post_id']   = $post_id;
			$payload['fighter']['wp_post_id'] = $post_id;

			$this->save_related_data( $fighter_id, $payload, $user_id, array() );
			$after = $this->fighters->find( $fighter_id );

			$this->audit_log->write(
				'fighter_created',
				'fighter',
				$fighter_id,
				null,
				$after,
				'Manual fighter creation',
				$user_id
			);

			FighterLifecycleService::changed($fighter_id,$user_id,[], $after ?: $fighter,false);
			\MMAF\DataEngine\Services\RegularImport\Transaction::commit();
			do_action( 'mmaf_public_data_changed', 'fighter' );

			$result                  = $after ?: $fighter;
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();
			throw $error;
		}
	}

	public function update( int $fighter_id, array $input, int $user_id ): array {
        \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();
		global $wpdb;

		$before = $this->fighters->find( $fighter_id );

		if ( ! $before ) {
			throw new \RuntimeException( __( 'Fighter not found.', 'mma-future-data-engine' ) );
		}

		$payload            = $this->prepare_payload( $input, false, $fighter_id );
		$this->last_notices = $payload['notices'];

		\MMAF\DataEngine\Services\RegularImport\Transaction::begin();

		try {
			$changes = array();
			foreach ($payload['fighter'] as $field=>$value) {
				if (!self::same_value($before[$field] ?? null,$value)) { $changes[$field]=$value; }
			}
			if ($changes) { $this->fighters->update( $fighter_id, $changes ); }

			$current = $this->fighters->find( $fighter_id );
			if ( ! $current ) {
				throw new \RuntimeException( __( 'Could not reload the updated fighter.', 'mma-future-data-engine' ) );
			}

			$post_id                = $this->post_sync->sync( $fighter_id, $current );
			$current['wp_post_id']  = $post_id;
			$payload['fighter']['wp_post_id'] = $post_id;

			$related = $this->save_related_data( $fighter_id, $payload, $user_id, $before );
			$after = $this->fighters->find( $fighter_id );

			if ($changes || array_filter($related)) { $this->audit_log->write(
				'fighter_updated',
				'fighter',
				$fighter_id,
				$before,
				$after,
				'Manual fighter update',
				$user_id
			); }

			if ($related['source_identity'] || array_intersect(array_keys($changes),self::SPORTS_FIELDS)) {
				FighterLifecycleService::changed($fighter_id,$user_id,$before,$after ?: $current,false);
			} elseif ($changes || $related['aliases'] || $related['source'] || $related['confirmations']) {
				\MMAF\DataEngine\Services\Operations\Readiness::invalidate($fighter_id);
			}
			\MMAF\DataEngine\Services\RegularImport\Transaction::commit();
			if ($changes || array_filter($related)) { do_action( 'mmaf_public_data_changed', 'fighter' ); }

			$result                  = $after ?: $current;
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();
			throw $error;
		}
	}

	public function prepare_payload( array $input, bool $is_new, int $fighter_id = 0 ): array {
		$before = $fighter_id ? ($this->fighters->find($fighter_id) ?: array()) : array();
		foreach (self::DECISION_FIELDS as $field=>$default) {
			$expected=$before[$field] ?? $default;
			if (array_key_exists($field,$input) && !self::same_value($input[$field],$expected)) {
				throw new \InvalidArgumentException('Save cannot change approval or publication decisions. Use a separate fighter operation: '.$field.'.');
			}
			$input[$field]=$expected;
		}
		foreach (self::FACT_FIELDS as $field) {
			if (!array_key_exists($field,$input) && array_key_exists($field,$before)) { $input[$field]=$before[$field]; }
			if($field==='in_ufc' && array_key_exists($field,$before)){$input[$field]=$before[$field];}
		}
		$display_name = Sanitizer::text_or_null( $input['display_name'] ?? '' );

		if ( null === $display_name ) {
			throw new \InvalidArgumentException( __( 'Display name is required.', 'mma-future-data-engine' ) );
		}

		$date_raw = Sanitizer::text_or_null( $input['date_of_birth'] ?? '' );
		if ( null !== $date_raw && null === Sanitizer::valid_date_or_null( $date_raw ) ) {
			throw new \InvalidArgumentException( __( 'Date of birth must be empty or a valid YYYY-MM-DD date.', 'mma-future-data-engine' ) );
		}

		$birth_year = $this->prepare_birth_year( $date_raw, $input['birth_year'] ?? '' );

		$normalized_name = Sanitizer::normalize_name( $display_name );

		$fighter = array(
			'display_name'       => $display_name,
			'nickname'           => Sanitizer::text_or_null( $input['nickname'] ?? '' ),
			'normalized_name'    => $normalized_name,
			'gender'             => Sanitizer::gender( $input['gender'] ?? '' ),
			'date_of_birth'      => Sanitizer::valid_date_or_null( $date_raw ),
			'birth_year'         => $birth_year['value'],
			'nationality'        => Sanitizer::text_or_null( $input['nationality'] ?? '' ),
			'weight_class'       => Sanitizer::weight_class( $input['weight_class'] ?? 'unknown' ),
			'height'             => Sanitizer::text_or_null( $input['height'] ?? '' ),
			'height_cm'          => $this->prepare_height_cm( $input['height_cm'] ?? '' ),
			'last_weigh_in'      => Sanitizer::text_or_null( $input['last_weigh_in'] ?? '' ),
			'status'             => isset( $input['status'] ) ? Sanitizer::fighter_status( $input['status'] ) : 'provisional',
			'rankability_status' => isset( $input['rankability_status'] ) ? Sanitizer::rankability_status( $input['rankability_status'] ) : 'pending_review',
			'is_public'          => Sanitizer::bool_int( $input['is_public'] ?? 0 ),
			'is_rankable'        => Sanitizer::bool_int( $input['is_rankable'] ?? 0 ),
			'in_ufc'             => Sanitizer::bool_int( $input['in_ufc'] ?? 0 ),
			'deleted_soft'       => Sanitizer::bool_int( $input['deleted_soft'] ?? 0 ),
		);

		$current_facts = $fighter_id ? $this->fighters->find( $fighter_id ) : array();
		foreach ( array( 'stance', 'birthplace', 'fighting_out_of', 'team_name', 'coach_name' ) as $fact ) {
			$fighter[$fact] = array_key_exists( $fact, $input ) ? Sanitizer::text_or_null( $input[$fact] ) : ( $current_facts[$fact] ?? null );
			if ( null !== $fighter[$fact] && strlen( $fighter[$fact] ) > ( 'stance' === $fact ? 40 : 255 ) ) { throw new \InvalidArgumentException( 'Fighter fact is too long: ' . $fact ); }
		}
		$fighter['reach_cm'] = array_key_exists( 'reach_cm', $input ) ? Sanitizer::text_or_null( $input['reach_cm'] ) : ( $current_facts['reach_cm'] ?? null );
		if ( null !== $fighter['reach_cm'] ) {
			if ( ! ctype_digit( (string) $fighter['reach_cm'] ) || (int) $fighter['reach_cm'] < 100 || (int) $fighter['reach_cm'] > 270 ) { throw new \InvalidArgumentException( 'Reach must be a whole number from 100 through 270 cm.' ); }
			$fighter['reach_cm'] = (int) $fighter['reach_cm'];
		}
		if ( $is_new ) {
			$fighter['wp_post_id'] = null;
		}

		$source = $this->prepare_source( $input );
		if ( null === $source || 'tapology' !== (string) ( $source['source_type'] ?? '' ) || empty( $source['source_url'] ) ) {
			throw new \InvalidArgumentException( __( 'Tapology Profile URL is required before a fighter can be saved manually.', 'mma-future-data-engine' ) );
		}

		$this->validate_gender_weight_request( array_merge($input,array('is_rankable'=>0)), $fighter );
		// Publication and rankability gates belong to the confirmed operation, not Save.
		$guardrails = array('fighter'=>$fighter,'notices'=>array());
		$confirmed = array_values(array_unique(array_map('sanitize_key',(array)($input['confirm_fact_fields'] ?? array()))));
		if (array_diff($confirmed,self::FACT_FIELDS)) { throw new \InvalidArgumentException('Unsupported fact confirmation.'); }

		return array(
			'fighter' => $guardrails['fighter'],
			'source'  => $source,
			'aliases' => $this->prepare_aliases( $input['aliases'] ?? '' ),
			'image'   => $this->prepare_image( $input ),
			'notices' => array_merge( $birth_year['notices'], $guardrails['notices'] ),
			'confirmed_facts' => $confirmed,
		);
	}

	private function save_related_data( int $fighter_id, array $payload, int $user_id, array $before ): array {
		$changed = array('source'=>false,'source_identity'=>false,'aliases'=>false,'image'=>false,'confirmations'=>false);
		if ( null !== $payload['source'] ) {
			$source_before = $this->sources->find_for_fighter( $fighter_id, $payload['source']['source_type'] );
			$changed['source_identity']=!$source_before || !self::same_value($source_before['source_fighter_id'] ?? null,$payload['source']['source_fighter_id']) || !self::same_value($source_before['source_url'] ?? null,$payload['source']['source_url']);
			if (!$changed['source_identity']) { $payload['source']['confidence']=$source_before['confidence'] ?? null; }
			$existing_source = $this->sources->find_by_source(
				$payload['source']['source_type'],
				$payload['source']['source_fighter_id']
			);

			if ( $existing_source && ! empty( $existing_source['fighter_id'] ) && (int) $existing_source['fighter_id'] !== $fighter_id ) {
				$existing_fighter = $this->fighters->find( (int) $existing_source['fighter_id'] );
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: fighter ID, 2: fighter display name. */
						__( 'This source fighter ID is already linked to fighter #%1$d %2$s.', 'mma-future-data-engine' ),
						(int) $existing_source['fighter_id'],
						$existing_fighter ? (string) $existing_fighter['display_name'] : __( 'Unknown', 'mma-future-data-engine' )
					)
				);
			} elseif ( $existing_source && ! empty( $existing_source['fighter_id'] ) && (int) $existing_source['fighter_id'] === $fighter_id ) {
				$this->last_notices[] = __( 'Source mapping is already linked to this fighter.', 'mma-future-data-engine' );
			}

			if ( 'tapology' === $payload['source']['source_type'] && ! empty( $payload['source']['source_url'] ) ) {
				foreach ( $this->sources->find_by_normalized_source_url( 'tapology', (string) $payload['source']['source_url'] ) as $url_source ) {
					if ( ! empty( $url_source['fighter_id'] ) && (int) $url_source['fighter_id'] !== $fighter_id ) {
						$existing_fighter = $this->fighters->find( (int) $url_source['fighter_id'] );
						throw new \RuntimeException(
							sprintf(
								/* translators: 1: fighter ID, 2: fighter display name. */
								__( 'This Tapology source URL is already linked to fighter #%1$d %2$s.', 'mma-future-data-engine' ),
								(int) $url_source['fighter_id'],
								$existing_fighter ? (string) $existing_fighter['display_name'] : __( 'Unknown', 'mma-future-data-engine' )
							)
						);
					}
				}
			}

			$source_changed=false;
			foreach ($payload['source'] as $key=>$value) {
				if (!self::same_value($source_before[$key] ?? null,$value)) { $source_changed=true; }
			}
			if ($source_changed) { $this->sources->upsert_for_fighter( $fighter_id, $payload['source'] ); }
			$source_after = $this->sources->find_for_fighter( $fighter_id, $payload['source']['source_type'] );

			if ( $source_before !== $source_after ) {
				$changed['source']=true;
				$this->audit_log->write(
					$source_before ? 'fighter_source_mapping_updated' : 'fighter_source_mapping_attached',
					'fighter',
					$fighter_id,
					$source_before,
					$source_after,
					'Manual fighter source mapping save',
					$user_id
				);
			}
		}

		$aliases=array_column($this->aliases->list_by_fighter($fighter_id),'alias','normalized_alias');
		foreach ($payload['aliases'] as $alias) {
			if (($aliases[$alias['normalized_alias']] ?? null)!==$alias['alias']) { $changed['aliases']=true; }
		}
		if ($changed['aliases']) { $this->aliases->replace_for_fighter( $fighter_id, $payload['aliases'] ); }
		$post_id=(int)($payload['fighter']['wp_post_id'] ?? 0);
		$image_before=(int)get_post_thumbnail_id($post_id);
		$this->save_image($post_id,$payload['image']);
		$changed['image']=$image_before!==(int)get_post_thumbnail_id($post_id);

		foreach ( self::FACT_FIELDS as $field_name ) {
			$value=$payload['fighter'][$field_name] ?? null;
			$explicit=in_array($field_name,$payload['confirmed_facts'],true);
			if (!$explicit && self::same_value($before[$field_name] ?? ($field_name==='in_ufc'?0:null),$value)) { continue; }
			$changed['confirmations']=true;
			$this->provenance->upsert(
				'fighter',
				$fighter_id,
				$field_name,
				$payload['fighter'][ $field_name ] ?? null,
				$user_id
			);
			$this->audit_log->write($explicit?'fighter_fact_confirmed':'fighter_fact_changed','fighter',$fighter_id,array($field_name=>$before[$field_name] ?? null),array($field_name=>$value),'Explicit manual fact change or confirmation',$user_id);
		}
		return $changed;
	}

	private static function same_value($a,$b): bool {
		$a=$a===''?null:$a; $b=$b===''?null:$b;
		return $a===null || $b===null ? $a===$b : (string)$a===(string)$b;
	}

	private function prepare_birth_year( ?string $date_raw, $year_input ): array {
		$notices = array();
		$date    = Sanitizer::valid_date_or_null( $date_raw );
		$year_raw = Sanitizer::text_or_null( $year_input );

		if ( null !== $date ) {
			$derived_year = (int) substr( $date, 0, 4 );
			$submitted    = Sanitizer::valid_year_or_null( $year_raw );

			if ( null !== $year_raw && $submitted !== $derived_year ) {
				$notices[] = __( 'Birth year was derived from date of birth.', 'mma-future-data-engine' );
			}

			return array(
				'value'   => $derived_year,
				'notices' => $notices,
			);
		}

		if ( null !== $year_raw && null === Sanitizer::valid_year_or_null( $year_raw ) ) {
			throw new \InvalidArgumentException( __( 'Birth year must be empty or a valid year from 1900 through next year.', 'mma-future-data-engine' ) );
		}

		return array(
			'value'   => Sanitizer::valid_year_or_null( $year_raw ),
			'notices' => $notices,
		);
	}

	private function prepare_height_cm( $height_cm_input ): ?int {
		$raw = Sanitizer::text_or_null( $height_cm_input );
		if ( null === $raw ) {
			return null;
		}

		if ( ! preg_match( '/^\d{2,3}$/', $raw ) ) {
			throw new \InvalidArgumentException( __( 'Height in centimeters must be empty or a whole number from 100 through 260.', 'mma-future-data-engine' ) );
		}

		$height_cm = (int) $raw;
		if ( $height_cm < 100 || $height_cm > 260 ) {
			throw new \InvalidArgumentException( __( 'Height in centimeters must be empty or a whole number from 100 through 260.', 'mma-future-data-engine' ) );
		}

		return $height_cm;
	}

	private function prepare_image( array $input ): array {
		$remove        = Sanitizer::bool_int( $input['remove_fighter_image'] ?? 0 );
		$attachment_id = isset( $input['fighter_image_id'] ) ? absint( $input['fighter_image_id'] ) : 0;

		if ( 1 === $remove ) {
			return array(
				'remove'        => true,
				'attachment_id' => 0,
			);
		}

		if ( $attachment_id > 0 ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
				throw new \InvalidArgumentException( __( 'Selected fighter image must be a valid image attachment.', 'mma-future-data-engine' ) );
			}
		}

		return array(
			'remove'        => false,
			'attachment_id' => $attachment_id,
		);
	}

	private function save_image( $post_id, array $image ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! empty( $image['remove'] ) ) {
			delete_post_thumbnail( $post_id );
			return;
		}

		$attachment_id = (int) ( $image['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || $attachment_id === (int)get_post_thumbnail_id($post_id) ) {
			return;
		}

		if ( false === set_post_thumbnail( $post_id, $attachment_id ) ) {
			throw new \RuntimeException( __( 'Could not save fighter image.', 'mma-future-data-engine' ) );
		}
	}

	private function prepare_source( array $input ): ?array {
		$source_type       = Sanitizer::source_type( $input['source_type'] ?? 'manual' );
		$source_fighter_id = null;
		$source_numeric_id = null;
		$source_url        = isset( $input['source_url'] ) ? esc_url_raw( wp_unslash( $input['source_url'] ) ) : null;
		$source_slug       = Sanitizer::text_or_null( $input['source_slug'] ?? '' );
		$confidence        = null;

		if ( ! $source_url ) {
			return null;
		}

		$tapology_source = TapologyFighterUrl::parse( $source_url );

		if ( $tapology_source && 'tapology' !== $source_type ) {
			$source_type = 'tapology';
		}

		if ( 'tapology' === $source_type ) {
			if ( ! $tapology_source ) {
				throw new \InvalidArgumentException( __( 'Tapology source URL must be a valid Tapology fighter profile URL.', 'mma-future-data-engine' ) );
			}

			$source_fighter_id = $tapology_source['source_fighter_id'];
			$source_numeric_id = $tapology_source['source_numeric_id'];
			$source_slug       = $source_slug ?: $tapology_source['source_slug'];
			$source_url        = $tapology_source['source_url'];
			$confidence        = 100;
		} elseif ( ! empty( $input['is_verified'] ) ) {
			$confidence = 100;
		}

		return array(
			'source_type'       => $source_type,
			'source_fighter_id' => $source_fighter_id,
			'source_numeric_id' => $source_numeric_id,
			'source_url'        => $source_url ?: null,
			'source_slug'       => $source_slug,
			'confidence'        => $confidence,
			'is_verified'       => Sanitizer::bool_int( $input['is_verified'] ?? 0 ),
			'is_primary'        => Sanitizer::bool_int( $input['is_primary'] ?? 0 ),
		);
	}

	private function apply_guardrails( array $fighter ): array {
		$notices = array();

		$add_notice = static function ( string $message ) use ( &$notices ): void {
			if ( ! in_array( $message, $notices, true ) ) {
				$notices[] = $message;
			}
		};

		// Ranking exclusion is not deletion. Ineligible fighters keep canonical rows,
		// source mappings, aliases, audit/provenance history, and linked posts unless
		// an admin explicitly changes Public or uses a true removal state.
		if ( 1 === (int) $fighter['in_ufc'] ) {
			if ( 1 === (int) $fighter['is_rankable'] || 'ineligible_ufc' !== $fighter['rankability_status'] ) {
				$add_notice( __( 'UFC roster status forced Rankable off and rankability status to ineligible_ufc.', 'mma-future-data-engine' ) );
			}
			$fighter['is_rankable']        = 0;
			$fighter['rankability_status'] = 'ineligible_ufc';
		}

		if ( 1 === (int) $fighter['deleted_soft'] ) {
			if ( 'deleted_soft' !== $fighter['status'] || 1 === (int) $fighter['is_public'] || 1 === (int) $fighter['is_rankable'] ) {
				$add_notice( __( 'Deleted soft forced Public and Rankable off and status to deleted_soft.', 'mma-future-data-engine' ) );
			}
			$fighter['is_public']   = 0;
			$fighter['is_rankable'] = 0;
			$fighter['status']      = 'deleted_soft';

			if ( in_array( $fighter['rankability_status'], array( 'rankable', 'pending_review' ), true ) ) {
				$fighter['rankability_status'] = 'not_public';
				$add_notice( __( 'Deleted soft set rankability status to not_public.', 'mma-future-data-engine' ) );
			}
		}

		if ( 'hidden' === $fighter['status'] ) {
			if ( 1 === (int) $fighter['is_rankable'] || 'rankable' === $fighter['rankability_status'] ) {
				$add_notice( __( 'Hidden status forced Rankable off and changed rankable status to not_public.', 'mma-future-data-engine' ) );
			}
			$fighter['is_rankable'] = 0;
			if ( 'rankable' === $fighter['rankability_status'] ) {
				$fighter['rankability_status'] = 'not_public';
			}
		}

		if ( 'retired' === $fighter['status'] ) {
			if ( 1 === (int) $fighter['is_rankable'] || 'rankable' === $fighter['rankability_status'] ) {
				$add_notice( __( 'Retired status forced Rankable off and changed rankable status to ineligible_inactive.', 'mma-future-data-engine' ) );
			}
			$fighter['is_rankable'] = 0;
			if ( 'rankable' === $fighter['rankability_status'] ) {
				$fighter['rankability_status'] = 'ineligible_inactive';
			}
		}

		if ( 'merged' === $fighter['status'] ) {
			if ( 1 === (int) $fighter['is_rankable'] || 'rankable' === $fighter['rankability_status'] ) {
				$add_notice( __( 'Merged status forced Rankable off and changed rankable status to insufficient_data.', 'mma-future-data-engine' ) );
			}
			$fighter['is_rankable'] = 0;
			if ( 'rankable' === $fighter['rankability_status'] ) {
				$fighter['rankability_status'] = 'insufficient_data';
			}
		}

		if ( 'deleted_soft' === $fighter['status'] ) {
			if ( 1 === (int) $fighter['is_public'] || 1 === (int) $fighter['is_rankable'] ) {
				$add_notice( __( 'Deleted soft status forced Public and Rankable off.', 'mma-future-data-engine' ) );
			}
			$fighter['is_public']   = 0;
			$fighter['is_rankable'] = 0;

			if ( 'rankable' === $fighter['rankability_status'] ) {
				$fighter['rankability_status'] = 'not_public';
				$add_notice( __( 'Deleted soft status changed rankability status from rankable to not_public.', 'mma-future-data-engine' ) );
			}
		}

		if ( 'rankable' !== $fighter['rankability_status'] && 1 === (int) $fighter['is_rankable'] ) {
			$fighter['is_rankable'] = 0;
			$add_notice( __( 'Rankable was turned off because rankability status is not rankable.', 'mma-future-data-engine' ) );
		}

		if ( in_array( $fighter['rankability_status'], self::INELIGIBLE_STATUSES, true ) ) {
			$fighter['is_rankable'] = 0;
		}

		return array(
			'fighter' => $fighter,
			'notices' => $notices,
		);
	}

	private function validate_rankable_request( array $input, array $fighter, int $fighter_id = 0 ): void {
		$rankable_checked = 1 === Sanitizer::bool_int( $input['is_rankable'] ?? 0 );

		if ( ! $rankable_checked ) {
			return;
		}

		$status              = $fighter['status'];
		$rankability_status  = $fighter['rankability_status'];
		$guarded_status      = in_array( $status, array( 'hidden', 'retired', 'merged', 'deleted_soft' ), true );
		$guarded_ineligible  = in_array( $rankability_status, self::INELIGIBLE_STATUSES, true );
		$guarded_boolean     = 1 === (int) $fighter['in_ufc'] || 1 === (int) $fighter['deleted_soft'];

		if ( ! $guarded_status && ! $guarded_ineligible && ! $guarded_boolean && 'rankable' !== $rankability_status ) {
			throw new \InvalidArgumentException( __( 'Rankable fighters must have rankability status set to rankable.', 'mma-future-data-engine' ) );
		}

		if ( 'rankable' !== $rankability_status || $guarded_status || $guarded_ineligible || $guarded_boolean ) {
			return;
		}

		if ( 'verified' !== $status ) {
			throw new \InvalidArgumentException( __( 'Rankable fighters must have status set to verified before they can be ranked.', 'mma-future-data-engine' ) );
		}

		if ( ! in_array( $fighter['gender'], array( 'male', 'female' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Gender is required for rankable fighters because ranking boards are split by male/female divisions.', 'mma-future-data-engine' ) );
		}

		if ( empty( $fighter['weight_class'] ) || 'unknown' === $fighter['weight_class'] ) {
			throw new \InvalidArgumentException( __( 'Weight class is required for rankable fighters because ranking boards include weight divisions.', 'mma-future-data-engine' ) );
		}

		if ( ! $this->is_weight_class_compatible( $fighter['gender'], $fighter['weight_class'] ) ) {
			throw new \InvalidArgumentException( __( 'Rankable fighters must use a weight class that matches the selected gender.', 'mma-future-data-engine' ) );
		}

		if ( empty( $fighter['date_of_birth'] ) && empty( $fighter['birth_year'] ) ) {
			throw new \InvalidArgumentException( __( 'Rankable fighters must have date of birth or birth year set so age eligibility can be evaluated.', 'mma-future-data-engine' ) );
		}

		if ( $fighter_id > 0 && ! $this->fighter_has_countable_pro_bout( $fighter_id ) ) {
			throw new \InvalidArgumentException( __( 'Rankable fighters must have at least one countable canonical MMA bout recorded.', 'mma-future-data-engine' ) );
		}
	}

	private function validate_public_request( array $input, array $fighter, ?array $source, int $fighter_id = 0 ): void {
		$public_checked = 1 === Sanitizer::bool_int( $input['is_public'] ?? 0 );

		if ( ! $public_checked ) {
			return;
		}

		// Identity gate: public fighters must have a Tapology source mapping.
		$has_tapology_source = is_array( $source ) && 'tapology' === ( $source['source_type'] ?? '' ) && ! empty( $source['source_url'] );
		$has_existing_source = false;
		if ( ! $has_tapology_source && $fighter_id > 0 ) {
			$existing = $this->sources->find_for_fighter( $fighter_id, 'tapology' );
			$has_existing_source = is_array( $existing ) && ! empty( $existing['source_url'] );
		}

		if ( ! $has_tapology_source && ! $has_existing_source ) {
			throw new \InvalidArgumentException( __( 'Public fighters must have a valid Tapology source.', 'mma-future-data-engine' ) );
		}

		if ( in_array( $fighter['status'], array( 'hidden', 'merged', 'retired', 'deleted_soft' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Public fighters must not be hidden, merged, retired, or deleted_soft.', 'mma-future-data-engine' ) );
		}

		if ( 1 === (int) $fighter['deleted_soft'] ) {
			throw new \InvalidArgumentException( __( 'Public fighters must not be deleted_soft.', 'mma-future-data-engine' ) );
		}

		if ( 'verified' !== $fighter['status'] ) {
			throw new \InvalidArgumentException( __( 'Public fighters must be reviewed/verified before publishing.', 'mma-future-data-engine' ) );
		}

		if ( ! in_array( $fighter['gender'], array( 'male', 'female' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Public fighters must have gender set.', 'mma-future-data-engine' ) );
		}

		if ( empty( $fighter['weight_class'] ) || 'unknown' === $fighter['weight_class'] ) {
			throw new \InvalidArgumentException( __( 'Public fighters must have weight class set.', 'mma-future-data-engine' ) );
		}

		if ( ! $this->is_weight_class_compatible( $fighter['gender'], $fighter['weight_class'] ) ) {
			throw new \InvalidArgumentException( __( 'Public fighters must use a weight class that matches the selected gender.', 'mma-future-data-engine' ) );
		}

		// Countable bout gate is recommended; only enforceable on update where a
		// stats row may exist. On create the fighter row does not yet exist, so
		// no bout can be associated.
		if ( $fighter_id > 0 && ! $this->fighter_has_countable_pro_bout( $fighter_id ) ) {
			throw new \InvalidArgumentException( __( 'Public fighters must have at least one countable canonical MMA bout.', 'mma-future-data-engine' ) );
		}

		if ( 0 === $fighter_id ) {
			throw new \InvalidArgumentException( __( 'New fighters cannot be published in the same save: create as non-public first, then publish after a countable bout is recorded.', 'mma-future-data-engine' ) );
		}
	}

	private function fighter_has_countable_pro_bout( int $fighter_id ): bool {
		if ( $fighter_id <= 0 ) {
			return false;
		}

		$stats = $this->stats->find_stat_by_fighter( $fighter_id );

		return is_array( $stats ) && (int) ( $stats['pro_fights_count'] ?? 0 ) >= 1;
	}

	private function validate_gender_weight_request( array $input, array $fighter ): void {
		$gender       = $fighter['gender'];
		$weight_class = $fighter['weight_class'];

		if ( null === $gender && 'unknown' !== $weight_class ) {
			throw new \InvalidArgumentException( __( 'Select gender before choosing a weight class.', 'mma-future-data-engine' ) );
		}

		if ( null !== $gender && 'unknown' !== $weight_class && ! $this->is_weight_class_compatible( $gender, $weight_class ) ) {
			throw new \InvalidArgumentException( __( 'Weight class must match the selected gender.', 'mma-future-data-engine' ) );
		}

		if ( 1 !== Sanitizer::bool_int( $input['is_rankable'] ?? 0 ) ) {
			return;
		}

		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Gender is required for rankable fighters because ranking boards are split by male/female divisions.', 'mma-future-data-engine' ) );
		}

		if ( 'unknown' === $weight_class ) {
			throw new \InvalidArgumentException( __( 'Weight class is required for rankable fighters because ranking boards include weight divisions.', 'mma-future-data-engine' ) );
		}
	}

	private function is_weight_class_compatible( ?string $gender, string $weight_class ): bool {
		if ( 'unknown' === $weight_class ) {
			return true;
		}

		if ( 'male' === $gender ) {
			return in_array( $weight_class, self::MALE_WEIGHT_CLASSES, true );
		}

		if ( 'female' === $gender ) {
			return in_array( $weight_class, self::FEMALE_WEIGHT_CLASSES, true );
		}

		return false;
	}

	private function prepare_aliases( $raw_aliases ): array {
		$raw_aliases = (string) wp_unslash( $raw_aliases );
		$lines       = preg_split( '/\r\n|\r|\n/', $raw_aliases );
		$aliases     = array();

		foreach ( $lines as $line ) {
			$alias = sanitize_text_field( $line );
			if ( '' === $alias ) {
				continue;
			}

			$normalized = Sanitizer::normalize_name( $alias );
			if ( '' === $normalized || isset( $aliases[ $normalized ] ) ) {
				continue;
			}

			$aliases[ $normalized ] = array(
				'alias'            => $alias,
				'normalized_alias' => $normalized,
			);
		}

		return array_values( $aliases );
	}
}
