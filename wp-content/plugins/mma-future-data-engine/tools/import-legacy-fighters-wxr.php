<?php
/**
 * One-time local legacy WXR fighter importer for MMA Future Data Engine.
 *
 * CLI:
 * php tools/import-legacy-fighters-wxr.php --file="C:\path\export.xml" --dry-run
 * php tools/import-legacy-fighters-wxr.php --file="C:\path\export.xml" --import --yes --user-id=1
 *
 * Browser:
 * Open this file directly while logged in as an admin. Actual import requires a nonce.
 */

declare(strict_types=1);

$mmaf_wxr_wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $mmaf_wxr_wp_load ) ) {
	fwrite( STDERR, "Could not locate wp-load.php.\n" );
	exit( 1 );
}

require_once $mmaf_wxr_wp_load;

if ( ! class_exists( '\MMAF\DataEngine\Repositories\FighterRepository' ) ) {
	require_once dirname( __DIR__ ) . '/mma-future-data-engine.php';
}

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\FighterPostSyncService;
use MMAF\DataEngine\Support\DateTime;
use MMAF\DataEngine\Support\Sanitizer;

final class MMAF_Legacy_Fighter_WXR_Importer {
	private const SOURCE_TYPE = 'legacy_wp_export';
	private const PROVENANCE_FIELDS = array(
		'display_name',
		'nickname',
		'gender',
		'date_of_birth',
		'birth_year',
		'nationality',
		'weight_class',
		'status',
		'rankability_status',
		'is_public',
		'is_rankable',
		'in_ufc',
	);
	private const STATS_FIELDS = array(
		'results_details_fighter_wins',
		'results_details_fighter_losses',
		'results_details_fighter_finishes',
		'results_details_fighter_combined_opponent_score',
	);
	private const WEIGHT_CLASS_MAP = array(
		'flyweight'             => 'flyweight',
		'bantamweight'          => 'bantamweight',
		'featherweight'         => 'featherweight',
		'lightweight'           => 'lightweight',
		'welterweight'          => 'welterweight',
		'middleweight'          => 'middleweight',
		'light-heavyweight'     => 'light_heavyweight',
		'heavyweight'           => 'heavyweight',
		'womens-strawweight'    => 'women_strawweight',
		'womens-flyweight'      => 'women_flyweight',
		'womens-bantamweight'   => 'women_bantamweight',
		'womens-featherweight'  => 'women_featherweight',
	);
	private const AMBIGUOUS_WEIGHT_CLASSES = array(
		'featherweight-lightweight',
	);

	private FighterRepository $fighters;
	private FighterSourceRepository $sources;
	private FighterPostSyncService $post_sync;
	private AuditLogService $audit_log;
	private array $tables;
	private array $attachment_urls = array();

	public function __construct() {
		$this->fighters  = new FighterRepository();
		$this->sources   = new FighterSourceRepository();
		$this->post_sync = new FighterPostSyncService( $this->fighters );
		$this->audit_log = new AuditLogService();
		$this->tables    = Schema::table_names();
	}

	public function run( string $file, bool $dry_run, int $actor_user_id = 0, int $limit = 0 ): array {
		$report = $this->empty_report( $dry_run );
		$xml    = $this->load_xml( $file );
		$items  = $xml->channel->item ?? array();
		$ns     = $xml->getNamespaces( true );

		$this->index_attachments( $items, $ns );

		foreach ( $items as $item ) {
			$report['total_xml_items']++;
			$wp = $item->children( $ns['wp'] );

			if ( 'fighter' !== (string) $wp->post_type ) {
				continue;
			}

			$report['fighter_items_found']++;

			if ( $limit > 0 && $report['fighter_items_found'] > $limit ) {
				break;
			}

			$candidate = $this->map_item( $item, $ns );
			$this->add_candidate_counts( $report, $candidate );

			if ( '' === $candidate['fighter']['display_name'] ) {
				$candidate['warnings'][] = 'Missing display name; row skipped.';
				$report['skipped_invalid_rows']++;
				$this->add_report_warning( $report, $candidate );
				continue;
			}

			$duplicate = $this->find_duplicate( $candidate );
			if ( null !== $duplicate ) {
				$report['duplicate_skipped_candidates']++;
				$report['duplicates'][] = $duplicate;
				$this->add_report_warning( $report, $candidate, $duplicate['reason'] );
				continue;
			}

			$report['valid_import_candidates']++;
			$this->add_report_warning( $report, $candidate );

			if ( $dry_run ) {
				continue;
			}

			$this->import_candidate( $candidate, $actor_user_id, $report );
		}

		$report['warnings_count'] = count( $report['warnings'] );

		if ( ! $dry_run ) {
			$this->store_summary( $report );
		}

		return $report;
	}

	private function load_xml( string $file ): \SimpleXMLElement {
		if ( '' === $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			throw new \InvalidArgumentException( 'WXR file is missing or not readable.' );
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			throw new \RuntimeException( 'Could not read WXR file.' );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( ltrim( $raw ) );

		if ( ! $xml instanceof \SimpleXMLElement ) {
			$errors = array_map(
				static fn( \LibXMLError $error ): string => trim( $error->message ),
				libxml_get_errors()
			);
			libxml_clear_errors();
			throw new \RuntimeException( 'Could not parse WXR XML: ' . implode( '; ', array_slice( $errors, 0, 3 ) ) );
		}

		return $xml;
	}

	private function index_attachments( $items, array $ns ): void {
		foreach ( $items as $item ) {
			$wp = $item->children( $ns['wp'] );
			if ( 'attachment' !== (string) $wp->post_type ) {
				continue;
			}

			$old_id = (int) $wp->post_id;
			$url    = (string) $wp->attachment_url;
			if ( $old_id > 0 && '' !== $url ) {
				$this->attachment_urls[ $old_id ] = $url;
			}
		}
	}

	private function map_item( \SimpleXMLElement $item, array $ns ): array {
		$wp       = $item->children( $ns['wp'] );
		$old_id   = (int) $wp->post_id;
		$meta     = $this->extract_meta( $wp );
		$terms    = $this->extract_fighter_categories( $item );
		$warnings = array();

		$date = $this->parse_legacy_date( $meta['essential_details_fighter_date_of_birth'] ?? '' );
		if ( ! empty( $date['warning'] ) ) {
			$warnings[] = $date['warning'];
		}

		$weight = $this->map_weight_class( $terms );
		if ( ! empty( $weight['warning'] ) ) {
			$warnings[] = $weight['warning'];
		}

		$gender       = $this->infer_gender( $weight['weight_class'] );
		$thumbnail_id = isset( $meta['_thumbnail_id'] ) ? absint( $meta['_thumbnail_id'] ) : 0;
		if ( $thumbnail_id > 0 ) {
			$warnings[] = isset( $this->attachment_urls[ $thumbnail_id ] )
				? 'Thumbnail reference found but image mapping is not performed by this MVP importer.'
				: 'Thumbnail reference found without a matching WXR attachment; image must be selected manually.';
		}

		return array(
			'legacy'       => array(
				'old_post_id'   => $old_id,
				'post_slug'     => (string) $wp->post_name,
				'post_status'   => (string) $wp->status,
				'post_date'     => (string) $wp->post_date,
				'link'          => (string) $item->link,
				'thumbnail_id'  => $thumbnail_id,
				'thumbnail_url' => $thumbnail_id > 0 ? ( $this->attachment_urls[ $thumbnail_id ] ?? null ) : null,
				'terms'         => $terms,
				'stats'         => $this->extract_stats( $meta ),
			),
			'fighter'      => array(
				'wp_post_id'         => null,
				'display_name'       => sanitize_text_field( (string) $item->title ),
				'nickname'           => Sanitizer::text_or_null( $meta['essential_details_fighter_nikname'] ?? '' ),
				'normalized_name'    => Sanitizer::normalize_name( sanitize_text_field( (string) $item->title ) ),
				'gender'             => $gender,
				'date_of_birth'      => $date['date_of_birth'],
				'birth_year'         => $date['birth_year'],
				'nationality'        => Sanitizer::text_or_null( $meta['essential_details_fighter_nationality'] ?? '' ),
				'weight_class'       => $weight['weight_class'],
				'status'             => 'provisional',
				'rankability_status' => 'pending_review',
				'is_public'          => 'publish' === (string) $wp->status ? 1 : 0,
				'is_rankable'        => 0,
				'in_ufc'             => 0,
				'deleted_soft'       => 0,
			),
			'warnings'     => $warnings,
			'date_kind'    => $date['kind'],
			'weight_kind'  => $weight['kind'],
		);
	}

	private function extract_meta( \SimpleXMLElement $wp ): array {
		$meta = array();

		foreach ( $wp->postmeta as $postmeta ) {
			$key = (string) $postmeta->meta_key;
			if ( '' === $key || str_starts_with( $key, '_' ) && '_thumbnail_id' !== $key ) {
				continue;
			}

			$meta[ $key ] = (string) $postmeta->meta_value;
		}

		return $meta;
	}

	private function extract_fighter_categories( \SimpleXMLElement $item ): array {
		$terms = array();

		foreach ( $item->category as $category ) {
			$attrs = $category->attributes();
			if ( 'fighter-category' !== (string) $attrs['domain'] ) {
				continue;
			}

			$slug = sanitize_title( (string) $attrs['nicename'] );
			if ( '' !== $slug ) {
				$terms[] = $slug;
			}
		}

		return array_values( array_unique( $terms ) );
	}

	private function extract_stats( array $meta ): array {
		$stats = array();

		foreach ( self::STATS_FIELDS as $field ) {
			if ( isset( $meta[ $field ] ) && '' !== trim( (string) $meta[ $field ] ) ) {
				$stats[ $field ] = trim( (string) $meta[ $field ] );
			}
		}

		return $stats;
	}

	private function parse_legacy_date( string $raw ): array {
		$value = trim( $raw );

		if ( '' === $value ) {
			return array(
				'date_of_birth' => null,
				'birth_year'    => null,
				'kind'          => 'empty',
				'warning'       => null,
			);
		}

		if ( preg_match( '/^\d{4}$/', $value ) ) {
			$year = Sanitizer::valid_year_or_null( $value );
			return array(
				'date_of_birth' => null,
				'birth_year'    => $year,
				'kind'          => null === $year ? 'invalid' : 'year_only',
				'warning'       => null === $year ? 'Invalid birth year: ' . $value : null,
			);
		}

		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?$/', $value, $matches ) ) {
			$day   = (int) $matches[1];
			$month = (int) $matches[2];
			$year  = (int) $matches[3];

			if ( checkdate( $month, $day, $year ) ) {
				return array(
					'date_of_birth' => sprintf( '%04d-%02d-%02d', $year, $month, $day ),
					'birth_year'    => $year,
					'kind'          => 'exact',
					'warning'       => null,
				);
			}
		}

		return array(
			'date_of_birth' => null,
			'birth_year'    => null,
			'kind'          => 'invalid',
			'warning'       => 'Invalid legacy date of birth: ' . $value,
		);
	}

	private function map_weight_class( array $terms ): array {
		$mapped = array();

		foreach ( $terms as $term ) {
			if ( in_array( $term, self::AMBIGUOUS_WEIGHT_CLASSES, true ) ) {
				return array(
					'weight_class' => 'unknown',
					'kind'         => 'ambiguous',
					'warning'      => 'Ambiguous fighter-category weight class: ' . $term,
				);
			}

			if ( isset( self::WEIGHT_CLASS_MAP[ $term ] ) ) {
				$mapped[] = self::WEIGHT_CLASS_MAP[ $term ];
			}
		}

		$mapped = array_values( array_unique( $mapped ) );
		if ( 1 === count( $mapped ) ) {
			return array(
				'weight_class' => $mapped[0],
				'kind'         => 'mapped',
				'warning'      => null,
			);
		}

		if ( count( $mapped ) > 1 ) {
			return array(
				'weight_class' => 'unknown',
				'kind'         => 'ambiguous',
				'warning'      => 'Multiple fighter-category weight classes found: ' . implode( ', ', $terms ),
			);
		}

		return array(
			'weight_class' => 'unknown',
			'kind'         => 'unknown',
			'warning'      => empty( $terms ) ? 'No fighter-category weight class found.' : 'Unmapped fighter-category weight class: ' . implode( ', ', $terms ),
		);
	}

	private function infer_gender( string $weight_class ): ?string {
		if ( str_starts_with( $weight_class, 'women_' ) ) {
			return 'female';
		}

		return 'unknown' === $weight_class ? null : 'male';
	}

	private function find_duplicate( array $candidate ): ?array {
		global $wpdb;

		$source_id = $this->legacy_source_id( (int) $candidate['legacy']['old_post_id'] );
		$source    = $this->sources->find_by_source( self::SOURCE_TYPE, $source_id );
		if ( $source ) {
			return array(
				'reason'              => 'Existing legacy source mapping.',
				'existing_fighter_id' => (int) $source['fighter_id'],
				'old_post_id'         => (int) $candidate['legacy']['old_post_id'],
			);
		}

		$fighter = $candidate['fighter'];
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, display_name, normalized_name, date_of_birth, birth_year FROM {$this->tables['fighters']} WHERE normalized_name = %s OR display_name = %s LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fighter['normalized_name'],
				$fighter['display_name']
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$reason = 'Existing canonical fighter name match; manual review required.';
			if ( ! empty( $fighter['date_of_birth'] ) && $fighter['date_of_birth'] === (string) $row['date_of_birth'] ) {
				$reason = 'Existing canonical fighter with matching normalized name and date of birth.';
			} elseif ( ! empty( $fighter['birth_year'] ) && (int) $fighter['birth_year'] === (int) $row['birth_year'] ) {
				$reason = 'Existing canonical fighter with matching normalized name and birth year.';
			}

			return array(
				'reason'              => $reason,
				'existing_fighter_id' => (int) $row['id'],
				'old_post_id'         => (int) $candidate['legacy']['old_post_id'],
				'display_name'        => $fighter['display_name'],
			);
		}

		return null;
	}

	private function import_candidate( array $candidate, int $actor_user_id, array &$report ): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$fighter_id = $this->fighters->insert( $candidate['fighter'] );
			$fighter    = $this->fighters->find( $fighter_id );
			if ( ! $fighter ) {
				throw new \RuntimeException( 'Could not reload created fighter.' );
			}

			$post_id = $this->post_sync->sync( $fighter_id, $fighter );
			$fighter['wp_post_id'] = $post_id;

			$this->create_source_mapping( $fighter_id, $candidate );
			$source_rows = 1;

			$provenance_rows = $this->write_legacy_provenance( $fighter_id, $candidate['fighter'], $candidate['legacy'], $actor_user_id );

			$after = $this->fighters->find( $fighter_id );
			$after_with_summary = $after ?: $fighter;
			$after_with_summary['_legacy_source_summary'] = $this->legacy_summary( $candidate );

			$this->audit_log->write(
				'legacy_fighter_imported',
				'fighter',
				$fighter_id,
				null,
				$after_with_summary,
				'Legacy WXR fighter import',
				$actor_user_id
			);

			$wpdb->query( 'COMMIT' );

			$report['imported_count']++;
			$report['linked_wp_posts_created']++;
			$report['source_mappings_created'] += $source_rows;
			$report['provenance_rows_created'] += $provenance_rows;
			$report['audit_rows_created']++;

			if ( ! empty( $candidate['legacy']['thumbnail_id'] ) ) {
				$report['images_skipped']++;
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$report['skipped_invalid_rows']++;
			$report['warnings'][] = array(
				'old_post_id'  => (int) $candidate['legacy']['old_post_id'],
				'display_name' => $candidate['fighter']['display_name'],
				'warnings'     => array( 'Import failed: ' . $error->getMessage() ),
			);
		}
	}

	private function create_source_mapping( int $fighter_id, array $candidate ): void {
		$legacy = $candidate['legacy'];

		$this->sources->upsert_for_fighter(
			$fighter_id,
			array(
				'source_type'       => self::SOURCE_TYPE,
				'source_fighter_id' => $this->legacy_source_id( (int) $legacy['old_post_id'] ),
				'source_numeric_id' => (string) $legacy['old_post_id'],
				'source_url'        => $legacy['link'] ?: null,
				'source_slug'       => $legacy['post_slug'] ?: null,
				'confidence'        => 100,
				'is_verified'       => 1,
				'is_primary'        => 1,
			)
		);
	}

	private function write_legacy_provenance( int $fighter_id, array $fighter, array $legacy, int $actor_user_id ): int {
		global $wpdb;

		$now   = DateTime::mysql_now();
		$count = 0;

		foreach ( self::PROVENANCE_FIELDS as $field ) {
			$value = $fighter[ $field ] ?? null;
			$wpdb->insert(
				$this->tables['field_provenance'],
				array(
					'entity_type'   => 'fighter',
					'entity_id'     => $fighter_id,
					'field_name'    => $field,
					'source_type'   => self::SOURCE_TYPE,
					'source_id'     => $this->legacy_source_id( (int) $legacy['old_post_id'] ),
					'value_hash'    => hash( 'sha256', $this->normalize_value( $value ) ),
					'locked'        => 0,
					'verified_by'   => $actor_user_id > 0 ? $actor_user_id : null,
					'verified_at'   => $now,
					'first_seen_at' => $now,
					'last_seen_at'  => $now,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
			$count++;
		}

		return $count;
	}

	private function store_summary( array $report ): void {
		global $wpdb;

		$wpdb->replace(
			$this->tables['system_state'],
			array(
				'state_key'   => 'last_legacy_fighter_import_summary',
				'state_value' => wp_json_encode( $report ),
				'autoload'    => 'no',
				'updated_at'  => DateTime::mysql_now(),
			)
		);
	}

	private function legacy_source_id( int $old_post_id ): string {
		return 'legacy_fighter_post_' . $old_post_id;
	}

	private function legacy_summary( array $candidate ): array {
		return array(
			'source_type'       => self::SOURCE_TYPE,
			'source_fighter_id' => $this->legacy_source_id( (int) $candidate['legacy']['old_post_id'] ),
			'old_post_id'       => (int) $candidate['legacy']['old_post_id'],
			'post_slug'         => $candidate['legacy']['post_slug'],
			'post_status'       => $candidate['legacy']['post_status'],
			'old_stats_seen'    => array_keys( $candidate['legacy']['stats'] ),
			'thumbnail_id'      => $candidate['legacy']['thumbnail_id'],
		);
	}

	private function normalize_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return trim( strtolower( (string) $value ) );
	}

	private function add_candidate_counts( array &$report, array $candidate ): void {
		if ( 'exact' === $candidate['date_kind'] ) {
			$report['exact_dob_count']++;
		} elseif ( 'year_only' === $candidate['date_kind'] ) {
			$report['birth_year_only_count']++;
		} elseif ( 'invalid' === $candidate['date_kind'] ) {
			$report['invalid_dob_count']++;
		}

		if ( 'mapped' === $candidate['weight_kind'] ) {
			$report['mapped_weight_classes_count']++;
		} elseif ( in_array( $candidate['weight_kind'], array( 'unknown', 'ambiguous' ), true ) ) {
			$report['ambiguous_unknown_weight_classes_count']++;
		}

		if ( 'male' === $candidate['fighter']['gender'] ) {
			$report['inferred_male_count']++;
		} elseif ( 'female' === $candidate['fighter']['gender'] ) {
			$report['inferred_female_count']++;
		}

		if ( null !== $candidate['fighter']['nickname'] ) {
			$report['nicknames_found']++;
		}

		if ( ! empty( $candidate['legacy']['thumbnail_id'] ) ) {
			$report['thumbnail_references_found']++;
		}

		if ( ! empty( $candidate['legacy']['stats'] ) ) {
			$report['old_stats_fields_found']++;
		}
	}

	private function add_report_warning( array &$report, array $candidate, string $extra = '' ): void {
		$warnings = $candidate['warnings'];
		if ( '' !== $extra ) {
			$warnings[] = $extra;
		}

		if ( empty( $warnings ) ) {
			return;
		}

		$report['warnings'][] = array(
			'old_post_id'  => (int) $candidate['legacy']['old_post_id'],
			'display_name' => $candidate['fighter']['display_name'],
			'warnings'     => $warnings,
		);
	}

	private function empty_report( bool $dry_run ): array {
		return array(
			'mode'                                   => $dry_run ? 'dry-run' : 'import',
			'total_xml_items'                        => 0,
			'fighter_items_found'                    => 0,
			'valid_import_candidates'                => 0,
			'duplicate_skipped_candidates'           => 0,
			'skipped_invalid_rows'                   => 0,
			'exact_dob_count'                        => 0,
			'birth_year_only_count'                  => 0,
			'invalid_dob_count'                      => 0,
			'mapped_weight_classes_count'            => 0,
			'ambiguous_unknown_weight_classes_count' => 0,
			'inferred_male_count'                    => 0,
			'inferred_female_count'                  => 0,
			'nicknames_found'                        => 0,
			'thumbnail_references_found'             => 0,
			'old_stats_fields_found'                 => 0,
			'imported_count'                         => 0,
			'linked_wp_posts_created'                => 0,
			'source_mappings_created'                => 0,
			'provenance_rows_created'                => 0,
			'audit_rows_created'                     => 0,
			'images_mapped'                          => 0,
			'images_skipped'                         => 0,
			'official_stats_imported'                => 0,
			'warnings_count'                         => 0,
			'warnings'                               => array(),
			'duplicates'                             => array(),
		);
	}
}

function mmaf_legacy_wxr_parse_cli_args( array $argv ): array {
	$args = array(
		'file'    => '',
		'dry_run' => true,
		'yes'     => false,
		'user_id' => 0,
		'limit'   => 0,
	);

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( str_starts_with( $arg, '--file=' ) ) {
			$args['file'] = substr( $arg, 7 );
		} elseif ( str_starts_with( $arg, '--user-id=' ) ) {
			$args['user_id'] = absint( substr( $arg, 10 ) );
		} elseif ( str_starts_with( $arg, '--limit=' ) ) {
			$args['limit'] = absint( substr( $arg, 8 ) );
		} elseif ( '--import' === $arg ) {
			$args['dry_run'] = false;
		} elseif ( '--dry-run' === $arg ) {
			$args['dry_run'] = true;
		} elseif ( '--yes' === $arg ) {
			$args['yes'] = true;
		} elseif ( ! str_starts_with( $arg, '--' ) && '' === $args['file'] ) {
			$args['file'] = $arg;
		}
	}

	return $args;
}

function mmaf_legacy_wxr_render_report( array $report ): void {
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}

if ( 'cli' === PHP_SAPI ) {
	$args = mmaf_legacy_wxr_parse_cli_args( $argv );

	if ( '' === $args['file'] ) {
		fwrite( STDERR, "Usage: php tools/import-legacy-fighters-wxr.php --file=\"C:\\path\\export.xml\" --dry-run\n" );
		fwrite( STDERR, "Import: php tools/import-legacy-fighters-wxr.php --file=\"C:\\path\\export.xml\" --import --yes --user-id=1\n" );
		exit( 1 );
	}

	if ( ! $args['dry_run'] && ! $args['yes'] ) {
		fwrite( STDERR, "Actual import writes canonical fighters, WP posts, source mappings, provenance, and audit logs. Back up the database first, then pass --yes.\n" );
		exit( 1 );
	}

	$importer = new MMAF_Legacy_Fighter_WXR_Importer();
	mmaf_legacy_wxr_render_report( $importer->run( $args['file'], $args['dry_run'], $args['user_id'], $args['limit'] ) );
	exit;
}

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You must be logged in as an administrator to run this migration tool.', 'mma-future-data-engine' ) );
}

$default_file = 'C:/Users/lukam/Downloads/mmafuturewebsite.WordPress.2026-05-13.xml';
$file         = isset( $_REQUEST['file'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['file'] ) ) : $default_file;
$action       = isset( $_POST['mmaf_legacy_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_legacy_action'] ) ) : '';
$report       = null;
$error        = null;

if ( in_array( $action, array( 'dry_run', 'import' ), true ) ) {
	if ( 'import' === $action ) {
		check_admin_referer( 'mmaf_legacy_wxr_import', 'mmaf_legacy_nonce' );
		if ( empty( $_POST['confirm_backup'] ) ) {
			$error = 'Confirm that you have a database backup before actual import.';
		}
	}

	if ( null === $error ) {
		try {
			$importer = new MMAF_Legacy_Fighter_WXR_Importer();
			$report   = $importer->run( $file, 'dry_run' === $action, get_current_user_id() );
		} catch ( \Throwable $throwable ) {
			$error = $throwable->getMessage();
		}
	}
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>MMA Future Legacy Fighter WXR Import</title>
	<style>
		body { color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
		.button { border: 1px solid #2271b1; border-radius: 3px; cursor: pointer; display: inline-block; line-height: 2; padding: 0 10px; text-decoration: none; }
		.button-primary { background: #2271b1; color: #fff; }
		.button-secondary { background: #f6f7f7; color: #2271b1; }
		.form-table th { padding: 12px 10px 12px 0; text-align: left; vertical-align: top; width: 180px; }
		.form-table td { padding: 8px 10px; }
		.notice { border-left: 4px solid #d63638; background: #fff; margin: 16px 0; padding: 1px 12px; }
	</style>
</head>
<body class="wp-core-ui" style="padding: 24px; max-width: 1100px;">
	<h1>MMA Future Legacy Fighter WXR Import</h1>
	<p>This temporary tool imports old <code>fighter</code> WXR posts into the canonical MMA Future Data Engine fighter tables. It does not import old stats as official stats and does not make fighters rankable.</p>
	<p><strong>Before actual import, back up the database.</strong> This tool never deletes, truncates, auto-merges, or overwrites canonical fighters.</p>

	<?php if ( null !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'mmaf_legacy_wxr_import', 'mmaf_legacy_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="file">WXR XML file</label></th>
				<td><input class="regular-text" style="width: 620px;" id="file" name="file" value="<?php echo esc_attr( $file ); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Backup confirmation</th>
				<td><label><input type="checkbox" name="confirm_backup" value="1"> I have a database backup and understand import writes canonical fighter data.</label></td>
			</tr>
		</table>
		<p>
			<button class="button button-secondary" type="submit" name="mmaf_legacy_action" value="dry_run">Dry run</button>
			<button class="button button-primary" type="submit" name="mmaf_legacy_action" value="import">Import</button>
		</p>
	</form>

	<?php if ( null !== $report ) : ?>
		<h2>Report</h2>
		<pre style="background:#fff; border:1px solid #ccd0d4; padding:16px; overflow:auto;"><?php echo esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
	<?php endif; ?>
</body>
</html>
