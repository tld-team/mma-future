<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScraperLatestBundleService {
	private const REQUIRED_FILES = array(
		'results'                 => 'results.json',
		'daily_summary'           => 'daily_summary.json',
		'manual_review'           => 'manual_review.json',
		'run_manifest'            => 'run_manifest.json',
		'changes'                 => 'changes.json',
		'fighter_profiles'        => 'fighter_profiles.json',
		'fighter_profiles_report' => 'fighter_profiles_report.json',
	);

	private const OPTIONAL_FILES = array(
		'state_report' => 'state_report.json',
	);

	public static function default_latest_dir(): string {
		return dirname( MMAF_PLUGIN_DIR, 5 ) . DIRECTORY_SEPARATOR . 'scraper' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'latest';
	}

	public function analyze_bundle( string $bundle_dir, int $created_by = 0, bool $persist_results_dry_run = false ): array {
		$bundle = $this->load_bundle( $bundle_dir );
		$results_dry_run = ( new ScraperJsonDryRunService() )->analyze_file( $bundle['paths']['results'], $created_by, $persist_results_dry_run );
		$profile_preview = $this->preview_profiles( $bundle, $results_dry_run );
		$summary = $this->build_bundle_summary( $bundle, $results_dry_run, $profile_preview );

		return array(
			'is_valid'           => empty( $summary['bundle_errors'] ) && ! empty( $results_dry_run['is_valid'] ),
			'ready_for_import'   => (bool) ( $summary['ready_for_import'] ?? false ),
			'summary'            => $summary,
			'results_dry_run'    => $results_dry_run,
			'profile_enrichment' => $profile_preview,
			'bundle'             => $bundle,
		);
	}

	public function import_bundle( string $bundle_dir, int $created_by = 0, bool $allow_not_ready = false, string $not_ready_reason = '' ): array {
		$analysis = $this->analyze_bundle( $bundle_dir, $created_by, false );
		$summary = (array) $analysis['summary'];

		if ( empty( $analysis['is_valid'] ) ) {
			throw new \RuntimeException( 'Latest bundle is invalid: ' . implode( '; ', (array) ( $summary['bundle_errors'] ?? array() ) ) );
		}

		if ( empty( $analysis['ready_for_import'] ) && ! $allow_not_ready ) {
			throw new \RuntimeException( 'Latest bundle is not ready for automatic import: ' . implode( '; ', (array) ( $summary['blocking_issues'] ?? array() ) ) );
		}

		$not_ready_reason = trim( $not_ready_reason );
		if ( empty( $analysis['ready_for_import'] ) && $allow_not_ready && '' === $not_ready_reason ) {
			throw new \RuntimeException( 'A manual-review import reason is required when importing a not-ready latest bundle.' );
		}

		$bundle = (array) $analysis['bundle'];
		$result = ( new ScraperJsonImportService() )->import_file( (string) $bundle['paths']['results'], $created_by );
		$result_summary = (array) ( $result['summary'] ?? array() );
		$import_run_id = (int) ( $result_summary['import_run_id'] ?? 0 );

		$result['bundle_summary'] = $summary;
		$result['summary']['latest_bundle'] = $summary;
		$result['summary']['latest_bundle_ready_for_import'] = (bool) ( $summary['ready_for_import'] ?? false );
		$result['summary']['latest_bundle_manual_review_count'] = (int) ( $summary['manual_review_count'] ?? 0 );
		$result['summary']['latest_bundle_blocking_issues'] = (array) ( $summary['blocking_issues'] ?? array() );
		$result['summary']['latest_bundle_not_ready_override'] = empty( $analysis['ready_for_import'] ) && $allow_not_ready;
		$result['summary']['latest_bundle_not_ready_override_reason'] = $result['summary']['latest_bundle_not_ready_override'] ? $not_ready_reason : '';

		$profile_apply = $this->apply_profiles( $bundle, $created_by );
		$result['profile_enrichment'] = $profile_apply;
		$result['summary']['fighter_profile_enrichment_status'] = (string) ( $profile_apply['summary']['status'] ?? 'not_run' );
		$result['summary']['fighter_profile_enrichment_profiles_applied'] = (int) ( $profile_apply['summary']['profiles_applied'] ?? 0 );
		$result['summary']['fighter_profile_enrichment_fields_applied'] = (int) ( $profile_apply['summary']['fields_applied_total'] ?? 0 );

		$review = $this->upsert_manual_review_items( $bundle, $import_run_id, $created_by );
		$result['manual_review_import'] = $review;
		$result['summary']['manual_review_items_upserted'] = (int) ( $review['items_upserted'] ?? 0 );

		$this->update_import_run_bundle_summary( $import_run_id, (array) $result['summary'] );
		$this->write_not_ready_override_audit( $import_run_id, $summary, $not_ready_reason, $created_by );

		return $result;
	}

	public function load_bundle( string $bundle_dir ): array {
		$dir = $this->normalize_bundle_dir( $bundle_dir );
		$paths = array();
		$data = array();
		$errors = array();
		$file_hashes = array();
		$file_sizes = array();

		foreach ( array_merge( self::REQUIRED_FILES, self::OPTIONAL_FILES ) as $key => $file_name ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file_name;
			if ( ! is_file( $path ) ) {
				if ( in_array( $key, array_keys( self::REQUIRED_FILES ), true ) ) {
					$errors[] = 'Missing required latest bundle file: ' . $file_name;
				}
				continue;
			}

			$paths[ $key ] = $path;
			$file_sizes[ $key ] = (int) filesize( $path );
			$file_hashes[ $key ] = hash_file( 'sha256', $path );
			$content = file_get_contents( $path );
			if ( false === $content ) {
				$errors[] = 'Could not read latest bundle file: ' . $file_name;
				continue;
			}

			$decoded = json_decode( $content, true, 512, JSON_BIGINT_AS_STRING );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				$errors[] = 'Invalid JSON in latest bundle file ' . $file_name . ': ' . json_last_error_msg();
				continue;
			}

			$data[ $key ] = $decoded;
		}

		return array(
			'dir'         => $dir,
			'paths'       => $paths,
			'data'        => $data,
			'errors'      => $errors,
			'file_hashes' => $file_hashes,
			'file_sizes'  => $file_sizes,
		);
	}

	private function normalize_bundle_dir( string $bundle_dir ): string {
		$bundle_dir = trim( $bundle_dir );
		if ( '' === $bundle_dir ) {
			$bundle_dir = self::default_latest_dir();
		}

		$real = realpath( $bundle_dir );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new \RuntimeException( 'Latest bundle directory does not exist: ' . $bundle_dir );
		}

		return $real;
	}

	private function preview_profiles( array $bundle, array $results_dry_run ): array {
		if ( empty( $bundle['paths']['fighter_profiles'] ) ) {
			return array(
				'summary' => array(
					'status' => 'missing',
					'profiles_total' => 0,
				),
				'rows' => array(),
				'total' => 0,
			);
		}

		$profile_path = (string) $bundle['paths']['fighter_profiles'];
		$profile_size = isset( $bundle['file_sizes']['fighter_profiles'] ) ? (int) $bundle['file_sizes']['fighter_profiles'] : 0;
		if ( $profile_size > FighterProfileEnrichmentPreviewService::max_file_size() ) {
			return $this->oversized_profile_preview_summary( $bundle, $profile_path, $profile_size );
		}

		$content = file_get_contents( $profile_path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read fighter_profiles.json from latest bundle.' );
		}

		return ( new FighterProfileEnrichmentPreviewService() )->analyze_json_string(
			$content,
			$profile_path,
			array(),
			'',
			25,
			0,
			$results_dry_run
		);
	}

	private function oversized_profile_preview_summary( array $bundle, string $profile_path, int $profile_size ): array {
		$report = (array) ( $bundle['data']['fighter_profiles_report'] ?? array() );
		$summary = array(
			'status' => 'skipped_large_file',
			'enrichment_file' => $profile_path,
			'profiles_total' => (int) ( $report['profiles_total'] ?? 0 ),
			'profiles_success' => (int) ( $report['profiles_success'] ?? 0 ),
			'profiles_failed' => (int) ( $report['profiles_failed'] ?? 0 ),
			'error' => sprintf(
				'fighter_profiles.json is %d bytes, above the %d-byte preview limit. Detailed profile rows were skipped during latest bundle dry-run.',
				$profile_size,
				FighterProfileEnrichmentPreviewService::max_file_size()
			),
		);

		if ( ! empty( $report ) ) {
			$summary['schema_version'] = (string) ( $report['schema_version'] ?? '' );
			$summary['source'] = (string) ( $report['source'] ?? '' );
			$summary['run_id'] = (string) ( $report['run_id'] ?? '' );
		}

		return array(
			'summary' => $summary,
			'rows' => array(),
			'total' => 0,
		);
	}

	private function apply_profiles( array $bundle, int $created_by ): array {
		if ( empty( $bundle['paths']['fighter_profiles'] ) ) {
			return array(
				'summary' => array(
					'status' => 'missing',
					'profiles_total' => 0,
				),
				'rows' => array(),
			);
		}

		$content = file_get_contents( (string) $bundle['paths']['fighter_profiles'] );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read fighter_profiles.json from latest bundle.' );
		}

		return ( new FighterProfileEnrichmentApplyService() )->apply_all_safe_json_string(
			$content,
			(string) $bundle['paths']['fighter_profiles'],
			$created_by
		);
	}

	private function build_bundle_summary( array $bundle, array $results_dry_run, array $profile_preview ): array {
		$data = (array) $bundle['data'];
		$daily = (array) ( $data['daily_summary'] ?? array() );
		$manifest = (array) ( $data['run_manifest'] ?? array() );
		$manual_review = (array) ( $data['manual_review'] ?? array() );
		$changes = (array) ( $data['changes'] ?? array() );
		$results_summary = (array) ( $results_dry_run['summary'] ?? array() );
		$profile_summary = (array) ( $profile_preview['summary'] ?? array() );

		$bundle_errors = (array) ( $bundle['errors'] ?? array() );
		if ( empty( $results_dry_run['is_valid'] ) ) {
			$bundle_errors[] = 'results.json dry-run validation failed.';
		}

		$source_run_id = (string) ( $results_summary['source_run_id'] ?? '' );
		$manifest_run_id = (string) ( $manifest['run_id'] ?? '' );
		if ( '' !== $source_run_id && '' !== $manifest_run_id && $source_run_id !== $manifest_run_id ) {
			$bundle_errors[] = 'results.json run_id does not match run_manifest.json run_id.';
		}

		$daily_events_total = isset( $daily['events_total'] ) ? (int) $daily['events_total'] : null;
		$daily_bouts_total = isset( $daily['bouts_total'] ) ? (int) $daily['bouts_total'] : null;
		if ( null !== $daily_events_total && $daily_events_total !== (int) ( $results_summary['events_total'] ?? 0 ) ) {
			$bundle_errors[] = 'daily_summary events_total does not match results.json dry-run count.';
		}
		if ( null !== $daily_bouts_total && $daily_bouts_total !== (int) ( $results_summary['bouts_total'] ?? 0 ) ) {
			$bundle_errors[] = 'daily_summary bouts_total does not match results.json dry-run count.';
		}

		$blocking_issues = array_values( array_unique( array_map( 'strval', (array) ( $daily['blocking_issues'] ?? array() ) ) ) );
		$ready_for_import = empty( $bundle_errors ) && ! empty( $daily['ready_for_import'] );

		return array(
			'bundle_dir'               => (string) ( $bundle['dir'] ?? '' ),
			'bundle_hash'              => $this->bundle_hash( (array) ( $bundle['file_hashes'] ?? array() ) ),
			'file_hashes'              => (array) ( $bundle['file_hashes'] ?? array() ),
			'file_sizes'               => (array) ( $bundle['file_sizes'] ?? array() ),
			'bundle_errors'            => $bundle_errors,
			'bundle_errors_count'      => count( $bundle_errors ),
			'ready_for_import'         => $ready_for_import,
			'daily_ready_for_import'   => ! empty( $daily['ready_for_import'] ),
			'blocking_issues'          => $blocking_issues,
			'blocking_issues_count'    => count( $blocking_issues ),
			'event_run_status'         => (string) ( $daily['event_run_status'] ?? ( $manifest['status'] ?? '' ) ),
			'profile_run_status'       => (string) ( $daily['profile_run_status'] ?? '' ),
			'source_run_id'            => $source_run_id,
			'manifest_run_id'          => $manifest_run_id,
			'events_total'             => (int) ( $results_summary['events_total'] ?? 0 ),
			'bouts_total'              => (int) ( $results_summary['bouts_total'] ?? 0 ),
			'profiles_total'           => (int) ( $daily['profiles_total'] ?? ( $profile_summary['profiles_total'] ?? 0 ) ),
			'profiles_success'         => (int) ( $daily['profiles_success'] ?? 0 ),
			'manual_review_count'      => (int) ( $daily['manual_review_count'] ?? count( (array) ( $manual_review['items'] ?? array() ) ) ),
			'manual_review_schema'     => (string) ( $manual_review['schema_version'] ?? '' ),
			'manual_review_items'      => count( (array) ( $manual_review['items'] ?? array() ) ),
			'changes'                  => $this->changes_summary( $changes ),
			'results_payload_hash'     => (string) ( $results_summary['payload_hash'] ?? '' ),
			'results_validation_errors_count' => (int) ( $results_summary['validation_errors_count'] ?? 0 ),
			'results_conflicts_count'  => (int) ( $results_summary['conflicts_count'] ?? 0 ),
			'results_warnings_count'   => (int) ( $results_summary['warnings_count'] ?? 0 ),
			'results_unsupported_fields_count' => (int) ( $results_summary['unsupported_fields_count'] ?? 0 ),
			'profile_enrichment_preview' => $profile_summary,
			'profile_enrichment_match_counts' => $this->profile_match_counts( $profile_summary ),
		);
	}

	private function profile_match_counts( array $profile_summary ): array {
		$existing = (int) ( $profile_summary['profiles_matched_existing_canonical'] ?? 0 );
		$planned = (int) ( $profile_summary['profiles_matched_planned_import'] ?? 0 );
		$ambiguous = (int) ( $profile_summary['profiles_ambiguous'] ?? 0 );
		$unmatched = (int) ( $profile_summary['profiles_unmatched'] ?? 0 );

		return array(
			'existing_canonical' => $existing,
			'planned_import'     => $planned,
			'total_resolvable'   => $existing + $planned,
			'unmatched'          => $unmatched,
			'ambiguous'          => $ambiguous,
		);
	}

	private function changes_summary( array $changes ): array {
		$out = array();
		foreach ( array( 'new_events', 'updated_events', 'unchanged_events', 'new_bouts', 'updated_bouts', 'new_fighter_urls', 'fighters_to_refresh', 'manual_review_events' ) as $key ) {
			$value = $changes[ $key ] ?? null;
			$out[ $key ] = is_array( $value ) ? count( $value ) : ( null === $value ? 0 : $value );
		}

		return $out;
	}

	private function bundle_hash( array $file_hashes ): string {
		ksort( $file_hashes );

		return hash( 'sha256', wp_json_encode( $file_hashes ) ?: '' );
	}

	private function upsert_manual_review_items( array $bundle, int $import_run_id, int $created_by ): array {
		global $wpdb;

		$items = (array) ( $bundle['data']['manual_review']['items'] ?? array() );
		if ( empty( $items ) ) {
			return array(
				'items_total' => 0,
				'items_upserted' => 0,
			);
		}

		$tables = Schema::table_names();
		$now = DateTime::mysql_now();
		$upserted = 0;
		$run_id = (string) ( $bundle['data']['manual_review']['run_id'] ?? '' );

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $item['type'] ?? 'manual_review' ) );
			$entity = sanitize_key( (string) ( $item['entity'] ?? 'scraper' ) );
			$context = is_array( $item['context'] ?? null ) ? (array) $item['context'] : array();
			$source_id = $this->source_id_from_review_context( $context );
			$item_key = $this->manual_review_item_key( $run_id, $type, $source_id, (int) $index );
			$notes = $this->manual_review_notes( $item, $import_run_id );

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tables['review_items']} WHERE item_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_key
				)
			);

			$row = array(
				'item_type'     => 'scraper_' . $entity,
				'item_key'      => $item_key,
				'source_type'   => 'tapology',
				'source_id'     => '' !== $source_id ? $source_id : null,
				'canonical_id'  => null,
				'related_canonical_id' => null,
				'status'        => 'open',
				'action_taken'  => $type,
				'notes'         => $notes,
				'updated_at'    => $now,
			);

			if ( $existing_id > 0 ) {
				$wpdb->update( $tables['review_items'], $row, array( 'id' => $existing_id ), null, array( '%d' ) );
			} else {
				$row['created_by'] = $created_by > 0 ? $created_by : null;
				$row['created_at'] = $now;
				$wpdb->insert( $tables['review_items'], $row );
			}
			++$upserted;
		}

		return array(
			'items_total' => count( $items ),
			'items_upserted' => $upserted,
		);
	}

	private function source_id_from_review_context( array $context ): string {
		foreach ( array( 'source_event_id', 'source_bout_id', 'source_fighter_id', 'event_url', 'bout_url', 'source_url' ) as $key ) {
			if ( ! empty( $context[ $key ] ) ) {
				return (string) $context[ $key ];
			}
		}

		return '';
	}

	private function manual_review_item_key( string $run_id, string $type, string $source_id, int $index ): string {
		$raw = implode( '|', array( '' !== $run_id ? $run_id : 'unknown_run', $type, '' !== $source_id ? $source_id : (string) $index ) );

		return 'scraper_' . hash( 'sha256', $raw );
	}

	private function manual_review_notes( array $item, int $import_run_id ): string {
		$payload = array(
			'import_run_id' => $import_run_id,
			'severity'      => (string) ( $item['severity'] ?? '' ),
			'context'       => (array) ( $item['context'] ?? array() ),
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : substr( $json, 0, 60000 );
	}

	private function update_import_run_bundle_summary( int $import_run_id, array $summary ): void {
		if ( $import_run_id <= 0 ) {
			return;
		}

		global $wpdb;
		$tables = Schema::table_names();
		$wpdb->update(
			$tables['source_import_runs'],
			array(
				'summary_json' => wp_json_encode( $summary ),
				'updated_at'   => DateTime::mysql_now(),
			),
			array( 'id' => $import_run_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function write_not_ready_override_audit( int $import_run_id, array $summary, string $reason, int $created_by ): void {
		if ( $import_run_id <= 0 || '' === $reason || ! empty( $summary['ready_for_import'] ) ) {
			return;
		}

		( new AuditLogService() )->write(
			'latest_bundle_not_ready_import_override',
			'source_import_run',
			$import_run_id,
			null,
			array(
				'bundle_hash' => (string) ( $summary['bundle_hash'] ?? '' ),
				'blocking_issues' => (array) ( $summary['blocking_issues'] ?? array() ),
				'ready_for_import' => (bool) ( $summary['ready_for_import'] ?? false ),
			),
			$reason,
			$created_by
		);
	}
}
