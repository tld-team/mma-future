<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\EventSourceRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Repositories\SourceImportItemRepository;
use MMAF\DataEngine\Repositories\SourceImportRunRepository;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Services\FighterPostSyncService;
use MMAF\DataEngine\Support\DateTime;
use MMAF\DataEngine\Support\Sanitizer;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScraperJsonImportService {
	private const SOURCE = 'tapology';

	private ScraperJsonDryRunService $dry_run;
	private SourceImportRunRepository $runs;
	private SourceImportItemRepository $items;
	private FighterRepository $fighters;
	private FighterSourceRepository $fighter_sources;
	private EventRepository $events;
	private EventSourceRepository $event_sources;
	private BoutRepository $bouts;
	private BoutSourceRepository $bout_sources;
	private BoutParticipantRepository $participants;
	private FighterPostSyncService $post_sync;
	private FieldProvenanceService $provenance;
	private AuditLogService $audit_log;
	private array $tables;
	private array $summary;
	private array $resolved_fighters = array();
	private array $resolved_events = array();

	public function __construct() {
		$this->dry_run         = new ScraperJsonDryRunService();
		$this->runs            = new SourceImportRunRepository();
		$this->items           = new SourceImportItemRepository();
		$this->fighters        = new FighterRepository();
		$this->fighter_sources = new FighterSourceRepository();
		$this->events          = new EventRepository();
		$this->event_sources   = new EventSourceRepository();
		$this->bouts           = new BoutRepository();
		$this->bout_sources    = new BoutSourceRepository();
		$this->participants    = new BoutParticipantRepository();
		$this->post_sync       = new FighterPostSyncService( $this->fighters );
		$this->provenance      = new FieldProvenanceService();
		$this->audit_log       = new AuditLogService();
		$this->tables          = Schema::table_names();
	}

	public function import_file( string $path, int $created_by = 0 ): array {
		$content = file_get_contents( $path );

		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read JSON file: ' . $path );
		}

		return $this->import_json_string( $content, $created_by );
	}

	public function import_json_string( string $json, int $created_by = 0 ): array {
		$started_at = DateTime::mysql_now();
		$plan       = $this->dry_run->analyze_json_string( $json, $created_by, false );
		$summary    = (array) $plan['summary'];
		$run_id     = $this->runs->insert_import_run(
			array(
				'source_type'           => (string) ( $summary['source'] ?? self::SOURCE ),
				'source_schema_version' => (string) ( $summary['schema_version'] ?? '' ),
				'source_run_id'         => (string) ( $summary['source_run_id'] ?? '' ),
				'payload_hash'          => (string) ( $summary['payload_hash'] ?? hash( 'sha256', $json ) ),
				'status'                => 'running',
				'started_at'            => $started_at,
				'summary'               => $summary,
				'created_by'            => $created_by,
			)
		);

		$summary['import_run_id'] = $run_id;

		if ( empty( $plan['is_valid'] ) ) {
			$summary['canonical_writes_performed'] = false;
			$this->runs->update_run(
				$run_id,
				array(
					'status'        => 'failed',
					'finished_at'   => DateTime::mysql_now(),
					'summary'       => $summary,
					'error_message' => 'Schema validation failed.',
				)
			);

			return array(
				'is_valid' => false,
				'summary'  => $summary,
			);
		}

		$decoded = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Decoded payload was not an object after validation.' );
		}

		$this->summary = $this->initial_import_summary( $summary, $run_id );

		try {
			$fighter_plan = $this->index_by( (array) $plan['fighters'], 'ref_key' );
			$event_plan   = $this->index_by( (array) $plan['events'], 'source_event_id' );
			$bout_plan    = $this->index_bouts( (array) $plan['bouts'] );

			$this->process_fighters( (array) ( $decoded['events'] ?? array() ), $fighter_plan, $run_id, $created_by );
			$this->process_events( (array) ( $decoded['events'] ?? array() ), $event_plan, $run_id, $created_by );
			$this->process_bouts( (array) ( $decoded['events'] ?? array() ), $bout_plan, $run_id, $created_by );

			$this->summary['finished_at'] = DateTime::mysql_now();
			$this->summary['status']      = $this->has_completion_warnings() ? 'completed_with_warnings' : 'completed';

			$this->runs->update_run(
				$run_id,
				array(
					'status'      => $this->summary['status'],
					'finished_at' => $this->summary['finished_at'],
					'summary'     => $this->summary,
				)
			);
		} catch ( \Throwable $error ) {
			$this->summary['status']        = 'failed';
			$this->summary['finished_at']   = DateTime::mysql_now();
			$this->summary['error_message'] = $error->getMessage();
			$this->runs->update_run(
				$run_id,
				array(
					'status'        => 'failed',
					'finished_at'   => $this->summary['finished_at'],
					'summary'       => $this->summary,
					'error_message' => $error->getMessage(),
				)
			);

			throw $error;
		}

		return array(
			'is_valid' => true,
			'summary'  => $this->summary,
		);
	}

	private function process_fighters( array $events, array $fighter_plan, int $run_id, int $user_id ): void {
		foreach ( $this->collect_refs( $events ) as $key => $ref ) {
			$preview           = (array) ( $fighter_plan[ $key ] ?? array() );
			$action            = (string) ( $preview['action'] ?? 'unresolved_fighter_ref' );
			$source_fighter_id = (string) ( $ref['source_fighter_id'] ?? '' );
			$warnings          = array();

			try {
				if ( in_array( $action, array( 'exact_source_match', 'exact_source_url_match', 'exact_source_url_hash_match' ), true ) ) {
					$source = in_array( $action, array( 'exact_source_url_match', 'exact_source_url_hash_match' ), true )
						? $this->single_source_url_match( (string) ( $ref['url'] ?? '' ) )
						: $this->fighter_sources->find_by_source( self::SOURCE, $source_fighter_id );
					$fighter_id = $source ? (int) $source['fighter_id'] : 0;

					if ( $fighter_id <= 0 ) {
						throw new \RuntimeException( 'Exact source mapping has no fighter_id.' );
					}

					$this->resolved_fighters[ $key ] = $fighter_id;
					$this->update_fighter_source_metadata( (int) $source['id'], $ref );
					$this->summary['fighters_exact_matched']++;
					if ( 'exact_source_url_hash_match' === $action ) {
						$this->summary['fighters_exact_source_url_hash_matched']++;
					}
					$this->log_item( $run_id, 'fighter', $this->source_identity_for_log( $ref ), null, null, $fighter_id, 'no_change', 'exact_source_url_hash_match' === $action ? 'exact_source_url_hash_match' : ( 'exact_source_url_match' === $action ? 'exact_url_match' : 'exact_match' ), $warnings );
					continue;
				}

				if ( 'likely_match_review' === $action ) {
					$this->summary['fighters_likely_match_skipped']++;
					$this->summary['needs_review_count']++;
					$this->log_item( $run_id, 'fighter', $source_fighter_id, null, null, null, 'needs_review', 'likely_match_review', array( (string) ( $preview['reason'] ?? 'Likely name match requires manual review.' ) ) );
					continue;
				}

				$can_create_url_only = 'create_provisional_with_url_only_identity' === $action && null !== TapologyFighterUrl::source_url_hash( (string) ( $ref['url'] ?? '' ) );
				$can_create_by_id    = 'create_provisional_candidate' === $action && '' !== $source_fighter_id;
				if ( ! ( $can_create_url_only || $can_create_by_id ) || '' === (string) ( $ref['name'] ?? '' ) ) {
					$this->summary['needs_review_count']++;
					if ( 'ambiguous_source_url_hash' === $action ) {
						$this->summary['fighters_ambiguous_source_url_hash']++;
					}
					$this->log_item( $run_id, 'fighter', $this->source_identity_for_log( $ref ), null, null, null, 'needs_review', $action ?: 'unresolved_fighter_ref', array( 'Fighter reference could not be safely resolved.' ) );
					continue;
				}

				$existing = '' !== $source_fighter_id
					? $this->fighter_sources->find_by_source( self::SOURCE, $source_fighter_id )
					: $this->single_source_url_match( (string) ( $ref['url'] ?? '' ) );
				if ( $existing && ! empty( $existing['fighter_id'] ) ) {
					$fighter_id = (int) $existing['fighter_id'];
					$this->resolved_fighters[ $key ] = $fighter_id;
					$this->summary['fighters_exact_matched']++;
					$this->log_item( $run_id, 'fighter', $this->source_identity_for_log( $ref ), null, null, $fighter_id, 'no_change', $can_create_url_only ? 'exact_source_url_hash_match' : 'exact_match', array( 'Source mapping already exists at import time.' ) );
					continue;
				}

				$fighter_id = $this->create_provisional_fighter( $ref, $user_id );
				$this->resolved_fighters[ $key ] = $fighter_id;
				$this->summary['fighters_created_provisional']++;
				if ( $can_create_url_only ) {
					$this->summary['fighters_created_provisional_with_url_only_identity']++;
				}
				$this->summary['canonical_writes_performed'] = true;
				$this->log_item( $run_id, 'fighter', $this->source_identity_for_log( $ref ), null, null, $fighter_id, 'created', $can_create_url_only ? 'created_provisional_with_url_only_identity' : 'create_provisional', $warnings );
			} catch ( \Throwable $error ) {
				$this->summary['item_failures']++;
				$this->log_item( $run_id, 'fighter', $this->source_identity_for_log( $ref ), null, null, null, 'failed', $action, $warnings, $error->getMessage() );
			}
		}
	}

	private function process_events( array $events, array $event_plan, int $run_id, int $user_id ): void {
		foreach ( $events as $event ) {
			$source_event_id = (string) ( $event['source_event_id'] ?? '' );
			$identity_hash   = (string) ( $event['identity_hash'] ?? '' );
			$content_hash    = (string) ( $event['content_hash'] ?? '' );
			$preview         = (array) ( $event_plan[ $source_event_id ] ?? array() );
			$action          = (string) ( $preview['action'] ?? 'review_event_match' );
			$warnings        = (array) ( $event['warnings'] ?? array() );

			try {
				if ( 'review_event_match' === $action ) {
					$this->summary['events_needs_review_conflict']++;
					$this->summary['needs_review_count']++;
					$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, null, 'needs_review', 'review_match', $warnings );
					continue;
				}

				$source = $this->event_sources->find_by_source( self::SOURCE, $source_event_id );
				if ( $source && ! empty( $source['identity_hash'] ) && $source['identity_hash'] !== $identity_hash ) {
					$this->summary['events_needs_review_conflict']++;
					$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, (int) $source['event_id'], 'conflict', 'conflict', array( 'Existing source mapping identity_hash differs; canonical event was not overwritten.' ) );
					continue;
				}

				if ( $source && ! empty( $source['event_id'] ) ) {
					$event_id = (int) $source['event_id'];
					$this->resolved_events[ $source_event_id ] = $event_id;

					if ( 'no_change_candidate' === $action && (string) $source['content_hash'] === $content_hash ) {
						$this->summary['events_no_change']++;
						$this->touch_event_source( (int) $source['id'], $event, $run_id );
						$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, $event_id, 'no_change', 'no_change', $warnings );
						continue;
					}

					$updated = $this->update_event_from_source( $event_id, $event, $warnings, $user_id );
					$this->touch_event_source( (int) $source['id'], $event, $run_id );
					$this->summary[ $updated ? 'events_updated' : 'events_no_change' ]++;
					$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, $event_id, $updated ? 'updated' : 'no_change', $updated ? 'update' : 'no_change', $warnings );
					continue;
				}

				if ( 'create_candidate' !== $action ) {
					$this->summary['events_needs_review_conflict']++;
					$this->summary['needs_review_count']++;
					$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, null, 'needs_review', 'review_match', $warnings );
					continue;
				}

				$event_id = $this->create_event( $event, $run_id, $user_id );
				$this->resolved_events[ $source_event_id ] = $event_id;
				$this->summary['events_created']++;
				$this->summary['canonical_writes_performed'] = true;
				$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, $event_id, 'created', 'create', $warnings );
			} catch ( \Throwable $error ) {
				$this->summary['item_failures']++;
				$this->log_item( $run_id, 'event', $source_event_id, $identity_hash, $content_hash, null, 'failed', $action, $warnings, $error->getMessage() );
			}
		}
	}

	private function process_bouts( array $events, array $bout_plan, int $run_id, int $user_id ): void {
		foreach ( $events as $event ) {
			$source_event_id = (string) ( $event['source_event_id'] ?? '' );
			$event_id        = (int) ( $this->resolved_events[ $source_event_id ] ?? 0 );

			foreach ( (array) ( $event['bouts'] ?? array() ) as $bout ) {
				$source_bout_id = (string) ( $bout['source_bout_id'] ?? '' );
				$identity_hash  = (string) ( $bout['identity_hash'] ?? '' );
				$content_hash   = (string) ( $bout['content_hash'] ?? '' );
				$preview        = (array) ( $bout_plan[ $source_bout_id ] ?? array() );
				$action         = (string) ( $preview['action'] ?? 'review_bout_match' );
				$warnings       = (array) ( $bout['warnings'] ?? array() );

				try {
					if ( $event_id <= 0 ) {
						$this->summary['bouts_needs_review_conflict']++;
						$this->summary['needs_review_count']++;
						$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, null, 'needs_review', 'unresolved_event', array_merge( $warnings, array( 'Parent event was not safely resolved.' ) ) );
						continue;
					}

					if ( in_array( $action, array( 'review_bout_match', 'excluded_amateur', 'excluded_cancelled', 'excluded_overturned', 'upcoming_review', 'skipped_non_scoring' ), true ) ) {
						$status = in_array( $action, array( 'review_bout_match', 'upcoming_review' ), true ) ? 'needs_review' : 'skipped';
						$this->summary[ 'skipped_non_scoring' === $action ? 'bouts_skipped_non_scoring' : 'bouts_needs_review_conflict' ]++;
						if ( 'needs_review' === $status ) {
							$this->summary['needs_review_count']++;
						}
						$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, null, $status, 'skipped_non_scoring' === $action ? 'skipped_non_scoring' : 'needs_review', $warnings );
						continue;
					}

					$fighter_a_id = (int) ( $this->resolved_fighters[ $this->fighter_ref_key( (array) ( $bout['fighter_a'] ?? array() ) ) ] ?? 0 );
					$fighter_b_id = (int) ( $this->resolved_fighters[ $this->fighter_ref_key( (array) ( $bout['fighter_b'] ?? array() ) ) ] ?? 0 );
					if ( $fighter_a_id <= 0 || $fighter_b_id <= 0 || $fighter_a_id === $fighter_b_id ) {
						$this->summary['bouts_needs_review_conflict']++;
						$this->summary['needs_review_count']++;
						$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, null, 'needs_review', 'unresolved_fighter_ref', array_merge( $warnings, array( 'One or both bout fighters were not safely resolved.' ) ) );
						continue;
					}

					$source = $this->bout_sources->find_by_source( self::SOURCE, $source_bout_id );
					if ( $source && ! empty( $source['identity_hash'] ) && $source['identity_hash'] !== $identity_hash ) {
						$warnings[] = 'source_bout_identity_hash_changed:update_existing_source_bout';
						$this->summary['warnings_count']++;
					}

					if ( $source && ! empty( $source['bout_id'] ) ) {
						$bout_id = (int) $source['bout_id'];
						if ( 'no_change_candidate' === $action && (string) $source['content_hash'] === $content_hash ) {
							$this->summary['bouts_no_change']++;
							$this->touch_bout_source( (int) $source['id'], $bout, $event_id, $run_id );
							$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, $bout_id, 'no_change', 'no_change', $warnings );
							continue;
						}

						$updated = $this->update_bout_from_source( $bout_id, $bout, $event_id, $fighter_a_id, $fighter_b_id, $warnings, $user_id );
						$this->touch_bout_source( (int) $source['id'], $bout, $event_id, $run_id );
						$this->summary[ $updated ? 'bouts_updated' : 'bouts_no_change' ]++;
						$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, $bout_id, $updated ? 'updated' : 'no_change', $updated ? 'update' : 'no_change', $warnings );
						continue;
					}

					if ( 'create_candidate' !== $action ) {
						$this->summary['bouts_needs_review_conflict']++;
						$this->summary['needs_review_count']++;
						$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, null, 'needs_review', 'review_match', $warnings );
						continue;
					}

					$bout_id = $this->create_bout( $bout, $event_id, $fighter_a_id, $fighter_b_id, $run_id, $user_id, $warnings );
					$this->summary['bouts_created']++;
					$this->summary['participants_created_updated'] += 2;
					$this->summary['canonical_writes_performed'] = true;
					$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, $bout_id, 'created', 'create', $warnings );
				} catch ( \Throwable $error ) {
					$this->summary['item_failures']++;
					$this->log_item( $run_id, 'bout', $source_bout_id, $identity_hash, $content_hash, null, 'failed', $action, $warnings, $error->getMessage() );
				}
			}
		}
	}

	private function create_provisional_fighter( array $ref, int $user_id ): int {
		global $wpdb;

		$name = sanitize_text_field( (string) $ref['name'] );
		$row  = array(
			'wp_post_id'          => null,
			'display_name'        => $name,
			'nickname'            => null,
			'normalized_name'     => Sanitizer::normalize_name( $name ),
			'gender'              => null,
			'date_of_birth'       => null,
			'birth_year'          => null,
			'nationality'         => null,
			'weight_class'        => 'unknown',
			'status'              => 'provisional',
			'rankability_status'  => 'pending_review',
			'is_public'           => 0,
			'is_rankable'         => 0,
			'in_ufc'              => 0,
			'deleted_soft'        => 0,
		);

		$wpdb->query( 'START TRANSACTION' );

		try {
			$fighter_id = $this->fighters->insert( $row );
			$fighter    = $this->fighters->find( $fighter_id );
			if ( ! $fighter ) {
				throw new \RuntimeException( 'Could not reload provisional fighter.' );
			}

			$post_id = $this->post_sync->sync( $fighter_id, $fighter );
			$this->insert_fighter_source( $fighter_id, $ref );

			foreach ( array( 'display_name', 'weight_class', 'status', 'rankability_status', 'is_public', 'is_rankable', 'in_ufc' ) as $field ) {
				$this->provenance->upsert_source( 'fighter', $fighter_id, $field, $row[ $field ] ?? null, self::SOURCE, (string) ( $ref['source_fighter_id'] ?? '' ), $user_id );
				$this->summary['provenance_rows_written']++;
			}

			$after = $this->fighters->find( $fighter_id );
			$this->audit_log->write( 'fighter_created_from_scraper', 'fighter', $fighter_id, null, $after, 'Tapology scraper JSON import created a provisional fighter.', $user_id );
			$this->summary['audit_rows_written']++;

			$wpdb->query( 'COMMIT' );

			return $fighter_id;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function create_event( array $event, int $run_id, int $user_id ): int {
		global $wpdb;

		$row = $this->event_row( $event );

		$wpdb->query( 'START TRANSACTION' );

		try {
			$event_id = $this->events->insert( $row );
			$this->insert_event_source( $event_id, $event, $run_id );

			foreach ( array_keys( $row ) as $field ) {
				$this->provenance->upsert_source( 'event', $event_id, $field, $row[ $field ], self::SOURCE, (string) ( $event['source_event_id'] ?? '' ), $user_id );
				$this->summary['provenance_rows_written']++;
			}

			$after = $this->events->find( $event_id );
			$this->audit_log->write( 'event_created_from_scraper', 'event', $event_id, null, $after, 'Tapology scraper JSON import created an event.', $user_id );
			$this->summary['audit_rows_written']++;

			$wpdb->query( 'COMMIT' );

			return $event_id;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function create_bout( array $bout, int $event_id, int $fighter_a_id, int $fighter_b_id, int $run_id, int $user_id, array &$warnings ): int {
		global $wpdb;

		$row          = $this->bout_row( $bout, $event_id, $warnings );
		$participants = $this->participant_rows( $bout, $fighter_a_id, $fighter_b_id );

		$wpdb->query( 'START TRANSACTION' );

		try {
			$bout_id = $this->bouts->insert( $row );
			$this->insert_bout_source( $bout_id, $bout, $event_id, $run_id );
			$this->participants->replace_exactly_two( $bout_id, $participants );

			foreach ( array_keys( $row ) as $field ) {
				$this->provenance->upsert_source( 'bout', $bout_id, $field, $row[ $field ], self::SOURCE, (string) ( $bout['source_bout_id'] ?? '' ), $user_id );
				$this->summary['provenance_rows_written']++;
			}

			foreach ( $this->participants->list_by_bout( $bout_id ) as $participant ) {
				foreach ( array( 'fighter_id', 'participant_role', 'source_fighter_id', 'source_name', 'result_for_fighter', 'opponent_fighter_id', 'prefight_wins', 'prefight_losses', 'prefight_draws', 'prefight_nc', 'prefight_record_raw', 'is_winner' ) as $field ) {
					$this->provenance->upsert_source( 'bout_participant', (int) $participant['id'], $field, $participant[ $field ] ?? null, self::SOURCE, (string) ( $bout['source_bout_id'] ?? '' ), $user_id );
					$this->summary['provenance_rows_written']++;
				}
			}

			$after = $this->bouts->find( $bout_id );
			$after['_participants'] = $this->participants->list_by_bout( $bout_id );
			$this->audit_log->write( 'bout_created_from_scraper', 'bout', $bout_id, null, $after, 'Tapology scraper JSON import created a bout.', $user_id );
			$this->summary['audit_rows_written']++;

			$wpdb->query( 'COMMIT' );

			return $bout_id;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function update_event_from_source( int $event_id, array $event, array &$warnings, int $user_id ): bool {
		$before = $this->events->find( $event_id );
		if ( ! $before ) {
			throw new \RuntimeException( 'Event not found for update.' );
		}

		$incoming = $this->event_row( $event );
		$updates  = $this->safe_updates( 'event', $event_id, $before, $incoming, $warnings );
		if ( empty( $updates ) ) {
			return false;
		}

		$this->events->update( $event_id, $updates );
		foreach ( $updates as $field => $value ) {
			$this->provenance->upsert_source( 'event', $event_id, $field, $value, self::SOURCE, (string) ( $event['source_event_id'] ?? '' ), $user_id );
			$this->summary['provenance_rows_written']++;
		}

		$after = $this->events->find( $event_id );
		$this->audit_log->write( 'event_updated_from_scraper', 'event', $event_id, $before, $after, 'Tapology scraper JSON import updated safe event fields.', $user_id );
		$this->summary['audit_rows_written']++;
		$this->summary['canonical_writes_performed'] = true;

		return true;
	}

	private function update_bout_from_source( int $bout_id, array $bout, int $event_id, int $fighter_a_id, int $fighter_b_id, array &$warnings, int $user_id ): bool {
		$before = $this->bouts->find( $bout_id );
		if ( ! $before ) {
			throw new \RuntimeException( 'Bout not found for update.' );
		}

		$incoming = $this->bout_row( $bout, $event_id, $warnings );
		$updates  = $this->safe_updates( 'bout', $bout_id, $before, $incoming, $warnings );
		$changed  = false;

		if ( ! empty( $updates ) ) {
			$this->bouts->update( $bout_id, $updates );
			foreach ( $updates as $field => $value ) {
				$this->provenance->upsert_source( 'bout', $bout_id, $field, $value, self::SOURCE, (string) ( $bout['source_bout_id'] ?? '' ), $user_id );
				$this->summary['provenance_rows_written']++;
			}
			$changed = true;
		}

		if ( $this->provenance->has_source( 'bout', $bout_id, 'result_type', self::SOURCE ) && ! $this->provenance->has_manual_verified( 'bout', $bout_id, 'result_type' ) ) {
			$this->participants->replace_exactly_two( $bout_id, $this->participant_rows( $bout, $fighter_a_id, $fighter_b_id ) );
			$this->summary['participants_created_updated'] += 2;
			$changed = true;
		} else {
			$warnings[] = 'manual_participant_rows_preserved';
			$this->summary['warnings_count']++;
		}

		if ( $changed ) {
			$after = $this->bouts->find( $bout_id );
			$after['_participants'] = $this->participants->list_by_bout( $bout_id );
			$this->audit_log->write( 'bout_updated_from_scraper', 'bout', $bout_id, $before, $after, 'Tapology scraper JSON import updated safe bout fields.', $user_id );
			$this->summary['audit_rows_written']++;
			$this->summary['canonical_writes_performed'] = true;
		}

		return $changed;
	}

	private function safe_updates( string $entity_type, int $entity_id, array $current, array $incoming, array &$warnings ): array {
		$updates = array();

		foreach ( $incoming as $field => $value ) {
			$current_value = $current[ $field ] ?? null;
			if ( $this->same_value( $current_value, $value ) ) {
				continue;
			}

			$current_empty = null === $current_value || '' === (string) $current_value;
			$tapology_owned = $this->provenance->has_source( $entity_type, $entity_id, $field, self::SOURCE ) && ! $this->provenance->has_manual_verified( $entity_type, $entity_id, $field );

			if ( $current_empty || $tapology_owned ) {
				$updates[ $field ] = $value;
				continue;
			}

			$warnings[] = 'manual_field_preserved:' . $entity_type . '.' . $field;
			$this->summary['warnings_count']++;
		}

		return $updates;
	}

	private function event_row( array $event ): array {
		$name = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );

		return array(
			'event_name'            => '' !== $name ? $name : 'Unknown Tapology Event',
			'normalized_event_name' => Sanitizer::normalize_name( $name ),
			'event_date'            => Sanitizer::valid_date_or_null( $event['event_date'] ?? '' ),
			'event_datetime_utc'    => null,
			'timezone_raw'          => Sanitizer::text_or_null( $event['timezone_raw'] ?? '' ),
			'promotion_name'        => Sanitizer::text_or_null( $event['promotion_name'] ?? '' ),
			'venue'                 => Sanitizer::text_or_null( $event['venue'] ?? '' ),
			'location'              => Sanitizer::text_or_null( $event['location'] ?? '' ),
			'city'                  => Sanitizer::text_or_null( $event['city'] ?? '' ),
			'country'               => Sanitizer::text_or_null( $event['country'] ?? '' ),
			'region'                => Sanitizer::text_or_null( $event['region'] ?? '' ),
			'status'                => 'completed',
			'deleted_soft'          => 0,
		);
	}

	private function bout_row( array $bout, int $event_id, array &$warnings ): array {
		$result = (array) ( $bout['result'] ?? array() );
		$flags  = (array) ( $bout['flags'] ?? array() );
		$result_type = $this->result_type( (string) ( $bout['result_type'] ?? 'unknown' ) );
		$method = $this->method_category( (string) ( $result['method_category'] ?? 'unknown' ) );
		$status = 'completed';

		if ( ! empty( $flags['is_amateur'] ) ) {
			$status = 'excluded_amateur';
		} elseif ( ! empty( $flags['is_cancelled'] ) ) {
			$status = 'excluded_cancelled';
			$result_type = 'cancelled';
		} elseif ( ! empty( $flags['is_overturned'] ) ) {
			$status = 'excluded_overturned';
		} elseif ( ! empty( $flags['is_upcoming'] ) ) {
			$status = 'pending_result_review';
		}

		$is_scoring = ! empty( $flags['is_scoring_candidate'] ) && 'win_loss' === $result_type && in_array( $status, array( 'valid', 'completed' ), true ) && ! in_array( $method, array( 'unknown', 'draw', 'no_contest' ), true ) ? 1 : 0;

		if ( 'unknown' === $method ) {
			$warnings[] = 'method_category_unknown';
			$this->summary['warnings_count']++;
		}

		return array(
			'event_id'              => $event_id,
			'bout_order'            => Sanitizer::int_or_null( $bout['bout_order'] ?? '' ),
			'card_position'         => $this->card_position( (string) ( $bout['card_position'] ?? 'unknown' ) ),
			'weight_class'          => $this->weight_class( (string) ( $bout['weight_class'] ?? '' ), $warnings ),
			'weight_lbs'            => Sanitizer::int_or_null( $bout['weight_lbs'] ?? '' ),
			'status'                => $status,
			'result_type'           => $result_type,
			'method_category'       => $method,
			'method_detail'         => Sanitizer::text_or_null( $result['method_detail'] ?? '' ),
			'round_number'          => Sanitizer::int_or_null( $result['round'] ?? '' ),
			'time_in_round'         => Sanitizer::text_or_null( $result['time'] ?? '' ),
			'is_scoring_candidate'  => $is_scoring,
			'deleted_soft'          => 0,
		);
	}

	private function participant_rows( array $bout, int $fighter_a_id, int $fighter_b_id ): array {
		$result_type = $this->result_type( (string) ( $bout['result_type'] ?? 'unknown' ) );
		$winner      = sanitize_key( (string) ( $bout['winner'] ?? '' ) );
		$a_ref       = (array) ( $bout['fighter_a'] ?? array() );
		$b_ref       = (array) ( $bout['fighter_b'] ?? array() );
		$a_record    = (array) ( $a_ref['prefight_record'] ?? array() );
		$b_record    = (array) ( $b_ref['prefight_record'] ?? array() );

		return array(
			array_merge( $this->participant_base( $a_ref, 'fighter_a', $fighter_a_id, $fighter_b_id, $result_type, $winner ), $this->record_columns( $a_record, $b_record ) ),
			array_merge( $this->participant_base( $b_ref, 'fighter_b', $fighter_b_id, $fighter_a_id, $result_type, $winner ), $this->record_columns( $b_record, $a_record ) ),
		);
	}

	private function participant_base( array $ref, string $role, int $fighter_id, int $opponent_id, string $result_type, string $winner ): array {
		$is_winner = null;
		$result    = 'unknown';

		if ( 'win_loss' === $result_type && in_array( $winner, array( 'fighter_a', 'fighter_b' ), true ) ) {
			$is_winner = $role === $winner ? 1 : 0;
			$result    = $role === $winner ? 'win' : 'loss';
		} elseif ( 'draw' === $result_type ) {
			$result = 'draw';
		} elseif ( 'no_contest' === $result_type ) {
			$result = 'no_contest';
		} elseif ( 'cancelled' === $result_type ) {
			$result = 'cancelled';
		}

		return array(
			'fighter_id'                => $fighter_id,
			'participant_role'          => $role,
			'source_fighter_id'         => isset( $ref['source_fighter_id'] ) ? (string) $ref['source_fighter_id'] : null,
			'source_fighter_numeric_id' => isset( $ref['source_fighter_numeric_id'] ) ? (string) $ref['source_fighter_numeric_id'] : null,
			'source_name'               => Sanitizer::text_or_null( $ref['name'] ?? '' ),
			'result_for_fighter'        => $result,
			'opponent_fighter_id'       => $opponent_id,
			'is_winner'                 => $is_winner,
		);
	}

	private function record_columns( array $own, array $opponent ): array {
		$opponent_wins   = $this->nullable_int( $opponent['wins'] ?? null );
		$opponent_losses = $this->nullable_int( $opponent['losses'] ?? null );

		return array(
			'prefight_wins'                => $this->nullable_int( $own['wins'] ?? null ),
			'prefight_losses'              => $this->nullable_int( $own['losses'] ?? null ),
			'prefight_draws'               => $this->nullable_int( $own['draws'] ?? null ),
			'prefight_nc'                  => $this->nullable_int( $own['nc'] ?? null ),
			'prefight_record_raw'          => Sanitizer::text_or_null( $own['raw'] ?? '' ),
			'opponent_prefight_wins'       => $opponent_wins,
			'opponent_prefight_losses'     => $opponent_losses,
			'opponent_prefight_draws'      => $this->nullable_int( $opponent['draws'] ?? null ),
			'opponent_prefight_nc'         => $this->nullable_int( $opponent['nc'] ?? null ),
			'opponent_prefight_record_raw' => Sanitizer::text_or_null( $opponent['raw'] ?? '' ),
			'opponent_prefight_diff'       => null !== $opponent_wins && null !== $opponent_losses ? $opponent_wins - $opponent_losses : null,
		);
	}

	private function insert_fighter_source( int $fighter_id, array $ref ): void {
		global $wpdb;

		$now = DateTime::mysql_now();
		$tapology = TapologyFighterUrl::parse( (string) ( $ref['url'] ?? '' ) );
		$url = $tapology ? (string) $tapology['canonical_url'] : esc_url_raw( TapologyFighterUrl::normalize( (string) ( $ref['url'] ?? '' ) ) );
		$source_fighter_id = $tapology ? $tapology['source_fighter_id'] : ( isset( $ref['source_fighter_id'] ) ? (string) $ref['source_fighter_id'] : null );
		$source_numeric_id = $tapology ? $tapology['source_numeric_id'] : ( isset( $ref['source_fighter_numeric_id'] ) ? (string) $ref['source_fighter_numeric_id'] : null );
		$source_slug       = $tapology ? $tapology['source_slug'] : $this->slug_from_url( $url );
		$identity_hash     = $this->fighter_sources->identity_hash_for_source( self::SOURCE, $source_fighter_id, $url );

		$wpdb->insert(
			$this->tables['fighter_sources'],
			array(
				'fighter_id'          => $fighter_id,
				'source_type'         => self::SOURCE,
				'source_fighter_id'   => $source_fighter_id,
				'source_numeric_id'   => $source_numeric_id,
				'source_url'          => '' !== $url ? $url : null,
				'source_slug'         => $source_slug,
				'identity_hash'       => $identity_hash,
				'confidence'          => 100,
				'is_verified'         => 0,
				'is_primary'          => 1,
				'created_at'          => $now,
				'updated_at'          => null,
			)
		);
	}

	private function update_fighter_source_metadata( int $source_id, array $ref ): void {
		global $wpdb;

		$tapology = TapologyFighterUrl::parse( (string) ( $ref['url'] ?? '' ) );
		$url = $tapology ? (string) $tapology['canonical_url'] : esc_url_raw( TapologyFighterUrl::normalize( (string) ( $ref['url'] ?? '' ) ) );
		$updates = array(
			'updated_at' => DateTime::mysql_now(),
		);
		if ( $tapology ) {
			$updates['source_fighter_id'] = $tapology['source_fighter_id'];
			$updates['source_numeric_id'] = $tapology['source_numeric_id'];
			$updates['identity_hash']     = $tapology['source_url_hash'];
		} elseif ( ! empty( $ref['source_fighter_numeric_id'] ) ) {
			$updates['source_numeric_id'] = (string) $ref['source_fighter_numeric_id'];
		}
		if ( '' !== $url ) {
			$updates['source_url']  = $url;
			$updates['source_slug'] = $tapology ? $tapology['source_slug'] : $this->slug_from_url( $url );
		}

		$wpdb->update(
			$this->tables['fighter_sources'],
			$updates,
			array( 'id' => $source_id ),
			null,
			array( '%d' )
		);
	}

	private function single_source_url_match( string $source_url ): ?array {
		$matches = $this->fighter_sources->find_by_normalized_source_url( self::SOURCE, $source_url );
		$fighter_ids = array();
		foreach ( $matches as $match ) {
			$fighter_id = (int) ( $match['fighter_id'] ?? 0 );
			if ( $fighter_id > 0 ) {
				$fighter_ids[ $fighter_id ] = true;
			}
		}

		if ( 1 !== count( $fighter_ids ) ) {
			return null;
		}

		$matched_fighter_id = (int) array_key_first( $fighter_ids );
		foreach ( $matches as $match ) {
			if ( (int) ( $match['fighter_id'] ?? 0 ) === $matched_fighter_id ) {
				return $match;
			}
		}

		return null;
	}

	private function insert_event_source( int $event_id, array $event, int $run_id ): void {
		global $wpdb;

		$now = DateTime::mysql_now();
		$wpdb->insert(
			$this->tables['event_sources'],
			array_merge(
				$this->event_source_row( $event, $run_id ),
				array(
					'event_id'    => $event_id,
					'is_verified' => 0,
					'is_primary'  => 1,
					'created_at'  => $now,
					'updated_at'  => null,
				)
			)
		);
	}

	private function touch_event_source( int $source_id, array $event, int $run_id ): void {
		global $wpdb;

		$row = $this->event_source_row( $event, $run_id );
		$row['updated_at'] = DateTime::mysql_now();

		$wpdb->update( $this->tables['event_sources'], $row, array( 'id' => $source_id ), null, array( '%d' ) );
	}

	private function event_source_row( array $event, int $run_id ): array {
		$event_url = esc_url_raw( (string) ( $event['event_url'] ?? '' ) );
		$promo_url = esc_url_raw( (string) ( $event['promotion_url'] ?? '' ) );
		$payload   = $event;
		unset( $payload['bouts'] );

		return array(
			'source_type'                 => self::SOURCE,
			'source_event_id'             => (string) ( $event['source_event_id'] ?? '' ),
			'source_event_numeric_id'     => (string) ( $event['source_event_numeric_id'] ?? '' ),
			'source_promotion_id'         => isset( $event['source_promotion_id'] ) ? (string) $event['source_promotion_id'] : null,
			'source_promotion_numeric_id' => isset( $event['source_promotion_numeric_id'] ) ? (string) $event['source_promotion_numeric_id'] : null,
			'source_promotion_url'        => '' !== $promo_url ? $promo_url : null,
			'source_url'                  => '' !== $event_url ? $event_url : null,
			'source_slug'                 => $this->slug_from_url( $event_url ),
			'identity_hash'               => (string) ( $event['identity_hash'] ?? '' ),
			'content_hash'                => (string) ( $event['content_hash'] ?? '' ),
			'raw_payload'                 => $this->source_raw_payload( $payload ),
			'last_import_run_id'          => $run_id,
		);
	}

	private function insert_bout_source( int $bout_id, array $bout, int $event_id, int $run_id ): void {
		global $wpdb;

		$now = DateTime::mysql_now();
		$wpdb->insert(
			$this->tables['bout_sources'],
			array_merge(
				$this->bout_source_row( $bout, $event_id, $run_id ),
				array(
					'bout_id'    => $bout_id,
					'created_at' => $now,
					'updated_at' => null,
				)
			)
		);
	}

	private function touch_bout_source( int $source_id, array $bout, int $event_id, int $run_id ): void {
		global $wpdb;

		$row = $this->bout_source_row( $bout, $event_id, $run_id );
		$row['updated_at'] = DateTime::mysql_now();

		$wpdb->update( $this->tables['bout_sources'], $row, array( 'id' => $source_id ), null, array( '%d' ) );
	}

	private function bout_source_row( array $bout, int $event_id, int $run_id ): array {
		$url = esc_url_raw( (string) ( $bout['bout_url'] ?? '' ) );

		return array(
			'event_source_id'        => $this->bout_sources->find_event_source_id( $event_id, self::SOURCE ),
			'source_type'            => self::SOURCE,
			'source_bout_id'         => (string) ( $bout['source_bout_id'] ?? '' ),
			'source_bout_numeric_id' => (string) ( $bout['source_bout_numeric_id'] ?? '' ),
			'source_url'             => '' !== $url ? $url : null,
			'identity_hash'          => (string) ( $bout['identity_hash'] ?? '' ),
			'content_hash'           => (string) ( $bout['content_hash'] ?? '' ),
			'raw_payload'            => $this->source_raw_payload( $bout ),
			'last_import_run_id'     => $run_id,
		);
	}

	private function source_raw_payload( array $payload ): ?string {
		$json = wp_json_encode( $payload );

		return is_string( $json ) && '' !== $json ? $json : null;
	}

	private function log_item( int $run_id, string $type, ?string $source_id, ?string $identity_hash, ?string $content_hash, ?int $canonical_id, string $status, string $action, array $warnings = array(), ?string $error = null ): void {
		$this->items->insert(
			array(
				'import_run_id' => $run_id,
				'item_type'     => $type,
				'source_id'     => $source_id,
				'identity_hash' => $identity_hash,
				'content_hash'  => $content_hash,
				'canonical_id'  => $canonical_id,
				'status'        => $status,
				'action'        => $action,
				'warnings'      => $warnings,
				'error_message' => $error,
			)
		);
		$this->summary['import_items_logged']++;
	}

	private function collect_refs( array $events ): array {
		$refs = array();

		foreach ( $events as $event ) {
			foreach ( (array) ( $event['bouts'] ?? array() ) as $bout ) {
				foreach ( array( 'fighter_a', 'fighter_b' ) as $role ) {
					if ( empty( $bout[ $role ] ) || ! is_array( $bout[ $role ] ) ) {
						continue;
					}

					$ref = $bout[ $role ];
					$key = $this->fighter_ref_key( $ref );
					if ( ! isset( $refs[ $key ] ) ) {
						$refs[ $key ] = $ref;
					}
				}
			}
		}

		return $refs;
	}

	private function fighter_ref_key( array $ref ): string {
		if ( ! empty( $ref['source_fighter_id'] ) ) {
			return 'source:' . (string) $ref['source_fighter_id'];
		}

		$source_url_hash = TapologyFighterUrl::source_url_hash( (string) ( $ref['url'] ?? '' ) );
		if ( null !== $source_url_hash ) {
			return 'url_hash:' . $source_url_hash;
		}

		return 'name:' . Sanitizer::normalize_name( (string) ( $ref['name'] ?? '' ) );
	}

	private function source_identity_for_log( array $ref ): string {
		if ( ! empty( $ref['source_fighter_id'] ) ) {
			return (string) $ref['source_fighter_id'];
		}

		$source_url_hash = TapologyFighterUrl::source_url_hash( (string) ( $ref['url'] ?? '' ) );
		if ( null !== $source_url_hash ) {
			return 'tapology_url_hash_' . $source_url_hash;
		}

		return '';
	}

	private function index_by( array $items, string $key ): array {
		$indexed = array();
		foreach ( $items as $item ) {
			if ( isset( $item[ $key ] ) && '' !== (string) $item[ $key ] ) {
				$indexed[ (string) $item[ $key ] ] = $item;
			}
		}

		return $indexed;
	}

	private function index_bouts( array $items ): array {
		return $this->index_by( $items, 'source_bout_id' );
	}

	private function initial_import_summary( array $dry_run_summary, int $run_id ): array {
		return array_merge(
			$dry_run_summary,
			array(
				'import_run_id'                 => $run_id,
				'dry_run_only'                  => false,
				'canonical_writes_performed'    => false,
				'transaction_strategy'          => 'Validated upfront, then item-by-item writes with idempotent source mapping checks and per-item logs; severe structural validation fails before writes.',
				'events_created'                => 0,
				'events_updated'                => 0,
				'events_no_change'              => 0,
				'events_needs_review_conflict'  => 0,
				'fighters_created_provisional'  => 0,
				'fighters_created_provisional_with_url_only_identity' => 0,
				'fighters_exact_matched'        => 0,
				'fighters_exact_source_url_hash_matched' => 0,
				'fighters_likely_match_skipped' => 0,
				'fighters_conflicts'            => 0,
				'fighters_ambiguous_source_url_hash' => 0,
				'bouts_created'                 => 0,
				'bouts_updated'                 => 0,
				'bouts_no_change'               => 0,
				'bouts_skipped_non_scoring'     => 0,
				'bouts_needs_review_conflict'   => 0,
				'participants_created_updated'  => 0,
				'provenance_rows_written'       => 0,
				'audit_rows_written'            => 0,
				'import_items_logged'           => 0,
				'needs_review_count'            => 0,
				'item_failures'                 => 0,
				'stats_rebuilt'                 => false,
				'rankings_recalculated'         => false,
				'rankings_activated'            => false,
			)
		);
	}

	private function has_completion_warnings(): bool {
		return (int) ( $this->summary['warnings_count'] ?? 0 ) > 0
			|| (int) ( $this->summary['conflicts_count'] ?? 0 ) > 0
			|| (int) ( $this->summary['needs_review_count'] ?? 0 ) > 0
			|| (int) ( $this->summary['item_failures'] ?? 0 ) > 0
			|| (int) ( $this->summary['bouts_skipped_non_scoring'] ?? 0 ) > 0;
	}

	private function result_type( string $value ): string {
		$value = sanitize_key( $value );

		return in_array( $value, array( 'win_loss', 'draw', 'no_contest', 'cancelled', 'unknown' ), true ) ? $value : 'unknown';
	}

	private function method_category( string $value ): string {
		$value = sanitize_key( $value );

		return in_array( $value, array( 'unknown', 'ko_tko', 'submission', 'decision', 'dq', 'no_contest', 'draw' ), true ) ? $value : 'unknown';
	}

	private function card_position( string $value ): string {
		$value = sanitize_key( $value );

		return in_array( $value, array( 'unknown', 'main_event', 'co_main_event', 'main_card', 'prelim', 'postlim' ), true ) ? $value : 'unknown';
	}

	private function weight_class( string $value, array &$warnings ): string {
		$normalized = strtolower( trim( $value ) );
		$normalized = str_replace( array( ' ', '-' ), '_', $normalized );
		$map = array(
			'flyweight'            => 'flyweight',
			'bantamweight'         => 'bantamweight',
			'featherweight'        => 'featherweight',
			'lightweight'          => 'lightweight',
			'welterweight'         => 'welterweight',
			'middleweight'         => 'middleweight',
			'light_heavyweight'    => 'light_heavyweight',
			'heavyweight'          => 'heavyweight',
			'strawweight'         => 'women_strawweight',
			'women_strawweight'    => 'women_strawweight',
			'women_flyweight'      => 'women_flyweight',
			'women_bantamweight'   => 'women_bantamweight',
			'women_featherweight'  => 'women_featherweight',
			'womens_strawweight'   => 'women_strawweight',
			'womens_flyweight'     => 'women_flyweight',
			'womens_bantamweight'  => 'women_bantamweight',
			'womens_featherweight' => 'women_featherweight',
		);

		if ( isset( $map[ $normalized ] ) ) {
			return $map[ $normalized ];
		}

		if ( '' !== trim( $value ) ) {
			$warnings[] = 'unknown_weight_class:' . $value;
			$this->summary['warnings_count']++;
		}

		return 'unknown';
	}

	private function nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return is_numeric( $value ) ? (int) $value : null;
	}

	private function slug_from_url( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = basename( $path );
		if ( preg_match( '/^\d+-(.+)$/', $base, $matches ) ) {
			return sanitize_title( $matches[1] );
		}

		return sanitize_title( $base );
	}

	private function same_value( $left, $right ): bool {
		if ( null === $left || '' === $left ) {
			$left = null;
		}

		if ( null === $right || '' === $right ) {
			$right = null;
		}

		return (string) $left === (string) $right;
	}
}
