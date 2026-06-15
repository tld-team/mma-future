<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Audit\BoutIntegrityAuditService;
use MMAF\DataEngine\Services\Audit\DataQualityReportService;
use MMAF\DataEngine\Services\Audit\FighterDuplicateAuditService;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Support\Capabilities;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReviewPage {
	private const PAGE_SLUG = 'mmaf-review';
	private const NONCE_ACTION = 'mmaf_review_action';
	private const NONCE_NAME = 'mmaf_review_nonce';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;
		$preview = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$notice = self::handle_post();
			if ( isset( $notice['preview'] ) && is_array( $notice['preview'] ) ) {
				$preview = $notice['preview'];
			}
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( ! in_array( $tab, array( 'overview', 'likely_matches', 'duplicates', 'conflicts', 'needs_review', 'provisional' ), true ) ) {
			$tab = 'overview';
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Review', 'mma-future-data-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="<?php echo esc_attr( 'error' === $notice['type'] ? 'notice notice-error' : 'notice notice-success' ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a class="<?php echo esc_attr( 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( $preview ) {
				self::render_source_link_preview( $preview, $tab );
			}

			if ( 'overview' === $tab ) {
				self::render_overview();
			} elseif ( 'likely_matches' === $tab ) {
				self::render_likely_matches();
			} elseif ( 'duplicates' === $tab ) {
				self::render_duplicates();
			} elseif ( 'conflicts' === $tab ) {
				self::render_conflicts();
			} elseif ( 'needs_review' === $tab ) {
				self::render_needs_review();
			} elseif ( 'provisional' === $tab ) {
				self::render_provisional_fighters();
			}
			?>
		</div>
		<?php
	}

	public static function summary(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$summary = array(
			'likely_match_items'            => 0,
			'duplicate_candidates'          => 0,
			'import_conflicts'              => 0,
			'needs_review_import_items'     => 0,
			'provisional_tapology_fighters' => 0,
			'unresolved_source_mappings'    => 0,
			'open_review_items'             => 0,
			'resolved_review_items'         => 0,
			'dismissed_review_items'        => 0,
			'needs_research_items'          => 0,
			'source_link_actions'           => 0,
			'participant_remap_actions'     => 0,
			'last_review_action_time'       => null,
			'malformed_bouts_count'         => 0,
			'same_fighter_bouts_count'      => 0,
		);
		$tracked_open = 0;

		if ( self::table_exists( $tables['source_import_items'] ) ) {
			$summary['likely_match_items'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE action = 'likely_match_review' AND status NOT IN ('reviewed', 'dismissed', 'resolved')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['import_conflicts'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE status = 'conflict'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['needs_review_import_items'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE status IN ('needs_review', 'failed', 'needs_research') OR action IN ('likely_match_review', 'review_bout_match', 'review_event_match')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$duplicates = ( new FighterDuplicateAuditService() )->audit( 100 );
		$summary['duplicate_candidates'] = (int) ( $duplicates['likely_duplicates_count'] ?? 0 );

		if ( self::table_exists( $tables['fighters'] ) && self::table_exists( $tables['fighter_sources'] ) ) {
			$summary['provisional_tapology_fighters'] = (int) $wpdb->get_var(
				"
				SELECT COUNT(DISTINCT f.id)
				FROM {$tables['fighters']} f
				INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
				WHERE f.status = 'provisional'
					AND f.rankability_status = 'pending_review'
					AND f.is_public = 0
					AND f.is_rankable = 0
					AND f.deleted_soft = 0
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$summary['unresolved_source_mappings'] += (int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT source_type, source_fighter_id
					FROM {$tables['fighter_sources']}
					WHERE source_fighter_id IS NOT NULL AND source_fighter_id <> ''
					GROUP BY source_type, source_fighter_id
					HAVING COUNT(*) > 1 OR COUNT(DISTINCT fighter_id) > 1
				) conflicts
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		if ( self::table_exists( $tables['review_items'] ) ) {
			$tracked_open = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['review_items']} WHERE status = 'open'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['resolved_review_items'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['review_items']} WHERE status = 'resolved'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['dismissed_review_items'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['review_items']} WHERE status = 'dismissed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['needs_research_items'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['review_items']} WHERE status = 'needs_research'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		if ( self::table_exists( $tables['audit_log'] ) ) {
			$summary['source_link_actions'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = 'review_source_linked_to_existing_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['participant_remap_actions'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = 'review_participants_remapped'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$summary['last_review_action_time'] = $wpdb->get_var(
				"SELECT MAX(created_at) FROM {$tables['audit_log']} WHERE action LIKE 'review_%'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$integrity = self::global_integrity_summary();
		$summary['malformed_bouts_count'] = (int) $integrity['malformed_bouts_count'];
		$summary['same_fighter_bouts_count'] = (int) $integrity['same_fighter_bouts_count'];

		$summary['open_review_items'] = $tracked_open
			+ $summary['needs_review_import_items']
			+ $summary['duplicate_candidates']
			+ $summary['provisional_tapology_fighters']
			+ $summary['unresolved_source_mappings'];

		return $summary;
	}

	private static function tabs(): array {
		return array(
			'overview'       => __( 'Overview', 'mma-future-data-engine' ),
			'likely_matches' => __( 'Likely Fighter Matches', 'mma-future-data-engine' ),
			'duplicates'     => __( 'Duplicate Fighters', 'mma-future-data-engine' ),
			'conflicts'      => __( 'Import Conflicts', 'mma-future-data-engine' ),
			'needs_review'   => __( 'Needs Review', 'mma-future-data-engine' ),
			'provisional'    => __( 'Provisional Scraped Fighters', 'mma-future-data-engine' ),
		);
	}

	private static function source_link_input(): array {
		$provisional_id = isset( $_POST['provisional_fighter_id'] ) ? absint( $_POST['provisional_fighter_id'] ) : 0;
		$existing_id = isset( $_POST['existing_fighter_id'] ) ? absint( $_POST['existing_fighter_id'] ) : 0;
		$source_fighter_id = isset( $_POST['source_fighter_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fighter_id'] ) ) : '';
		$item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : 'source_link:' . $provisional_id . ':' . $existing_id . ':' . $source_fighter_id;

		if ( $provisional_id <= 0 || $existing_id <= 0 || $provisional_id === $existing_id || '' === $source_fighter_id ) {
			throw new \RuntimeException( __( 'Missing or invalid source-link identifiers.', 'mma-future-data-engine' ) );
		}

		return array(
			'provisional_id'        => $provisional_id,
			'existing_id'           => $existing_id,
			'source_fighter_id'     => $source_fighter_id,
			'item_key'              => $item_key,
			'source_import_item_id' => isset( $_POST['source_import_item_id'] ) ? absint( $_POST['source_import_item_id'] ) : 0,
		);
	}

	private static function build_source_link_preflight( int $provisional_id, int $existing_id, string $source_fighter_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$errors = array();
		$source = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['fighter_sources']} WHERE source_type = %s AND source_fighter_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'tapology',
				$source_fighter_id
			),
			ARRAY_A
		);
		$provisional = self::fighter_row( $provisional_id );
		$existing = self::fighter_row( $existing_id );
		$existing_tapology = self::tapology_source_for_fighter( $existing_id );
		$participants_before = self::participant_rows_for_fighter( $provisional_id );
		$remap = self::simulate_participant_remap( $provisional_id, $existing_id );

		if ( ! $provisional ) {
			$errors[] = __( 'Provisional fighter does not exist.', 'mma-future-data-engine' );
		} elseif ( 'provisional' !== (string) $provisional['status'] ) {
			$errors[] = __( 'Source-linking is limited to provisional scraped fighters.', 'mma-future-data-engine' );
		}
		if ( ! $existing ) {
			$errors[] = __( 'Existing fighter does not exist.', 'mma-future-data-engine' );
		}
		if ( ! $source ) {
			$errors[] = __( 'Tapology source mapping was not found.', 'mma-future-data-engine' );
		} elseif ( (int) $source['fighter_id'] !== $provisional_id ) {
			$errors[] = __( 'Tapology source ID is mapped to another fighter.', 'mma-future-data-engine' );
		}
		if ( $existing_tapology ) {
			$errors[] = __( 'Existing fighter already has a Tapology source mapping.', 'mma-future-data-engine' );
		}
		if ( ! $remap['ok'] ) {
			$errors = array_merge( $errors, $remap['errors'] );
		}

		$wp_post_status = 'none';
		if ( $provisional && ! empty( $provisional['wp_post_id'] ) ) {
			$wp_post_status = (string) get_post_status( (int) $provisional['wp_post_id'] );
			if ( ! in_array( $wp_post_status, array( 'draft', 'private', 'pending', 'auto-draft' ), true ) ) {
				$errors[] = __( 'Linked WP post for the provisional fighter is not draft/private/pending.', 'mma-future-data-engine' );
			}
		}

		return array(
			'ok'                         => empty( $errors ),
			'errors'                     => $errors,
			'provisional_fighter'        => $provisional,
			'existing_fighter'           => $existing,
			'source_mapping'             => $source,
			'existing_source_mappings'   => self::source_mappings_for_fighter( $existing_id ),
			'participants_before'        => $participants_before,
			'participants_after_preview' => $remap['participants_after'],
			'affected_bout_ids'          => $remap['bout_ids'],
			'affected_bouts'             => $remap['bout_summaries'],
			'checks'                     => $remap['checks'],
			'wp_post_status_after'       => $wp_post_status,
			'fields_not_overwritten'     => array(
				'existing fighter display_name/nickname/nationality/weight_class/status/rankability fields',
				'manual verified provenance fields',
				'stats current rows',
				'ranking current rows',
				'active ranking run',
				'public/rankable promotion flags',
			),
		);
	}

	private static function review_filters( string $tab ): array {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return array(
			'status'        => $status,
			'item_type'     => isset( $_GET['item_type'] ) ? sanitize_key( wp_unslash( $_GET['item_type'] ) ) : '',
			'confidence'    => isset( $_GET['confidence'] ) ? sanitize_key( wp_unslash( $_GET['confidence'] ) ) : '',
			'import_run_id' => isset( $_GET['import_run_id'] ) ? absint( $_GET['import_run_id'] ) : 0,
			'source_type'   => isset( $_GET['source_type'] ) ? sanitize_key( wp_unslash( $_GET['source_type'] ) ) : '',
			'show_closed'   => isset( $_GET['show_closed'] ) ? 1 : 0,
		);
	}

	private static function render_review_filters( string $tab, array $filters ): void {
		?>
		<form method="get" style="margin: 12px 0 16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<label><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?> <input type="text" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" placeholder="open"></label>
			<label><?php echo esc_html__( 'Item type', 'mma-future-data-engine' ); ?> <input type="text" name="item_type" value="<?php echo esc_attr( $filters['item_type'] ); ?>"></label>
			<label><?php echo esc_html__( 'Confidence', 'mma-future-data-engine' ); ?> <input type="text" name="confidence" value="<?php echo esc_attr( $filters['confidence'] ); ?>"></label>
			<label><?php echo esc_html__( 'Import run', 'mma-future-data-engine' ); ?> <input type="number" min="0" name="import_run_id" value="<?php echo esc_attr( (string) $filters['import_run_id'] ); ?>"></label>
			<label><?php echo esc_html__( 'Source type', 'mma-future-data-engine' ); ?> <input type="text" name="source_type" value="<?php echo esc_attr( $filters['source_type'] ); ?>" placeholder="tapology"></label>
			<label><input type="checkbox" name="show_closed" value="1" <?php checked( 1, (int) $filters['show_closed'] ); ?>> <?php echo esc_html__( 'Show resolved/dismissed', 'mma-future-data-engine' ); ?></label>
			<?php submit_button( __( 'Filter', 'mma-future-data-engine' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function filter_review_rows( array $rows, array $filters ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filters ): bool {
					$status = (string) ( $row['review_status'] ?? 'open' );
					if ( empty( $filters['show_closed'] ) && in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
						return false;
					}
					if ( '' !== $filters['status'] && $status !== $filters['status'] ) {
						return false;
					}
					if ( '' !== $filters['confidence'] && (string) ( $row['confidence'] ?? '' ) !== $filters['confidence'] ) {
						return false;
					}
					if ( $filters['import_run_id'] > 0 && (int) ( $row['import_run_id'] ?? 0 ) !== $filters['import_run_id'] ) {
						return false;
					}
					if ( '' !== $filters['source_type'] && (string) ( $row['source_type'] ?? '' ) !== $filters['source_type'] ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	private static function render_overview(): void {
		$summary = self::summary();
		$health  = HealthPage::collect();
		$audit   = ( new DataQualityReportService() )->latest_stored_summary();
		?>
		<h2><?php echo esc_html__( 'Overview', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_key_value_table( $summary ); ?>
		<p><strong><?php echo esc_html__( 'Review actions do not automatically rebuild stats or rankings. Rebuild stats manually after resolving relevant data issues.', 'mma-future-data-engine' ); ?></strong></p>

		<h2><?php echo esc_html__( 'Last Post-Import Audit Summary', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_key_value_table( is_array( $audit ) ? $audit : array( 'status' => __( 'No stored post-import audit summary found.', 'mma-future-data-engine' ) ) ); ?>

		<h2><?php echo esc_html__( 'Latest Actual Import Summary', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_key_value_table( $health['phase_10'] ); ?>

		<h2><?php echo esc_html__( 'Latest Stats Rebuild Summary', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_key_value_table( $health['phase_5'] ); ?>
		<?php
	}

	private static function render_likely_matches(): void {
		$filters = self::review_filters( 'likely_matches' );
		$rows = self::filter_review_rows( self::likely_match_rows( $filters ), $filters );
		?>
		<h2><?php echo esc_html__( 'Likely Fighter Matches', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'These rows are review candidates only. Linking is explicit, blocks source conflicts, and does not merge fighters or promote public/rankable flags.', 'mma-future-data-engine' ); ?></p>
		<?php self::render_review_filters( 'likely_matches', $filters ); ?>
		<?php self::render_likely_table( $rows ); ?>
		<?php
	}

	private static function render_duplicates(): void {
		$filters = self::review_filters( 'duplicates' );
		$rows    = self::filter_review_rows( self::duplicate_rows( $filters ), $filters );
		$compare = isset( $_GET['compare'] ) ? sanitize_text_field( wp_unslash( $_GET['compare'] ) ) : '';
		?>
		<h2><?php echo esc_html__( 'Duplicate Fighters', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'Phase 11 does not merge fighters. Use this table to compare, mark review state, or link a Tapology provisional source to an existing fighter when identity is confirmed.', 'mma-future-data-engine' ); ?></p>
		<?php self::render_review_filters( 'duplicates', $filters ); ?>
		<?php
		if ( preg_match( '/^(\d+):(\d+)$/', $compare, $matches ) ) {
			self::render_compare_view( (int) $matches[1], (int) $matches[2] );
		}
		self::render_duplicate_table( $rows );
	}

	private static function render_conflicts(): void {
		$filters = self::review_filters( 'conflicts' );
		$rows = self::import_conflict_rows( $filters );
		?>
		<h2><?php echo esc_html__( 'Import Conflicts', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'Conflicts are not repaired automatically. Existing mappings and identity hashes are preserved unless a future explicit repair flow is added.', 'mma-future-data-engine' ); ?></p>
		<?php self::render_review_filters( 'conflicts', $filters ); ?>
		<?php self::render_import_items_table( $rows, 'conflicts' ); ?>
		<?php
	}

	private static function render_needs_review(): void {
		$filters = array(
			'item_type'     => isset( $_GET['item_type'] ) ? sanitize_key( wp_unslash( $_GET['item_type'] ) ) : '',
			'status'        => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'action_filter' => isset( $_GET['action_filter'] ) ? sanitize_key( wp_unslash( $_GET['action_filter'] ) ) : '',
			'import_run_id' => isset( $_GET['import_run_id'] ) ? absint( $_GET['import_run_id'] ) : 0,
		);
		$rows = self::needs_review_rows( $filters );
		?>
		<h2><?php echo esc_html__( 'Needs Review', 'mma-future-data-engine' ); ?></h2>
		<form method="get" style="margin: 12px 0 16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="needs_review">
			<label><?php echo esc_html__( 'Item type', 'mma-future-data-engine' ); ?> <input type="text" name="item_type" value="<?php echo esc_attr( $filters['item_type'] ); ?>"></label>
			<label><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?> <input type="text" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>"></label>
			<label><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?> <input type="text" name="action_filter" value="<?php echo esc_attr( $filters['action_filter'] ); ?>"></label>
			<label><?php echo esc_html__( 'Import run ID', 'mma-future-data-engine' ); ?> <input type="number" name="import_run_id" min="0" value="<?php echo esc_attr( (string) $filters['import_run_id'] ); ?>"></label>
			<?php submit_button( __( 'Filter', 'mma-future-data-engine' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php self::render_import_items_table( $rows, 'needs_review' ); ?>
		<?php
	}

	private static function render_provisional_fighters(): void {
		$filters = self::review_filters( 'provisional' );
		$rows = self::filter_review_rows( self::provisional_fighter_rows( $filters ), $filters );
		?>
		<h2><?php echo esc_html__( 'Provisional Scraped Fighters', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'Promotion to public or rankable status remains on the Fighter edit form. There is no bulk promotion action here.', 'mma-future-data-engine' ); ?></p>
		<?php self::render_review_filters( 'provisional', $filters ); ?>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php echo esc_html__( 'No unresolved provisional Tapology fighters found.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Fighter ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Display name', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Tapology source ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Linked bouts', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Stats', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Duplicate candidates', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['fighter_id'] ); ?></td>
						<td><?php echo esc_html( $row['display_name'] ); ?></td>
						<td><?php echo esc_html( $row['source_fighter_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['linked_bouts_count'] ); ?></td>
						<td><?php echo esc_html( self::format_stats_row( $row ) ); ?></td>
						<td><?php echo esc_html( (string) $row['duplicate_candidate_count'] ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-fighters&action=edit&fighter_id=' . (int) $row['fighter_id'] ) ); ?>"><?php echo esc_html__( 'Edit fighter', 'mma-future-data-engine' ); ?></a>
							<?php if ( ! empty( $row['wp_post_id'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['wp_post_id'] . '&action=edit' ) ); ?>"><?php echo esc_html__( 'View WP draft', 'mma-future-data-engine' ); ?></a>
							<?php endif; ?>
							<?php self::render_action_form( 'mark_provisional_reviewed', __( 'Mark reviewed only', 'mma-future-data-engine' ), array( 'fighter_id' => (int) $row['fighter_id'], 'item_key' => 'provisional:' . (int) $row['fighter_id'] ), 'provisional' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_likely_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No unresolved likely fighter matches found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Scraped / provisional record', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Candidate existing record', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Confidence / reason', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created / import run', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Review state', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo wp_kses_post( self::format_candidate_context( $row, 'scraped' ) ); ?></td>
						<td><?php echo wp_kses_post( self::format_candidate_context( $row, 'existing' ) ); ?></td>
						<td><?php echo wp_kses_post( self::format_confidence_reason( $row ) ); ?></td>
						<td><?php echo esc_html( self::format_created_import_reference( $row ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['review_status'] ?? 'open' ) ); ?></td>
						<td><?php self::render_candidate_actions( $row, 'likely_matches' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_source_link_preview( array $preview, string $tab ): void {
		$provisional = is_array( $preview['provisional_fighter'] ?? null ) ? $preview['provisional_fighter'] : array();
		$existing = is_array( $preview['existing_fighter'] ?? null ) ? $preview['existing_fighter'] : array();
		$source = is_array( $preview['source_mapping'] ?? null ) ? $preview['source_mapping'] : array();
		?>
		<div style="border:1px solid <?php echo esc_attr( $preview['ok'] ? '#8c8f94' : '#b32d2e' ); ?>; background:#fff; padding:14px; margin:14px 0 20px;">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Source-Link Dry Run Preview', 'mma-future-data-engine' ); ?></h2>
			<p><strong><?php echo esc_html__( 'Identity-changing action. Requires human confirmation.', 'mma-future-data-engine' ); ?></strong></p>
			<?php if ( ! $preview['ok'] ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html__( 'Confirm is blocked for these reasons:', 'mma-future-data-engine' ); ?></p></div>
				<ul>
					<?php foreach ( (array) $preview['errors'] as $error ) : ?>
						<li><?php echo esc_html( (string) $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php self::render_key_value_table(
				array(
					'source_mapping_to_move'             => $source ? '#' . (int) $source['id'] . ' tapology:' . (string) $source['source_fighter_id'] . ' from fighter #' . (int) $source['fighter_id'] : 'missing',
					'provisional_fighter_superseded'     => $provisional ? '#' . (int) $provisional['id'] . ' ' . (string) $provisional['display_name'] : 'missing',
					'existing_fighter_receives_source'   => $existing ? '#' . (int) $existing['id'] . ' ' . (string) $existing['display_name'] : 'missing',
					'participant_rows_remapped'          => count( (array) ( $preview['participants_before'] ?? array() ) ),
					'affected_bouts'                     => implode( ', ', array_map( 'strval', (array) ( $preview['affected_bout_ids'] ?? array() ) ) ),
					'wp_post_status_after'               => $preview['wp_post_status_after'] ?? 'none',
					'fields_not_overwritten'             => implode( '; ', (array) ( $preview['fields_not_overwritten'] ?? array() ) ),
				)
			); ?>

			<h3><?php echo esc_html__( 'Integrity Checks', 'mma-future-data-engine' ); ?></h3>
			<?php self::render_key_value_table( (array) ( $preview['checks'] ?? array() ) ); ?>

			<h3><?php echo esc_html__( 'Affected Bouts', 'mma-future-data-engine' ); ?></h3>
			<?php self::render_key_value_table( array( 'bout_preview' => $preview['affected_bouts'] ?? array() ) ); ?>

			<?php if ( $preview['ok'] ) : ?>
				<form method="post" style="border-left:4px solid #b32d2e; padding:10px; background:#fcf0f1; max-width:760px;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="mmaf_review_action" value="confirm_link_source_existing">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
					<input type="hidden" name="provisional_fighter_id" value="<?php echo esc_attr( (string) ( $provisional['id'] ?? 0 ) ); ?>">
					<input type="hidden" name="existing_fighter_id" value="<?php echo esc_attr( (string) ( $existing['id'] ?? 0 ) ); ?>">
					<input type="hidden" name="source_fighter_id" value="<?php echo esc_attr( (string) ( $source['source_fighter_id'] ?? '' ) ); ?>">
					<input type="hidden" name="item_key" value="<?php echo esc_attr( (string) ( $preview['item_key'] ?? '' ) ); ?>">
					<input type="hidden" name="source_import_item_id" value="<?php echo esc_attr( (string) ( $preview['source_import_item_id'] ?? 0 ) ); ?>">
					<p><strong><?php echo esc_html__( 'Warning: This remaps fight-log participants from the provisional scraped fighter to the selected existing fighter.', 'mma-future-data-engine' ); ?></strong></p>
					<label style="display:block; margin:6px 0;"><input type="checkbox" name="confirm_identity_change" value="1" required> <?php echo esc_html__( 'I confirm these two records represent the same real fighter.', 'mma-future-data-engine' ); ?></label>
					<label style="display:block; margin:6px 0;"><?php echo esc_html__( 'Type LINK or the existing fighter ID', 'mma-future-data-engine' ); ?> <input type="text" name="typed_confirmation" required></label>
					<label style="display:block; margin:6px 0;"><?php echo esc_html__( 'Notes', 'mma-future-data-engine' ); ?> <textarea name="notes" rows="2" cols="70"></textarea></label>
					<?php submit_button( __( 'Confirm source link', 'mma-future-data-engine' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_duplicate_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No unresolved duplicate candidates found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Fighter A', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Fighter B', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Confidence / reason', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Source types', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Review state', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo wp_kses_post( self::format_fighter_detail( $row['fighter_a'] ) ); ?></td>
						<td><?php echo wp_kses_post( self::format_fighter_detail( $row['fighter_b'] ) ); ?></td>
						<td><?php echo wp_kses_post( self::format_confidence_reason( $row ) ); ?></td>
						<td><?php echo esc_html( $row['source_types'] ); ?></td>
						<td><?php echo esc_html( (string) ( $row['review_status'] ?? 'open' ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'tab' => 'duplicates', 'compare' => (int) $row['fighter_a']['id'] . ':' . (int) $row['fighter_b']['id'] ) ) ); ?>"><?php echo esc_html__( 'Compare', 'mma-future-data-engine' ); ?></a>
							<?php self::render_action_form( 'mark_not_duplicate', __( 'Mark not duplicate', 'mma-future-data-engine' ), array( 'item_key' => $row['item_key'], 'fighter_a_id' => (int) $row['fighter_a']['id'], 'fighter_b_id' => (int) $row['fighter_b']['id'] ), 'duplicates' ); ?>
							<?php self::render_action_form( 'mark_needs_research', __( 'Needs research', 'mma-future-data-engine' ), array( 'item_key' => $row['item_key'], 'fighter_a_id' => (int) $row['fighter_a']['id'], 'fighter_b_id' => (int) $row['fighter_b']['id'], 'item_type' => 'duplicate_fighter_candidate' ), 'duplicates' ); ?>
							<?php if ( $row['can_link_source'] ) : ?>
								<?php self::render_link_form( (int) $row['provisional_fighter_id'], (int) $row['existing_fighter_id'], $row['source_fighter_id'], $row['item_key'], 'duplicates' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_import_items_table( array $rows, string $tab ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No matching import items found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Run', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Source ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Canonical ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Conflict preview', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Error', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Review actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr<?php echo 'tapology_bout_1116772' === (string) $row['source_id'] ? ' style="font-weight:600; background:#fff8e5;"' : ''; ?>>
						<td><?php echo esc_html( (string) $row['id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['import_run_id'] ); ?></td>
						<td><?php echo esc_html( $row['item_type'] ); ?></td>
						<td><code><?php echo esc_html( (string) $row['source_id'] ); ?></code></td>
						<td><?php echo esc_html( null === $row['canonical_id'] ? '-' : (string) $row['canonical_id'] ); ?></td>
						<td><?php echo wp_kses_post( self::format_import_conflict_preview( $row ) ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( (string) $row['action'] ); ?></td>
						<td><?php echo esc_html( self::shorten( (string) $row['warnings_json'] ) ); ?></td>
						<td><?php echo esc_html( self::shorten( (string) $row['error_message'] ) ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td>
							<?php self::render_action_form( 'mark_import_reviewed', __( 'Mark reviewed', 'mma-future-data-engine' ), array( 'source_import_item_id' => (int) $row['id'], 'item_key' => 'import_item:' . (int) $row['id'] ), $tab ); ?>
							<?php self::render_action_form( 'mark_needs_research', __( 'Needs research', 'mma-future-data-engine' ), array( 'source_import_item_id' => (int) $row['id'], 'item_key' => 'import_item:' . (int) $row['id'], 'item_type' => 'source_import_item' ), $tab ); ?>
							<?php self::render_action_form( 'dismiss_candidate', __( 'Dismiss', 'mma-future-data-engine' ), array( 'source_import_item_id' => (int) $row['id'], 'item_key' => 'import_item:' . (int) $row['id'], 'item_type' => 'source_import_item' ), $tab, __( 'Dismiss this review item? Canonical data will not be changed.', 'mma-future-data-engine' ) ); ?>
							<?php if ( 'conflicts' === $tab ) : ?>
								<?php self::render_action_form( 'mark_test_artifact', __( 'Dismiss test artifact', 'mma-future-data-engine' ), array( 'source_import_item_id' => (int) $row['id'], 'item_key' => 'import_item:' . (int) $row['id'] ), $tab, __( 'Mark this conflict as a dismissed test artifact? Existing source mappings will not be changed.', 'mma-future-data-engine' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_candidate_actions( array $row, string $tab ): void {
		if ( $row['can_link_source'] ) {
			self::render_link_form( (int) $row['scraped_fighter_id'], (int) $row['existing_fighter_id'], $row['source_fighter_id'], $row['item_key'], $tab, (int) $row['source_import_item_id'] );
		}

		echo '<div style="margin-top:6px;"><strong>' . esc_html__( 'Safe action', 'mma-future-data-engine' ) . '</strong></div>';
		self::render_action_form( 'keep_separate', __( 'Keep separate', 'mma-future-data-engine' ), array_merge( self::candidate_hidden_fields( $row ), array( 'item_type' => 'likely_fighter_match' ) ), $tab, __( 'Mark this candidate as separate? No canonical fighter or source mapping fields will be changed.', 'mma-future-data-engine' ) );
		self::render_action_form( 'mark_needs_research', __( 'Needs research', 'mma-future-data-engine' ), array_merge( self::candidate_hidden_fields( $row ), array( 'item_type' => 'likely_fighter_match' ) ), $tab );
		self::render_action_form( 'dismiss_candidate', __( 'Dismiss', 'mma-future-data-engine' ), array_merge( self::candidate_hidden_fields( $row ), array( 'item_type' => 'likely_fighter_match' ) ), $tab, __( 'Dismiss this candidate? It will be hidden from the default unresolved list and canonical data will not change.', 'mma-future-data-engine' ) );
	}

	private static function render_link_form( int $provisional_fighter_id, int $existing_fighter_id, string $source_fighter_id, string $item_key, string $tab, int $source_import_item_id = 0 ): void {
		if ( $provisional_fighter_id <= 0 || $existing_fighter_id <= 0 || '' === $source_fighter_id ) {
			return;
		}
		?>
		<div style="border-left:4px solid #b32d2e; padding:6px 8px; margin:4px 0; background:#fcf0f1;">
			<strong><?php echo esc_html__( 'Identity-changing action', 'mma-future-data-engine' ); ?></strong><br>
			<span><?php echo esc_html__( 'Requires human confirmation after preview. This remaps fight-log participants from the provisional scraped fighter to the selected existing fighter.', 'mma-future-data-engine' ); ?></span>
		</div>
		<form method="post" style="display:inline-block; margin: 2px 0;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="mmaf_review_action" value="preview_link_source_existing">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<input type="hidden" name="provisional_fighter_id" value="<?php echo esc_attr( (string) $provisional_fighter_id ); ?>">
			<input type="hidden" name="existing_fighter_id" value="<?php echo esc_attr( (string) $existing_fighter_id ); ?>">
			<input type="hidden" name="source_fighter_id" value="<?php echo esc_attr( $source_fighter_id ); ?>">
			<input type="hidden" name="item_key" value="<?php echo esc_attr( $item_key ); ?>">
			<input type="hidden" name="source_import_item_id" value="<?php echo esc_attr( (string) $source_import_item_id ); ?>">
			<?php submit_button( __( 'Preview link', 'mma-future-data-engine' ), 'delete button-small', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_action_form( string $action, string $label, array $fields, string $tab, string $confirm = '' ): void {
		?>
		<form method="post" style="display:inline-block; margin: 2px 0;"<?php echo '' !== $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="mmaf_review_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php foreach ( $fields as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<label style="display:block; margin:2px 0;">
				<span class="screen-reader-text"><?php echo esc_html__( 'Review notes', 'mma-future-data-engine' ); ?></span>
				<input type="text" name="notes" value="" placeholder="<?php echo esc_attr__( 'Notes', 'mma-future-data-engine' ); ?>" style="max-width:220px;">
			</label>
			<?php submit_button( $label, 'secondary button-small', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function handle_post(): array {
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$action = isset( $_POST['mmaf_review_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_review_action'] ) ) : '';

		try {
			if ( 'preview_link_source_existing' === $action ) {
				return self::handle_preview_link_source_existing();
			}
			if ( 'confirm_link_source_existing' === $action ) {
				return self::handle_confirm_link_source_existing();
			}
			if ( 'keep_separate' === $action ) {
				return self::handle_simple_review_action( 'resolved', 'review_keep_separate', 'review_candidate_kept_separate', __( 'Review item marked as separate. Canonical data was not changed.', 'mma-future-data-engine' ) );
			}
			if ( 'mark_needs_research' === $action ) {
				return self::handle_simple_review_action( 'needs_research', 'review_needs_research', 'review_marked_needs_research', __( 'Review item marked as needing manual research. Canonical data was not changed.', 'mma-future-data-engine' ) );
			}
			if ( 'dismiss_candidate' === $action ) {
				return self::handle_simple_review_action( 'dismissed', 'review_dismissed', 'review_candidate_dismissed', __( 'Review item dismissed. Canonical data was not changed.', 'mma-future-data-engine' ) );
			}
			if ( 'mark_import_reviewed' === $action ) {
				return self::handle_simple_review_action( 'resolved', 'reviewed_keep_unresolved', 'review_import_item_marked_reviewed', __( 'Import item marked reviewed. No source mapping or canonical data was changed.', 'mma-future-data-engine' ) );
			}
			if ( 'mark_test_artifact' === $action ) {
				return self::handle_simple_review_action( 'dismissed', 'test_artifact', 'review_conflict_marked_test_artifact', __( 'Conflict marked as dismissed test artifact. Existing source mappings were not changed.', 'mma-future-data-engine' ) );
			}
			if ( 'mark_not_duplicate' === $action ) {
				return self::handle_simple_review_action( 'resolved', 'not_duplicate', 'review_candidate_marked_not_duplicate', __( 'Duplicate candidate marked not duplicate. Canonical data was not changed.', 'mma-future-data-engine' ) );
			}
			if ( 'mark_provisional_reviewed' === $action ) {
				return self::handle_simple_review_action( 'resolved', 'reviewed_only', 'review_provisional_fighter_marked_reviewed', __( 'Provisional fighter marked reviewed only. Public/rankable flags were not changed.', 'mma-future-data-engine' ) );
			}
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Invalid review action.', 'mma-future-data-engine' ),
		);
	}

	private static function handle_simple_review_action( string $status, string $action_taken, string $audit_action, string $message ): array {
		$item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';
		if ( '' === $item_key ) {
			throw new \RuntimeException( __( 'Missing review item key.', 'mma-future-data-engine' ) );
		}

		$item_type = isset( $_POST['item_type'] ) ? sanitize_key( wp_unslash( $_POST['item_type'] ) ) : 'review_item';
		$source_import_item_id = isset( $_POST['source_import_item_id'] ) ? absint( $_POST['source_import_item_id'] ) : 0;
		$fighter_id = isset( $_POST['fighter_id'] ) ? absint( $_POST['fighter_id'] ) : 0;
		$fighter_a_id = isset( $_POST['fighter_a_id'] ) ? absint( $_POST['fighter_a_id'] ) : 0;
		$fighter_b_id = isset( $_POST['fighter_b_id'] ) ? absint( $_POST['fighter_b_id'] ) : 0;
		$scraped_fighter_id = isset( $_POST['scraped_fighter_id'] ) ? absint( $_POST['scraped_fighter_id'] ) : 0;
		$existing_fighter_id = isset( $_POST['existing_fighter_id'] ) ? absint( $_POST['existing_fighter_id'] ) : 0;
		$source_fighter_id = isset( $_POST['source_fighter_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fighter_id'] ) ) : null;
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;
		$review_before = self::review_item_by_key( $item_key );
		$import_before = $source_import_item_id > 0 ? self::source_import_item_row( $source_import_item_id ) : null;

		self::upsert_review_item(
			array(
				'item_type'            => $item_type,
				'item_key'             => $item_key,
				'source_type'          => $source_fighter_id ? 'tapology' : null,
				'source_id'            => $source_fighter_id,
				'canonical_id'         => $fighter_id > 0 ? $fighter_id : ( $scraped_fighter_id > 0 ? $scraped_fighter_id : ( $fighter_a_id > 0 ? $fighter_a_id : null ) ),
				'related_canonical_id' => $existing_fighter_id > 0 ? $existing_fighter_id : ( $fighter_b_id > 0 ? $fighter_b_id : null ),
				'status'               => $status,
				'action_taken'         => $action_taken,
				'notes'                => $notes,
			)
		);

		if ( $source_import_item_id > 0 ) {
			$item_status = 'needs_research' === $status ? 'needs_research' : ( 'dismissed' === $status ? 'dismissed' : 'reviewed' );
			if ( 'review_keep_separate' === $action_taken ) {
				$item_status = 'needs_review';
			}
			self::update_import_item_review( $source_import_item_id, $item_status, $action_taken, $notes );
		}

		$entity_type = $source_import_item_id > 0 ? 'source_import_item' : ( $fighter_id > 0 ? 'fighter' : 'review_item' );
		$entity_id = $source_import_item_id > 0 ? $source_import_item_id : ( $fighter_id > 0 ? $fighter_id : max( $scraped_fighter_id, $fighter_a_id ) );
		$review_after = self::review_item_by_key( $item_key );
		$import_after = $source_import_item_id > 0 ? self::source_import_item_row( $source_import_item_id ) : null;
		( new AuditLogService() )->write(
			$audit_action,
			$entity_type,
			max( 0, $entity_id ),
			array(
				'review_item'        => $review_before,
				'source_import_item' => $import_before,
			),
			array(
				'review_item'        => $review_after,
				'source_import_item' => $import_after,
				'notes'              => $notes,
			),
			'Phase 11 review action. Canonical data unchanged unless otherwise stated.',
			get_current_user_id()
		);

		return array(
			'type'    => 'success',
			'message' => $message,
		);
	}

	private static function handle_preview_link_source_existing(): array {
		$input = self::source_link_input();
		$preview = self::build_source_link_preflight( $input['provisional_id'], $input['existing_id'], $input['source_fighter_id'] );
		$preview['item_key'] = $input['item_key'];
		$preview['source_import_item_id'] = $input['source_import_item_id'];

		return array(
			'type'    => $preview['ok'] ? 'success' : 'error',
			'message' => $preview['ok']
				? __( 'Source-link preview generated. Review the details before confirming.', 'mma-future-data-engine' )
				: __( 'Source-link preview found unsafe conditions. Confirm is blocked.', 'mma-future-data-engine' ),
			'preview' => $preview,
		);
	}

	private static function handle_confirm_link_source_existing(): array {
		global $wpdb;

		if ( empty( $_POST['confirm_identity_change'] ) ) {
			throw new \RuntimeException( __( 'Identity-changing action was not confirmed.', 'mma-future-data-engine' ) );
		}
		$typed = isset( $_POST['typed_confirmation'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['typed_confirmation'] ) ) ) : '';
		$input = self::source_link_input();
		if ( 'LINK' !== strtoupper( $typed ) && (string) $input['existing_id'] !== $typed ) {
			throw new \RuntimeException( __( 'Typed confirmation must be LINK or the existing fighter ID.', 'mma-future-data-engine' ) );
		}

		$tables = Schema::table_names();
		$provisional_id = $input['provisional_id'];
		$existing_id = $input['existing_id'];
		$source_fighter_id = $input['source_fighter_id'];
		$item_key = $input['item_key'];
		$source_import_item_id = $input['source_import_item_id'];
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$user_id = get_current_user_id();
		$now     = DateTime::mysql_now();
		$baseline = self::stats_ranking_baseline();

		$wpdb->query( 'START TRANSACTION' );
		try {
			$preflight = self::build_source_link_preflight( $provisional_id, $existing_id, $source_fighter_id );
			if ( ! $preflight['ok'] ) {
				throw new \RuntimeException( implode( ' ', $preflight['errors'] ) );
			}
			$source = $preflight['source_mapping'];
			$provisional = $preflight['provisional_fighter'];
			$existing = $preflight['existing_fighter'];
			$participants_before = $preflight['participants_before'];

			$wpdb->update(
				$tables['bout_participants'],
				array( 'fighter_id' => $existing_id, 'updated_at' => $now ),
				array( 'fighter_id' => $provisional_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			$wpdb->update(
				$tables['bout_participants'],
				array( 'opponent_fighter_id' => $existing_id, 'updated_at' => $now ),
				array( 'opponent_fighter_id' => $provisional_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			$wpdb->update(
				$tables['fighter_sources'],
				array(
					'fighter_id'   => $existing_id,
					'confidence'   => 100,
					'is_verified'  => 1,
					'is_primary'   => 1,
					'updated_at'   => $now,
				),
				array( 'id' => (int) $source['id'] ),
				array( '%d', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);

			$wpdb->update(
				$tables['fighters'],
				array(
					'status'             => 'merged',
					'rankability_status' => 'not_public',
					'is_public'          => 0,
					'is_rankable'        => 0,
					'deleted_soft'       => 0,
					'updated_at'         => $now,
				),
				array( 'id' => $provisional_id ),
				array( '%s', '%s', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);

			self::upsert_review_item(
				array(
					'item_type'            => 'likely_fighter_match',
					'item_key'             => $item_key,
					'source_type'          => 'tapology',
					'source_id'            => $source_fighter_id,
					'canonical_id'         => $provisional_id,
					'related_canonical_id' => $existing_id,
					'status'               => 'resolved',
					'action_taken'         => 'source_linked_to_existing_fighter',
					'notes'                => '' !== $notes ? $notes : 'Admin confirmed Tapology provisional fighter is same as existing canonical fighter.',
				)
			);

			if ( $source_import_item_id > 0 ) {
				self::update_import_item_review( $source_import_item_id, 'reviewed', 'source_linked_to_existing_fighter', $notes );
			}

			$provenance = new FieldProvenanceService();
			foreach ( array( 'status', 'rankability_status', 'is_public', 'is_rankable', 'deleted_soft' ) as $field ) {
				$provenance->upsert_source( 'fighter', $provisional_id, $field, 'status' === $field ? 'merged' : ( 'rankability_status' === $field ? 'not_public' : 0 ), 'review', $source_fighter_id, $user_id );
			}

			$post_integrity = self::post_action_integrity_summary( $preflight['affected_bout_ids'], $baseline );
			if ( ! $post_integrity['ok'] ) {
				throw new \RuntimeException( __( 'Post-action integrity check failed; transaction was rolled back. ', 'mma-future-data-engine' ) . implode( ' ', $post_integrity['errors'] ) );
			}

			$participants_after = self::participant_rows_for_fighter( $existing_id, $preflight['affected_bout_ids'] );
			$source_after = self::source_mapping_by_id( (int) $source['id'] );
			$provisional_after = self::fighter_row( $provisional_id );
			$existing_after = self::fighter_row( $existing_id );
			$audit = new AuditLogService();
			$audit->write(
				'review_source_linked_to_existing_fighter',
				'fighter',
				$existing_id,
				array(
					'provisional_fighter'       => $provisional,
					'existing_fighter'          => $existing,
					'source_mapping'            => $source,
					'affected_participants'     => $participants_before,
					'affected_bout_ids'         => $preflight['affected_bout_ids'],
					'stats_ranking_baseline'    => $baseline,
				),
				array(
					'provisional_fighter'       => $provisional_after,
					'existing_fighter'          => $existing_after,
					'source_mapping'            => $source_after,
					'affected_participants'     => $participants_after,
					'integrity_summary'         => $post_integrity,
					'fields_not_overwritten'    => $preflight['fields_not_overwritten'],
				),
				'Admin confirmed Tapology provisional fighter is same as existing canonical fighter.',
				$user_id
			);
			$audit->write(
				'review_participants_remapped',
				'fighter',
				$existing_id,
				array(
					'participants'      => $participants_before,
					'affected_bout_ids' => $preflight['affected_bout_ids'],
				),
				array(
					'participants'      => $participants_after,
					'affected_bout_ids' => $preflight['affected_bout_ids'],
					'integrity_summary' => $post_integrity,
				),
				'Bout participants and opponent references were remapped from provisional fighter to existing fighter after integrity checks.',
				$user_id
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		$summary = self::post_action_integrity_summary( array(), $baseline );
		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: malformed bouts, 2: stats rows, 3: current ranking rows, 4: active ranking run. */
				__( 'Review completed. Tapology source linked and participants remapped safely. Malformed bouts: %1$d. Stats rows: %2$d. Current ranking rows: %3$d. Active ranking run: %4$d. Rebuild stats manually if needed.', 'mma-future-data-engine' ),
				(int) $summary['malformed_bouts_count'],
				(int) $summary['stats_rows_count'],
				(int) $summary['current_ranking_rows_count'],
				(int) $summary['active_ranking_run_id']
			),
		);
	}

	private static function likely_match_rows( array $filters = array() ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = array();

		$duplicates = ( new FighterDuplicateAuditService() )->audit( 100 );
		foreach ( (array) ( $duplicates['review_candidates'] ?? array() ) as $candidate ) {
			$item_key = 'likely_match:' . (int) $candidate['scraped_fighter_id'] . ':' . (int) $candidate['existing_fighter_id'];
			if ( empty( $filters['show_closed'] ) && self::is_review_closed( $item_key ) ) {
				continue;
			}
			$scraped = self::fighter_row( (int) $candidate['scraped_fighter_id'] );
			$existing = self::fighter_row( (int) $candidate['existing_fighter_id'] );
			$rows[] = self::likely_row_from_candidate( $candidate, $scraped, $existing, $item_key );
		}

		if ( self::table_exists( $tables['source_import_items'] ) ) {
			$items = $wpdb->get_results(
				"
				SELECT *
				FROM {$tables['source_import_items']}
				WHERE action = 'likely_match_review'
					AND status NOT IN ('reviewed', 'dismissed', 'resolved')
				ORDER BY id DESC
				LIMIT 100
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( $items as $item ) {
				$item_key = 'import_item:' . (int) $item['id'];
				if ( empty( $filters['show_closed'] ) && self::is_review_closed( $item_key ) ) {
					continue;
				}
				$rows[] = array(
					'item_key'              => $item_key,
					'source_import_item_id' => (int) $item['id'],
					'scraped_fighter_id'    => 0,
					'scraped_display_name'  => __( 'Not stored in import item', 'mma-future-data-engine' ),
					'source_fighter_id'     => (string) $item['source_id'],
					'scraped_linked_bouts'  => 0,
					'scraped_stats_summary' => 'none',
					'existing_fighter_id'   => 0,
					'existing_display_name' => __( 'Review from source payload', 'mma-future-data-engine' ),
					'existing_status'       => '-',
					'existing_linked_bouts' => 0,
					'existing_stats_summary'=> 'none',
					'existing_source_count' => 0,
					'reason'                => self::shorten( (string) $item['warnings_json'] ),
					'confidence'            => 'medium',
					'import_run_id'         => (int) $item['import_run_id'],
					'created_at'            => (string) $item['created_at'],
					'review_status'         => self::review_status_for_key( $item_key ),
					'can_link_source'       => false,
				);
			}
		}

		return $rows;
	}

	private static function duplicate_rows( array $filters = array() ): array {
		$duplicates = ( new FighterDuplicateAuditService() )->audit( 100 );
		$rows = array();
		$seen = array();

		foreach ( (array) ( $duplicates['review_candidates'] ?? array() ) as $candidate ) {
			$a_id = (int) $candidate['scraped_fighter_id'];
			$b_id = (int) $candidate['existing_fighter_id'];
			$key = self::duplicate_key( $a_id, $b_id );
			if ( isset( $seen[ $key ] ) || ( empty( $filters['show_closed'] ) && self::is_review_closed( $key ) ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$rows[] = self::duplicate_row( $a_id, $b_id, (string) $candidate['reason'], (string) $candidate['confidence'], $key );
		}

		foreach ( (array) ( $duplicates['exact_normalized_name_groups'] ?? array() ) as $group ) {
			$ids = array_map( 'intval', explode( ',', (string) $group['fighter_ids'] ) );
			for ( $i = 0; $i < count( $ids ); $i++ ) {
				for ( $j = $i + 1; $j < count( $ids ); $j++ ) {
					$key = self::duplicate_key( $ids[ $i ], $ids[ $j ] );
					if ( isset( $seen[ $key ] ) || ( empty( $filters['show_closed'] ) && self::is_review_closed( $key ) ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$rows[] = self::duplicate_row( $ids[ $i ], $ids[ $j ], 'same normalized_name group', 'high', $key );
				}
			}
		}

		return array_values( array_filter( $rows ) );
	}

	private static function import_conflict_rows( array $filters = array() ): array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( ! self::table_exists( $tables['source_import_items'] ) ) {
			return array();
		}

		$where = array( "status = 'conflict'" );
		$args = array();
		if ( ! empty( $filters['item_type'] ) ) {
			$where[] = 'item_type = %s';
			$args[] = $filters['item_type'];
		}
		if ( ! empty( $filters['import_run_id'] ) ) {
			$where[] = 'import_run_id = %d';
			$args[] = (int) $filters['import_run_id'];
		}
		if ( ! empty( $filters['source_type'] ) && 'tapology' !== $filters['source_type'] ) {
			return array();
		}
		$sql = "
			SELECT *
			FROM {$tables['source_import_items']}
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY source_id = 'tapology_bout_1116772' DESC, id DESC
			LIMIT 200";
		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function needs_review_rows( array $filters ): array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( ! self::table_exists( $tables['source_import_items'] ) ) {
			return array();
		}

		$where = array( "(status IN ('needs_review', 'conflict', 'failed', 'needs_research') OR action IN ('likely_match_review', 'review_bout_match', 'review_event_match'))" );
		$args = array();

		if ( '' !== $filters['item_type'] ) {
			$where[] = 'item_type = %s';
			$args[] = $filters['item_type'];
		}
		if ( '' !== $filters['status'] ) {
			$where[] = 'status = %s';
			$args[] = $filters['status'];
		}
		if ( '' !== $filters['action_filter'] ) {
			$where[] = 'action = %s';
			$args[] = $filters['action_filter'];
		}
		if ( $filters['import_run_id'] > 0 ) {
			$where[] = 'import_run_id = %d';
			$args[] = $filters['import_run_id'];
		}

		$sql = "SELECT * FROM {$tables['source_import_items']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function provisional_fighter_rows( array $filters = array() ): array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( ! self::table_exists( $tables['fighters'] ) || ! self::table_exists( $tables['fighter_sources'] ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			"
			SELECT
				f.id AS fighter_id,
				f.wp_post_id,
				f.display_name,
				f.status,
				f.rankability_status,
				f.is_public,
				f.is_rankable,
				f.created_at,
				fs.source_fighter_id,
				COUNT(DISTINCT bp.bout_id) AS linked_bouts_count,
				COALESCE(st.pro_fights_count, 0) AS pro_fights_count,
				COALESCE(st.wins, 0) AS wins,
				COALESCE(st.losses, 0) AS losses,
				COALESCE(st.draws, 0) AS draws,
				COALESCE(st.nc, 0) AS nc
			FROM {$tables['fighters']} f
			INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
			LEFT JOIN {$tables['bout_participants']} bp ON bp.fighter_id = f.id
			LEFT JOIN {$tables['fighter_stats_current']} st ON st.fighter_id = f.id
			WHERE f.status = 'provisional'
				AND f.rankability_status = 'pending_review'
				AND f.is_public = 0
				AND f.is_rankable = 0
				AND f.deleted_soft = 0
			GROUP BY f.id, fs.source_fighter_id, st.id
			ORDER BY f.created_at DESC, f.id DESC
			LIMIT 200
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$duplicates = self::duplicate_rows( $filters );
		$counts = array();
		foreach ( $duplicates as $duplicate ) {
			$a = (int) $duplicate['fighter_a']['id'];
			$b = (int) $duplicate['fighter_b']['id'];
			$counts[ $a ] = ( $counts[ $a ] ?? 0 ) + 1;
			$counts[ $b ] = ( $counts[ $b ] ?? 0 ) + 1;
		}

		$filtered = array();
		foreach ( $rows as $row ) {
			$item_key = 'provisional:' . (int) $row['fighter_id'];
			if ( empty( $filters['show_closed'] ) && self::is_review_closed( $item_key ) ) {
				continue;
			}
			$row['duplicate_candidate_count'] = $counts[ (int) $row['fighter_id'] ] ?? 0;
			$row['item_key'] = $item_key;
			$row['review_status'] = self::review_status_for_key( $item_key );
			$row['source_type'] = 'tapology';
			$filtered[] = $row;
		}

		return $filtered;
	}

	private static function likely_row_from_candidate( array $candidate, ?array $scraped, ?array $existing, string $item_key ): array {
		return array(
			'item_key'              => $item_key,
			'source_import_item_id' => 0,
			'scraped_fighter_id'    => (int) $candidate['scraped_fighter_id'],
			'scraped_display_name'  => (string) $candidate['scraped_display_name'],
			'source_fighter_id'     => (string) $candidate['scraped_source_fighter_id'],
			'scraped_linked_bouts'  => $scraped ? self::linked_bouts_count( (int) $candidate['scraped_fighter_id'] ) : 0,
			'scraped_stats_summary' => $scraped ? self::stats_summary( (int) $candidate['scraped_fighter_id'] ) : 'none',
			'existing_fighter_id'   => (int) $candidate['existing_fighter_id'],
			'existing_display_name' => (string) $candidate['existing_display_name'],
			'existing_status'       => $existing ? self::format_status_flags( $existing ) : '-',
			'existing_linked_bouts' => $existing ? self::linked_bouts_count( (int) $candidate['existing_fighter_id'] ) : 0,
			'existing_stats_summary'=> $existing ? self::stats_summary( (int) $candidate['existing_fighter_id'] ) : 'none',
			'existing_source_count' => $existing ? self::source_count( (int) $candidate['existing_fighter_id'] ) : 0,
			'reason'                => (string) $candidate['reason'],
			'confidence'            => (string) $candidate['confidence'],
			'import_run_id'         => 0,
			'created_at'            => $scraped ? (string) $scraped['created_at'] : '',
			'review_status'         => self::review_status_for_key( $item_key ),
			'source_type'           => 'tapology',
			'can_link_source'       => $scraped && $existing && 'provisional' === (string) $scraped['status'] && '' !== (string) $candidate['scraped_source_fighter_id'],
		);
	}

	private static function duplicate_row( int $a_id, int $b_id, string $reason, string $confidence, string $key ): ?array {
		$a = self::fighter_row( $a_id );
		$b = self::fighter_row( $b_id );
		if ( ! $a || ! $b ) {
			return null;
		}

		$a_source = self::tapology_source_for_fighter( $a_id );
		$b_source = self::tapology_source_for_fighter( $b_id );
		$can_link_a_to_b = $a_source && 'provisional' === (string) $a['status'] && ! $b_source;
		$can_link_b_to_a = $b_source && 'provisional' === (string) $b['status'] && ! $a_source;

		return array(
			'item_key'               => $key,
			'fighter_a'              => array_merge( $a, array( 'source_count' => self::source_count( $a_id ) ) ),
			'fighter_b'              => array_merge( $b, array( 'source_count' => self::source_count( $b_id ) ) ),
			'reason'                 => $reason,
			'confidence'             => $confidence,
			'source_types'           => self::source_types_for_pair( $a_id, $b_id ),
			'source_type'            => false !== strpos( self::source_types_for_pair( $a_id, $b_id ), 'tapology' ) ? 'tapology' : '',
			'review_status'          => self::review_status_for_key( $key ),
			'can_link_source'        => $can_link_a_to_b || $can_link_b_to_a,
			'provisional_fighter_id' => $can_link_a_to_b ? $a_id : ( $can_link_b_to_a ? $b_id : 0 ),
			'existing_fighter_id'    => $can_link_a_to_b ? $b_id : ( $can_link_b_to_a ? $a_id : 0 ),
			'source_fighter_id'      => $can_link_a_to_b ? (string) $a_source['source_fighter_id'] : ( $can_link_b_to_a ? (string) $b_source['source_fighter_id'] : '' ),
		);
	}

	private static function render_compare_view( int $a_id, int $b_id ): void {
		$a = self::fighter_row( $a_id );
		$b = self::fighter_row( $b_id );
		if ( ! $a || ! $b ) {
			return;
		}

		?>
		<h3><?php echo esc_html__( 'Compare Fighters', 'mma-future-data-engine' ); ?></h3>
		<table class="widefat striped" style="margin-bottom: 16px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Field', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html( '#' . (int) $a['id'] . ' ' . $a['display_name'] ); ?></th>
					<th><?php echo esc_html( '#' . (int) $b['id'] . ' ' . $b['display_name'] ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'display_name', 'nickname', 'normalized_name', 'gender', 'date_of_birth', 'birth_year', 'nationality', 'weight_class', 'status', 'rankability_status', 'is_public', 'is_rankable', 'wp_post_id' ) as $field ) : ?>
					<tr>
						<th><?php echo esc_html( self::label( $field ) ); ?></th>
						<td><?php echo esc_html( (string) ( $a[ $field ] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $b[ $field ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th><?php echo esc_html__( 'Source mappings', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( self::source_mapping_summary( $a_id ) ); ?></td>
					<td><?php echo esc_html( self::source_mapping_summary( $b_id ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Linked bouts', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) self::linked_bouts_count( $a_id ) ); ?></td>
					<td><?php echo esc_html( (string) self::linked_bouts_count( $b_id ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Stats', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( self::stats_summary( $a_id ) ); ?></td>
					<td><?php echo esc_html( self::stats_summary( $b_id ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Latest audit', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( self::latest_audit_summary( $a_id ) ); ?></td>
					<td><?php echo esc_html( self::latest_audit_summary( $b_id ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private static function simulate_participant_remap( int $from_id, int $to_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$errors = array();
		$bout_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT bout_id FROM {$tables['bout_participants']} WHERE fighter_id = %d OR opponent_fighter_id = %d ORDER BY bout_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$from_id,
					$from_id
				)
			)
		);
		$participants_after = array();
		$bout_summaries = array();
		$checks = array(
			'affected_bouts_count'                 => count( $bout_ids ),
			'participant_count_remains_two'        => true,
			'no_same_fighter_bouts'                => true,
			'no_duplicate_participant_roles'       => true,
			'no_missing_fighter_references'        => true,
			'no_missing_opponent_references'       => true,
			'no_duplicate_fighters_in_bout'        => true,
		);

		foreach ( $bout_ids as $bout_id ) {
			$participants = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['bout_participants']} WHERE bout_id = %d ORDER BY participant_role ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$bout_id
				),
				ARRAY_A
			);
			$after = array();
			$roles = array();
			$fighter_ids = array();
			$opponent_ids = array();

			if ( 2 !== count( $participants ) ) {
				$checks['participant_count_remains_two'] = false;
				$errors[] = sprintf( __( 'Bout %d does not have exactly two participants.', 'mma-future-data-engine' ), $bout_id );
			}

			foreach ( $participants as $participant ) {
				$simulated = $participant;
				if ( (int) $simulated['fighter_id'] === $from_id ) {
					$simulated['fighter_id'] = $to_id;
				}
				if ( (int) $simulated['opponent_fighter_id'] === $from_id ) {
					$simulated['opponent_fighter_id'] = $to_id;
				}
				$after[] = $simulated;
				$participants_after[] = $simulated;
				$roles[] = (string) $simulated['participant_role'];
				$fighter_ids[] = (int) $simulated['fighter_id'];
				$opponent_ids[] = (int) $simulated['opponent_fighter_id'];

				if ( (int) $simulated['fighter_id'] <= 0 ) {
					$checks['no_missing_fighter_references'] = false;
					$errors[] = sprintf( __( 'Bout %d would have a missing fighter_id.', 'mma-future-data-engine' ), $bout_id );
				}
				if ( (int) $simulated['opponent_fighter_id'] <= 0 ) {
					$checks['no_missing_opponent_references'] = false;
					$errors[] = sprintf( __( 'Bout %d would have a missing opponent_fighter_id.', 'mma-future-data-engine' ), $bout_id );
				}
				if ( (int) $simulated['fighter_id'] === (int) $simulated['opponent_fighter_id'] ) {
					$checks['no_same_fighter_bouts'] = false;
					$errors[] = sprintf( __( 'Bout %d would put the same fighter on both sides.', 'mma-future-data-engine' ), $bout_id );
				}
			}

			if ( count( $roles ) !== count( array_unique( $roles ) ) ) {
				$checks['no_duplicate_participant_roles'] = false;
				$errors[] = sprintf( __( 'Bout %d would have duplicate participant roles.', 'mma-future-data-engine' ), $bout_id );
			}
			if ( count( $fighter_ids ) !== count( array_unique( $fighter_ids ) ) ) {
				$checks['no_duplicate_fighters_in_bout'] = false;
				$errors[] = sprintf( __( 'Bout %d would have duplicate fighters.', 'mma-future-data-engine' ), $bout_id );
			}
			foreach ( array_merge( $fighter_ids, $opponent_ids ) as $fighter_id ) {
				if ( $fighter_id > 0 && ! self::fighter_row( $fighter_id ) ) {
					$checks['no_missing_fighter_references'] = false;
					$errors[] = sprintf( __( 'Bout %1$d would reference missing fighter %2$d.', 'mma-future-data-engine' ), $bout_id, $fighter_id );
				}
			}

			$bout_summaries[] = array(
				'bout_id'             => $bout_id,
				'participant_count'   => count( $participants ),
				'roles_after'         => array_values( $roles ),
				'fighter_ids_after'   => array_values( $fighter_ids ),
				'opponent_ids_after'  => array_values( $opponent_ids ),
			);
		}

		return array(
			'ok'                 => empty( $errors ),
			'errors'             => array_values( array_unique( $errors ) ),
			'bout_ids'           => $bout_ids,
			'participants_after' => $participants_after,
			'bout_summaries'     => $bout_summaries,
			'checks'             => $checks,
		);
	}

	private static function validate_participant_remap( int $from_id, int $to_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$bout_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT bout_id FROM {$tables['bout_participants']} WHERE fighter_id = %d OR opponent_fighter_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_id,
				$from_id
			)
		);

		foreach ( $bout_ids as $bout_id ) {
			$participants = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['bout_participants']} WHERE bout_id = %d ORDER BY participant_role ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $bout_id
				),
				ARRAY_A
			);

			if ( 2 !== count( $participants ) ) {
				return array( 'ok' => false, 'message' => sprintf( __( 'Remap blocked: bout %d does not have exactly two participants.', 'mma-future-data-engine' ), (int) $bout_id ), 'bout_ids' => $bout_ids );
			}

			$roles = array();
			$fighter_ids = array();
			foreach ( $participants as $participant ) {
				$fighter_id = (int) $participant['fighter_id'];
				$opponent_id = (int) $participant['opponent_fighter_id'];
				if ( $fighter_id === $from_id ) {
					$fighter_id = $to_id;
				}
				if ( $opponent_id === $from_id ) {
					$opponent_id = $to_id;
				}
				if ( $fighter_id <= 0 || $opponent_id <= 0 ) {
					return array( 'ok' => false, 'message' => sprintf( __( 'Remap blocked: bout %d would have a missing fighter reference.', 'mma-future-data-engine' ), (int) $bout_id ), 'bout_ids' => $bout_ids );
				}
				if ( $fighter_id === $opponent_id ) {
					return array( 'ok' => false, 'message' => sprintf( __( 'Remap blocked: bout %d would put the same fighter on both sides.', 'mma-future-data-engine' ), (int) $bout_id ), 'bout_ids' => $bout_ids );
				}
				$roles[] = (string) $participant['participant_role'];
				$fighter_ids[] = $fighter_id;
			}

			if ( 2 !== count( array_unique( $roles ) ) || 2 !== count( array_unique( $fighter_ids ) ) ) {
				return array( 'ok' => false, 'message' => sprintf( __( 'Remap blocked: bout %d would create duplicate roles or duplicate fighters.', 'mma-future-data-engine' ), (int) $bout_id ), 'bout_ids' => $bout_ids );
			}
		}

		return array( 'ok' => true, 'message' => '', 'bout_ids' => array_map( 'intval', $bout_ids ) );
	}

	private static function upsert_review_item( array $data ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$now = DateTime::mysql_now();
		$item_key = (string) $data['item_key'];
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$tables['review_items']} WHERE item_key = %s LIMIT 1", $item_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $existing_id <= 0 && ! empty( $data['source_id'] ) ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tables['review_items']} WHERE item_type = %s AND source_type = %s AND source_id = %s AND action_taken = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(string) ( $data['item_type'] ?? 'review_item' ),
					(string) ( $data['source_type'] ?? '' ),
					(string) $data['source_id'],
					(string) ( $data['action_taken'] ?? '' )
				)
			);
		}

		$is_resolved_state = in_array( (string) ( $data['status'] ?? 'open' ), array( 'resolved', 'dismissed', 'needs_research' ), true );
		$row = array(
			'item_type'            => (string) ( $data['item_type'] ?? 'review_item' ),
			'item_key'             => $item_key,
			'source_type'          => $data['source_type'] ?? null,
			'source_id'            => $data['source_id'] ?? null,
			'canonical_id'         => $data['canonical_id'] ?? null,
			'related_canonical_id' => $data['related_canonical_id'] ?? null,
			'status'               => (string) ( $data['status'] ?? 'open' ),
			'action_taken'         => $data['action_taken'] ?? null,
			'notes'                => $data['notes'] ?? null,
			'resolved_by'          => $is_resolved_state && get_current_user_id() > 0 ? get_current_user_id() : null,
			'resolved_at'          => $is_resolved_state ? $now : null,
			'updated_at'           => $now,
		);

		if ( $existing_id > 0 ) {
			$wpdb->update( $tables['review_items'], $row, array( 'id' => $existing_id ), null, array( '%d' ) );
			return;
		}

		$row['created_by'] = get_current_user_id() > 0 ? get_current_user_id() : null;
		$row['created_at'] = $now;
		$wpdb->insert( $tables['review_items'], $row );
	}

	private static function update_import_item_review( int $item_id, string $status, string $action_taken, ?string $notes = null ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$item = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['source_import_items']} WHERE id = %d LIMIT 1", $item_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $item ) {
			return;
		}

		$warnings = array();
		if ( is_string( $item['warnings_json'] ) && '' !== $item['warnings_json'] ) {
			$decoded = json_decode( $item['warnings_json'], true );
			$warnings = is_array( $decoded ) ? $decoded : array( $item['warnings_json'] );
		}
		$warnings[] = array(
			'review_action' => $action_taken,
			'review_notes'  => $notes,
			'reviewed_by'   => get_current_user_id(),
			'reviewed_at'   => DateTime::mysql_now(),
		);

		$wpdb->update(
			$tables['source_import_items'],
			array(
				'status'        => $status,
				'action'        => $action_taken,
				'warnings_json' => wp_json_encode( $warnings ),
				'updated_at'    => DateTime::mysql_now(),
			),
			array( 'id' => $item_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function is_review_closed( string $item_key ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		if ( ! self::table_exists( $tables['review_items'] ) ) {
			return false;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$tables['review_items']} WHERE item_key = %s LIMIT 1", $item_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return in_array( $status, array( 'resolved', 'dismissed' ), true );
	}

	private static function candidate_hidden_fields( array $row ): array {
		return array(
			'item_key'              => $row['item_key'],
			'source_import_item_id' => (int) $row['source_import_item_id'],
			'scraped_fighter_id'    => (int) $row['scraped_fighter_id'],
			'existing_fighter_id'   => (int) $row['existing_fighter_id'],
			'source_fighter_id'     => $row['source_fighter_id'],
		);
	}

	private static function review_item_by_key( string $item_key ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( ! self::table_exists( $tables['review_items'] ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['review_items']} WHERE item_key = %s LIMIT 1", $item_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function review_status_for_key( string $item_key ): string {
		$row = self::review_item_by_key( $item_key );
		return $row ? (string) $row['status'] : 'open';
	}

	private static function source_import_item_row( int $item_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['source_import_items']} WHERE id = %d LIMIT 1", $item_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function fighter_row( int $fighter_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['fighters']} WHERE id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	private static function source_mapping_by_id( int $source_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['fighter_sources']} WHERE id = %d LIMIT 1", $source_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function tapology_source_for_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['fighter_sources']} WHERE fighter_id = %d AND source_type = 'tapology' LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	private static function source_mappings_for_fighter( int $fighter_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['fighter_sources']} WHERE fighter_id = %d ORDER BY source_type ASC, id ASC", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	private static function source_count( int $fighter_id ): int {
		global $wpdb;

		$tables = Schema::table_names();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['fighter_sources']} WHERE fighter_id = %d", $fighter_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private static function linked_bouts_count( int $fighter_id ): int {
		global $wpdb;

		$tables = Schema::table_names();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT bout_id) FROM {$tables['bout_participants']} WHERE fighter_id = %d", $fighter_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private static function participant_rows_for_fighter( int $fighter_id, array $bout_ids = array() ): array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( empty( $bout_ids ) ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$tables['bout_participants']} WHERE fighter_id = %d OR opponent_fighter_id = %d ORDER BY bout_id ASC, id ASC", $fighter_id, $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		$ids = implode( ',', array_map( 'absint', $bout_ids ) );
		return $wpdb->get_results(
			"SELECT * FROM {$tables['bout_participants']} WHERE bout_id IN ({$ids}) ORDER BY bout_id ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	private static function source_types_for_pair( int $a_id, int $b_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$types = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT source_type FROM {$tables['fighter_sources']} WHERE fighter_id IN (%d, %d) ORDER BY source_type ASC", $a_id, $b_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return implode( ', ', array_map( 'strval', $types ) );
	}

	private static function source_mapping_summary( int $fighter_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT source_type, source_fighter_id, is_verified FROM {$tables['fighter_sources']} WHERE fighter_id = %d ORDER BY source_type ASC", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return 'none';
		}

		return implode(
			'; ',
			array_map(
				static fn( array $row ): string => (string) $row['source_type'] . ':' . (string) $row['source_fighter_id'] . ' verified=' . (int) $row['is_verified'],
				$rows
			)
		);
	}

	private static function stats_summary( int $fighter_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['fighter_stats_current']} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $row ) {
			return 'none';
		}

		return sprintf(
			'%d-%d-%d (%d NC), pro_fights=%d, last=%s',
			(int) $row['wins'],
			(int) $row['losses'],
			(int) $row['draws'],
			(int) $row['nc'],
			(int) $row['pro_fights_count'],
			(string) $row['last_fight_date']
		);
	}

	private static function latest_audit_summary( int $fighter_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT action, created_at FROM {$tables['audit_log']} WHERE entity_type = 'fighter' AND entity_id = %d ORDER BY id DESC LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? (string) $row['action'] . ' at ' . (string) $row['created_at'] : 'none';
	}

	private static function stats_ranking_baseline(): array {
		$summary = self::global_integrity_summary();
		return array(
			'stats_rows_count'           => (int) $summary['stats_rows_count'],
			'current_ranking_rows_count' => (int) $summary['current_ranking_rows_count'],
			'active_ranking_run_id'      => (int) $summary['active_ranking_run_id'],
		);
	}

	private static function post_action_integrity_summary( array $affected_bout_ids, array $baseline ): array {
		$summary = self::global_integrity_summary( $affected_bout_ids );
		$errors = array();
		foreach ( array( 'stats_rows_count', 'current_ranking_rows_count', 'active_ranking_run_id' ) as $key ) {
			if ( isset( $baseline[ $key ] ) && (int) $baseline[ $key ] !== (int) $summary[ $key ] ) {
				$errors[] = sprintf( '%s changed from %d to %d.', $key, (int) $baseline[ $key ], (int) $summary[ $key ] );
			}
		}
		foreach ( array( 'malformed_bouts_count', 'same_fighter_bouts_count', 'missing_fighter_references_count', 'missing_opponent_fighter_references_count', 'duplicate_roles_count' ) as $key ) {
			if ( (int) $summary[ $key ] > 0 ) {
				$errors[] = sprintf( '%s is %d.', $key, (int) $summary[ $key ] );
			}
		}
		$summary['ok'] = empty( $errors );
		$summary['errors'] = $errors;
		return $summary;
	}

	private static function global_integrity_summary( array $affected_bout_ids = array() ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$affected_where = '';
		if ( ! empty( $affected_bout_ids ) ) {
			$ids = implode( ',', array_map( 'absint', $affected_bout_ids ) );
			$affected_where = " AND b.id IN ({$ids})";
		}

		$malformed = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['bouts']} b
			LEFT JOIN (
				SELECT bout_id, COUNT(*) AS participant_count
				FROM {$tables['bout_participants']}
				GROUP BY bout_id
			) p ON p.bout_id = b.id
			WHERE COALESCE(p.participant_count, 0) <> 2 {$affected_where}
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$same_fighter = (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT p.bout_id)
			FROM {$tables['bout_participants']} p
			INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
			WHERE p.fighter_id IS NOT NULL
				AND p.opponent_fighter_id IS NOT NULL
				AND p.fighter_id = p.opponent_fighter_id {$affected_where}
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$missing_fighter_refs = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['bout_participants']} p
			INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
			LEFT JOIN {$tables['fighters']} f ON f.id = p.fighter_id
			WHERE (p.fighter_id IS NULL OR f.id IS NULL) {$affected_where}
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$missing_opponent_refs = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['bout_participants']} p
			INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
			LEFT JOIN {$tables['fighters']} f ON f.id = p.opponent_fighter_id
			WHERE (p.opponent_fighter_id IS NULL OR f.id IS NULL) {$affected_where}
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$duplicate_roles = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM (
				SELECT p.bout_id, p.participant_role, COUNT(*) AS role_count
				FROM {$tables['bout_participants']} p
				INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
				WHERE 1=1 {$affected_where}
				GROUP BY p.bout_id, p.participant_role
				HAVING role_count > 1
			) duplicate_roles
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'malformed_bouts_count'                    => $malformed,
			'affected_malformed_bouts_count'           => $malformed,
			'same_fighter_bouts_count'                 => $same_fighter,
			'missing_fighter_references_count'         => $missing_fighter_refs,
			'missing_opponent_fighter_references_count'=> $missing_opponent_refs,
			'duplicate_roles_count'                    => $duplicate_roles,
			'stats_rows_count'                         => self::table_exists( $tables['fighter_stats_current'] ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_stats_current']}" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'current_ranking_rows_count'               => self::table_exists( $tables['ranking_current'] ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'active_ranking_run_id'                    => self::table_exists( $tables['ranking_runs'] ) ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$tables['ranking_runs']} WHERE is_active = 1" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private static function duplicate_key( int $a_id, int $b_id ): string {
		$ids = array( $a_id, $b_id );
		sort( $ids );
		return 'duplicate:' . $ids[0] . ':' . $ids[1];
	}

	private static function render_key_value_table( array $data ): void {
		?>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $data as $key => $value ) : ?>
					<tr>
						<th scope="row" style="width: 300px;"><?php echo esc_html( self::label( (string) $key ) ); ?></th>
						<td><?php echo esc_html( self::format_value( $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function format_candidate_context( array $row, string $side ): string {
		if ( 'scraped' === $side ) {
			return sprintf(
				'<strong>#%d %s</strong><br><code>tapology:%s</code><br>%s: %d<br>%s: %s',
				(int) ( $row['scraped_fighter_id'] ?? 0 ),
				esc_html( (string) ( $row['scraped_display_name'] ?? '-' ) ),
				esc_html( (string) ( $row['source_fighter_id'] ?? '-' ) ),
				esc_html__( 'Linked bouts', 'mma-future-data-engine' ),
				(int) ( $row['scraped_linked_bouts'] ?? 0 ),
				esc_html__( 'Stats', 'mma-future-data-engine' ),
				esc_html( (string) ( $row['scraped_stats_summary'] ?? 'none' ) )
			);
		}

		return sprintf(
			'<strong>#%d %s</strong><br>%s<br>%s: %d<br>%s: %s<br>%s: %d',
			(int) ( $row['existing_fighter_id'] ?? 0 ),
			esc_html( (string) ( $row['existing_display_name'] ?? '-' ) ),
			esc_html( (string) ( $row['existing_status'] ?? '-' ) ),
			esc_html__( 'Linked bouts', 'mma-future-data-engine' ),
			(int) ( $row['existing_linked_bouts'] ?? 0 ),
			esc_html__( 'Stats', 'mma-future-data-engine' ),
			esc_html( (string) ( $row['existing_stats_summary'] ?? 'none' ) ),
			esc_html__( 'Source mappings', 'mma-future-data-engine' ),
			(int) ( $row['existing_source_count'] ?? 0 )
		);
	}

	private static function format_confidence_reason( array $row ): string {
		return sprintf(
			'<strong>%s</strong><br>%s',
			esc_html( (string) ( $row['confidence'] ?? '-' ) ),
			esc_html( (string) ( $row['reason'] ?? '-' ) )
		);
	}

	private static function format_created_import_reference( array $row ): string {
		$created = '' !== (string) ( $row['created_at'] ?? '' ) ? (string) $row['created_at'] : '-';
		$run = ! empty( $row['import_run_id'] ) ? '#' . (int) $row['import_run_id'] : '-';
		return 'created=' . $created . '; import_run=' . $run;
	}

	private static function format_fighter_detail( array $fighter ): string {
		return sprintf(
			'<strong>#%d %s</strong><br>%s<br>%s: %d<br>%s: %s<br>%s: %d',
			(int) $fighter['id'],
			esc_html( (string) $fighter['display_name'] ),
			esc_html( self::format_status_flags( $fighter ) ),
			esc_html__( 'Linked bouts', 'mma-future-data-engine' ),
			self::linked_bouts_count( (int) $fighter['id'] ),
			esc_html__( 'Stats', 'mma-future-data-engine' ),
			esc_html( self::stats_summary( (int) $fighter['id'] ) ),
			esc_html__( 'Source mappings', 'mma-future-data-engine' ),
			self::source_count( (int) $fighter['id'] )
		);
	}

	private static function format_import_conflict_preview( array $row ): string {
		$mapping = self::canonical_mapping_for_import_item( $row );
		$lines = array(
			'existing canonical mapping=' . ( $mapping ? '#' . (int) $mapping['canonical_id'] . ' identity=' . (string) $mapping['identity_hash'] : 'none found' ),
			'incoming source ID=' . (string) $row['source_id'],
			'incoming identity_hash=' . (string) ( $row['identity_hash'] ?? '' ),
			'import run=' . (int) $row['import_run_id'],
		);
		return esc_html( implode( '; ', $lines ) );
	}

	private static function canonical_mapping_for_import_item( array $row ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$item_type = (string) ( $row['item_type'] ?? '' );
		if ( 'fighter' === $item_type ) {
			$table = $tables['fighter_sources'];
			$source_id_col = 'source_fighter_id';
			$canonical_col = 'fighter_id';
		} elseif ( 'event' === $item_type ) {
			$table = $tables['event_sources'];
			$source_id_col = 'source_event_id';
			$canonical_col = 'event_id';
		} else {
			$table = $tables['bout_sources'];
			$source_id_col = 'source_bout_id';
			$canonical_col = 'bout_id';
		}
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$canonical_col} AS canonical_id, identity_hash FROM {$table} WHERE source_type = %s AND {$source_id_col} = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'tapology',
				(string) $row['source_id']
			),
			ARRAY_A
		);
		return $mapping ?: null;
	}

	private static function format_status_flags( array $fighter ): string {
		return sprintf(
			'%s / %s / public=%d rankable=%d',
			(string) $fighter['status'],
			(string) $fighter['rankability_status'],
			(int) $fighter['is_public'],
			(int) $fighter['is_rankable']
		);
	}

	private static function format_fighter_summary( array $fighter ): string {
		return sprintf(
			'#%d %s (%s, sources=%d)',
			(int) $fighter['id'],
			(string) $fighter['display_name'],
			self::format_status_flags( $fighter ),
			(int) ( $fighter['source_count'] ?? 0 )
		);
	}

	private static function format_duplicate_flags( array $row ): string {
		return sprintf(
			'A public=%d rankable=%d; B public=%d rankable=%d',
			(int) $row['fighter_a']['is_public'],
			(int) $row['fighter_a']['is_rankable'],
			(int) $row['fighter_b']['is_public'],
			(int) $row['fighter_b']['is_rankable']
		);
	}

	private static function format_stats_row( array $row ): string {
		return sprintf(
			'%d-%d-%d (%d NC), pro_fights=%d',
			(int) $row['wins'],
			(int) $row['losses'],
			(int) $row['draws'],
			(int) $row['nc'],
			(int) $row['pro_fights_count']
		);
	}

	private static function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value ) {
			return 'none';
		}
		if ( is_array( $value ) ) {
			return self::shorten( wp_json_encode( $value ) );
		}
		return (string) $value;
	}

	private static function label( string $key ): string {
		return ucwords( str_replace( '_', ' ', $key ) );
	}

	private static function shorten( string $value ): string {
		if ( strlen( $value ) <= 220 ) {
			return $value;
		}
		return substr( $value, 0, 217 ) . '...';
	}
}
