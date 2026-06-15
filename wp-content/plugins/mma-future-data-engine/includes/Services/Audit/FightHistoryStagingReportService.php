<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FightHistoryStagingReportService {
	public const FILTERS = array(
		'high_confidence',
		'medium_confidence',
		'low_confidence',
		'already_canonical',
		'candidate_new_historical_bout',
		'possible_weak_duplicate',
		'missing_opponent_prefight_record',
		'non_mma_filtered',
		'cancelled_amateur_overturned',
		'needs_bout_detail_fetch',
		'stage_for_review',
	);

	private const SCHEMA_VERSION = 'fight_history_staging_dry_run_v0_1';
	private const MAX_FILE_SIZE = 26214400;

	private array $tables;
	private array $source_id_map = array();
	private array $normalized_url_map = array();
	private array $exact_url_map = array();
	private array $fighters = array();

	public function __construct() {
		$this->tables = Schema::table_names();
	}

	public static function default_path(): string {
		return self::workspace_root() . DIRECTORY_SEPARATOR . 'scraper' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'latest' . DIRECTORY_SEPARATOR . 'fight_history_staging_dry_run.json';
	}

	public function build_report( string $path = '', array $filters = array(), string $search = '', int $limit = 50, int $offset = 0 ): array {
		$path = '' === trim( $path ) ? self::default_path() : trim( $path );
		$path = self::resolve_safe_json_path( $path );
		$data = $this->decode_file( $path );

		$this->load_database_context();

		$rows = array();
		foreach ( (array) $data['rows'] as $index => $entry ) {
			if ( is_array( $entry ) ) {
				$rows[] = $this->build_row( $entry, (int) $index );
			}
		}

		$filtered = $this->filter_rows( $rows, $filters, $search );
		$total = count( $filtered );
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		return array(
			'summary' => $this->summary( $data, $path, count( $rows ) ),
			'rows' => array_slice( $filtered, $offset, $limit ),
			'total' => $total,
			'all_rows_count' => count( $rows ),
			'path' => $path,
		);
	}

	public static function resolve_safe_json_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			throw new \RuntimeException( 'Choose a fight-history staging JSON file.' );
		}

		if ( 'json' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			throw new \RuntimeException( 'Only .json files are accepted for fight-history staging review.' );
		}

		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			throw new \RuntimeException( 'Fight-history staging JSON path does not exist or is not a file.' );
		}

		$normalized = trailingslashit( wp_normalize_path( $real_path ) );
		$allowed = false;
		foreach ( self::allowed_roots() as $root ) {
			if ( 0 === strpos( $normalized, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			throw new \RuntimeException( 'Fight-history staging JSON path is outside the allowed scraper/data/latest or scraper/data/runs directories.' );
		}

		$size = filesize( $real_path );
		if ( false === $size || $size <= 0 ) {
			throw new \RuntimeException( 'Fight-history staging JSON file is empty or cannot be sized.' );
		}
		if ( $size > self::MAX_FILE_SIZE ) {
			throw new \RuntimeException( 'Fight-history staging JSON file exceeds the 25 MB preview limit.' );
		}

		return $real_path;
	}

	private function decode_file( string $path ): array {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read fight-history staging JSON.' );
		}

		$data = json_decode( $content, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid fight-history staging JSON.' );
		}

		if ( self::SCHEMA_VERSION !== (string) ( $data['schema_version'] ?? '' ) ) {
			throw new \RuntimeException( 'Unsupported fight-history staging schema version.' );
		}

		if ( ! isset( $data['summary'] ) || ! is_array( $data['summary'] ) ) {
			throw new \RuntimeException( 'Fight-history staging JSON is missing summary counts.' );
		}
		if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			throw new \RuntimeException( 'Fight-history staging JSON is missing rows.' );
		}
		if ( true !== (bool) ( $data['non_writable_phase'] ?? false ) || false !== (bool) ( $data['writes_db'] ?? true ) ) {
			throw new \RuntimeException( 'Fight-history staging JSON is not marked as a read-only dry-run report.' );
		}

		return $data;
	}

	private function summary( array $data, string $path, int $row_count ): array {
		$summary = (array) $data['summary'];

		return array(
			'input_file' => $path,
			'schema_version' => (string) $data['schema_version'],
			'generated_at' => (string) ( $data['generated_at'] ?? '' ),
			'writes_db' => ! empty( $data['writes_db'] ) ? 'yes' : 'no',
			'report_rows_loaded' => $row_count,
			'profile_history_rows_total' => (int) ( $summary['profile_history_rows_total'] ?? 0 ),
			'rows_mma_candidate' => (int) ( $summary['rows_mma_candidate'] ?? 0 ),
			'rows_non_mma_filtered' => (int) ( $summary['rows_non_mma_filtered'] ?? 0 ),
			'rows_amateur_cancelled_overturned' => (int) ( $summary['rows_amateur_cancelled_overturned'] ?? 0 ),
			'staging_high' => (int) ( $summary['staging_high_confidence'] ?? 0 ),
			'staging_medium' => (int) ( $summary['staging_medium_confidence'] ?? 0 ),
			'staging_low' => (int) ( $summary['staging_low_confidence'] ?? 0 ),
			'not_staging_candidate' => (int) ( $summary['not_staging_candidate'] ?? 0 ),
			'already_canonical' => (int) ( $summary['already_canonical'] ?? 0 ),
			'candidate_new_historical_bouts' => (int) ( $summary['candidate_new_historical_bouts'] ?? 0 ),
			'possible_duplicates_weak' => (int) ( $summary['possible_duplicates_weak'] ?? 0 ),
			'rows_with_opponent_prefight_record' => (int) ( $summary['rows_with_opponent_prefight_record'] ?? 0 ),
			'rows_missing_opponent_prefight_record' => (int) ( $summary['rows_missing_opponent_prefight_record'] ?? 0 ),
		);
	}

	private function load_database_context(): void {
		global $wpdb;

		$this->source_id_map = array();
		$this->normalized_url_map = array();
		$this->exact_url_map = array();
		$this->fighters = array();

		$rows = $wpdb->get_results(
			"
			SELECT
				fs.fighter_id,
				fs.source_type,
				fs.source_fighter_id,
				fs.source_url,
				f.display_name,
				f.status,
				f.rankability_status,
				f.is_public,
				f.is_rankable
			FROM {$this->tables['fighter_sources']} fs
			LEFT JOIN {$this->tables['fighters']} f ON f.id = fs.fighter_id
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$fighter_id = (int) ( $row['fighter_id'] ?? 0 );
			if ( $fighter_id > 0 ) {
				$this->fighters[ $fighter_id ] = $row;
			}

			$source_fighter_id = trim( (string) ( $row['source_fighter_id'] ?? '' ) );
			if ( 'tapology' === (string) ( $row['source_type'] ?? '' ) && '' !== $source_fighter_id ) {
				$this->source_id_map[ $source_fighter_id ][] = $row;
			}

			$source_url = trim( (string) ( $row['source_url'] ?? '' ) );
			if ( '' !== $source_url ) {
				$normalized_url = TapologyFighterUrl::normalize( $source_url );
				if ( 'tapology' === (string) ( $row['source_type'] ?? '' ) && '' !== $normalized_url ) {
					$this->normalized_url_map[ $normalized_url ][] = $row;
				}
				$this->exact_url_map[ $source_url ][] = $row;
			}
		}
	}

	private function build_row( array $entry, int $index ): array {
		$profile = (array) ( $entry['profile'] ?? array() );
		$row = (array) ( $entry['row'] ?? array() );
		$classification = (array) ( $entry['classification'] ?? array() );
		$prefight = (array) ( $entry['prefight'] ?? array() );

		$source_fighter_id = trim( (string) ( $profile['source_fighter_id'] ?? '' ) );
		$source_url = trim( (string) ( $profile['source_url'] ?? '' ) );
		$match = $this->match_profile( $source_fighter_id, $source_url );
		$fighter = $match['fighter'];
		$warnings = $this->warnings_for_row( $row, $classification, $prefight, $match['match_type'] );

		return array(
			'row_index' => $index,
			'fighter_profile_name' => (string) ( $profile['display_name'] ?? '' ),
			'profile_source_fighter_id' => $source_fighter_id,
			'profile_source_url' => $source_url,
			'matched_canonical_fighter_id' => $fighter ? (int) $fighter['fighter_id'] : 0,
			'matched_canonical_name' => $fighter ? (string) $fighter['display_name'] : '',
			'match_type' => $match['match_type'],
			'fight_date' => (string) ( $row['fight_date'] ?? '' ),
			'opponent_name' => (string) ( $row['opponent_name'] ?? '' ),
			'result' => (string) ( $row['result'] ?? '' ),
			'method' => (string) ( $row['method'] ?? '' ),
			'round' => (string) ( $row['round'] ?? '' ),
			'time' => (string) ( $row['time'] ?? '' ),
			'event_name' => (string) ( $row['event_name'] ?? '' ),
			'event_url' => (string) ( $row['event_url'] ?? '' ),
			'bout_url' => (string) ( $row['bout_url'] ?? '' ),
			'opponent_url' => (string) ( $row['opponent_url'] ?? '' ),
			'fighter_prefight_record' => (string) ( $row['fighter_prefight_record_raw'] ?? '' ),
			'opponent_prefight_record' => (string) ( $row['opponent_prefight_record_raw'] ?? '' ),
			'sport_type' => (string) ( $classification['sport_type'] ?? '' ),
			'staging_confidence' => (string) ( $classification['staging_confidence'] ?? '' ),
			'canonical_match_status' => (string) ( $classification['canonical_match_status'] ?? '' ),
			'canonical_match_evidence' => (string) ( $classification['canonical_match_evidence'] ?? '' ),
			'recommended_action' => (string) ( $classification['recommended_action'] ?? '' ),
			'needs_bout_detail_fetch' => ! empty( $prefight['needs_bout_detail_fetch'] ),
			'opponent_prefight_present' => ! empty( $prefight['opponent_prefight_present'] ),
			'warnings' => implode( ', ', $warnings ),
		);
	}

	private function warnings_for_row( array $row, array $classification, array $prefight, string $match_type ): array {
		$warnings = array();
		if ( in_array( $match_type, array( 'unmatched', 'ambiguous' ), true ) ) {
			$warnings[] = 'canonical_fighter_' . $match_type;
		}
		if ( empty( $prefight['opponent_prefight_present'] ) ) {
			$warnings[] = 'missing_opponent_prefight_record';
		}
		if ( ! empty( $prefight['needs_bout_detail_fetch'] ) ) {
			$warnings[] = 'needs_bout_detail_fetch';
		}
		if ( 'possible_duplicate' === (string) ( $classification['canonical_match_status'] ?? '' ) ) {
			$warnings[] = 'possible_weak_duplicate';
		}
		if ( '' === (string) ( $row['event_url'] ?? '' ) ) {
			$warnings[] = 'missing_event_url';
		}
		if ( '' === (string) ( $row['bout_url'] ?? '' ) ) {
			$warnings[] = 'missing_bout_url';
		}

		return array_values( array_unique( $warnings ) );
	}

	private function match_profile( string $source_fighter_id, string $source_url ): array {
		if ( 0 === strpos( $source_fighter_id, 'tapology_fighter_' ) ) {
			$match = $this->single_fighter_match( $this->source_id_map[ $source_fighter_id ] ?? array() );
			if ( 'matched' === $match['status'] ) {
				return array( 'match_type' => 'exact_source_match', 'fighter' => $match['fighter'] );
			}
			if ( 'ambiguous' === $match['status'] ) {
				return array( 'match_type' => 'ambiguous', 'fighter' => null );
			}
		}

		$identity_url = '' !== $source_url ? $source_url : ( 0 === strpos( $source_fighter_id, 'http' ) ? $source_fighter_id : '' );
		if ( '' !== $identity_url ) {
			$normalized_url = TapologyFighterUrl::normalize( $identity_url );
			if ( '' !== $normalized_url ) {
				$match = $this->single_fighter_match( $this->normalized_url_map[ $normalized_url ] ?? array() );
				if ( 'matched' === $match['status'] ) {
					return array( 'match_type' => 'normalized_url_match', 'fighter' => $match['fighter'] );
				}
				if ( 'ambiguous' === $match['status'] ) {
					return array( 'match_type' => 'ambiguous', 'fighter' => null );
				}
			}

			$match = $this->single_fighter_match( $this->exact_url_map[ $identity_url ] ?? array() );
			if ( 'matched' === $match['status'] ) {
				return array( 'match_type' => 'exact_url_match', 'fighter' => $match['fighter'] );
			}
			if ( 'ambiguous' === $match['status'] ) {
				return array( 'match_type' => 'ambiguous', 'fighter' => null );
			}
		}

		return array( 'match_type' => 'unmatched', 'fighter' => null );
	}

	private function single_fighter_match( array $rows ): array {
		$fighter_ids = array();
		foreach ( $rows as $row ) {
			$fighter_id = (int) ( $row['fighter_id'] ?? 0 );
			if ( $fighter_id > 0 ) {
				$fighter_ids[ $fighter_id ] = true;
			}
		}

		if ( 1 === count( $fighter_ids ) ) {
			$fighter_id = (int) array_key_first( $fighter_ids );
			return array( 'status' => 'matched', 'fighter' => $this->fighters[ $fighter_id ] ?? null );
		}

		return array( 'status' => count( $fighter_ids ) > 1 ? 'ambiguous' : 'none', 'fighter' => null );
	}

	private function filter_rows( array $rows, array $filters, string $search ): array {
		$filters = array_values( array_intersect( array_map( 'sanitize_key', $filters ), self::FILTERS ) );
		$search = strtolower( trim( $search ) );

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filters, $search ): bool {
					if ( '' !== $search ) {
						$haystack = strtolower(
							(string) $row['fighter_profile_name'] . ' ' .
							(string) $row['opponent_name'] . ' ' .
							(string) $row['event_name']
						);
						if ( false === strpos( $haystack, $search ) ) {
							return false;
						}
					}

					foreach ( $filters as $filter ) {
						if ( ! self::row_matches_filter( $row, $filter ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	private static function row_matches_filter( array $row, string $filter ): bool {
		switch ( $filter ) {
			case 'high_confidence':
				return 'high' === (string) $row['staging_confidence'];
			case 'medium_confidence':
				return 'medium' === (string) $row['staging_confidence'];
			case 'low_confidence':
				return 'low' === (string) $row['staging_confidence'];
			case 'already_canonical':
				return 'already_canonical' === (string) $row['canonical_match_status'];
			case 'candidate_new_historical_bout':
				return 'missing_from_canonical' === (string) $row['canonical_match_status'];
			case 'possible_weak_duplicate':
				return 'possible_duplicate' === (string) $row['canonical_match_status'];
			case 'missing_opponent_prefight_record':
				return ! (bool) $row['opponent_prefight_present'];
			case 'non_mma_filtered':
				return in_array( (string) $row['sport_type'], array( 'boxing', 'bare_knuckle', 'kickboxing', 'muay_thai', 'grappling' ), true );
			case 'cancelled_amateur_overturned':
				return in_array( (string) $row['sport_type'], array( 'cancelled', 'amateur', 'overturned' ), true );
			case 'needs_bout_detail_fetch':
				return (bool) $row['needs_bout_detail_fetch'];
			case 'stage_for_review':
				return 'stage_for_review' === (string) $row['recommended_action'];
		}

		return true;
	}

	private static function allowed_roots(): array {
		$root = self::workspace_root();

		return array(
			$root . DIRECTORY_SEPARATOR . 'scraper' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'latest',
			$root . DIRECTORY_SEPARATOR . 'scraper' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runs',
		);
	}

	private static function workspace_root(): string {
		if ( defined( 'ABSPATH' ) ) {
			$root = realpath( dirname( ABSPATH, 2 ) );
			if ( is_string( $root ) && '' !== $root ) {
				return $root;
			}
		}

		if ( defined( 'MMAF_PLUGIN_DIR' ) ) {
			$root = realpath( dirname( MMAF_PLUGIN_DIR, 5 ) );
			if ( is_string( $root ) && '' !== $root ) {
				return $root;
			}
		}

		return dirname( __DIR__, 6 );
	}
}
