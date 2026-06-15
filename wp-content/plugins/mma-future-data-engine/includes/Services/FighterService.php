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

	private const PROVENANCE_FIELDS = array(
		'display_name',
		'nickname',
		'gender',
		'date_of_birth',
		'birth_year',
		'nationality',
		'weight_class',
		'height',
		'height_cm',
		'last_weigh_in',
		'status',
		'rankability_status',
		'is_public',
		'is_rankable',
		'in_ufc',
	);

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
		global $wpdb;

		$payload            = $this->prepare_payload( $input, true, 0 );
		$this->last_notices = $payload['notices'];

		$wpdb->query( 'START TRANSACTION' );

		try {
			$fighter_id = $this->fighters->insert( $payload['fighter'] );
			$fighter    = $this->fighters->find( $fighter_id );

			if ( ! $fighter ) {
				throw new \RuntimeException( __( 'Could not load the created fighter.', 'mma-future-data-engine' ) );
			}

			$post_id                 = $this->post_sync->sync( $fighter_id, $fighter );
			$fighter['wp_post_id']   = $post_id;
			$payload['fighter']['wp_post_id'] = $post_id;

			$this->save_related_data( $fighter_id, $payload, $user_id );
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

			$wpdb->query( 'COMMIT' );

			$result                  = $after ?: $fighter;
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function update( int $fighter_id, array $input, int $user_id ): array {
		global $wpdb;

		$before = $this->fighters->find( $fighter_id );

		if ( ! $before ) {
			throw new \RuntimeException( __( 'Fighter not found.', 'mma-future-data-engine' ) );
		}

		$payload            = $this->prepare_payload( $input, false, $fighter_id );
		$this->last_notices = $payload['notices'];

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->fighters->update( $fighter_id, $payload['fighter'] );

			$current = $this->fighters->find( $fighter_id );
			if ( ! $current ) {
				throw new \RuntimeException( __( 'Could not reload the updated fighter.', 'mma-future-data-engine' ) );
			}

			$post_id                = $this->post_sync->sync( $fighter_id, $current );
			$current['wp_post_id']  = $post_id;
			$payload['fighter']['wp_post_id'] = $post_id;

			$this->save_related_data( $fighter_id, $payload, $user_id );
			$after = $this->fighters->find( $fighter_id );

			$this->audit_log->write(
				'fighter_updated',
				'fighter',
				$fighter_id,
				$before,
				$after,
				'Manual fighter update',
				$user_id
			);

			$wpdb->query( 'COMMIT' );

			$result                  = $after ?: $current;
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function prepare_payload( array $input, bool $is_new, int $fighter_id = 0 ): array {
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

		if ( $is_new ) {
			$fighter['wp_post_id'] = null;
		}

		$source = $this->prepare_source( $input );
		if ( null === $source || 'tapology' !== (string) ( $source['source_type'] ?? '' ) || empty( $source['source_url'] ) ) {
			throw new \InvalidArgumentException( __( 'Tapology Profile URL is required before a fighter can be saved manually.', 'mma-future-data-engine' ) );
		}

		$this->validate_gender_weight_request( $input, $fighter );
		$this->validate_rankable_request( $input, $fighter, $fighter_id );
		$this->validate_public_request( $input, $fighter, $source, $fighter_id );

		$guardrails = $this->apply_guardrails( $fighter );

		return array(
			'fighter' => $guardrails['fighter'],
			'source'  => $source,
			'aliases' => $this->prepare_aliases( $input['aliases'] ?? '' ),
			'image'   => $this->prepare_image( $input ),
			'notices' => array_merge( $birth_year['notices'], $guardrails['notices'] ),
		);
	}

	private function save_related_data( int $fighter_id, array $payload, int $user_id ): void {
		if ( null !== $payload['source'] ) {
			$source_before = $this->sources->find_for_fighter( $fighter_id, $payload['source']['source_type'] );
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

			$this->sources->upsert_for_fighter( $fighter_id, $payload['source'] );
			$source_after = $this->sources->find_for_fighter( $fighter_id, $payload['source']['source_type'] );

			if ( $source_before !== $source_after ) {
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

		$this->aliases->replace_for_fighter( $fighter_id, $payload['aliases'] );
		$this->save_image( $payload['fighter']['wp_post_id'] ?? 0, $payload['image'] );

		foreach ( self::PROVENANCE_FIELDS as $field_name ) {
			$this->provenance->upsert(
				'fighter',
				$fighter_id,
				$field_name,
				$payload['fighter'][ $field_name ] ?? null,
				$user_id
			);
		}
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
		if ( $attachment_id <= 0 ) {
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
