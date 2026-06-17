<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\FighterAliasRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Repositories\FighterStatsOverrideRepository;
use MMAF\DataEngine\Repositories\FighterStatsRepository;
use MMAF\DataEngine\Repositories\RestReadRepository;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\FighterReadinessService;
use MMAF\DataEngine\Services\FighterService;
use MMAF\DataEngine\Support\Capabilities;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FightersPage {
	private const PAGE_SLUG = 'mmaf-fighters';

	public static function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'mmaf-fighter-admin',
			plugins_url( 'assets/admin-fighters.js', MMAF_PLUGIN_FILE ),
			array( 'jquery' ),
			MMAF_PLUGIN_VERSION,
			true
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;
		$form_input = null;
		$form_preflight = array();

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' === $request_method ) {
			$notice = self::handle_post();
			if ( isset( $notice['form_input'] ) && is_array( $notice['form_input'] ) ) {
				$form_input = $notice['form_input'];
			}
			if ( isset( $notice['preflight'] ) && is_array( $notice['preflight'] ) ) {
				$form_preflight = $notice['preflight'];
			}
		}

		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$fighter_id = isset( $_GET['fighter_id'] ) ? absint( $_GET['fighter_id'] ) : 0;

		if ( isset( $_GET['mmaf_notice'] ) ) {
			$notice = array(
				'type'    => 'success',
				'message' => sanitize_text_field( wp_unslash( $_GET['mmaf_notice'] ) ),
			);
		}
		if ( isset( $notice['redirect_fallback'] ) && is_array( $notice['redirect_fallback'] ) ) {
			$action     = isset( $notice['redirect_fallback']['action'] ) ? sanitize_key( $notice['redirect_fallback']['action'] ) : $action;
			$fighter_id = isset( $notice['redirect_fallback']['fighter_id'] ) ? absint( $notice['redirect_fallback']['fighter_id'] ) : $fighter_id;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Canonical Fighters', 'mma-future-data-engine' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::page_url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Fighter', 'mma-future-data-engine' ) . '</a>';

		if ( $notice ) {
			self::render_notice( $notice['type'], $notice['message'] );
			if ( isset( $notice['bulk_result'] ) && is_array( $notice['bulk_result'] ) ) {
				self::render_bulk_result( $notice['bulk_result'] );
			}
		}

		if ( $form_input ) {
			$form_action = isset( $form_input['mmaf_action'] ) ? sanitize_key( wp_unslash( $form_input['mmaf_action'] ) ) : '';
			self::render_form( 'update' === $form_action ? $fighter_id : 0, $form_input, $form_preflight );
		} elseif ( 'new' === $action ) {
			self::render_form();
		} elseif ( 'edit' === $action && $fighter_id > 0 ) {
			self::render_form( $fighter_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function handle_post(): ?array {
		$action = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';

		if ( 'bulk_readiness' === $action ) {
			return self::handle_bulk_post();
		}

		if ( 'stats_override_save' === $action || 'stats_override_clear' === $action ) {
			return self::handle_stats_override_post( $action );
		}

		if ( ! isset( $_POST['mmaf_fighter_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_save_fighter', 'mmaf_fighter_nonce' );

		$fighter_id = isset( $_POST['fighter_id'] ) ? absint( $_POST['fighter_id'] ) : 0;
		$service    = new FighterService();

		try {
			if ( 'create' === $action ) {
				$fighter = $service->create( $_POST, get_current_user_id() );
				return self::redirect_to_fighter_edit_or_notice(
					(int) $fighter['id'],
					self::save_message( __( 'Fighter created.', 'mma-future-data-engine' ), $fighter['_mmaf_notices'] ?? array() )
				);
			}

			if ( 'update' === $action && $fighter_id > 0 ) {
				$fighter = $service->update( $fighter_id, $_POST, get_current_user_id() );
				return self::redirect_to_fighter_edit_or_notice(
					$fighter_id,
					self::save_message( __( 'Fighter updated.', 'mma-future-data-engine' ), $fighter['_mmaf_notices'] ?? array() )
				);
			}

			return array(
				'type'    => 'error',
				'message' => __( 'Invalid fighter action.', 'mma-future-data-engine' ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function handle_stats_override_post( string $action ): ?array {
		if ( ! isset( $_POST['mmaf_stats_override_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing stats override security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_stats_override', 'mmaf_stats_override_nonce' );

		$fighter_id = isset( $_POST['fighter_id'] ) ? absint( $_POST['fighter_id'] ) : 0;
		if ( $fighter_id <= 0 || ! ( new FighterRepository() )->find( $fighter_id ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Fighter not found for stats override.', 'mma-future-data-engine' ),
			);
		}

		$repository = new FighterStatsOverrideRepository();
		$audit_log  = new AuditLogService();
		$before     = $repository->find_for_fighter( $fighter_id );

		try {
			if ( 'stats_override_clear' === $action ) {
				$after = $repository->clear( $fighter_id, get_current_user_id() );
				$audit_log->write(
					'fighter_stats_override_cleared',
					'fighter',
					$fighter_id,
					$before,
					$after,
					'Manual display stats override cleared.',
					get_current_user_id()
				);

				return self::redirect_to_fighter_edit_or_notice(
					$fighter_id,
					__( 'Manual stats override cleared.', 'mma-future-data-engine' )
				);
			}

			$record = self::stats_override_record_from_post();
			$reason = isset( $_POST['override_reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['override_reason'] ) ) ) : '';
			if ( '' === $reason ) {
				throw new \InvalidArgumentException( __( 'Manual stats override requires a reason.', 'mma-future-data-engine' ) );
			}

			$stats = ( new FighterStatsRepository() )->find_stat_by_fighter( $fighter_id );
			$after = $repository->upsert(
				$fighter_id,
				$record,
				$reason,
				FighterStatsOverrideRepository::calculated_stats_hash( $stats ),
				get_current_user_id()
			);

			$audit_log->write(
				'fighter_stats_override_saved',
				'fighter',
				$fighter_id,
				$before,
				$after,
				'Manual display stats override saved: ' . $reason,
				get_current_user_id()
			);

			return self::redirect_to_fighter_edit_or_notice(
				$fighter_id,
				__( 'Manual stats override saved.', 'mma-future-data-engine' )
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function stats_override_record_from_post(): array {
		$record = array();
		foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $field ) {
			$key = 'override_' . $field;
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( '' === $value || ! preg_match( '/^\d{1,4}$/', $value ) ) {
				throw new \InvalidArgumentException( __( 'Override record values must be whole numbers from 0 through 9999.', 'mma-future-data-engine' ) );
			}
			$record[ $field ] = (int) $value;
		}

		return $record;
	}

	private static function handle_bulk_post(): array {
		if ( ! isset( $_POST['mmaf_fighter_bulk_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing bulk action security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_fighter_bulk_action', 'mmaf_fighter_bulk_nonce' );

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$fighter_ids = isset( $_POST['fighter_ids'] ) && is_array( $_POST['fighter_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['fighter_ids'] ) ) : array();

		try {
			$result = ( new FighterReadinessService() )->process_bulk_action( $bulk_action, $fighter_ids, get_current_user_id() );

			return array(
				'type'        => $result['blocked_count'] > 0 ? 'warning' : 'success',
				'message'     => $result['message'],
				'bulk_result' => $result,
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function render_list(): void {
		$repository = new FighterRepository();
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters    = self::current_filters();
		$per_page   = self::current_per_page();
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total      = $repository->count( $search, $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged      = min( $paged, $total_pages );
		$offset     = ( $paged - 1 ) * $per_page;
		$fighters   = $repository->list( $search, $per_page, $offset, $filters );
		$readiness_service = new FighterReadinessService();
		$readiness_report  = $readiness_service->report();
		$table_readiness   = $readiness_service->evaluate_fighters_for_table( $fighters );
		$query_filters     = array_merge( array( 's' => $search ), self::filter_query_args( $filters ) );
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label class="screen-reader-text" for="mmaf-fighter-search"><?php echo esc_html__( 'Search fighters', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-fighter-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search display name', 'mma-future-data-engine' ); ?>">
			<?php self::render_fighter_filters( $filters ); ?>
			<?php self::render_per_page_select( $per_page ); ?>
			<?php submit_button( __( 'Filter', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
		</form>

		<?php self::render_readiness_report( $readiness_report, $readiness_service ); ?>

		<?php self::render_pagination( $total, $paged, $per_page, $query_filters ); ?>

		<form method="post" action="<?php echo esc_url( self::page_url( array_merge( $query_filters, array( 'per_page' => $per_page, 'paged' => $paged ) ) ) ); ?>">
			<?php wp_nonce_field( 'mmaf_fighter_bulk_action', 'mmaf_fighter_bulk_nonce' ); ?>
			<input type="hidden" name="mmaf_action" value="bulk_readiness">
			<?php self::render_bulk_controls( 'top' ); ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><input type="checkbox" id="mmaf-select-all-fighters"></th>
					<th><?php echo esc_html__( 'ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Display name', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Nickname', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Rankability status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Public', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Rankable', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Tapology', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'DOB / Birth year', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Weight class', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Stats', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Last fight', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Eligibility preview', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Blockers', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'WP post', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created at', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Updated at', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $fighters ) ) : ?>
					<tr><td colspan="19"><?php echo esc_html__( 'No canonical fighters found.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $fighters as $fighter ) : ?>
					<?php $post = ! empty( $fighter['wp_post_id'] ) ? get_post( (int) $fighter['wp_post_id'] ) : null; ?>
					<?php $readiness = $table_readiness[ (int) $fighter['id'] ] ?? null; ?>
					<tr>
						<td><input type="checkbox" class="mmaf-fighter-row-checkbox" name="fighter_ids[]" value="<?php echo esc_attr( (string) $fighter['id'] ); ?>"></td>
						<td><?php echo esc_html( (string) $fighter['id'] ); ?></td>
						<td><strong><?php echo esc_html( $fighter['display_name'] ); ?></strong></td>
						<td><?php echo esc_html( (string) ( $fighter['nickname'] ?? '' ) ); ?></td>
						<td><?php self::render_badge( (string) $fighter['status'], 'verified' === (string) $fighter['status'] ? 'good' : 'warn' ); ?></td>
						<td><?php self::render_badge( (string) $fighter['rankability_status'], 'rankable' === (string) $fighter['rankability_status'] ? 'good' : 'warn' ); ?></td>
						<td><?php self::render_badge( self::yes_no( $fighter['is_public'] ), (int) $fighter['is_public'] ? 'good' : 'warn' ); ?></td>
						<td><?php self::render_badge( self::yes_no( $fighter['is_rankable'] ), (int) $fighter['is_rankable'] ? 'good' : 'warn' ); ?></td>
						<td><?php self::render_badge( $readiness && $readiness['has_tapology_source'] ? __( 'Present', 'mma-future-data-engine' ) : __( 'Missing', 'mma-future-data-engine' ), $readiness && $readiness['has_tapology_source'] ? 'good' : 'bad' ); ?></td>
						<td><?php echo esc_html( self::empty_marker( trim( (string) $fighter['date_of_birth'] . ' / ' . (string) $fighter['birth_year'], ' /' ) ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) $fighter['weight_class'] ) ); ?></td>
						<td><?php self::render_badge( $readiness && $readiness['has_stats'] ? __( 'Present', 'mma-future-data-engine' ) : __( 'Missing', 'mma-future-data-engine' ), $readiness && $readiness['has_stats'] ? 'good' : 'bad' ); ?></td>
						<td><?php echo esc_html( $readiness && $readiness['has_last_fight'] ? (string) $readiness['stats']['last_fight_date'] : '-' ); ?></td>
						<td><?php self::render_badge( $readiness && 'ready' === $readiness['eligibility_preview'] ? __( 'Ready', 'mma-future-data-engine' ) : __( 'Blocked', 'mma-future-data-engine' ), $readiness && 'ready' === $readiness['eligibility_preview'] ? 'good' : 'bad' ); ?></td>
						<td><?php echo esc_html( $readiness ? (string) $readiness['blocker_summary'] : '-' ); ?></td>
						<td><?php self::render_post_link( $post ); ?></td>
						<td><?php echo esc_html( (string) $fighter['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $fighter['updated_at'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( self::page_url( array( 'action' => 'edit', 'fighter_id' => (int) $fighter['id'] ) ) ); ?>"><?php echo esc_html__( 'Edit', 'mma-future-data-engine' ); ?></a>
							<?php if ( $post instanceof \WP_Post ) : ?>
								| <a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html__( 'View WP post', 'mma-future-data-engine' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</form>
		<?php self::render_pagination( $total, $paged, $per_page, $query_filters ); ?>
		<?php
	}

	private static function render_readiness_report( array $report, FighterReadinessService $readiness_service ): void {
		$counts = $report['counts'] ?? array();
		$buckets = $report['buckets'] ?? array();
		$closest = $report['closest'] ?? array();
		?>
		<div class="postbox" style="margin: 16px 0;">
			<div class="postbox-header"><h2><?php echo esc_html__( 'Fighter Readiness Report', 'mma-future-data-engine' ); ?></h2></div>
			<div class="inside">
				<table class="widefat striped" style="max-width: 1100px;">
					<tbody>
						<tr>
							<th><?php echo esc_html__( 'Total fighters', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['total_fighters'] ?? 0 ) ); ?></td>
							<th><?php echo esc_html__( 'Verified / Provisional', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['verified_fighters'] ?? 0 ) . ' / ' . (string) ( $counts['provisional_fighters'] ?? 0 ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Public / Rankable', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['public_fighters'] ?? 0 ) . ' / ' . (string) ( $counts['rankable_fighters'] ?? 0 ) ); ?></td>
							<th><?php echo esc_html__( 'Tapology mapped / missing', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['tapology_mapped_fighters'] ?? 0 ) . ' / ' . (string) ( $counts['fighters_without_tapology_mapping'] ?? 0 ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'With stats', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['fighters_with_stats'] ?? 0 ) ); ?></td>
							<th><?php echo esc_html__( 'Missing DOB/year / weight / last fight', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['fighters_missing_dob_or_birth_year'] ?? 0 ) . ' / ' . (string) ( $counts['fighters_missing_weight_class'] ?? 0 ) . ' / ' . (string) ( $counts['fighters_missing_last_fight'] ?? 0 ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Blocked by insufficient data', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['fighters_blocked_by_insufficient_data'] ?? 0 ) ); ?></td>
							<th><?php echo esc_html__( 'Blocked by review/public/rankable state', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) ( $counts['fighters_blocked_by_public_rankable_review_state'] ?? 0 ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<h3><?php echo esc_html__( 'Readiness buckets', 'mma-future-data-engine' ); ?></h3>
				<table class="widefat striped" style="max-width: 1100px;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Bucket', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Meaning', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Fighters', 'mma-future-data-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $buckets as $bucket_key => $bucket ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $bucket_key ); ?></strong></td>
								<td><?php echo esc_html( (string) $bucket['label'] ); ?></td>
								<td><?php echo esc_html( (string) $bucket['count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php echo esc_html__( 'Closest to rankable', 'mma-future-data-engine' ); ?></h3>
				<table class="widefat striped" style="max-width: 1100px;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Fighter', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Bucket', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Score', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Blockers', 'mma-future-data-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $closest as $item ) : ?>
							<tr>
								<td><?php echo esc_html( '#' . (int) $item['fighter']['id'] . ' ' . (string) $item['fighter']['display_name'] ); ?></td>
								<td><?php echo esc_html( (string) $item['readiness_bucket'] ); ?></td>
								<td><?php echo esc_html( (string) $item['readiness_score'] ); ?></td>
								<td><?php echo esc_html( empty( $item['rankable_blocker_codes'] ) ? __( 'Ready', 'mma-future-data-engine' ) : implode( ', ', array_map( array( $readiness_service, 'reason_label' ), array_slice( $item['rankable_blocker_codes'], 0, 6 ) ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function render_bulk_controls( string $suffix ): void {
		$select_id = 'mmaf-fighter-bulk-action-' . sanitize_key( $suffix );
		?>
		<div class="tablenav top" style="margin: 10px 0;">
			<div class="alignleft actions bulkactions">
				<label class="screen-reader-text" for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html__( 'Bulk action', 'mma-future-data-engine' ); ?></label>
				<select id="<?php echo esc_attr( $select_id ); ?>" name="bulk_action">
					<option value=""><?php echo esc_html__( 'Bulk actions', 'mma-future-data-engine' ); ?></option>
					<option value="validate_selected_for_ranking"><?php echo esc_html__( 'Validate selected for ranking', 'mma-future-data-engine' ); ?></option>
					<option value="mark_verified"><?php echo esc_html__( 'Mark as verified', 'mma-future-data-engine' ); ?></option>
					<option value="mark_public"><?php echo esc_html__( 'Mark as public', 'mma-future-data-engine' ); ?></option>
					<option value="mark_not_public"><?php echo esc_html__( 'Mark as not public', 'mma-future-data-engine' ); ?></option>
					<option value="mark_rankable"><?php echo esc_html__( 'Mark as rankable', 'mma-future-data-engine' ); ?></option>
					<option value="mark_not_rankable"><?php echo esc_html__( 'Mark as not rankable', 'mma-future-data-engine' ); ?></option>
					<option value="move_to_provisional"><?php echo esc_html__( 'Move to provisional / pending review', 'mma-future-data-engine' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'mma-future-data-engine' ), 'action', '', false ); ?>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	private static function render_bulk_result( array $result ): void {
		$service = new FighterReadinessService();
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Bulk readiness result', 'mma-future-data-engine' ) . '</strong></p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: selected count, 2: updated count, 3: ready count, 4: blocked count, 5: skipped count. */
				__( '%1$d selected. %2$d updated. %3$d ready. %4$d blocked. %5$d skipped/no-op.', 'mma-future-data-engine' ),
				(int) ( $result['selected_count'] ?? 0 ),
				(int) ( $result['updated_count'] ?? 0 ),
				(int) ( $result['ready_count'] ?? 0 ),
				(int) ( $result['blocked_count'] ?? 0 ),
				(int) ( $result['skipped_count'] ?? 0 )
			)
		) . '</p>';

		if ( ! empty( $result['reason_counts'] ) ) {
			echo '<p><strong>' . esc_html__( 'Reason summary:', 'mma-future-data-engine' ) . '</strong> ';
			$parts = array();
			foreach ( $result['reason_counts'] as $reason => $count ) {
				$parts[] = (int) $count . ' ' . $service->reason_label( (string) $reason );
			}
			echo esc_html( implode( ', ', $parts ) ) . '</p>';
		}

		self::render_bulk_result_names( __( 'Updated', 'mma-future-data-engine' ), (array) ( $result['updated'] ?? array() ) );
		self::render_bulk_result_names( __( 'Ready', 'mma-future-data-engine' ), (array) ( $result['ready'] ?? array() ), $service );
		self::render_bulk_result_names( __( 'Blocked', 'mma-future-data-engine' ), (array) ( $result['blocked'] ?? array() ), $service );
		self::render_bulk_result_names( __( 'Skipped/no-op', 'mma-future-data-engine' ), (array) ( $result['skipped'] ?? array() ), $service );

		if ( ! empty( $result['next_step'] ) ) {
			echo '<p><strong>' . esc_html__( 'Next step:', 'mma-future-data-engine' ) . '</strong> ' . esc_html( (string) $result['next_step'] ) . '</p>';
		}

		echo '<p>' . esc_html__( 'No bouts were changed. No rankings were recalculated or activated. Linked WP post status was not changed by this bulk action.', 'mma-future-data-engine' ) . '</p>';
		echo '</div>';
	}

	private static function render_bulk_result_names( string $label, array $rows, ?FighterReadinessService $service = null ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$items = array_slice( $rows, 0, 20 );
		$parts = array();
		foreach ( $items as $row ) {
			$text = '#' . (int) $row['id'] . ' ' . (string) $row['name'];
			if ( $service && ! empty( $row['reasons'] ) ) {
				$text .= ' (' . implode( ', ', array_map( array( $service, 'reason_label' ), (array) $row['reasons'] ) ) . ')';
			}
			$parts[] = $text;
		}

		echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( implode( '; ', $parts ) );
		if ( count( $rows ) > count( $items ) ) {
			echo esc_html( sprintf( __( ' and %d more', 'mma-future-data-engine' ), count( $rows ) - count( $items ) ) );
		}
		echo '</p>';
	}

	private static function render_form( int $fighter_id = 0, ?array $input = null, array $preflight = array() ): void {
		$fighters = new FighterRepository();
		$sources  = new FighterSourceRepository();
		$aliases  = new FighterAliasRepository();
		$is_edit  = $fighter_id > 0;
		$fighter  = $is_edit ? $fighters->find( $fighter_id ) : self::default_fighter();

		if ( ! $fighter ) {
			self::render_notice( 'error', __( 'Fighter not found.', 'mma-future-data-engine' ) );
			return;
		}

		$source       = $is_edit ? $sources->find_first_for_fighter( $fighter_id ) : self::default_source();
		$alias_lines  = '';
		$post         = ! empty( $fighter['wp_post_id'] ) ? get_post( (int) $fighter['wp_post_id'] ) : null;

		if ( $is_edit ) {
			$alias_rows = $aliases->list_by_fighter( $fighter_id );
			$alias_lines = implode( "\n", wp_list_pluck( $alias_rows, 'alias' ) );
		}

		if ( null !== $input ) {
			$fighter = self::fighter_from_input( $fighter, $input, $is_edit );
			$source  = self::source_from_input( $source ?: self::default_source(), $input );
			$alias_lines = isset( $input['aliases'] ) ? (string) wp_unslash( $input['aliases'] ) : $alias_lines;
		}
		?>
		<hr class="wp-header-end">

		<?php if ( $is_edit ) : ?>
			<?php self::render_fighter_stats_dashboard( $fighter_id ); ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( self::page_url( $is_edit ? array( 'action' => 'edit', 'fighter_id' => $fighter_id ) : array( 'action' => 'new' ) ) ); ?>">
			<?php wp_nonce_field( 'mmaf_save_fighter', 'mmaf_fighter_nonce' ); ?>
			<input type="hidden" name="mmaf_action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>">
			<input type="hidden" name="fighter_id" value="<?php echo esc_attr( (string) $fighter_id ); ?>">

			<?php self::open_section( __( 'Canonical fighter', 'mma-future-data-engine' ) ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::text_row( 'display_name', __( 'Display name', 'mma-future-data-engine' ), $fighter['display_name'], true ); ?>
						<?php self::text_row( 'nickname', __( 'Nickname', 'mma-future-data-engine' ), $fighter['nickname'] ?? '' ); ?>
						<?php self::select_row( 'gender', __( 'Gender', 'mma-future-data-engine' ), (string) $fighter['gender'], Sanitizer::GENDERS ); ?>
						<tr>
							<th scope="row"></th>
							<td><p class="description"><?php echo esc_html__( 'Gender is required only for rankable fighters because ranking boards are split by male/female divisions.', 'mma-future-data-engine' ); ?></p></td>
						</tr>
						<?php self::text_row( 'date_of_birth', __( 'Date of birth', 'mma-future-data-engine' ), $fighter['date_of_birth'], false, 'YYYY-MM-DD', 'date', __( 'Exact birth date, optional. Use YYYY-MM-DD. Ambiguous formats are rejected.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'birth_year', __( 'Birth year', 'mma-future-data-engine' ), $fighter['birth_year'], false, '', 'number', __( 'Use only if exact date of birth is unknown. If date of birth is provided, birth year is derived automatically.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'nationality', __( 'Nationality', 'mma-future-data-engine' ), $fighter['nationality'] ); ?>
						<?php self::select_row( 'weight_class', __( 'Weight class', 'mma-future-data-engine' ), (string) $fighter['weight_class'], Sanitizer::WEIGHT_CLASSES, __( 'Select gender first; only compatible divisions are available.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'height', __( 'Height', 'mma-future-data-engine' ), $fighter['height'] ?? '', false, '5\'11" (180cm)', 'text', __( 'Source height text from Tapology, optional and display-only for rankability.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'height_cm', __( 'Height (cm)', 'mma-future-data-engine' ), $fighter['height_cm'] ?? '', false, '', 'number', __( 'Normalized height in centimeters, optional. Use a whole number from 100 through 260.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'last_weigh_in', __( 'Last weigh-in', 'mma-future-data-engine' ), $fighter['last_weigh_in'] ?? '', false, '155.8 lbs', 'text', __( 'Source last weigh-in text from Tapology, optional and not a rankability blocker.', 'mma-future-data-engine' ) ); ?>
						<tr>
							<th scope="row"></th>
							<td><p class="description"><?php echo esc_html__( 'Weight class is required only for rankable fighters because ranking boards include weight divisions.', 'mma-future-data-engine' ); ?></p></td>
						</tr>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'Ranking/publication status', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Final age, inactivity, fight-count, UFC, and loss-limit eligibility will be enforced by the ranking/eligibility engine later. This admin form only stores current manual flags and guardrails.', 'mma-future-data-engine' ); ?></p>
				<p><?php echo esc_html__( 'Ranking exclusion is not deletion: ineligible fighters stay in mmaf_fighters with source mappings, aliases, audit/provenance history, and linked WP posts unless an admin explicitly hides or unpublishes them. deleted_soft is only for true removal/hiding cases, not normal ranking ineligibility.', 'mma-future-data-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::select_row( 'status', __( 'Status', 'mma-future-data-engine' ), $fighter['status'], Sanitizer::FIGHTER_STATUSES, self::status_help_text() ); ?>
						<?php self::select_row( 'rankability_status', __( 'Rankability status', 'mma-future-data-engine' ), $fighter['rankability_status'], Sanitizer::RANKABILITY_STATUSES, self::rankability_status_help_text() ); ?>
						<?php self::checkbox_row( 'is_public', __( 'Public', 'mma-future-data-engine' ), $fighter['is_public'], __( 'Controls whether the linked WP fighter profile post is published.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'is_rankable', __( 'Rankable', 'mma-future-data-engine' ), $fighter['is_rankable'], __( 'Controls whether the fighter may appear in ranking outputs.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'in_ufc', __( 'In UFC', 'mma-future-data-engine' ), $fighter['in_ufc'], __( 'Marks fighter as UFC roster/ineligible for MMA Future prospect rankings.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'deleted_soft', __( 'Deleted soft', 'mma-future-data-engine' ), $fighter['deleted_soft'], __( 'Use only for true removal or hiding cases, not ordinary ranking ineligibility.', 'mma-future-data-engine' ) ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'External source profile', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Tapology Profile URL is required for every manual fighter save. It is parsed into a source identity mapping and used to prevent future duplicate imports. Manual edits remain allowed; a source profile is an identity reference, not automatic truth for every field.', 'mma-future-data-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::fixed_source_type_row(); ?>
						<?php self::text_row( 'source_url', __( 'Tapology Profile URL', 'mma-future-data-engine' ), $source['source_url'] ?? '', true, 'https://www.tapology.com/fightcenter/fighters/12345-name', 'url', __( 'Required. Numeric and slug-only Tapology fighter URLs are normalized and linked as identity-only source mappings.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'source_slug', __( 'Source slug, optional', 'mma-future-data-engine' ), $source['source_slug'] ?? '' ); ?>
						<?php self::checkbox_row( 'is_verified', __( 'Source verified', 'mma-future-data-engine' ), $source['is_verified'] ?? 0 ); ?>
						<?php self::checkbox_row( 'is_primary', __( 'Primary source', 'mma-future-data-engine' ), $source['is_primary'] ?? 0 ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'Fighter image', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Image is stored in the WordPress Media Library and attached as the fighter profile featured image.', 'mma-future-data-engine' ); ?></p>
				<?php self::render_image_management( $post ); ?>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'Aliases for search and matching', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Aliases are alternate names or spellings used for search, matching, and future imports. They are not the same as the public nickname.', 'mma-future-data-engine' ); ?></p>
				<p class="description"><?php echo esc_html__( 'Example: full legal name, alternate spelling, transliteration, or old fight-record name.', 'mma-future-data-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="aliases"><?php echo esc_html__( 'Aliases', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<textarea id="aliases" name="aliases" class="large-text" rows="5"><?php echo esc_textarea( $alias_lines ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'One alias per line.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php submit_button( $is_edit ? __( 'Update Fighter', 'mma-future-data-engine' ) : __( 'Create Fighter', 'mma-future-data-engine' ) ); ?>
		</form>
		<?php
	}

	private static function render_fighter_stats_dashboard( int $fighter_id ): void {
		$stats_repository = new FighterStatsRepository();
		$override_repository = new FighterStatsOverrideRepository();
		$read_repository  = new RestReadRepository();
		$readiness_items  = ( new FighterReadinessService() )->evaluate_fighter_ids( array( $fighter_id ) );
		$readiness        = $readiness_items[0] ?? null;
		$stats            = $stats_repository->find_stat_by_fighter( $fighter_id );
		$override         = $override_repository->find_active_for_fighter( $fighter_id );
		$current_hash     = FighterStatsOverrideRepository::calculated_stats_hash( $stats );
		$override_stale   = $override && (string) ( $override['calculated_stats_hash'] ?? '' ) !== $current_hash;
		$recent_fights    = $read_repository->recent_fights( $fighter_id, 8 );
		$rankings         = $read_repository->fighter_current_rankings( $fighter_id );
		$warnings         = self::stats_warnings( $stats );

		self::open_section( __( 'Current stats dashboard', 'mma-future-data-engine' ) );
		?>
			<p class="description"><?php echo esc_html__( 'Read-only canonical stats derived from imported canonical bouts. To correct the aggregate record, edit the underlying bout rows and rebuild stats; this panel does not write ranking or record overrides.', 'mma-future-data-engine' ); ?></p>
			<?php if ( $override ) : ?>
				<div class="notice <?php echo $override_stale ? 'notice-warning' : 'notice-info'; ?> inline">
					<p>
						<strong><?php echo esc_html__( 'Manual display override is active.', 'mma-future-data-engine' ); ?></strong>
						<?php echo esc_html( $override_stale ? __( 'Calculated stats changed since this override was saved; review whether the override is still needed.', 'mma-future-data-engine' ) : __( 'Calculated stats have not changed since this override was saved.', 'mma-future-data-engine' ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! $stats ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'No stats row exists for this fighter yet. Run stats rebuild after importing bouts.', 'mma-future-data-engine' ); ?></p></div>
				<?php if ( $override ) : ?>
					<table class="widefat striped" style="max-width: 980px;">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Display record', 'mma-future-data-engine' ); ?></th>
								<td><strong><?php echo esc_html( self::display_record_summary( null, $override ) ); ?></strong></td>
								<th><?php echo esc_html__( 'Display source', 'mma-future-data-engine' ); ?></th>
								<td><?php echo esc_html__( 'Manual override', 'mma-future-data-engine' ); ?></td>
							</tr>
						</tbody>
					</table>
				<?php endif; ?>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 980px;">
					<tbody>
						<tr>
							<th><?php echo esc_html__( 'Display record', 'mma-future-data-engine' ); ?></th>
							<td><strong><?php echo esc_html( self::display_record_summary( $stats, $override ) ); ?></strong></td>
							<th><?php echo esc_html__( 'Display source', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( $override ? __( 'Manual override', 'mma-future-data-engine' ) : __( 'Calculated canonical stats', 'mma-future-data-engine' ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Record', 'mma-future-data-engine' ); ?></th>
							<td><strong><?php echo esc_html( self::record_summary( $stats ) ); ?></strong></td>
							<th><?php echo esc_html__( 'Countable fights', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( (string) (int) $stats['pro_fights_count'] ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Win breakdown', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::win_breakdown_summary( $stats ) ); ?></td>
							<th><?php echo esc_html__( 'Finish rate', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::finish_rate_summary( $stats ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Last fight', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::empty_marker( (string) ( $stats['last_fight_date'] ?? '' ) ) ); ?></td>
							<th><?php echo esc_html__( 'Activity', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::empty_marker( (string) ( $stats['activity_status'] ?? '' ) ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Streak', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::empty_marker( (string) ( $stats['streak'] ?? '' ) ) ); ?></td>
							<th><?php echo esc_html__( 'Recent form', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::empty_marker( (string) ( $stats['recent_form'] ?? '' ) ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Calculated at', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( self::empty_marker( (string) ( $stats['calculated_at'] ?? '' ) ) ); ?></td>
							<th><?php echo esc_html__( 'Stats warnings', 'mma-future-data-engine' ); ?></th>
							<td><?php echo esc_html( empty( $warnings ) ? '-' : implode( ', ', $warnings ) ); ?></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::render_stats_override_controls( $fighter_id, $stats, $override, $override_stale ); ?>

			<h3><?php echo esc_html__( 'Readiness', 'mma-future-data-engine' ); ?></h3>
			<table class="widefat striped" style="max-width: 980px;">
				<tbody>
					<tr>
						<th><?php echo esc_html__( 'Eligibility preview', 'mma-future-data-engine' ); ?></th>
						<td><?php self::render_badge( $readiness && 'ready' === (string) $readiness['eligibility_preview'] ? __( 'Ready', 'mma-future-data-engine' ) : __( 'Blocked', 'mma-future-data-engine' ), $readiness && 'ready' === (string) $readiness['eligibility_preview'] ? 'good' : 'bad' ); ?></td>
						<th><?php echo esc_html__( 'Readiness bucket / score', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $readiness ? (string) $readiness['readiness_bucket'] . ' / ' . (string) $readiness['readiness_score'] : '-' ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Rankable blockers', 'mma-future-data-engine' ); ?></th>
						<td colspan="3"><?php echo esc_html( $readiness ? self::readiness_codes_summary( (array) $readiness['rankable_blocker_codes'] ) : '-' ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $rankings ) ) : ?>
				<h3><?php echo esc_html__( 'Current ranking rows', 'mma-future-data-engine' ); ?></h3>
				<table class="widefat striped" style="max-width: 980px;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Board', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Position', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Score', 'mma-future-data-engine' ); ?></th>
							<th><?php echo esc_html__( 'Run ID', 'mma-future-data-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rankings as $ranking ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $ranking['board_key'] ); ?></td>
								<td><?php echo esc_html( (string) $ranking['rank_position'] ); ?></td>
								<td><?php echo esc_html( (string) $ranking['total_score'] ); ?></td>
								<td><?php echo esc_html( (string) $ranking['ranking_run_id'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Recent canonical fight history', 'mma-future-data-engine' ); ?></h3>
			<?php self::render_recent_fights_table( $recent_fights ); ?>
		<?php
		self::close_section();
	}

	private static function render_stats_override_controls( int $fighter_id, ?array $stats, ?array $override, bool $override_stale ): void {
		$record = $override ?: array(
			'wins'   => $stats['wins'] ?? 0,
			'losses' => $stats['losses'] ?? 0,
			'draws'  => $stats['draws'] ?? 0,
			'nc'     => $stats['nc'] ?? 0,
			'reason' => '',
		);
		?>
		<details style="margin-top: 14px;">
			<summary style="cursor:pointer;"><strong><?php echo esc_html__( 'Edit manual display override', 'mma-future-data-engine' ); ?></strong></summary>
			<div style="margin-top: 10px; max-width: 980px;">
				<p class="description"><?php echo esc_html__( 'Manual override changes display record only. It does not change calculated stats, eligibility, ranking formulas, imported bouts, or fight history.', 'mma-future-data-engine' ); ?></p>
				<?php if ( $override_stale ) : ?>
					<p class="description"><strong><?php echo esc_html__( 'Review needed:', 'mma-future-data-engine' ); ?></strong> <?php echo esc_html__( 'A newer import or stats rebuild changed the calculated stats after this override was saved.', 'mma-future-data-engine' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( self::page_url( array( 'action' => 'edit', 'fighter_id' => $fighter_id ) ) ); ?>">
					<?php wp_nonce_field( 'mmaf_stats_override', 'mmaf_stats_override_nonce' ); ?>
					<input type="hidden" name="mmaf_action" value="stats_override_save">
					<input type="hidden" name="fighter_id" value="<?php echo esc_attr( (string) $fighter_id ); ?>">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Override record', 'mma-future-data-engine' ); ?></th>
								<td>
									<label><?php echo esc_html__( 'W', 'mma-future-data-engine' ); ?> <input type="number" min="0" max="9999" name="override_wins" value="<?php echo esc_attr( (string) (int) ( $record['wins'] ?? 0 ) ); ?>" style="width:80px;" required></label>
									<label><?php echo esc_html__( 'L', 'mma-future-data-engine' ); ?> <input type="number" min="0" max="9999" name="override_losses" value="<?php echo esc_attr( (string) (int) ( $record['losses'] ?? 0 ) ); ?>" style="width:80px;" required></label>
									<label><?php echo esc_html__( 'D', 'mma-future-data-engine' ); ?> <input type="number" min="0" max="9999" name="override_draws" value="<?php echo esc_attr( (string) (int) ( $record['draws'] ?? 0 ) ); ?>" style="width:80px;" required></label>
									<label><?php echo esc_html__( 'NC', 'mma-future-data-engine' ); ?> <input type="number" min="0" max="9999" name="override_nc" value="<?php echo esc_attr( (string) (int) ( $record['nc'] ?? 0 ) ); ?>" style="width:80px;" required></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="override_reason"><?php echo esc_html__( 'Reason', 'mma-future-data-engine' ); ?></label></th>
								<td>
									<textarea id="override_reason" name="override_reason" class="large-text" rows="3" required><?php echo esc_textarea( (string) ( $record['reason'] ?? '' ) ); ?></textarea>
									<p class="description"><?php echo esc_html__( 'Required. Explain why display stats should differ from calculated canonical stats.', 'mma-future-data-engine' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( $override ? __( 'Update manual display override', 'mma-future-data-engine' ) : __( 'Save manual display override', 'mma-future-data-engine' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( $override ) : ?>
					<form method="post" action="<?php echo esc_url( self::page_url( array( 'action' => 'edit', 'fighter_id' => $fighter_id ) ) ); ?>" style="margin-top: 8px;">
						<?php wp_nonce_field( 'mmaf_stats_override', 'mmaf_stats_override_nonce' ); ?>
						<input type="hidden" name="mmaf_action" value="stats_override_clear">
						<input type="hidden" name="fighter_id" value="<?php echo esc_attr( (string) $fighter_id ); ?>">
						<?php submit_button( __( 'Clear manual override and use calculated stats', 'mma-future-data-engine' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Clear this manual stats override?', 'mma-future-data-engine' ) ) . "');" ) ); ?>
					</form>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private static function render_recent_fights_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No canonical fight history found for this fighter.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 980px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Date', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Result', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Opponent', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Event', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Method', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Round/time', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Weight', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Bout', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['event_date'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::result_code( (string) ( $row['result_for_fighter'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['opponent_display_name'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['event_name'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::method_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::round_time_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['weight_class'] ?? '' ) ) ); ?></td>
							<td><a href="<?php echo esc_url( self::bout_edit_url( (int) $row['bout_id'] ) ); ?>"><?php echo esc_html( '#' . (int) $row['bout_id'] . ' ' . __( 'Edit', 'mma-future-data-engine' ) ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function fighter_from_input( array $fighter, array $input, bool $is_edit ): array {
		foreach ( array( 'display_name', 'nickname', 'gender', 'date_of_birth', 'birth_year', 'nationality', 'weight_class', 'height', 'height_cm', 'last_weigh_in', 'status', 'rankability_status' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$fighter[ $field ] = sanitize_text_field( wp_unslash( $input[ $field ] ) );
			}
		}

		foreach ( array( 'is_public', 'is_rankable', 'in_ufc', 'deleted_soft' ) as $field ) {
			$fighter[ $field ] = empty( $input[ $field ] ) ? 0 : 1;
		}

		if ( ! $is_edit ) {
			$fighter['wp_post_id'] = null;
		}

		return $fighter;
	}

	private static function source_from_input( array $source, array $input ): array {
		foreach ( array( 'source_type', 'source_url', 'source_slug' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$source[ $field ] = sanitize_text_field( wp_unslash( $input[ $field ] ) );
			}
		}

		foreach ( array( 'is_verified', 'is_primary' ) as $field ) {
			$source[ $field ] = empty( $input[ $field ] ) ? 0 : 1;
		}

		return $source;
	}

	private static function text_row( string $name, string $label, $value, bool $required = false, string $placeholder = '', string $type = 'text', string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="regular-text"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					<?php echo $required ? 'required' : ''; ?>
				>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function readonly_text_row( string $name, string $label, $value, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" readonly>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function fixed_source_type_row(): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Source type', 'mma-future-data-engine' ); ?></th>
			<td>
				<code>tapology</code>
				<input type="hidden" name="source_type" value="tapology">
				<p class="description"><?php echo esc_html__( 'Manual saves use Tapology URLs as canonical source identity mappings.', 'mma-future-data-engine' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function select_row( string $name, string $label, string $value, array $options, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( self::option_label( $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function checkbox_row( string $name, string $label, $checked, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (int) $checked, 1 ); ?>> <?php echo esc_html__( 'Yes', 'mma-future-data-engine' ); ?></label>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function open_section( string $title ): void {
		echo '<div class="postbox" style="max-width: 980px; margin-top: 18px;">';
		echo '<div class="postbox-header"><h2>' . esc_html( $title ) . '</h2></div>';
		echo '<div class="inside">';
	}

	private static function close_section(): void {
		echo '</div></div>';
	}

	private static function render_post_link( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			echo esc_html__( 'Not synced', 'mma-future-data-engine' );
			return;
		}

		$url = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( '#' . $post->ID . ' ' . $post->post_status ) . '</a>';
	}

	private static function render_image_management( $post ): void {
		$attachment_id = $post instanceof \WP_Post ? (int) get_post_thumbnail_id( $post ) : 0;
		if ( $attachment_id > 0 && ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = 0;
		}

		$preview       = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, array( 96, 96 ), false, array( 'style' => 'display:block; margin-bottom: 8px;' ) ) : '';

		if ( ! $post instanceof \WP_Post ) {
			echo '<p class="description">' . esc_html__( 'The linked WP fighter post will be created when this fighter is saved, then this image will be applied.', 'mma-future-data-engine' ) . '</p>';
		}
		?>
		<div class="mmaf-fighter-image-field">
			<div class="mmaf-fighter-image-preview">
				<?php echo wp_kses_post( $preview ); ?>
			</div>
			<input type="hidden" id="fighter_image_id" name="fighter_image_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<input type="hidden" id="remove_fighter_image" name="remove_fighter_image" value="0">
			<button type="button" class="button" id="mmaf-select-fighter-image"><?php echo esc_html__( 'Select image', 'mma-future-data-engine' ); ?></button>
			<button type="button" class="button" id="mmaf-remove-fighter-image" <?php echo $attachment_id > 0 ? '' : 'style="display:none;"'; ?>><?php echo esc_html__( 'Remove image', 'mma-future-data-engine' ); ?></button>
		</div>
		<?php
	}

	private static function render_notice( string $type, string $message ): void {
		$class = 'error' === $type ? 'notice notice-error' : ( 'warning' === $type ? 'notice notice-warning' : 'notice notice-success' );
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function render_badge( string $label, string $tone ): void {
		$colors = array(
			'good' => 'background:#e7f7ed;color:#14532d;border-color:#bbf7d0;',
			'warn' => 'background:#fff7ed;color:#7c2d12;border-color:#fed7aa;',
			'bad'  => 'background:#fef2f2;color:#7f1d1d;border-color:#fecaca;',
		);
		$style = $colors[ $tone ] ?? $colors['warn'];

		echo '<span style="display:inline-block;padding:2px 7px;border:1px solid;border-radius:3px;' . esc_attr( $style ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function save_message( string $base, array $notices ): string {
		if ( empty( $notices ) ) {
			return $base;
		}

		return $base . ' ' . __( 'Adjusted values:', 'mma-future-data-engine' ) . ' ' . implode( ' ', $notices );
	}

	private static function redirect_to_fighter_edit_or_notice( int $fighter_id, string $message ): array {
		$url = self::page_url(
			array(
				'action'      => 'edit',
				'fighter_id'  => $fighter_id,
				'mmaf_notice' => $message,
			)
		);

		if ( ! headers_sent() && wp_safe_redirect( $url ) ) {
			exit;
		}

		return array(
			'type'              => 'success',
			'message'           => $message,
			'redirect_fallback' => array(
				'action'     => 'edit',
				'fighter_id' => $fighter_id,
			),
		);
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	private static function current_per_page(): int {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;

		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
	}

	private static function current_filters(): array {
		$status = isset( $_GET['fighter_status'] ) ? sanitize_key( wp_unslash( $_GET['fighter_status'] ) ) : '';
		if ( ! in_array( $status, Sanitizer::FIGHTER_STATUSES, true ) ) {
			$status = '';
		}

		$rankability_status = isset( $_GET['rankability_status'] ) ? sanitize_key( wp_unslash( $_GET['rankability_status'] ) ) : '';
		if ( ! in_array( $rankability_status, Sanitizer::RANKABILITY_STATUSES, true ) ) {
			$rankability_status = '';
		}

		$public_state = isset( $_GET['public_state'] ) ? sanitize_key( wp_unslash( $_GET['public_state'] ) ) : '';
		if ( ! in_array( $public_state, array( 'public', 'not_public' ), true ) ) {
			$public_state = '';
		}

		$rankable_state = isset( $_GET['rankable_state'] ) ? sanitize_key( wp_unslash( $_GET['rankable_state'] ) ) : '';
		if ( ! in_array( $rankable_state, array( 'rankable', 'not_rankable' ), true ) ) {
			$rankable_state = '';
		}

		$readiness_issue = isset( $_GET['readiness_issue'] ) ? sanitize_key( wp_unslash( $_GET['readiness_issue'] ) ) : '';
		if ( ! in_array( $readiness_issue, array( 'missing_dob', 'missing_weight_class', 'provisional_tapology' ), true ) ) {
			$readiness_issue = '';
		}

		return array(
			'status'             => $status,
			'rankability_status' => $rankability_status,
			'public_state'       => $public_state,
			'rankable_state'     => $rankable_state,
			'readiness_issue'    => $readiness_issue,
		);
	}

	private static function filter_query_args( array $filters ): array {
		$args = array();
		foreach (
			array(
				'status'             => 'fighter_status',
				'rankability_status' => 'rankability_status',
				'public_state'       => 'public_state',
				'rankable_state'     => 'rankable_state',
				'readiness_issue'    => 'readiness_issue',
			) as $filter_key => $query_key
		) {
			if ( '' !== (string) ( $filters[ $filter_key ] ?? '' ) ) {
				$args[ $query_key ] = (string) $filters[ $filter_key ];
			}
		}

		return $args;
	}

	private static function render_fighter_filters( array $filters ): void {
		self::filter_select( 'fighter_status', __( 'Status', 'mma-future-data-engine' ), (string) ( $filters['status'] ?? '' ), Sanitizer::FIGHTER_STATUSES );
		self::filter_select( 'rankability_status', __( 'Rankability', 'mma-future-data-engine' ), (string) ( $filters['rankability_status'] ?? '' ), Sanitizer::RANKABILITY_STATUSES );
		self::filter_select(
			'public_state',
			__( 'Public', 'mma-future-data-engine' ),
			(string) ( $filters['public_state'] ?? '' ),
			array(
				'public'     => __( 'Public', 'mma-future-data-engine' ),
				'not_public' => __( 'Not public', 'mma-future-data-engine' ),
			)
		);
		self::filter_select(
			'rankable_state',
			__( 'Rankable', 'mma-future-data-engine' ),
			(string) ( $filters['rankable_state'] ?? '' ),
			array(
				'rankable'     => __( 'Rankable', 'mma-future-data-engine' ),
				'not_rankable' => __( 'Not rankable', 'mma-future-data-engine' ),
			)
		);
		self::filter_select(
			'readiness_issue',
			__( 'Readiness', 'mma-future-data-engine' ),
			(string) ( $filters['readiness_issue'] ?? '' ),
			array(
				'missing_dob'          => __( 'Missing DOB/year', 'mma-future-data-engine' ),
				'missing_weight_class' => __( 'Missing weight', 'mma-future-data-engine' ),
				'provisional_tapology' => __( 'Provisional + Tapology', 'mma-future-data-engine' ),
			)
		);
	}

	private static function filter_select( string $name, string $label, string $selected, array $options ): void {
		echo '<label for="mmaf-' . esc_attr( $name ) . '" style="margin-left:8px;">' . esc_html( $label ) . '</label> ';
		echo '<select id="mmaf-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( 'Any', 'mma-future-data-engine' ) . '</option>';
		foreach ( $options as $value => $option_label ) {
			if ( is_int( $value ) ) {
				$value        = (string) $option_label;
				$option_label = self::option_label( $value );
			}
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $selected, (string) $value, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
		}
		echo '</select>';
	}

	private static function render_per_page_select( int $per_page ): void {
		?>
		<label for="mmaf-per-page" style="margin-left: 8px;"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function render_pagination( int $total, int $paged, int $per_page, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args        = array(
			'page'     => self::PAGE_SLUG,
			'per_page' => $per_page,
		);

		foreach ( $filters as $key => $value ) {
			if ( '' !== (string) $value ) {
				$args[ $key ] = $value;
			}
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s fighter', '%s fighters', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function default_fighter(): array {
		return array(
			'id'                 => 0,
			'wp_post_id'         => null,
			'display_name'       => '',
			'nickname'           => '',
			'normalized_name'    => '',
			'gender'             => '',
			'date_of_birth'      => '',
			'birth_year'         => '',
			'nationality'        => '',
			'weight_class'       => 'unknown',
			'height'             => '',
			'height_cm'          => '',
			'last_weigh_in'      => '',
			'status'             => 'provisional',
			'rankability_status' => 'pending_review',
			'is_public'          => 0,
			'is_rankable'        => 0,
			'in_ufc'             => 0,
			'deleted_soft'       => 0,
		);
	}

	private static function default_source(): array {
		return array(
			'source_type' => 'tapology',
			'source_url'  => '',
			'source_slug' => '',
			'is_verified' => 0,
			'is_primary'  => 0,
		);
	}

	private static function yes_no( $value ): string {
		return (int) $value ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' );
	}

	private static function empty_marker( string $value ): string {
		return '' === trim( $value ) ? '-' : $value;
	}

	private static function record_summary( array $stats ): string {
		return sprintf(
			'%d-%d-%d-%d',
			(int) ( $stats['wins'] ?? 0 ),
			(int) ( $stats['losses'] ?? 0 ),
			(int) ( $stats['draws'] ?? 0 ),
			(int) ( $stats['nc'] ?? 0 )
		);
	}

	private static function display_record_summary( ?array $stats, ?array $override ): string {
		if ( $override ) {
			return FighterStatsOverrideRepository::record_string( $override );
		}

		return $stats ? self::record_summary( $stats ) : '-';
	}

	private static function win_breakdown_summary( array $stats ): string {
		return sprintf(
			/* translators: 1: KO/TKO wins, 2: submission wins, 3: decision wins, 4: finish wins. */
			__( 'KO/TKO %1$d, Sub %2$d, Dec %3$d, Finishes %4$d', 'mma-future-data-engine' ),
			(int) ( $stats['ko_tko_wins'] ?? 0 ),
			(int) ( $stats['submission_wins'] ?? 0 ),
			(int) ( $stats['decision_wins'] ?? 0 ),
			(int) ( $stats['finish_wins'] ?? 0 )
		);
	}

	private static function finish_rate_summary( array $stats ): string {
		if ( ! isset( $stats['finish_rate'] ) || null === $stats['finish_rate'] || '' === (string) $stats['finish_rate'] ) {
			return '-';
		}

		return number_format_i18n( (float) $stats['finish_rate'] * 100, 1 ) . '%';
	}

	private static function stats_warnings( ?array $stats ): array {
		if ( ! $stats || empty( $stats['warnings_json'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $stats['warnings_json'], true );
		if ( ! is_array( $decoded ) ) {
			return array( 'invalid_warnings_json' );
		}

		return array_values( array_map( 'strval', (array) ( $decoded['warnings'] ?? array() ) ) );
	}

	private static function readiness_codes_summary( array $codes ): string {
		return empty( $codes ) ? __( 'Ready', 'mma-future-data-engine' ) : implode( ', ', array_map( 'strval', $codes ) );
	}

	private static function result_code( string $result ): string {
		$map = array(
			'win'        => 'W',
			'loss'       => 'L',
			'draw'       => 'D',
			'no_contest' => 'NC',
			'cancelled'  => 'Cancelled',
			'unknown'    => 'Unknown',
		);

		return $map[ $result ] ?? self::empty_marker( $result );
	}

	private static function method_summary( array $row ): string {
		$method = trim( (string) ( $row['method_category'] ?? '' ) . ' ' . (string) ( $row['method_detail'] ?? '' ) );

		return self::empty_marker( $method );
	}

	private static function round_time_summary( array $row ): string {
		$value = trim( (string) ( $row['round_number'] ?? '' ) . ' / ' . (string) ( $row['time_in_round'] ?? '' ), ' /' );

		return self::empty_marker( $value );
	}

	private static function bout_edit_url( int $bout_id ): string {
		return add_query_arg(
			array(
				'page'    => 'mmaf-bouts',
				'action'  => 'edit',
				'bout_id' => $bout_id,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function dob_birth_year_summary( array $row ): string {
		$dob        = (string) ( $row['date_of_birth'] ?? '' );
		$birth_year = (string) ( $row['birth_year'] ?? '' );
		$value      = trim( $dob . ( '' !== $dob && '' !== $birth_year ? ' / ' : '' ) . $birth_year );

		return '' === $value ? '-' : $value;
	}

	private static function height_summary( array $row ): string {
		$height    = trim( (string) ( $row['height'] ?? '' ) );
		$height_cm = trim( (string) ( $row['height_cm'] ?? '' ) );

		if ( '' === $height && '' === $height_cm ) {
			return '-';
		}

		if ( '' === $height ) {
			return $height_cm . ' cm';
		}

		if ( '' !== $height_cm && false === stripos( $height, 'cm' ) ) {
			return $height . ' (' . $height_cm . ' cm)';
		}

		return $height;
	}

	private static function option_label( string $option ): string {
		if ( '' === $option ) {
			return __( 'Select gender', 'mma-future-data-engine' );
		}

		return str_replace( '_', ' ', $option );
	}

	private static function status_help_text(): string {
		return __( 'provisional: draft/unverified canonical fighter; verified: reviewed and trusted canonical fighter; merged: duplicate record merged into another fighter and not rankable; hidden: kept in database but hidden from public views; retired: no longer active and kept for history/fight logs; deleted_soft: intentionally removed/hidden from normal use, not ordinary ranking ineligibility.', 'mma-future-data-engine' );
	}

	private static function rankability_status_help_text(): string {
		return __( 'rankable: may appear in ranking outputs; pending_review: needs admin review before ranking; ineligible_age: excluded by age rule; ineligible_inactive: excluded by inactivity rule; ineligible_ufc: excluded because fighter entered UFC; ineligible_too_many_fights: excluded by pro fight count rule; ineligible_loss_limit: excluded by results/loss-limit rule; insufficient_data: not enough reliable data to rank; not_public: not eligible for public/ranking display. Ineligible does not mean deleted; these fighters remain useful for fight logs, opponent history, scoring context, and transparency.', 'mma-future-data-engine' );
	}
}
