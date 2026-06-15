<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentPreviewService;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileRecordComparisonService {
	public const FILTERS = array(
		'matched',
		'unmatched',
		'ambiguous',
		'has_profile_pro_record',
		'has_profile_fight_history',
		'canonical_zero_profile_has_record',
		'canonical_lower_than_profile',
		'possible_complete_canonical',
		'staging_candidates_available',
		'missing_useful_history',
		'needs_identity_review',
	);

	private const SCHEMA_VERSION = 'tapology_fighter_profiles_v0_1';

	private array $tables;
	private array $source_id_map = array();
	private array $normalized_url_map = array();
	private array $exact_url_map = array();
	private array $fighters = array();

	public function __construct() {
		$this->tables = Schema::table_names();
	}

	public static function default_path(): string {
		return FighterProfileEnrichmentPreviewService::default_path();
	}

	public function build_report( string $path = '', array $filters = array(), string $search = '', int $limit = 50, int $offset = 0 ): array {
		$path = '' === trim( $path ) ? self::default_path() : trim( $path );
		$path = FighterProfileEnrichmentPreviewService::resolve_safe_json_path( $path );
		$data = $this->decode_file( $path );

		$this->load_database_context();

		$rows = array();
		$summary = $this->empty_summary( $path, (string) $data['schema_version'], (string) ( $data['run_id'] ?? '' ), (string) ( $data['scraped_at'] ?? '' ) );
		foreach ( (array) $data['profiles'] as $index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$row = $this->build_row( $profile, (int) $index );
			$this->add_summary_counts( $summary, $row );
			$rows[] = $row;
		}

		$filtered = $this->filter_rows( $rows, $filters, $search );
		$total = count( $filtered );
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		return array(
			'summary' => $summary,
			'rows'    => array_slice( $filtered, $offset, $limit ),
			'total'   => $total,
			'all_rows_count' => count( $rows ),
			'path'    => $path,
		);
	}

	public function analyze_json_string( string $content, string $path = 'memory://fighter_profiles.json' ): array {
		$data = $this->decode_content( $content );
		$this->load_database_context();

		$summary = $this->empty_summary( $path, (string) $data['schema_version'], (string) ( $data['run_id'] ?? '' ), (string) ( $data['scraped_at'] ?? '' ) );
		$rows = array();
		foreach ( (array) $data['profiles'] as $index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$row = $this->build_row( $profile, (int) $index );
			$this->add_summary_counts( $summary, $row );
			$rows[] = $row;
		}

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'total'   => count( $rows ),
			'all_rows_count' => count( $rows ),
			'path'    => $path,
		);
	}

	private function decode_file( string $path ): array {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read fighter profile enrichment JSON.' );
		}

		return $this->decode_content( $content );
	}

	private function decode_content( string $content ): array {
		$data = json_decode( $content, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid fighter profile enrichment JSON.' );
		}

		if ( self::SCHEMA_VERSION !== (string) ( $data['schema_version'] ?? '' ) ) {
			throw new \RuntimeException( 'Unsupported fighter profile schema version.' );
		}

		if ( ! isset( $data['profiles'] ) || ! is_array( $data['profiles'] ) ) {
			throw new \RuntimeException( 'Fighter profile enrichment JSON is missing profiles.' );
		}

		return $data;
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
				fs.id AS source_row_id,
				fs.fighter_id,
				fs.source_type,
				fs.source_fighter_id,
				fs.source_url,
				f.display_name,
				f.status,
				f.rankability_status,
				f.is_public,
				f.is_rankable,
				COALESCE(st.wins, 0) AS wins,
				COALESCE(st.losses, 0) AS losses,
				COALESCE(st.draws, 0) AS draws,
				COALESCE(st.nc, 0) AS nc,
				COALESCE(st.pro_fights_count, 0) AS pro_fights_count,
				COALESCE(st.finish_wins, 0) AS finish_wins,
				st.last_fight_date,
				st.warnings_json
			FROM {$this->tables['fighter_sources']} fs
			LEFT JOIN {$this->tables['fighters']} f ON f.id = fs.fighter_id
			LEFT JOIN {$this->tables['fighter_stats_current']} st ON st.fighter_id = f.id
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

	private function build_row( array $profile, int $index ): array {
		$source_fighter_id = trim( (string) ( $profile['source_fighter_id'] ?? '' ) );
		$source_url = trim( (string) ( $profile['source_url'] ?? '' ) );
		$display_name = trim( (string) ( $profile['display_name'] ?? '' ) );
		$warnings = array_values( array_unique( array_map( 'strval', (array) ( $profile['warnings'] ?? array() ) ) ) );

		if ( '' === $source_fighter_id ) {
			$warnings[] = 'missing_source_fighter_id';
		}
		if ( '' === $source_url ) {
			$warnings[] = 'missing_source_url';
		}

		$match = $this->match_profile( $source_fighter_id, $source_url );
		$fighter = $match['fighter'];
		$record = $this->record_from_profile( (array) ( $profile['record_summary'] ?? array() ) );
		$canonical = $this->record_from_fighter( $fighter );
		$history = $this->history_coverage( (array) ( $profile['fight_history'] ?? array() ) );
		$gap = $this->record_gap( $match['match_type'], $canonical, $record );
		$last_fight_gap = $this->last_fight_gap( (string) ( $fighter['last_fight_date'] ?? '' ), (array) ( $profile['fight_history'] ?? array() ) );
		$action = $this->suggested_action( $match['match_type'], $gap['type'], $record, $history );

		return array(
			'profile_index' => $index,
			'source_fighter_id' => $source_fighter_id,
			'source_url' => $source_url,
			'profile_display_name' => $display_name,
			'matched_canonical_fighter_id' => $fighter ? (int) $fighter['fighter_id'] : 0,
			'matched_canonical_name' => $fighter ? (string) $fighter['display_name'] : '',
			'match_type' => $match['match_type'],
			'warnings' => implode( ', ', array_values( array_unique( $warnings ) ) ),
			'canonical_wins' => $canonical['wins'],
			'canonical_losses' => $canonical['losses'],
			'canonical_draws' => $canonical['draws'],
			'canonical_no_contests' => $canonical['nc'],
			'canonical_countable_fights' => $canonical['total'],
			'canonical_finish_wins' => $canonical['finish_wins'],
			'canonical_last_fight_date' => (string) ( $fighter['last_fight_date'] ?? '' ),
			'canonical_stats_warning_count' => $this->stats_warning_count( (string) ( $fighter['warnings_json'] ?? '' ) ),
			'pro_record_raw' => $record['raw'],
			'profile_wins' => $record['wins'],
			'profile_losses' => $record['losses'],
			'profile_draws' => $record['draws'],
			'profile_no_contests' => $record['nc'],
			'profile_total_fights' => $record['total'],
			'profile_fight_history_row_count' => $history['history_rows_total'],
			'profile_completeness_score' => isset( $profile['profile_completeness_score'] ) && is_numeric( $profile['profile_completeness_score'] ) ? (float) $profile['profile_completeness_score'] : null,
			'record_gap_type' => $gap['type'],
			'record_gap_summary' => $gap['summary'],
			'last_fight_gap' => $last_fight_gap,
			'fight_history_coverage_summary' => $this->history_summary( $history ),
			'suggested_action' => $action,
			'history_rows_total' => $history['history_rows_total'],
			'rows_with_date' => $history['rows_with_date'],
			'rows_with_result' => $history['rows_with_result'],
			'rows_with_method' => $history['rows_with_method'],
			'rows_with_event_name' => $history['rows_with_event_name'],
			'rows_with_event_url' => $history['rows_with_event_url'],
			'rows_with_bout_url' => $history['rows_with_bout_url'],
			'rows_with_opponent_name' => $history['rows_with_opponent_name'],
			'rows_with_opponent_url' => $history['rows_with_opponent_url'],
			'rows_with_opponent_record' => $history['rows_with_opponent_record'],
			'rows_missing_core_fields' => $history['rows_missing_core_fields'],
			'rows_staging_candidate_count' => $history['rows_staging_candidate_count'],
		);
	}

	private function match_profile( string $source_fighter_id, string $source_url ): array {
		if ( '' !== $source_fighter_id ) {
			$match = $this->single_fighter_match( $this->source_id_map[ $source_fighter_id ] ?? array() );
			if ( 'matched' === $match['status'] ) {
				return array( 'match_type' => 'exact_source_match', 'fighter' => $match['fighter'] );
			}
			if ( 'ambiguous' === $match['status'] ) {
				return array( 'match_type' => 'ambiguous', 'fighter' => null );
			}
		}

		if ( '' !== $source_url ) {
			$normalized_url = TapologyFighterUrl::normalize( $source_url );
			if ( '' !== $normalized_url ) {
				$match = $this->single_fighter_match( $this->normalized_url_map[ $normalized_url ] ?? array() );
				if ( 'matched' === $match['status'] ) {
					return array( 'match_type' => 'normalized_url_match', 'fighter' => $match['fighter'] );
				}
				if ( 'ambiguous' === $match['status'] ) {
					return array( 'match_type' => 'ambiguous', 'fighter' => null );
				}
			}

			$match = $this->single_fighter_match( $this->exact_url_map[ $source_url ] ?? array() );
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

	private function record_from_profile( array $record ): array {
		$wins = $this->nullable_int( $record['wins'] ?? null );
		$losses = $this->nullable_int( $record['losses'] ?? null );
		$draws = $this->nullable_int( $record['draws'] ?? null );
		$nc = $this->nullable_int( $record['no_contests'] ?? null );
		$has_record = null !== $wins && null !== $losses && null !== $draws && null !== $nc;

		return array(
			'raw' => trim( (string) ( $record['pro_record_raw'] ?? '' ) ),
			'wins' => $wins,
			'losses' => $losses,
			'draws' => $draws,
			'nc' => $nc,
			'total' => $has_record ? (int) $wins + (int) $losses + (int) $draws + (int) $nc : null,
			'has_record' => $has_record,
		);
	}

	private function record_from_fighter( ?array $fighter ): array {
		if ( ! $fighter ) {
			return array(
				'wins' => 0,
				'losses' => 0,
				'draws' => 0,
				'nc' => 0,
				'total' => 0,
				'finish_wins' => 0,
			);
		}

		return array(
			'wins' => (int) $fighter['wins'],
			'losses' => (int) $fighter['losses'],
			'draws' => (int) $fighter['draws'],
			'nc' => (int) $fighter['nc'],
			'total' => (int) $fighter['pro_fights_count'],
			'finish_wins' => (int) $fighter['finish_wins'],
		);
	}

	private function record_gap( string $match_type, array $canonical, array $profile ): array {
		if ( 'unmatched' === $match_type ) {
			return array( 'type' => 'unmatched_profile', 'summary' => 'Profile did not match a canonical fighter.' );
		}
		if ( 'ambiguous' === $match_type ) {
			return array( 'type' => 'ambiguous_match', 'summary' => 'Profile matched multiple canonical candidates.' );
		}
		if ( empty( $profile['has_record'] ) ) {
			return array( 'type' => 'profile_record_missing', 'summary' => 'Profile pro record is missing or unparsed.' );
		}

		$canonical_label = sprintf( 'canonical %d-%d-%d-%d', $canonical['wins'], $canonical['losses'], $canonical['draws'], $canonical['nc'] );
		$profile_label = sprintf( 'profile %d-%d-%d-%d', $profile['wins'], $profile['losses'], $profile['draws'], $profile['nc'] );
		$summary = $canonical_label . ' vs ' . $profile_label;

		if ( 0 === (int) $canonical['total'] && (int) $profile['total'] > 0 ) {
			return array( 'type' => 'canonical_zero_profile_has_record', 'summary' => $summary );
		}
		if ( (int) $canonical['total'] < (int) $profile['total'] ) {
			return array( 'type' => 'canonical_lower_than_profile', 'summary' => $summary );
		}
		if ( (int) $canonical['total'] > (int) $profile['total'] ) {
			return array( 'type' => 'canonical_higher_than_profile', 'summary' => $summary );
		}
		if ( (int) $canonical['wins'] !== (int) $profile['wins'] || (int) $canonical['losses'] !== (int) $profile['losses'] || (int) $canonical['draws'] !== (int) $profile['draws'] || (int) $canonical['nc'] !== (int) $profile['nc'] ) {
			return array( 'type' => 'canonical_higher_than_profile', 'summary' => $summary );
		}

		return array( 'type' => 'no_gap', 'summary' => $summary );
	}

	private function history_coverage( array $history ): array {
		$coverage = array(
			'history_rows_total' => count( $history ),
			'rows_with_date' => 0,
			'rows_with_result' => 0,
			'rows_with_method' => 0,
			'rows_with_event_name' => 0,
			'rows_with_event_url' => 0,
			'rows_with_bout_url' => 0,
			'rows_with_opponent_name' => 0,
			'rows_with_opponent_url' => 0,
			'rows_with_opponent_record' => 0,
			'rows_missing_core_fields' => 0,
			'rows_staging_candidate_count' => 0,
		);

		foreach ( $history as $row ) {
			$row = is_array( $row ) ? $row : array();
			$has_date = '' !== trim( (string) ( $row['fight_date'] ?? '' ) );
			$has_result = '' !== trim( (string) ( $row['result'] ?? '' ) );
			$has_method = $this->row_has_method( $row );
			$has_event_name = '' !== trim( (string) ( $row['event_name'] ?? '' ) );
			$has_event_url = '' !== trim( (string) ( $row['event_url'] ?? '' ) );
			$has_bout_url = '' !== trim( (string) ( $row['bout_url'] ?? '' ) );
			$has_opponent_name = '' !== trim( (string) ( $row['opponent_name'] ?? '' ) );
			$has_opponent_url = '' !== trim( (string) ( $row['opponent_url'] ?? '' ) );
			$has_opponent_record = '' !== trim( (string) ( $row['opponent_record_raw'] ?? '' ) ) || '' !== trim( (string) ( $row['prefight_record_raw'] ?? '' ) );
			$is_staging_candidate = $has_date && $has_opponent_name && $has_result && ( $has_event_name || $has_event_url ) && $has_method;

			$coverage['rows_with_date'] += $has_date ? 1 : 0;
			$coverage['rows_with_result'] += $has_result ? 1 : 0;
			$coverage['rows_with_method'] += $has_method ? 1 : 0;
			$coverage['rows_with_event_name'] += $has_event_name ? 1 : 0;
			$coverage['rows_with_event_url'] += $has_event_url ? 1 : 0;
			$coverage['rows_with_bout_url'] += $has_bout_url ? 1 : 0;
			$coverage['rows_with_opponent_name'] += $has_opponent_name ? 1 : 0;
			$coverage['rows_with_opponent_url'] += $has_opponent_url ? 1 : 0;
			$coverage['rows_with_opponent_record'] += $has_opponent_record ? 1 : 0;
			$coverage['rows_staging_candidate_count'] += $is_staging_candidate ? 1 : 0;
			$coverage['rows_missing_core_fields'] += $is_staging_candidate ? 0 : 1;
		}

		return $coverage;
	}

	private function row_has_method( array $row ): bool {
		$method = strtolower( trim( (string) ( $row['method'] ?? '' ) ) );
		$category = strtolower( trim( (string) ( $row['method_category'] ?? '' ) ) );
		$raw = strtolower( trim( (string) ( $row['raw_text'] ?? '' ) ) );

		return '' !== $method || '' !== $category || false !== strpos( $raw, 'decision' ) || false !== strpos( $raw, 'unknown' );
	}

	private function last_fight_gap( string $canonical_last_fight, array $history ): string {
		$profile_last = '';
		foreach ( $history as $row ) {
			if ( ! is_array( $row ) || empty( $row['fight_date'] ) ) {
				continue;
			}
			$date = (string) $row['fight_date'];
			if ( '' === $profile_last || $date > $profile_last ) {
				$profile_last = $date;
			}
		}

		if ( '' === $profile_last ) {
			return 'profile_last_fight_unknown';
		}
		if ( '' === $canonical_last_fight ) {
			return 'canonical_last_fight_missing; profile latest ' . $profile_last;
		}
		if ( $canonical_last_fight !== $profile_last ) {
			return 'canonical ' . $canonical_last_fight . ' vs profile ' . $profile_last;
		}

		return 'no_gap';
	}

	private function suggested_action( string $match_type, string $gap_type, array $record, array $history ): string {
		if ( 'unmatched' === $match_type || 'ambiguous' === $match_type ) {
			return 'needs identity review';
		}
		if ( empty( $record['has_record'] ) && 0 === (int) $history['rows_staging_candidate_count'] ) {
			return 'not enough profile data';
		}
		if ( in_array( $gap_type, array( 'canonical_zero_profile_has_record', 'canonical_lower_than_profile' ), true ) ) {
			return 0 === (int) $history['rows_staging_candidate_count'] ? 'canonical history incomplete' : 'stage profile history later';
		}
		if ( ! empty( $record['has_record'] ) ) {
			return 'display record candidate only';
		}

		return 'no action';
	}

	private function history_summary( array $history ): string {
		return sprintf(
			'%d rows; %d staging candidates; event URLs %d; bout URLs %d; opponent URLs %d; opponent records %d',
			(int) $history['history_rows_total'],
			(int) $history['rows_staging_candidate_count'],
			(int) $history['rows_with_event_url'],
			(int) $history['rows_with_bout_url'],
			(int) $history['rows_with_opponent_url'],
			(int) $history['rows_with_opponent_record']
		);
	}

	private function stats_warning_count( string $warnings_json ): int {
		$data = json_decode( $warnings_json, true );
		if ( ! is_array( $data ) ) {
			return 0;
		}

		return count( (array) ( $data['warnings'] ?? array() ) );
	}

	private function empty_summary( string $path, string $schema_version, string $run_id, string $scraped_at ): array {
		return array(
			'enrichment_file' => $path,
			'schema_version' => $schema_version,
			'run_id' => $run_id,
			'scraped_at' => $scraped_at,
			'profiles_total' => 0,
			'profiles_matched' => 0,
			'profiles_unmatched' => 0,
			'profiles_ambiguous' => 0,
			'profiles_with_pro_record' => 0,
			'profiles_with_fight_history' => 0,
			'total_profile_fight_history_rows' => 0,
			'matched_fighters_with_canonical_stats' => 0,
			'matched_fighters_with_0_canonical_fights' => 0,
			'matched_fighters_with_1_canonical_fight' => 0,
			'matched_fighters_with_multiple_canonical_fights' => 0,
			'matched_fighters_profile_record_differs_from_canonical_stats' => 0,
			'matched_fighters_profile_record_suggests_canonical_incomplete' => 0,
			'matched_fighters_canonical_stats_exceed_profile_record' => 0,
			'matched_fighters_with_last_fight_mismatch' => 0,
			'total_history_rows' => 0,
			'rows_with_event_url' => 0,
			'rows_with_bout_url' => 0,
			'rows_with_opponent_url' => 0,
			'rows_with_opponent_or_prefight_record' => 0,
			'rows_missing_method' => 0,
			'rows_missing_date' => 0,
			'rows_missing_result' => 0,
			'rows_potentially_useful_for_staging' => 0,
			'rows_not_useful_for_staging' => 0,
			'ranking_warning' => 'Profile aggregate record is display/audit suggestion only. Canonical stats remain the only ranking-grade source. Do not activate ranking based on this report.',
		);
	}

	private function add_summary_counts( array &$summary, array $row ): void {
		$summary['profiles_total']++;
		if ( in_array( (string) $row['match_type'], array( 'exact_source_match', 'normalized_url_match', 'exact_url_match' ), true ) ) {
			$summary['profiles_matched']++;
			$summary['matched_fighters_with_canonical_stats']++;
			if ( 0 === (int) $row['canonical_countable_fights'] ) {
				$summary['matched_fighters_with_0_canonical_fights']++;
			} elseif ( 1 === (int) $row['canonical_countable_fights'] ) {
				$summary['matched_fighters_with_1_canonical_fight']++;
			} else {
				$summary['matched_fighters_with_multiple_canonical_fights']++;
			}
		} elseif ( 'ambiguous' === (string) $row['match_type'] ) {
			$summary['profiles_ambiguous']++;
		} else {
			$summary['profiles_unmatched']++;
		}

		if ( '' !== (string) $row['pro_record_raw'] ) {
			$summary['profiles_with_pro_record']++;
		}
		if ( (int) $row['profile_fight_history_row_count'] > 0 ) {
			$summary['profiles_with_fight_history']++;
		}

		$summary['total_profile_fight_history_rows'] += (int) $row['history_rows_total'];
		$summary['total_history_rows'] += (int) $row['history_rows_total'];
		$summary['rows_with_event_url'] += (int) $row['rows_with_event_url'];
		$summary['rows_with_bout_url'] += (int) $row['rows_with_bout_url'];
		$summary['rows_with_opponent_url'] += (int) $row['rows_with_opponent_url'];
		$summary['rows_with_opponent_or_prefight_record'] += (int) $row['rows_with_opponent_record'];
		$summary['rows_missing_method'] += (int) $row['history_rows_total'] - (int) $row['rows_with_method'];
		$summary['rows_missing_date'] += (int) $row['history_rows_total'] - (int) $row['rows_with_date'];
		$summary['rows_missing_result'] += (int) $row['history_rows_total'] - (int) $row['rows_with_result'];
		$summary['rows_potentially_useful_for_staging'] += (int) $row['rows_staging_candidate_count'];
		$summary['rows_not_useful_for_staging'] += (int) $row['rows_missing_core_fields'];

		if ( in_array( (string) $row['record_gap_type'], array( 'canonical_zero_profile_has_record', 'canonical_lower_than_profile', 'canonical_higher_than_profile' ), true ) ) {
			$summary['matched_fighters_profile_record_differs_from_canonical_stats']++;
		}
		if ( in_array( (string) $row['record_gap_type'], array( 'canonical_zero_profile_has_record', 'canonical_lower_than_profile' ), true ) ) {
			$summary['matched_fighters_profile_record_suggests_canonical_incomplete']++;
		}
		if ( 'canonical_higher_than_profile' === (string) $row['record_gap_type'] ) {
			$summary['matched_fighters_canonical_stats_exceed_profile_record']++;
		}
		if ( ! in_array( (string) $row['last_fight_gap'], array( 'no_gap', 'profile_last_fight_unknown' ), true ) ) {
			$summary['matched_fighters_with_last_fight_mismatch']++;
		}
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
							(string) $row['profile_display_name'] . ' ' .
							(string) $row['matched_canonical_name'] . ' ' .
							(string) $row['source_fighter_id']
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
		$match_type = (string) $row['match_type'];
		$matched = in_array( $match_type, array( 'exact_source_match', 'normalized_url_match', 'exact_url_match' ), true );

		switch ( $filter ) {
			case 'matched':
				return $matched;
			case 'unmatched':
				return 'unmatched' === $match_type;
			case 'ambiguous':
				return 'ambiguous' === $match_type;
			case 'has_profile_pro_record':
				return '' !== (string) $row['pro_record_raw'];
			case 'has_profile_fight_history':
				return (int) $row['history_rows_total'] > 0;
			case 'canonical_zero_profile_has_record':
				return 'canonical_zero_profile_has_record' === (string) $row['record_gap_type'];
			case 'canonical_lower_than_profile':
				return 'canonical_lower_than_profile' === (string) $row['record_gap_type'];
			case 'possible_complete_canonical':
				return 'no_gap' === (string) $row['record_gap_type'];
			case 'staging_candidates_available':
				return (int) $row['rows_staging_candidate_count'] > 0;
			case 'missing_useful_history':
				return 0 === (int) $row['rows_staging_candidate_count'];
			case 'needs_identity_review':
				return in_array( $match_type, array( 'unmatched', 'ambiguous' ), true );
		}

		return true;
	}

	private function nullable_int( $value ): ?int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
