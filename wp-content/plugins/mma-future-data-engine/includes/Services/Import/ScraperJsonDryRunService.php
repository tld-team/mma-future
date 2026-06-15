<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Repositories\SourceImportRunRepository;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScraperJsonDryRunService {
	private ScraperJsonValidator $validator;
	private EventImportPreviewService $events;
	private FighterIdentityPreviewService $fighters;
	private BoutImportPreviewService $bouts;
	private SourceImportRunRepository $runs;

	public function __construct() {
		$this->validator = new ScraperJsonValidator();
		$this->events    = new EventImportPreviewService();
		$this->fighters  = new FighterIdentityPreviewService();
		$this->bouts     = new BoutImportPreviewService();
		$this->runs      = new SourceImportRunRepository();
	}

	public function analyze_json_string( string $json, int $created_by = 0, bool $persist_run = true ): array {
		$started_at   = DateTime::mysql_now();
		$payload_hash = hash( 'sha256', $json );
		$validation   = $this->validator->validate_string( $json );
		$data         = is_array( $validation['data'] ?? null ) ? $validation['data'] : array();
		$source       = (string) ( $data['source'] ?? 'tapology' );
		$schema       = (string) ( $data['schema_version'] ?? '' );
		$run_id       = (string) ( $data['run']['run_id'] ?? '' );

		if ( ! $validation['is_valid'] ) {
			$summary = $this->empty_summary( $schema, $source, $run_id, $payload_hash );
			$summary['validation_errors']       = (array) $validation['errors'];
			$summary['validation_errors_count'] = count( $summary['validation_errors'] );
			$summary['warnings']                = (array) $validation['warnings'];
			$summary['warnings_count']          = count( $summary['warnings'] );
			$summary['unsupported_fields']      = (array) $validation['unsupported_fields'];
			$summary['unsupported_fields_count'] = count( $summary['unsupported_fields'] );

			$run_db_id = $persist_run ? $this->persist_run( $summary, 'failed', $created_by, $started_at, 'Schema validation failed.' ) : 0;
			$summary['import_run_id'] = $run_db_id;

			return array(
				'is_valid' => false,
				'summary'  => $summary,
				'events'   => array(),
				'fighters' => array(),
				'bouts'    => array(),
			);
		}

		$warnings  = (array) $validation['warnings'];
		$conflicts = array();
		$events    = (array) ( $data['events'] ?? array() );

		$event_preview   = $this->events->preview_events( $events, $warnings, $conflicts );
		$fighter_preview = $this->fighters->preview_fighters( $events, $warnings, $conflicts );
		$bout_preview    = $this->bouts->preview_bouts( $events, $event_preview['by_source_event_id'], $fighter_preview['by_ref_key'], $warnings, $conflicts );

		$summary = $this->build_summary(
			$data,
			$payload_hash,
			$validation,
			$warnings,
			$conflicts,
			$event_preview,
			$fighter_preview,
			$bout_preview
		);

		$summary['import_run_id'] = $persist_run ? $this->persist_run( $summary, 'dry_run_completed', $created_by, $started_at, null ) : 0;

		return array(
			'is_valid' => true,
			'summary'  => $summary,
			'events'   => $event_preview['items'],
			'fighters' => $fighter_preview['items'],
			'bouts'    => $bout_preview['items'],
		);
	}

	public function analyze_file( string $path, int $created_by = 0, bool $persist_run = true ): array {
		$content = file_get_contents( $path );

		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read JSON file: ' . $path );
		}

		return $this->analyze_json_string( $content, $created_by, $persist_run );
	}

	private function build_summary( array $data, string $payload_hash, array $validation, array $warnings, array $conflicts, array $event_preview, array $fighter_preview, array $bout_preview ): array {
		$events_total       = count( (array) ( $data['events'] ?? array() ) );
		$bouts_total        = 0;
		$source_event_ids   = array();
		$source_bout_ids    = array();
		$source_fighter_ids = array();
		$source_fighter_url_hashes = array();

		foreach ( (array) ( $data['events'] ?? array() ) as $event ) {
			if ( ! empty( $event['source_event_id'] ) ) {
				$source_event_ids[] = (string) $event['source_event_id'];
			}

			foreach ( (array) ( $event['bouts'] ?? array() ) as $bout ) {
				++$bouts_total;

				if ( ! empty( $bout['source_bout_id'] ) ) {
					$source_bout_ids[] = (string) $bout['source_bout_id'];
				}

				foreach ( array( 'fighter_a', 'fighter_b' ) as $role ) {
					if ( ! empty( $bout[ $role ]['source_fighter_id'] ) ) {
						$source_fighter_ids[] = (string) $bout[ $role ]['source_fighter_id'];
					}
					$source_url_hash = \MMAF\DataEngine\Support\TapologyFighterUrl::source_url_hash( (string) ( $bout[ $role ]['url'] ?? '' ) );
					if ( null !== $source_url_hash ) {
						$source_fighter_url_hashes[] = $source_url_hash;
					}
				}
			}
		}

		$existing_payload_runs = $this->runs->count_by_payload_hash( $payload_hash );

		return array(
			'schema_version'               => (string) ( $data['schema_version'] ?? '' ),
			'source'                       => (string) ( $data['source'] ?? '' ),
			'source_url'                   => (string) ( $data['source_url'] ?? '' ),
			'scraped_at'                   => (string) ( $data['scraped_at'] ?? '' ),
			'source_run_id'                => (string) ( $data['run']['run_id'] ?? '' ),
			'payload_hash'                 => $payload_hash,
			'events_total'                 => $events_total,
			'bouts_total'                  => $bouts_total,
			'fighter_refs_total'           => (int) ( $fighter_preview['total_refs'] ?? 0 ),
			'unique_fighter_refs'          => (int) ( $fighter_preview['unique_refs'] ?? 0 ),
			'unique_source_event_ids'      => count( array_unique( $source_event_ids ) ),
			'unique_source_bout_ids'       => count( array_unique( $source_bout_ids ) ),
			'unique_source_fighter_ids'    => count( array_unique( $source_fighter_ids ) ),
			'unique_source_fighter_url_hashes' => count( array_unique( $source_fighter_url_hashes ) ),
			'validation_errors'            => (array) $validation['errors'],
			'validation_errors_count'      => count( (array) $validation['errors'] ),
			'warnings'                     => array_values( array_unique( $warnings ) ),
			'warnings_count'               => count( array_values( array_unique( $warnings ) ) ),
			'conflicts'                    => array_values( array_unique( $conflicts ) ),
			'conflicts_count'              => count( array_values( array_unique( $conflicts ) ) ),
			'unsupported_fields'           => (array) $validation['unsupported_fields'],
			'unsupported_fields_count'     => count( (array) $validation['unsupported_fields'] ),
			'event_actions'                => (array) $event_preview['actions'],
			'fighter_actions'              => (array) $fighter_preview['actions'],
			'bout_actions'                 => (array) $bout_preview['actions'],
			'non_scoring_bouts'            => (int) ( $bout_preview['non_scoring'] ?? 0 ),
			'idempotency_impact'           => array(
				'payload_hash_seen_before' => $existing_payload_runs > 0,
				'previous_dry_run_count'   => $existing_payload_runs,
				'source_event_ids_total'   => count( $source_event_ids ),
				'source_bout_ids_total'    => count( $source_bout_ids ),
			),
			'dry_run_only'                 => true,
			'canonical_writes_performed'   => false,
		);
	}

	private function empty_summary( string $schema, string $source, string $run_id, string $payload_hash ): array {
		return array(
			'schema_version'             => $schema,
			'source'                     => $source,
			'source_run_id'              => $run_id,
			'payload_hash'               => $payload_hash,
			'events_total'               => 0,
			'bouts_total'                => 0,
			'fighter_refs_total'         => 0,
			'unique_fighter_refs'        => 0,
			'validation_errors'          => array(),
			'validation_errors_count'    => 0,
			'warnings'                   => array(),
			'warnings_count'             => 0,
			'conflicts'                  => array(),
			'conflicts_count'            => 0,
			'unsupported_fields'         => array(),
			'unsupported_fields_count'   => 0,
			'event_actions'              => array(),
			'fighter_actions'            => array(),
			'bout_actions'               => array(),
			'non_scoring_bouts'          => 0,
			'idempotency_impact'         => array(
				'payload_hash_seen_before' => false,
				'previous_dry_run_count'   => 0,
			),
			'dry_run_only'               => true,
			'canonical_writes_performed' => false,
		);
	}

	private function persist_run( array $summary, string $status, int $created_by, string $started_at, ?string $error_message ): int {
		return $this->runs->insert_dry_run(
			array(
				'source_type'           => (string) ( $summary['source'] ?? 'tapology' ),
				'source_schema_version' => (string) ( $summary['schema_version'] ?? '' ),
				'source_run_id'         => (string) ( $summary['source_run_id'] ?? '' ),
				'payload_hash'          => (string) ( $summary['payload_hash'] ?? '' ),
				'status'                => $status,
				'started_at'            => $started_at,
				'finished_at'           => DateTime::mysql_now(),
				'summary'               => $summary,
				'error_message'         => $error_message,
				'created_by'            => $created_by,
			)
		);
	}
}
