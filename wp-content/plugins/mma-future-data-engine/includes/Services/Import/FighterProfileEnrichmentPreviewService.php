<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Support\Sanitizer;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterProfileEnrichmentPreviewService {
	private const SCHEMA_VERSION = 'tapology_fighter_profiles_v0_1';
	private const SOURCE = 'tapology';
	private const MAX_FILE_SIZE = 26214400;
	private const PROVENANCE_PROTECTED_FIELDS = array(
		'nickname',
		'date_of_birth',
		'birth_year',
		'weight_class',
		'height',
		'height_cm',
		'last_weigh_in',
		'nationality',
		'gender',
	);
	private const READ_ONLY_CONTEXT_FIELDS = array(
		'born_location',
		'fighting_out_of',
		'reach',
	);

	private array $tables;
	private FieldProvenanceService $provenance;
	private GenderInferenceService $gender_inference;
	private array $fighter_columns = array();
	private array $source_id_map = array();
	private array $tapology_url_map = array();
	private array $exact_url_map = array();
	private array $planned_source_id_map = array();
	private array $planned_tapology_url_map = array();
	private array $planned_exact_url_map = array();

	public function __construct() {
		$this->tables = Schema::table_names();
		$this->provenance = new FieldProvenanceService();
		$this->gender_inference = new GenderInferenceService();
	}

	public static function default_path(): string {
		return self::workspace_root() . DIRECTORY_SEPARATOR . 'scraper' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'latest' . DIRECTORY_SEPARATOR . 'fighter_profiles.json';
	}

	public static function max_file_size(): int {
		return self::MAX_FILE_SIZE;
	}

	public static function resolve_safe_json_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			throw new \RuntimeException( 'Choose a fighter profile enrichment JSON file.' );
		}

		if ( 'json' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			throw new \RuntimeException( 'Only .json files are accepted for fighter profile enrichment preview.' );
		}

		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			throw new \RuntimeException( 'Enrichment JSON path does not exist or is not a file.' );
		}

		$allowed_roots = self::allowed_roots();
		$normalized = trailingslashit( wp_normalize_path( $real_path ) );
		$allowed = false;
		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $normalized, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			throw new \RuntimeException( 'Enrichment JSON path is outside the allowed scraper/data/latest or scraper/data/runs directories.' );
		}

		$size = filesize( $real_path );
		if ( false === $size || $size <= 0 ) {
			throw new \RuntimeException( 'Enrichment JSON file is empty or cannot be sized.' );
		}
		if ( $size > self::MAX_FILE_SIZE ) {
			throw new \RuntimeException( 'Enrichment JSON file exceeds the 25 MB preview limit.' );
		}

		return $real_path;
	}

	public function analyze_file( string $path, array $filters = array(), string $search = '', int $limit = 50, int $offset = 0, array $results_dry_run = array() ): array {
		$path = self::resolve_safe_json_path( $path );
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read enrichment JSON file.' );
		}

		return $this->analyze_json_string( $content, $path, $filters, $search, $limit, $offset, $results_dry_run );
	}

	public function analyze_profile( string $path, string $source_fighter_id, string $source_url ): array {
		$path = self::resolve_safe_json_path( $path );
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read enrichment JSON file.' );
		}

		$data = $this->decode_payload( $content );
		$profiles = (array) $data['profiles'];
		$source_fighter_id = trim( $source_fighter_id );
		$source_url = trim( $source_url );

		if ( '' === $source_fighter_id && '' === $source_url ) {
			throw new \RuntimeException( 'Choose a profile by source fighter ID or source URL.' );
		}

		$matches = array();
		foreach ( $profiles as $index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$profile_source_fighter_id = trim( (string) ( $profile['source_fighter_id'] ?? '' ) );
			$profile_source_url = trim( (string) ( $profile['source_url'] ?? '' ) );
			$id_matches = '' !== $source_fighter_id && $profile_source_fighter_id === $source_fighter_id;
			$url_matches = '' !== $source_url && $this->normalize_url( $profile_source_url ) === $this->normalize_url( $source_url );

			if ( $id_matches || $url_matches ) {
				if ( '' !== $source_fighter_id && '' !== $source_url && ( ! $id_matches || ! $url_matches ) ) {
					continue;
				}

				$matches[] = array(
					'profile' => $profile,
					'index'   => (int) $index,
				);
			}
		}

		if ( empty( $matches ) ) {
			throw new \RuntimeException( 'Profile no longer exists in the selected enrichment JSON file.' );
		}
		if ( count( $matches ) > 1 ) {
			throw new \RuntimeException( 'Profile selection is ambiguous in the selected enrichment JSON file.' );
		}

		$this->load_database_context();
		$row = $this->build_profile_row( (array) $matches[0]['profile'], (int) $matches[0]['index'] );
		$row['enrichment_file'] = $path;
		$row['schema_version'] = (string) $data['schema_version'];
		$row['source'] = (string) ( $data['source'] ?? '' );
		$row['run_id'] = (string) ( $data['run_id'] ?? '' );
		$row['scraped_at'] = (string) ( $data['scraped_at'] ?? '' );

		return $row;
	}

	public function analyze_profile_json_string( string $content, string $path_label, string $source_fighter_id, string $source_url ): array {
		$data = $this->decode_payload( $content );
		$profiles = (array) $data['profiles'];
		$source_fighter_id = trim( $source_fighter_id );
		$source_url = trim( $source_url );

		if ( '' === $source_fighter_id && '' === $source_url ) {
			throw new \RuntimeException( 'Choose a profile by source fighter ID or source URL.' );
		}

		$matches = $this->find_profile_matches( $profiles, $source_fighter_id, $source_url );
		if ( empty( $matches ) ) {
			throw new \RuntimeException( 'Profile no longer exists in the selected enrichment JSON file.' );
		}
		if ( count( $matches ) > 1 ) {
			throw new \RuntimeException( 'Profile selection is ambiguous in the selected enrichment JSON file.' );
		}

		$this->load_database_context();
		$row = $this->build_profile_row( (array) $matches[0]['profile'], (int) $matches[0]['index'] );
		$row['enrichment_file'] = $path_label;
		$row['schema_version'] = (string) $data['schema_version'];
		$row['source'] = (string) ( $data['source'] ?? '' );
		$row['run_id'] = (string) ( $data['run_id'] ?? '' );
		$row['scraped_at'] = (string) ( $data['scraped_at'] ?? '' );

		return $row;
	}

	public function create_row_iterator( string $content, string $path, array $results_dry_run = array() ): array {
		return $this->prepare_profile_row_iteration( $content, $path, $results_dry_run );
	}

	public function analyze_json_string( string $content, string $path, array $filters = array(), string $search = '', int $limit = 50, int $offset = 0, array $results_dry_run = array() ): array {
		$analysis = $this->prepare_profile_row_iteration( $content, $path, $results_dry_run );
		$summary = (array) $analysis['summary'];
		$iterator = $analysis['iterator'];

		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$total = 0;
		$processed_rows = 0;
		$rows = array();
		foreach ( $iterator as $row ) {
			++$processed_rows;
			$this->add_row_counts( $summary, $row );

			if ( ! $this->row_matches( $row, $filters, $search ) ) {
				continue;
			}

			if ( $total >= $offset && count( $rows ) < $limit ) {
				$rows[] = $row;
			}

			++$total;
		}

		$summary['profiles_not_safe_for_auto_write'] = $summary['profiles_total'];
		$summary['matching_quality_good_enough_for_later_import'] = $this->matching_quality_answer( $summary );
		$summary['matching_quality_reason'] = $this->matching_quality_reason( $summary );

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'total'   => $total,
			'all_rows_count' => $processed_rows,
		);
	}

	public function analyze_json_string_unpaged( string $content, string $path, array $filters = array(), string $search = '' ): array {
		$analysis = $this->prepare_profile_row_iteration( $content, $path, array() );
		$summary = (array) $analysis['summary'];
		$iterator = $analysis['iterator'];

		$rows = array();
		$processed_rows = 0;
		foreach ( $iterator as $row ) {
			++$processed_rows;
			$this->add_row_counts( $summary, $row );
			if ( $this->row_matches( $row, $filters, $search ) ) {
				$rows[] = $row;
			}
		}

		$summary['profiles_not_safe_for_auto_write'] = $summary['profiles_total'];
		$summary['matching_quality_good_enough_for_later_import'] = $this->matching_quality_answer( $summary );
		$summary['matching_quality_reason'] = $this->matching_quality_reason( $summary );

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'total'   => count( $rows ),
			'all_rows_count' => $processed_rows,
		);
	}

	public static function filters(): array {
		return array(
			'matched',
			'unmatched',
			'ambiguous',
			'has_dob_suggestion',
			'has_weight_class_suggestion',
			'has_pro_record',
			'has_fight_history',
			'has_record_gap',
			'backend_column_missing',
			'unsafe_ambiguous',
			'low_completeness',
		);
	}

	private function build_profile_row( array $profile, int $index ): array {
		$summary = is_array( $profile['profile_summary'] ?? null ) ? (array) $profile['profile_summary'] : array();
		$record = is_array( $profile['record_summary'] ?? null ) ? (array) $profile['record_summary'] : array();
		$history = is_array( $profile['fight_history'] ?? null ) ? (array) $profile['fight_history'] : array();
		$source_fighter_id = trim( (string) ( $profile['source_fighter_id'] ?? '' ) );
		$source_url = trim( (string) ( $profile['source_url'] ?? '' ) );
		$display_name = trim( (string) ( $profile['display_name'] ?? '' ) );
		$match = $this->match_profile( $source_fighter_id, $source_url );
		$fighter = $match['fighter'];
		$stats = $match['stats'];
		$warnings = $match['warnings'];

		if ( '' === $source_fighter_id ) {
			$warnings[] = 'missing_source_fighter_id';
		}
		if ( '' === $source_url ) {
			$warnings[] = 'missing_source_url';
		}
		if ( empty( $history ) ) {
			$warnings[] = 'profile_has_no_fight_history';
		}

		$completeness_score = isset( $profile['profile_completeness_score'] ) && is_numeric( $profile['profile_completeness_score'] ) ? (float) $profile['profile_completeness_score'] : null;
		if ( null !== $completeness_score && $completeness_score < 8 ) {
			$warnings[] = 'low_completeness_score';
		}

		$suggested_dob = $this->valid_date( $summary['date_of_birth'] ?? null );
		$suggested_birth_year = $this->suggested_birth_year( $summary, $suggested_dob );
		$suggested_nickname = $this->string_or_null( $summary['nickname'] ?? null );
		$suggested_weight_class = $this->canonical_weight_class_suggestion( $this->string_or_null( $summary['weight_class'] ?? null ) );
		$gender_inference = $this->gender_inference->infer_from_weight_class( $suggested_weight_class );
		$suggested_gender = $this->string_or_null( $gender_inference['gender'] ?? null );
		$suggested_country = $this->string_or_null( $summary['nationality'] ?? null );
		if ( null === $suggested_country ) {
			$suggested_country = $this->string_or_null( $summary['country'] ?? null );
		}
		$born_location = $this->string_or_null( $summary['born_location'] ?? null );
		$fighting_out_of = $this->string_or_null( $summary['fighting_out_of'] ?? null );
		$suggested_height = $this->string_or_null( $summary['height'] ?? null );
		$suggested_height_cm = $this->suggested_height_cm( $summary );
		$suggested_last_weigh_in = $this->string_or_null( $summary['last_weigh_in'] ?? null );
		$suggested_reach = $this->string_or_null( $summary['reach'] ?? null );
		$suggested_association = null;
		$suggested_team = $this->string_or_null( $summary['team'] ?? null );
		$suggested_image_url = null;

		$field_statuses = array(
			'display_name' => $this->field_status( 'display_name', $display_name, $fighter ),
			'nickname' => $this->field_status( 'nickname', $suggested_nickname, $fighter ),
			'date_of_birth' => $this->field_status( 'date_of_birth', $suggested_dob, $fighter ),
			'birth_year' => $this->field_status( 'birth_year', null === $suggested_birth_year ? null : (string) $suggested_birth_year, $fighter ),
			'gender' => $this->field_status( 'gender', $suggested_gender, $fighter ),
			'nationality' => $this->nationality_field_status( $suggested_country, $born_location, $fighting_out_of, $fighter ),
			'born_location' => $this->field_status( 'born_location', $born_location, $fighter ),
			'fighting_out_of' => $this->field_status( 'fighting_out_of', $fighting_out_of, $fighter ),
			'weight_class' => $this->field_status( 'weight_class', $suggested_weight_class, $fighter ),
			'height' => $this->field_status( 'height', $suggested_height, $fighter ),
			'height_cm' => $this->field_status( 'height_cm', null === $suggested_height_cm ? null : (string) $suggested_height_cm, $fighter ),
			'last_weigh_in' => $this->field_status( 'last_weigh_in', $suggested_last_weigh_in, $fighter ),
			'reach' => $this->field_status( 'reach', $suggested_reach, $fighter ),
			'association' => $this->field_status( 'association', $suggested_association, $fighter ),
			'team' => $this->field_status( 'team', $suggested_team, $fighter ),
			'profile_image_url' => $this->field_status( 'profile_image_url', $suggested_image_url, $fighter ),
			'tapology_source_url' => $this->source_url_field_status( $source_url, $match['source_row'] ),
		);
		$field_statuses = $this->apply_provenance_protection_statuses( $field_statuses, $fighter );

		if ( '' !== (string) ( $gender_inference['warning'] ?? '' ) ) {
			$warnings[] = (string) $gender_inference['warning'];
		}

		foreach ( $field_statuses as $field => $status ) {
			if ( 'backend_column_missing' === $status ) {
				$warnings[] = 'backend_column_missing:' . $field;
			}
			if ( 'unsafe_ambiguous' === $status ) {
				$warnings[] = 'field_is_unsafe_or_ambiguous:' . $field;
			}
		}

		$enriched_record = $this->record_from_enrichment( $record );
		$canonical_record = $this->record_from_stats( $stats );
		$record_gap = $this->record_gap_indicator( $enriched_record, $canonical_record );
		if ( '' !== $record_gap ) {
			$warnings[] = $record_gap;
		}

		if ( isset( $fighter['date_of_birth'] ) && '' !== (string) $fighter['date_of_birth'] && null !== $suggested_dob && (string) $fighter['date_of_birth'] !== $suggested_dob ) {
			$warnings[] = 'canonical_dob_differs_from_enriched_dob';
		}
		if ( isset( $fighter['birth_year'] ) && '' !== (string) $fighter['birth_year'] && null !== $suggested_birth_year && (int) $fighter['birth_year'] !== $suggested_birth_year ) {
			$warnings[] = 'canonical_birth_year_differs_from_enriched_birth_year';
		}
		if ( isset( $fighter['weight_class'] ) && '' !== (string) $fighter['weight_class'] && null !== $suggested_weight_class && $this->normalize_weight_class( (string) $fighter['weight_class'] ) !== $this->normalize_weight_class( $suggested_weight_class ) ) {
			$warnings[] = 'canonical_weight_class_differs_from_enriched_weight_class';
		}

		$warnings = array_values( array_unique( array_filter( $warnings ) ) );

		return array(
			'profile_index' => $index,
			'source_fighter_id' => $source_fighter_id,
			'source_url' => $source_url,
			'profile_display_name' => $display_name,
			'matched_canonical_fighter_id' => $fighter ? (int) $fighter['id'] : 0,
			'matched_canonical_name' => $fighter ? (string) $fighter['display_name'] : '',
			'matched_planned_name' => (string) ( $match['planned_name'] ?? '' ),
			'profile_match_status' => (string) ( $match['profile_match_status'] ?? $match['match_type'] ),
			'match_type' => $match['match_type'],
			'current_nickname' => $fighter ? (string) ( $fighter['nickname'] ?? '' ) : '',
			'suggested_nickname' => $suggested_nickname ?: '',
			'current_dob' => $fighter ? (string) ( $fighter['date_of_birth'] ?? '' ) : '',
			'suggested_dob' => $suggested_dob ?: '',
			'current_birth_year' => $fighter ? (string) ( $fighter['birth_year'] ?? '' ) : '',
			'suggested_birth_year' => null === $suggested_birth_year ? '' : (string) $suggested_birth_year,
			'current_gender' => $fighter ? (string) ( $fighter['gender'] ?? '' ) : '',
			'suggested_gender' => $suggested_gender ?: '',
			'suggested_gender_source' => (string) ( $gender_inference['source'] ?? '' ),
			'suggested_gender_confidence' => (string) ( $gender_inference['confidence'] ?? '' ),
			'current_weight_class' => $fighter ? (string) ( $fighter['weight_class'] ?? '' ) : '',
			'suggested_weight_class' => $suggested_weight_class ?: '',
			'current_nationality' => $fighter ? (string) ( $fighter['nationality'] ?? '' ) : '',
			'suggested_country' => $suggested_country ?: '',
			'born_location' => $born_location ?: '',
			'fighting_out_of' => $fighting_out_of ?: '',
			'current_height' => $fighter ? (string) ( $fighter['height'] ?? '' ) : '',
			'suggested_height' => $suggested_height ?: '',
			'current_height_cm' => $fighter ? (string) ( $fighter['height_cm'] ?? '' ) : '',
			'suggested_height_cm' => null === $suggested_height_cm ? '' : (string) $suggested_height_cm,
			'current_last_weigh_in' => $fighter ? (string) ( $fighter['last_weigh_in'] ?? '' ) : '',
			'suggested_last_weigh_in' => $suggested_last_weigh_in ?: '',
			'suggested_reach' => $suggested_reach ?: '',
			'suggested_association' => $suggested_association ?: '',
			'suggested_team' => $suggested_team ?: '',
			'suggested_image_url' => $suggested_image_url ?: '',
			'current_tapology_source_url' => $match['source_row'] ? (string) ( $match['source_row']['source_url'] ?? '' ) : '',
			'canonical_stats_record' => $canonical_record['raw'],
			'enriched_pro_record' => (string) ( $record['pro_record_raw'] ?? $enriched_record['raw'] ),
			'record_gap_indicator' => $record_gap,
			'fight_history_rows' => count( $history ),
			'fight_history_opponent_url_rows' => $this->count_history_field( $history, 'opponent_url' ),
			'fight_history_event_url_rows' => $this->count_history_field( $history, 'event_url' ),
			'fight_history_bout_url_rows' => $this->count_history_field( $history, 'bout_url' ),
			'fight_history_prefight_record_rows' => $this->count_history_prefight_records( $history ),
			'image_available' => false,
			'completeness_score' => null === $completeness_score ? '' : (string) $completeness_score,
			'field_statuses' => $field_statuses,
			'warnings' => $warnings,
			'suggested_admin_action' => $this->suggested_admin_action( $match['match_type'], $field_statuses, $record_gap, $warnings ),
			'has_dob_suggestion' => null !== $suggested_dob || null !== $suggested_birth_year,
			'has_gender_suggestion' => null !== $suggested_gender,
			'has_weight_class_suggestion' => null !== $suggested_weight_class,
			'has_height_suggestion' => null !== $suggested_height || null !== $suggested_height_cm,
			'has_last_weigh_in_suggestion' => null !== $suggested_last_weigh_in,
			'has_pro_record' => '' !== (string) ( $record['pro_record_raw'] ?? '' ),
			'has_backend_column_missing' => in_array( 'backend_column_missing', $field_statuses, true ),
			'has_unsafe_ambiguous' => in_array( 'unsafe_ambiguous', $field_statuses, true ) || 'ambiguous_match' === $match['match_type'],
			'is_low_completeness' => null !== $completeness_score && $completeness_score < 8,
		);
	}

	private function find_profile_matches( array $profiles, string $source_fighter_id, string $source_url ): array {
		$matches = array();
		foreach ( $profiles as $index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$profile_source_fighter_id = trim( (string) ( $profile['source_fighter_id'] ?? '' ) );
			$profile_source_url = trim( (string) ( $profile['source_url'] ?? '' ) );
			$id_matches = '' !== $source_fighter_id && $profile_source_fighter_id === $source_fighter_id;
			$url_matches = '' !== $source_url && $this->normalize_url( $profile_source_url ) === $this->normalize_url( $source_url );

			if ( $id_matches || $url_matches ) {
				if ( '' !== $source_fighter_id && '' !== $source_url && ( ! $id_matches || ! $url_matches ) ) {
					continue;
				}

				$matches[] = array(
					'profile' => $profile,
					'index'   => (int) $index,
				);
			}
		}

		return $matches;
	}

	private function load_database_context(): void {
		global $wpdb;

		$this->source_id_map = array();
		$this->tapology_url_map = array();
		$this->exact_url_map = array();
		$this->planned_source_id_map = array();
		$this->planned_tapology_url_map = array();
		$this->planned_exact_url_map = array();
		$this->fighter_columns = $this->table_columns( $this->tables['fighters'] );

		$rows = $wpdb->get_results(
			"
			SELECT
				fs.*,
				f.display_name AS canonical_display_name,
				f.nickname,
				f.gender,
				f.date_of_birth,
				f.birth_year,
				f.nationality,
				f.weight_class,
				f.height,
				f.height_cm,
				f.last_weigh_in,
				st.wins,
				st.losses,
				st.draws,
				st.nc,
				st.pro_fights_count
			FROM {$this->tables['fighter_sources']} fs
			LEFT JOIN {$this->tables['fighters']} f ON f.id = fs.fighter_id
			LEFT JOIN {$this->tables['fighter_stats_current']} st ON st.fighter_id = f.id
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$source_fighter_id = (string) ( $row['source_fighter_id'] ?? '' );
			$source_type = (string) ( $row['source_type'] ?? '' );
			$source_url = (string) ( $row['source_url'] ?? '' );
			$normalized_url = $this->normalize_url( $source_url );

			if ( self::SOURCE === $source_type && '' !== $source_fighter_id ) {
				$this->source_id_map[ $source_fighter_id ][] = $row;
			}
			if ( self::SOURCE === $source_type && '' !== $normalized_url ) {
				$this->tapology_url_map[ $normalized_url ][] = $row;
			}
			if ( '' !== $source_url ) {
				$this->exact_url_map[ $source_url ][] = $row;
			}
		}
	}

	private function load_planned_import_context( array $results_dry_run ): void {
		$this->planned_source_id_map = array();
		$this->planned_tapology_url_map = array();
		$this->planned_exact_url_map = array();

		foreach ( (array) ( $results_dry_run['fighters'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$action = (string) ( $row['action'] ?? '' );
			if ( ! in_array( $action, array( 'create_provisional_candidate', 'create_provisional_with_url_only_identity' ), true ) ) {
				continue;
			}

			$source_fighter_id = trim( (string) ( $row['source_fighter_id'] ?? '' ) );
			$source_url = trim( (string) ( $row['source_url'] ?? '' ) );
			$normalized_url = $this->normalize_url( $source_url );
			$planned = array(
				'fighter_id' => 0,
				'source_type' => self::SOURCE,
				'source_fighter_id' => $source_fighter_id,
				'source_url' => $source_url,
				'identity_hash' => (string) ( $row['source_url_hash'] ?? '' ),
				'canonical_display_name' => (string) ( $row['source_name'] ?? '' ),
				'nickname' => '',
				'gender' => '',
				'date_of_birth' => '',
				'birth_year' => '',
				'nationality' => '',
				'weight_class' => 'unknown',
				'height' => '',
				'height_cm' => '',
				'last_weigh_in' => '',
				'planned_ref_key' => (string) ( $row['ref_key'] ?? '' ),
			);

			if ( '' !== $source_fighter_id ) {
				$this->planned_source_id_map[ $source_fighter_id ][] = $planned;
			}
			if ( '' !== $normalized_url ) {
				$this->planned_tapology_url_map[ $normalized_url ][] = $planned;
			}
			if ( '' !== $source_url ) {
				$this->planned_exact_url_map[ $source_url ][] = $planned;
			}
		}
	}

	private function match_profile( string $source_fighter_id, string $source_url ): array {
		$warnings = array();
		if ( '' !== $source_fighter_id && isset( $this->source_id_map[ $source_fighter_id ] ) ) {
			return $this->match_from_source_rows( $this->source_id_map[ $source_fighter_id ], 'exact_source_match', 'ambiguous source_fighter_id mapping', $warnings );
		}

		$normalized_url = $this->normalize_url( $source_url );
		if ( '' !== $normalized_url && isset( $this->tapology_url_map[ $normalized_url ] ) ) {
			return $this->match_from_source_rows( $this->tapology_url_map[ $normalized_url ], 'source_url_match', 'ambiguous normalized Tapology source_url mapping', $warnings );
		}

		if ( '' !== $source_url && isset( $this->exact_url_map[ $source_url ] ) ) {
			return $this->match_from_source_rows( $this->exact_url_map[ $source_url ], 'source_url_match', 'ambiguous exact source_url mapping', $warnings );
		}

		if ( '' !== $source_fighter_id && isset( $this->planned_source_id_map[ $source_fighter_id ] ) ) {
			return $this->match_from_planned_rows( $this->planned_source_id_map[ $source_fighter_id ], 'ambiguous planned source_fighter_id mapping', $warnings );
		}

		if ( '' !== $normalized_url && isset( $this->planned_tapology_url_map[ $normalized_url ] ) ) {
			return $this->match_from_planned_rows( $this->planned_tapology_url_map[ $normalized_url ], 'ambiguous planned normalized Tapology source_url mapping', $warnings );
		}

		if ( '' !== $source_url && isset( $this->planned_exact_url_map[ $source_url ] ) ) {
			return $this->match_from_planned_rows( $this->planned_exact_url_map[ $source_url ], 'ambiguous planned exact source_url mapping', $warnings );
		}

		$warnings[] = 'no_canonical_match';
		return array(
			'match_type' => 'no_canonical_match',
			'profile_match_status' => 'unmatched',
			'fighter' => null,
			'stats' => null,
			'source_row' => null,
			'warnings' => $warnings,
		);
	}

	private function match_from_source_rows( array $rows, string $match_type, string $ambiguous_warning, array $warnings ): array {
		$fighter_ids = array();
		foreach ( $rows as $row ) {
			$fighter_id = (int) ( $row['fighter_id'] ?? 0 );
			if ( $fighter_id > 0 ) {
				$fighter_ids[ $fighter_id ] = true;
			}
		}

		if ( 0 === count( $fighter_ids ) ) {
			$warnings[] = 'no_canonical_match';
			$warnings[] = 'source_mapping_has_no_canonical_fighter_id';
			return array(
				'match_type' => 'no_canonical_match',
				'fighter' => null,
				'stats' => null,
				'source_row' => $rows[0] ?? null,
				'warnings' => $warnings,
			);
		}

		if ( count( $fighter_ids ) !== 1 ) {
			$warnings[] = 'ambiguous_match';
			$warnings[] = $ambiguous_warning;
			return array(
				'match_type' => 'ambiguous_match',
				'fighter' => null,
				'stats' => null,
				'source_row' => $rows[0] ?? null,
				'warnings' => $warnings,
			);
		}

		$matched_fighter_id = (int) array_key_first( $fighter_ids );
		$row = $rows[0];
		foreach ( $rows as $candidate ) {
			if ( (int) ( $candidate['fighter_id'] ?? 0 ) === $matched_fighter_id ) {
				$row = $candidate;
				break;
			}
		}
		return array(
			'match_type' => $match_type,
			'profile_match_status' => 'matched_existing_canonical',
			'fighter' => $this->fighter_from_joined_source_row( $row ),
			'stats' => $this->stats_from_joined_source_row( $row ),
			'source_row' => $row,
			'warnings' => $warnings,
		);
	}

	private function match_from_planned_rows( array $rows, string $ambiguous_warning, array $warnings ): array {
		$planned_keys = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row['planned_ref_key'] ?? '' );
			if ( '' === $key ) {
				$key = (string) ( $row['source_fighter_id'] ?? '' ) . '|' . (string) ( $row['source_url'] ?? '' );
			}
			$planned_keys[ $key ] = true;
		}

		if ( count( $planned_keys ) !== 1 ) {
			$warnings[] = 'ambiguous_match';
			$warnings[] = $ambiguous_warning;
			return array(
				'match_type' => 'ambiguous_match',
				'profile_match_status' => 'unmatched',
				'fighter' => null,
				'stats' => null,
				'source_row' => $rows[0] ?? null,
				'warnings' => $warnings,
			);
		}

		$row = $rows[0];
		return array(
			'match_type' => 'matched_planned_import',
			'profile_match_status' => 'matched_planned_import',
			'fighter' => $this->planned_fighter_from_source_row( $row ),
			'stats' => null,
			'source_row' => $row,
			'planned_name' => (string) ( $row['canonical_display_name'] ?? '' ),
			'warnings' => $warnings,
		);
	}

	private function field_status( string $canonical_field, ?string $suggested_value, ?array $fighter ): string {
		if ( null === $suggested_value || '' === trim( $suggested_value ) ) {
			return 'not_available';
		}

		if ( in_array( $canonical_field, self::READ_ONLY_CONTEXT_FIELDS, true ) ) {
			return 'read_only_not_imported';
		}

		if ( ! isset( $this->fighter_columns[ $canonical_field ] ) ) {
			return 'backend_column_missing';
		}

		if ( 'weight_class' === $canonical_field && ! $this->suggested_weight_class_is_storable( $suggested_value ) ) {
			return 'not_available';
		}

		if ( ! $fighter ) {
			return 'unsafe_ambiguous';
		}

		$current = (string) ( $fighter[ $canonical_field ] ?? '' );
		if ( $this->is_empty_canonical_value( $canonical_field, $current ) ) {
			return 'canonical_empty_can_suggest';
		}

		if ( 'weight_class' === $canonical_field ) {
			return $this->normalize_weight_class( $current ) === $this->normalize_weight_class( $suggested_value )
				? 'already_same'
				: 'canonical_differs_source_suggestion';
		}

		return $this->normalize_compare( $current ) === $this->normalize_compare( $suggested_value )
			? 'already_same'
			: 'canonical_differs_source_suggestion';
	}

	private function is_empty_canonical_value( string $canonical_field, string $current ): bool {
		$current = trim( $current );
		if ( '' === $current ) {
			return true;
		}

		if ( 'weight_class' === $canonical_field && 'unknown' === $this->normalize_weight_class( $current ) ) {
			return true;
		}

		if ( 'gender' === $canonical_field && 'unknown' === strtolower( $current ) ) {
			return true;
		}

		if ( 'height_cm' === $canonical_field && '0' === $current ) {
			return true;
		}

		return 'birth_year' === $canonical_field && '0' === $current;
	}

	private function apply_provenance_protection_statuses( array $field_statuses, ?array $fighter ): array {
		if ( ! $fighter || empty( $fighter['id'] ) ) {
			return $field_statuses;
		}

		$fighter_id = (int) $fighter['id'];
		foreach ( self::PROVENANCE_PROTECTED_FIELDS as $field ) {
			$reason = $this->provenance->protected_reason( 'fighter', $fighter_id, $field );
			if ( null !== $reason ) {
				$field_statuses[ $field ] = $reason;
			}
		}

		return $field_statuses;
	}

	private function nationality_field_status( ?string $suggested_country, ?string $born_location, ?string $fighting_out_of, ?array $fighter ): string {
		if ( null !== $suggested_country && '' !== $suggested_country ) {
			return $this->field_status( 'nationality', $suggested_country, $fighter );
		}

		if ( null !== $born_location || null !== $fighting_out_of ) {
			return 'unsafe_ambiguous';
		}

		return 'not_available';
	}

	private function source_url_field_status( string $source_url, ?array $source_row ): string {
		if ( '' === $source_url ) {
			return 'not_available';
		}
		if ( ! $source_row ) {
			return 'unsafe_ambiguous';
		}

		$current = (string) ( $source_row['source_url'] ?? '' );
		if ( '' === $current ) {
			return 'canonical_empty_can_suggest';
		}

		return $this->normalize_url( $current ) === $this->normalize_url( $source_url )
			? 'already_same'
			: 'canonical_differs_source_suggestion';
	}

	private function record_from_enrichment( array $record ): array {
		foreach ( array( 'wins', 'losses', 'draws', 'no_contests' ) as $key ) {
			if ( ! isset( $record[ $key ] ) || ! is_numeric( $record[ $key ] ) ) {
				return array( 'raw' => (string) ( $record['pro_record_raw'] ?? '' ), 'complete' => false );
			}
		}

		return array(
			'raw' => sprintf( '%d-%d-%d-%d', (int) $record['wins'], (int) $record['losses'], (int) $record['draws'], (int) $record['no_contests'] ),
			'wins' => (int) $record['wins'],
			'losses' => (int) $record['losses'],
			'draws' => (int) $record['draws'],
			'nc' => (int) $record['no_contests'],
			'complete' => true,
		);
	}

	private function record_from_stats( ?array $stats ): array {
		if ( ! $stats ) {
			return array( 'raw' => '-', 'complete' => false );
		}

		return array(
			'raw' => sprintf( '%d-%d-%d-%d', (int) $stats['wins'], (int) $stats['losses'], (int) $stats['draws'], (int) $stats['nc'] ),
			'wins' => (int) $stats['wins'],
			'losses' => (int) $stats['losses'],
			'draws' => (int) $stats['draws'],
			'nc' => (int) $stats['nc'],
			'complete' => true,
		);
	}

	private function record_gap_indicator( array $enriched, array $canonical ): string {
		if ( empty( $enriched['complete'] ) || empty( $canonical['complete'] ) ) {
			return '';
		}

		foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $field ) {
			if ( (int) $enriched[ $field ] !== (int) $canonical[ $field ] ) {
				return 'record_gap_vs_canonical_stats';
			}
		}

		return '';
	}

	private function add_row_counts( array &$summary, array $row ): void {
		if ( 'exact_source_match' === $row['match_type'] ) {
			++$summary['profiles_matched_exact_source'];
			++$summary['profiles_matched_existing_canonical'];
		} elseif ( 'source_url_match' === $row['match_type'] ) {
			++$summary['profiles_matched_url'];
			++$summary['profiles_matched_existing_canonical'];
		} elseif ( 'matched_planned_import' === $row['match_type'] ) {
			++$summary['profiles_matched_planned_import'];
		} elseif ( 'ambiguous_match' === $row['match_type'] ) {
			++$summary['profiles_ambiguous'];
		} else {
			++$summary['profiles_unmatched'];
		}

		if ( '' === $row['source_fighter_id'] ) {
			++$summary['profiles_missing_source_fighter_id'];
		}
		if ( '' === $row['source_url'] ) {
			++$summary['profiles_missing_source_url'];
		}
		if ( '' !== $row['suggested_dob'] ) {
			++$summary['profiles_with_dob'];
		}
		if ( '' !== $row['suggested_birth_year'] ) {
			++$summary['profiles_with_birth_year'];
		}
		if ( ! empty( $row['has_gender_suggestion'] ) ) {
			++$summary['profiles_with_gender_inference'];
		}
		if ( in_array( 'unable_to_infer_gender_from_weight_class', (array) ( $row['warnings'] ?? array() ), true ) ) {
			++$summary['profiles_gender_cannot_infer'];
		}
		if ( '' !== $row['suggested_weight_class'] ) {
			++$summary['profiles_with_weight_class'];
		}
		if ( '' !== (string) ( $row['suggested_height'] ?? '' ) ) {
			++$summary['profiles_with_height'];
		}
		if ( '' !== (string) ( $row['suggested_height_cm'] ?? '' ) ) {
			++$summary['profiles_with_height_cm'];
		}
		if ( '' !== (string) ( $row['suggested_last_weigh_in'] ?? '' ) ) {
			++$summary['profiles_with_last_weigh_in'];
		}
		if ( $row['image_available'] ) {
			++$summary['profiles_with_image'];
		}
		if ( $row['has_pro_record'] ) {
			++$summary['profiles_with_pro_record'];
		}
		if ( $row['fight_history_rows'] > 0 ) {
			++$summary['profiles_with_fight_history'];
		}
		if ( '' !== $row['record_gap_indicator'] ) {
			++$summary['profiles_with_record_gap_vs_canonical_stats'];
		}

		foreach ( $row['field_statuses'] as $status ) {
			if ( 'canonical_empty_can_suggest' === $status ) {
				++$summary['fields_canonical_empty_can_suggest'];
			} elseif ( 'canonical_differs_source_suggestion' === $status ) {
				++$summary['fields_differs_source_suggestion'];
			} elseif ( 'backend_column_missing' === $status ) {
				++$summary['backend_column_missing_count'];
			} elseif ( 'unsafe_ambiguous' === $status ) {
				++$summary['unsafe_or_ambiguous_fields'];
			}
		}
	}

	private function prepare_profile_row_iteration( string $content, string $path, array $results_dry_run ): array {
		$data = $this->decode_payload( $content );
		$profiles = (array) $data['profiles'];

		$this->load_database_context();
		$this->load_planned_import_context( $results_dry_run );

		$summary = $this->empty_summary();
		$summary['schema_version'] = (string) $data['schema_version'];
		$summary['source'] = (string) ( $data['source'] ?? '' );
		$summary['run_id'] = (string) ( $data['run_id'] ?? '' );
		$summary['scraped_at'] = (string) ( $data['scraped_at'] ?? '' );
		$summary['enrichment_file'] = $path;
		$summary['profiles_total'] = count( $profiles );

		return array(
			'summary' => $summary,
			'iterator' => $this->yield_profile_rows( $profiles ),
		);
	}

	private function yield_profile_rows( array $profiles ): \Generator {
		foreach ( $profiles as $index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			yield $this->build_profile_row( $profile, (int) $index );
		}
	}

	private function filter_rows( array $rows, array $filters, string $search ): array {
		return array_values(
			array_filter(
				$rows,
				fn ( array $row ): bool => $this->row_matches( $row, $filters, $search )
			)
		);
	}

	private function row_matches( array $row, array $filters, string $search ): bool {
		$filters = array_values( array_intersect( $filters, self::filters() ) );
		$search = Sanitizer::normalize_name( $search );

		if ( '' !== $search ) {
			$haystack = Sanitizer::normalize_name(
				implode(
					' ',
					array(
						(string) ( $row['profile_display_name'] ?? '' ),
						(string) ( $row['source_fighter_id'] ?? '' ),
						(string) ( $row['matched_canonical_name'] ?? '' ),
					)
				)
			);
			if ( false === strpos( $haystack, $search ) ) {
				return false;
			}
		}

		foreach ( $filters as $filter ) {
			if ( 'matched' === $filter && ! in_array( (string) ( $row['match_type'] ?? '' ), array( 'exact_source_match', 'source_url_match', 'matched_planned_import' ), true ) ) {
				return false;
			}
			if ( 'unmatched' === $filter && 'no_canonical_match' !== (string) ( $row['match_type'] ?? '' ) ) {
				return false;
			}
			if ( 'ambiguous' === $filter && 'ambiguous_match' !== (string) ( $row['match_type'] ?? '' ) ) {
				return false;
			}
			if ( 'has_dob_suggestion' === $filter && empty( $row['has_dob_suggestion'] ) ) {
				return false;
			}
			if ( 'has_weight_class_suggestion' === $filter && empty( $row['has_weight_class_suggestion'] ) ) {
				return false;
			}
			if ( 'has_pro_record' === $filter && empty( $row['has_pro_record'] ) ) {
				return false;
			}
			if ( 'has_fight_history' === $filter && (int) ( $row['fight_history_rows'] ?? 0 ) <= 0 ) {
				return false;
			}
			if ( 'has_record_gap' === $filter && '' === (string) ( $row['record_gap_indicator'] ?? '' ) ) {
				return false;
			}
			if ( 'backend_column_missing' === $filter && empty( $row['has_backend_column_missing'] ) ) {
				return false;
			}
			if ( 'unsafe_ambiguous' === $filter && empty( $row['has_unsafe_ambiguous'] ) ) {
				return false;
			}
			if ( 'low_completeness' === $filter && empty( $row['is_low_completeness'] ) ) {
				return false;
			}
		}

		return true;
	}

	private function suggested_admin_action( string $match_type, array $field_statuses, string $record_gap, array $warnings ): string {
		if ( 'no_canonical_match' === $match_type ) {
			return 'no canonical match, review source mapping; do not auto-write';
		}
		if ( 'ambiguous_match' === $match_type ) {
			return 'ambiguous match, do not auto-write';
		}
		if ( in_array( 'backend_column_missing', $field_statuses, true ) ) {
			return 'backend column missing, cannot import this field yet; do not auto-write';
		}
		if ( 'record_gap_vs_canonical_stats' === $record_gap ) {
			return 'compare enriched record with canonical stats; do not auto-write';
		}
		if ( in_array( 'canonical_differs_source_suggestion', $field_statuses, true ) ) {
			if ( 'canonical_differs_source_suggestion' === ( $field_statuses['date_of_birth'] ?? '' ) || 'canonical_differs_source_suggestion' === ( $field_statuses['birth_year'] ?? '' ) ) {
				return 'review DOB suggestion; do not auto-write';
			}
			if ( 'canonical_differs_source_suggestion' === ( $field_statuses['weight_class'] ?? '' ) ) {
				return 'review weight class suggestion; do not auto-write';
			}
			return 'review source suggestion against canonical field; do not auto-write';
		}
		if ( 'canonical_empty_can_suggest' === ( $field_statuses['date_of_birth'] ?? '' ) || 'canonical_empty_can_suggest' === ( $field_statuses['birth_year'] ?? '' ) ) {
			return 'review DOB suggestion; do not auto-write';
		}
		if ( 'canonical_empty_can_suggest' === ( $field_statuses['weight_class'] ?? '' ) ) {
			return 'review weight class suggestion; do not auto-write';
		}
		if ( ! empty( $warnings ) ) {
			return 'review warnings; do not auto-write';
		}

		return 'safe candidate for later manual profile enrichment; do not auto-write';
	}

	private function empty_summary(): array {
		return array(
			'schema_version' => '',
			'source' => '',
			'run_id' => '',
			'scraped_at' => '',
			'enrichment_file' => '',
			'profiles_total' => 0,
			'profiles_matched_exact_source' => 0,
			'profiles_matched_url' => 0,
			'profiles_matched_existing_canonical' => 0,
			'profiles_matched_planned_import' => 0,
			'profiles_unmatched' => 0,
			'profiles_ambiguous' => 0,
			'profiles_missing_source_fighter_id' => 0,
			'profiles_missing_source_url' => 0,
			'profiles_with_dob' => 0,
			'profiles_with_birth_year' => 0,
			'profiles_with_gender_inference' => 0,
			'profiles_gender_cannot_infer' => 0,
			'profiles_with_weight_class' => 0,
			'profiles_with_height' => 0,
			'profiles_with_height_cm' => 0,
			'profiles_with_last_weigh_in' => 0,
			'profiles_with_image' => 0,
			'profiles_with_pro_record' => 0,
			'profiles_with_fight_history' => 0,
			'profiles_with_record_gap_vs_canonical_stats' => 0,
			'fields_canonical_empty_can_suggest' => 0,
			'fields_differs_source_suggestion' => 0,
			'backend_column_missing_count' => 0,
			'unsafe_or_ambiguous_fields' => 0,
			'profiles_not_safe_for_auto_write' => 0,
			'matching_quality_good_enough_for_later_import' => 'no',
			'matching_quality_reason' => '',
		);
	}

	private function decode_payload( string $content ): array {
		$data = json_decode( $content, true, 512, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Invalid enrichment JSON: ' . json_last_error_msg() );
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid enrichment JSON: root value must be an object.' );
		}

		$schema_version = (string) ( $data['schema_version'] ?? '' );
		if ( self::SCHEMA_VERSION !== $schema_version ) {
			throw new \RuntimeException( 'Unsupported fighter profile enrichment schema_version: ' . ( '' === $schema_version ? 'missing' : $schema_version ) . '.' );
		}

		$profiles = $data['profiles'] ?? null;
		if ( ! is_array( $profiles ) ) {
			throw new \RuntimeException( 'Invalid enrichment JSON: profiles must be an array.' );
		}

		return $data;
	}

	private function matching_quality_answer( array $summary ): string {
		$total = (int) ( $summary['profiles_total'] ?? 0 );
		if ( $total <= 0 ) {
			return 'no';
		}

		$matched = (int) $summary['profiles_matched_exact_source'] + (int) $summary['profiles_matched_url'];
		$matched += (int) ( $summary['profiles_matched_planned_import'] ?? 0 );
		$matched_rate = $matched / $total;

		return $matched_rate >= 0.9 && (int) $summary['profiles_ambiguous'] === 0 ? 'yes' : 'no';
	}

	private function matching_quality_reason( array $summary ): string {
		$total = max( 1, (int) ( $summary['profiles_total'] ?? 0 ) );
		$matched = (int) $summary['profiles_matched_exact_source'] + (int) $summary['profiles_matched_url'];
		$matched += (int) ( $summary['profiles_matched_planned_import'] ?? 0 );
		$matched_percent = round( ( $matched / $total ) * 100, 1 );

		if ( 'yes' === $this->matching_quality_answer( $summary ) ) {
			return 'Yes for a later guarded import design: ' . $matched_percent . '% matched by existing canonical or planned import source ID/URL and no ambiguous matches were detected. Field writes still require admin review.';
		}

		return 'No: only ' . $matched_percent . '% matched by existing canonical or planned import source ID/URL, or ambiguous matches were detected. Improve source mappings before any later import phase.';
	}

	private function table_columns( string $table ): array {
		global $wpdb;

		$columns = array();
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$columns[ (string) $row['Field'] ] = true;
			}
		}

		return $columns;
	}

	private function fighter_from_joined_source_row( array $row ): ?array {
		$fighter_id = (int) ( $row['fighter_id'] ?? 0 );
		if ( $fighter_id <= 0 ) {
			return null;
		}

		return array(
			'id' => $fighter_id,
			'display_name' => (string) ( $row['canonical_display_name'] ?? '' ),
			'nickname' => (string) ( $row['nickname'] ?? '' ),
			'gender' => (string) ( $row['gender'] ?? '' ),
			'date_of_birth' => (string) ( $row['date_of_birth'] ?? '' ),
			'birth_year' => (string) ( $row['birth_year'] ?? '' ),
			'nationality' => (string) ( $row['nationality'] ?? '' ),
			'weight_class' => (string) ( $row['weight_class'] ?? '' ),
			'height' => (string) ( $row['height'] ?? '' ),
			'height_cm' => (string) ( $row['height_cm'] ?? '' ),
			'last_weigh_in' => (string) ( $row['last_weigh_in'] ?? '' ),
		);
	}

	private function planned_fighter_from_source_row( array $row ): array {
		return array(
			'id' => 0,
			'display_name' => (string) ( $row['canonical_display_name'] ?? '' ),
			'nickname' => '',
			'gender' => '',
			'date_of_birth' => '',
			'birth_year' => '',
			'nationality' => '',
			'weight_class' => 'unknown',
			'height' => '',
			'height_cm' => '',
			'last_weigh_in' => '',
		);
	}

	private function stats_from_joined_source_row( array $row ): ?array {
		if ( ! isset( $row['wins'] ) || null === $row['wins'] ) {
			return null;
		}

		return array(
			'wins' => (int) $row['wins'],
			'losses' => (int) $row['losses'],
			'draws' => (int) $row['draws'],
			'nc' => (int) $row['nc'],
		);
	}

	private function suggested_birth_year( array $summary, ?string $suggested_dob ): ?int {
		if ( null !== $suggested_dob ) {
			return (int) substr( $suggested_dob, 0, 4 );
		}

		$year = Sanitizer::valid_year_or_null( $summary['birth_year'] ?? null );
		return null === $year ? null : (int) $year;
	}

	private function suggested_height_cm( array $summary ): ?int {
		if ( ! isset( $summary['height_cm'] ) || ! is_numeric( $summary['height_cm'] ) ) {
			return null;
		}

		$height_cm = (int) $summary['height_cm'];
		return $height_cm >= 100 && $height_cm <= 260 ? $height_cm : null;
	}

	private function valid_date( $value ): ?string {
		$value = $this->string_or_null( $value );
		if ( null === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		$parts = explode( '-', $value );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : null;
	}

	private function string_or_null( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	private function count_history_field( array $history, string $field ): int {
		$count = 0;
		foreach ( $history as $row ) {
			if ( is_array( $row ) && ! empty( $row[ $field ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function count_history_prefight_records( array $history ): int {
		$count = 0;
		foreach ( $history as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['prefight_record_raw'] ) || ! empty( $row['opponent_prefight_record_raw'] ) || ! empty( $row['opponent_record_raw'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function normalize_compare( string $value ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	}

	private function normalize_weight_class( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( "women's ", 'womens ', 'women ' ), 'women_', $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( (string) $value, '_' );
	}

	private function canonical_weight_class_suggestion( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$normalized = $this->normalize_weight_class( $value );
		$aliases = array(
			'strawweight' => 'women_strawweight',
			'light_heavy' => 'light_heavyweight',
		);
		if ( isset( $aliases[ $normalized ] ) ) {
			$normalized = $aliases[ $normalized ];
		}

		return in_array( $normalized, Sanitizer::WEIGHT_CLASSES, true ) && 'unknown' !== $normalized ? $normalized : null;
	}

	private function suggested_weight_class_is_storable( string $value ): bool {
		$suggestion = $this->canonical_weight_class_suggestion( $value );
		return null !== $suggestion && in_array( $this->normalize_weight_class( $suggestion ), Sanitizer::WEIGHT_CLASSES, true );
	}

	private function normalize_url( string $url ): string {
		return TapologyFighterUrl::normalize( $url );
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
