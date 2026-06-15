<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutService {
	public const RESULT_TYPES = array(
		'win_loss',
		'draw',
		'no_contest',
		'cancelled',
		'unknown',
	);

	public const BOUT_STATUSES = array(
		'valid',
		'completed',
		'pending_identity_review',
		'pending_result_review',
		'pending_method_review',
		'pending_prefight_warning',
		'excluded_amateur',
		'excluded_cancelled',
		'excluded_overturned',
		'duplicate_suspected',
		'hidden',
		'deleted_soft',
	);

	public const CARD_POSITIONS = array(
		'unknown',
		'main_event',
		'co_main_event',
		'main_card',
		'prelim',
		'postlim',
	);

	public const METHOD_CATEGORIES = array(
		'unknown',
		'ko_tko',
		'submission',
		'decision',
		'dq',
		'no_contest',
		'draw',
	);

	public const SOURCE_TYPES = array(
		'manual',
		'tapology',
		'sherdog',
		'other',
	);

	private const PROVENANCE_BOUT_FIELDS = array(
		'event_id',
		'bout_order',
		'card_position',
		'weight_class',
		'weight_lbs',
		'status',
		'result_type',
		'method_category',
		'method_detail',
		'round_number',
		'time_in_round',
		'is_scoring_candidate',
		'deleted_soft',
	);

	private const PROVENANCE_PARTICIPANT_FIELDS = array(
		'fighter_id',
		'participant_role',
		'result_for_fighter',
		'prefight_wins',
		'prefight_losses',
		'prefight_draws',
		'prefight_nc',
		'prefight_record_raw',
		'is_winner',
	);

	private BoutRepository $bouts;
	private BoutParticipantRepository $participants;
	private BoutSourceRepository $sources;
	private EventRepository $events;
	private FighterRepository $fighters;
	private FighterSourceRepository $fighter_sources;
	private FieldProvenanceService $provenance;
	private AuditLogService $audit_log;
	private array $last_notices = array();

	public function __construct() {
		$this->bouts           = new BoutRepository();
		$this->participants    = new BoutParticipantRepository();
		$this->sources         = new BoutSourceRepository();
		$this->events          = new EventRepository();
		$this->fighters        = new FighterRepository();
		$this->fighter_sources = new FighterSourceRepository();
		$this->provenance      = new FieldProvenanceService();
		$this->audit_log       = new AuditLogService();
	}

	public function create( array $input, int $user_id ): array {
		global $wpdb;

		$payload            = $this->prepare_payload( $input, 0 );
		$this->last_notices = $payload['notices'];

		$duplicate = $this->bouts->find_duplicate(
			(int) $payload['bout']['event_id'],
			(int) $payload['fighter_a']['id'],
			(int) $payload['fighter_b']['id'],
			$payload['bout'],
			0
		);
		if ( $duplicate ) {
			throw new \InvalidArgumentException( __( 'A bout with the same event, fighters, result, method, round, and time already exists. Review it before creating another record.', 'mma-future-data-engine' ) );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$bout_id = $this->bouts->insert( $payload['bout'] );
			$this->save_related_data( $bout_id, $payload, $user_id );

			$after = $this->snapshot( $bout_id );
			$this->audit_log->write(
				'bout_created',
				'bout',
				$bout_id,
				null,
				$after,
				'Manual bout creation',
				$user_id
			);

			$wpdb->query( 'COMMIT' );

			$result                  = $this->bouts->find( $bout_id ) ?: $payload['bout'];
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function update( int $bout_id, array $input, int $user_id ): array {
		global $wpdb;

		$before_row = $this->bouts->find( $bout_id );
		if ( ! $before_row ) {
			throw new \RuntimeException( __( 'Bout not found.', 'mma-future-data-engine' ) );
		}

		$before             = $this->snapshot( $bout_id );
		$payload            = $this->prepare_payload( $input, $bout_id );
		$this->last_notices = $payload['notices'];

		$duplicate = $this->bouts->find_duplicate(
			(int) $payload['bout']['event_id'],
			(int) $payload['fighter_a']['id'],
			(int) $payload['fighter_b']['id'],
			$payload['bout'],
			$bout_id
		);
		if ( $duplicate ) {
			throw new \InvalidArgumentException( __( 'A bout with the same event, fighters, result, method, round, and time already exists. Review it before saving this duplicate.', 'mma-future-data-engine' ) );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->bouts->update( $bout_id, $payload['bout'] );
			$this->save_related_data( $bout_id, $payload, $user_id );

			$after = $this->snapshot( $bout_id );
			$this->audit_log->write(
				'bout_updated',
				'bout',
				$bout_id,
				$before,
				$after,
				'Manual bout update',
				$user_id
			);

			$wpdb->query( 'COMMIT' );

			$result                  = $this->bouts->find( $bout_id ) ?: $payload['bout'];
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function prepare_payload( array $input, int $bout_id ): array {
		$event_id     = absint( $input['event_id'] ?? 0 );
		$fighter_a_id = absint( $input['fighter_a'] ?? 0 );
		$fighter_b_id = absint( $input['fighter_b'] ?? 0 );

		$event = $event_id > 0 ? $this->events->find( $event_id ) : null;
		if ( ! $event ) {
			throw new \InvalidArgumentException( __( 'A valid canonical event is required.', 'mma-future-data-engine' ) );
		}

		$fighter_a = $fighter_a_id > 0 ? $this->fighters->find( $fighter_a_id ) : null;
		$fighter_b = $fighter_b_id > 0 ? $this->fighters->find( $fighter_b_id ) : null;

		if ( ! $fighter_a || ! $fighter_b ) {
			throw new \InvalidArgumentException( __( 'Two valid canonical fighters are required.', 'mma-future-data-engine' ) );
		}

		if ( $fighter_a_id === $fighter_b_id ) {
			throw new \InvalidArgumentException( __( 'Fighter A and Fighter B cannot be the same fighter.', 'mma-future-data-engine' ) );
		}

		$result_type          = $this->enum_value( $input['result_type'] ?? 'unknown', self::RESULT_TYPES, 'unknown' );
		$status               = $this->enum_value( $input['status'] ?? 'valid', self::BOUT_STATUSES, 'valid' );
		$deleted_soft         = Sanitizer::bool_int( $input['deleted_soft'] ?? 0 );
		$is_scoring_candidate = Sanitizer::bool_int( $input['is_scoring_candidate'] ?? 0 );

		if ( 1 === $deleted_soft ) {
			$status = 'deleted_soft';
		}

		$bout = array(
			'event_id'             => $event_id,
			'bout_order'           => $this->positive_int_or_null( $input['bout_order'] ?? '' ),
			'card_position'        => $this->enum_value( $input['card_position'] ?? 'unknown', self::CARD_POSITIONS, 'unknown' ),
			'weight_class'         => Sanitizer::weight_class( $input['weight_class'] ?? 'unknown' ),
			'weight_lbs'           => $this->positive_int_or_null( $input['weight_lbs'] ?? '' ),
			'status'               => $status,
			'result_type'          => $result_type,
			'method_category'      => $this->enum_value( $input['method_category'] ?? 'unknown', self::METHOD_CATEGORIES, 'unknown' ),
			'method_detail'        => Sanitizer::text_or_null( $input['method_detail'] ?? '' ),
			'round_number'         => $this->positive_int_or_null( $input['round_number'] ?? '' ),
			'time_in_round'        => $this->time_or_null( $input['time_in_round'] ?? '' ),
			'is_scoring_candidate' => $is_scoring_candidate,
			'deleted_soft'         => $deleted_soft,
		);

		if ( ! $this->can_be_scoring_candidate( $bout ) ) {
			if ( 1 === $is_scoring_candidate ) {
				$this->last_notices[] = __( 'Scoring candidate was forced off because the result/status is not eligible for scoring.', 'mma-future-data-engine' );
			}
			$bout['is_scoring_candidate'] = 0;
		}

		$winner_key = $this->winner_key( $input['winner'] ?? '', $result_type );
		$records    = array(
			'fighter_a' => $this->prefight_record( $input, 'fighter_a' ),
			'fighter_b' => $this->prefight_record( $input, 'fighter_b' ),
		);

		return array(
			'bout'         => $bout,
			'event'        => $event,
			'fighter_a'    => $fighter_a,
			'fighter_b'    => $fighter_b,
			'winner_key'   => $winner_key,
			'participants' => $this->build_participants( $fighter_a, $fighter_b, $records, $result_type, $winner_key ),
			'source'       => $this->prepare_source( $input, $bout, $event, $fighter_a, $fighter_b, $bout_id ),
			'notices'      => $this->last_notices,
		);
	}

	private function save_related_data( int $bout_id, array $payload, int $user_id ): void {
		if ( null !== $payload['source'] ) {
			$existing_source = $this->sources->find_by_source(
				$payload['source']['source_type'],
				$payload['source']['source_bout_id']
			);

			if ( $existing_source && ! empty( $existing_source['bout_id'] ) && (int) $existing_source['bout_id'] !== $bout_id ) {
				throw new \RuntimeException( __( 'This source bout ID is already linked to another bout.', 'mma-future-data-engine' ) );
			}

			$this->sources->upsert_for_bout( $bout_id, $payload['source'] );
		}

		$this->participants->replace_exactly_two( $bout_id, $payload['participants'] );

		foreach ( self::PROVENANCE_BOUT_FIELDS as $field_name ) {
			$this->provenance->upsert(
				'bout',
				$bout_id,
				$field_name,
				$payload['bout'][ $field_name ] ?? null,
				$user_id
			);
		}

		foreach ( $this->participants->list_by_bout( $bout_id ) as $participant ) {
			foreach ( self::PROVENANCE_PARTICIPANT_FIELDS as $field_name ) {
				$this->provenance->upsert(
					'bout_participant',
					(int) $participant['id'],
					$field_name,
					$participant[ $field_name ] ?? null,
					$user_id
				);
			}
		}
	}

	private function build_participants( array $fighter_a, array $fighter_b, array $records, string $result_type, ?string $winner_key ): array {
		$a_result  = $this->result_for_role( 'fighter_a', $result_type, $winner_key );
		$b_result  = $this->result_for_role( 'fighter_b', $result_type, $winner_key );
		$a_winner  = $this->is_winner_for_role( 'fighter_a', $result_type, $winner_key );
		$b_winner  = $this->is_winner_for_role( 'fighter_b', $result_type, $winner_key );
		$a_source  = $this->fighter_sources->find_first_for_fighter( (int) $fighter_a['id'] );
		$b_source  = $this->fighter_sources->find_first_for_fighter( (int) $fighter_b['id'] );
		$a_record  = $records['fighter_a'];
		$b_record  = $records['fighter_b'];

		return array(
			array_merge(
				$this->participant_base( $fighter_a, 'fighter_a', $fighter_b, $a_source, $a_result, $a_winner ),
				$this->record_columns( $a_record, $b_record )
			),
			array_merge(
				$this->participant_base( $fighter_b, 'fighter_b', $fighter_a, $b_source, $b_result, $b_winner ),
				$this->record_columns( $b_record, $a_record )
			),
		);
	}

	private function participant_base( array $fighter, string $role, array $opponent, ?array $source, string $result, ?int $is_winner ): array {
		return array(
			'fighter_id'                 => (int) $fighter['id'],
			'participant_role'           => $role,
			'source_fighter_id'          => $source['source_fighter_id'] ?? null,
			'source_fighter_numeric_id'  => $source['source_numeric_id'] ?? null,
			'source_name'                => $fighter['display_name'] ?? null,
			'result_for_fighter'         => $result,
			'opponent_fighter_id'        => (int) $opponent['id'],
			'is_winner'                  => $is_winner,
		);
	}

	private function record_columns( array $own, array $opponent ): array {
		$diff = null;
		if ( null !== $opponent['wins'] && null !== $opponent['losses'] ) {
			$diff = (int) $opponent['wins'] - (int) $opponent['losses'];
		}

		return array(
			'prefight_wins'                 => $own['wins'],
			'prefight_losses'               => $own['losses'],
			'prefight_draws'                => $own['draws'],
			'prefight_nc'                   => $own['nc'],
			'prefight_record_raw'           => $own['raw'],
			'opponent_prefight_wins'        => $opponent['wins'],
			'opponent_prefight_losses'      => $opponent['losses'],
			'opponent_prefight_draws'       => $opponent['draws'],
			'opponent_prefight_nc'          => $opponent['nc'],
			'opponent_prefight_record_raw'  => $opponent['raw'],
			'opponent_prefight_diff'        => $diff,
		);
	}

	private function prefight_record( array $input, string $prefix ): array {
		return array(
			'wins'   => $this->non_negative_int_or_null( $input[ $prefix . '_prefight_wins' ] ?? '' ),
			'losses' => $this->non_negative_int_or_null( $input[ $prefix . '_prefight_losses' ] ?? '' ),
			'draws'  => $this->non_negative_int_or_null( $input[ $prefix . '_prefight_draws' ] ?? '' ),
			'nc'     => $this->non_negative_int_or_null( $input[ $prefix . '_prefight_nc' ] ?? '' ),
			'raw'    => Sanitizer::text_or_null( $input[ $prefix . '_prefight_record_raw' ] ?? '' ),
		);
	}

	private function prepare_source( array $input, array $bout, array $event, array $fighter_a, array $fighter_b, int $bout_id ): ?array {
		$source_type = $this->enum_value( $input['source_type'] ?? 'manual', self::SOURCE_TYPES, 'manual' );
		$source_url  = isset( $input['source_url'] ) ? esc_url_raw( wp_unslash( $input['source_url'] ) ) : '';

		if ( '' === $source_url ) {
			throw new \InvalidArgumentException( __( 'Tapology bout URL is required before a bout can be saved manually.', 'mma-future-data-engine' ) );
		}

		$tapology_bout = $this->derive_tapology_bout_from_url( $source_url );
		$source_type = 'tapology';

		if ( ! $tapology_bout ) {
			throw new \InvalidArgumentException( __( 'Tapology bout URL must match https://www.tapology.com/fightcenter/bouts/{numeric_id}-{slug}.', 'mma-future-data-engine' ) );
		}

		$source_bout_id         = $tapology_bout['source_bout_id'];
		$source_bout_numeric_id = $tapology_bout['source_bout_numeric_id'];
		$source_url             = $tapology_bout['source_url'];

		$event_source_id = $this->sources->find_event_source_id( (int) $event['id'], $source_type );
		$identity_basis  = implode( '|', array( $source_type, $source_bout_id, $source_url, (string) $event['id'], (string) $fighter_a['id'], (string) $fighter_b['id'] ) );
		$content_basis   = implode( '|', array( (string) $bout['result_type'], (string) $bout['method_category'], (string) $bout['round_number'], (string) $bout['time_in_round'] ) );

		return array(
			'event_source_id'        => $event_source_id,
			'source_type'            => $source_type,
			'source_bout_id'         => $source_bout_id,
			'source_bout_numeric_id' => $source_bout_numeric_id,
			'source_url'             => $source_url,
			'identity_hash'          => hash( 'sha256', $identity_basis ),
			'content_hash'           => hash( 'sha256', $content_basis ),
			'raw_payload'            => null,
			'last_import_run_id'     => null,
		);
	}

	private function derive_tapology_bout_from_url( string $url ): ?array {
		if ( ! preg_match( '~^https?://(?:www\.)?tapology\.com/fightcenter/bouts/(\d+)-([^/?#]+)~i', $url, $matches ) ) {
			return null;
		}

		$numeric_id = $matches[1];
		$slug       = sanitize_title( $matches[2] );

		return array(
			'source_bout_id'         => 'tapology_bout_' . $numeric_id,
			'source_bout_numeric_id' => $numeric_id,
			'source_url'             => 'https://www.tapology.com/fightcenter/bouts/' . $numeric_id . '-' . $slug,
		);
	}

	private function winner_key( $value, string $result_type ): ?string {
		if ( 'win_loss' !== $result_type ) {
			return null;
		}

		$value = sanitize_key( wp_unslash( $value ) );
		if ( ! in_array( $value, array( 'fighter_a', 'fighter_b' ), true ) ) {
			throw new \InvalidArgumentException( __( 'A winner is required for win/loss bouts.', 'mma-future-data-engine' ) );
		}

		return $value;
	}

	private function result_for_role( string $role, string $result_type, ?string $winner_key ): string {
		if ( 'win_loss' === $result_type ) {
			return $role === $winner_key ? 'win' : 'loss';
		}

		if ( 'draw' === $result_type ) {
			return 'draw';
		}

		if ( 'no_contest' === $result_type ) {
			return 'no_contest';
		}

		if ( 'cancelled' === $result_type ) {
			return 'cancelled';
		}

		return 'unknown';
	}

	private function is_winner_for_role( string $role, string $result_type, ?string $winner_key ): ?int {
		if ( 'win_loss' !== $result_type ) {
			return null;
		}

		return $role === $winner_key ? 1 : 0;
	}

	private function can_be_scoring_candidate( array $bout ): bool {
		if ( 'win_loss' !== $bout['result_type'] ) {
			return false;
		}

		if ( ! in_array( $bout['status'], array( 'valid', 'completed' ), true ) ) {
			return false;
		}

		if ( 1 === (int) $bout['deleted_soft'] ) {
			return false;
		}

		return ! in_array( $bout['method_category'], array( 'unknown', 'no_contest', 'draw' ), true );
	}

	private function enum_value( $value, array $allowed, string $default ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function positive_int_or_null( $value ): ?int {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
			throw new \InvalidArgumentException( __( 'Positive integer fields must be empty or greater than zero.', 'mma-future-data-engine' ) );
		}

		return (int) $value;
	}

	private function non_negative_int_or_null( $value ): ?int {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! ctype_digit( $value ) ) {
			throw new \InvalidArgumentException( __( 'Prefight record fields must be empty or non-negative integers.', 'mma-future-data-engine' ) );
		}

		return (int) $value;
	}

	private function time_or_null( $value ): ?string {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! preg_match( '/^\d{1,2}:[0-5]\d$/', $value ) ) {
			throw new \InvalidArgumentException( __( 'Time in round must be empty or use M:SS / MM:SS.', 'mma-future-data-engine' ) );
		}

		return $value;
	}

	private function snapshot( int $bout_id ): ?array {
		$bout = $this->bouts->find( $bout_id );
		if ( ! $bout ) {
			return null;
		}

		$bout['_participants']   = $this->participants->list_by_bout( $bout_id );
		$bout['_source_summary'] = $this->sources->find_first_for_bout( $bout_id );

		return $bout;
	}
}
