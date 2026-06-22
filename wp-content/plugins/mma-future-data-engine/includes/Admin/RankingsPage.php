<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\RankingActivationService;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\RankingCalculatorService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingsPage {
	private const PAGE_SLUG = 'mmaf-rankings';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$notice = self::handle_post();
		}

		$runs             = new RankingRunRepository();
		$rankings         = new RankingCurrentRepository();
		$latest_run       = $runs->latest();
		$active_run       = $runs->active();
		$summary          = $runs->get_last_calculation_summary();
		$activation       = $runs->get_last_activation_summary();
		$recent_runs      = $runs->recent( 10 );
		$integrity        = $rankings->current_integrity();
		$current_count    = $rankings->current_count();
		$latest_run_id    = $latest_run ? (int) $latest_run['id'] : 0;
		$active_run_id    = $active_run ? (int) $active_run['id'] : 0;
		$draft_rows_count = $latest_run_id > 0 ? $rankings->snapshot_count_for_run( $latest_run_id ) : 0;
		$draft_boards_count = $latest_run_id > 0 ? $rankings->snapshot_board_count_for_run( $latest_run_id ) : 0;
		$draft_unique_fighters = $latest_run_id > 0 ? $rankings->snapshot_unique_fighter_count_for_run( $latest_run_id ) : 0;
		$available_boards = $latest_run_id > 0 ? $rankings->snapshot_boards_for_run( $latest_run_id ) : array();
		$selected_board = self::selected_board( $available_boards );
		$selected_board_rows_count = $latest_run_id > 0 && '' !== $selected_board ? $rankings->snapshot_count_for_run_board( $latest_run_id, $selected_board ) : 0;
		$selected_board_unique_fighters = $latest_run_id > 0 && '' !== $selected_board ? $rankings->snapshot_unique_fighter_count_for_run_board( $latest_run_id, $selected_board ) : 0;
		$preview_per_page = self::current_preview_per_page();
		$preview_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$preview_total_pages = max( 1, (int) ceil( $selected_board_rows_count / $preview_per_page ) );
		$preview_paged    = min( $preview_paged, $preview_total_pages );
		$preview_offset   = ( $preview_paged - 1 ) * $preview_per_page;
		$preview_rows     = $latest_run_id > 0 ? $rankings->latest_preview( $latest_run_id, $preview_per_page, $selected_board, $preview_offset ) : array();
		$latest_summary   = $latest_run ? ( self::summary_from_run( $latest_run ) ?: $summary ) : $summary;
		$latest_formula_version = $latest_run ? (string) $latest_run['formula_version'] : FormulaRegistry::current_version();
		$latest_uses_direct_scores = FormulaRegistry::uses_direct_scores( $latest_formula_version );
		$latest_warnings  = $latest_run_id > 0 ? $rankings->snapshot_warning_diagnostics( $latest_run_id ) : null;
		$active_warnings  = $active_run_id > 0 ? $rankings->current_warning_diagnostics( $active_run_id ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Rankings', 'mma-future-data-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="<?php echo esc_attr( 'error' === $notice['type'] ? 'notice notice-error' : 'notice notice-success' ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Ranking State', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 920px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Ranking runs count', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $runs->count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $active_run ? self::format_run( $active_run ) : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest ranking run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $latest_run ? self::format_run( $latest_run ) : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest draft newer than active', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $latest_run_id > 0 && $active_run_id > 0 && $latest_run_id > $active_run_id ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current live ranking rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $current_count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current live boards', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $integrity['current_boards_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest draft rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $draft_rows_count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest draft boards', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $draft_boards_count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest draft unique fighters', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $draft_unique_fighters ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Eligible fighters from latest run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) ( $latest_summary['eligible_fighters'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Ineligible fighters from latest run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) ( $latest_summary['ineligible_fighters'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Warnings from latest run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) ( $latest_summary['warnings_count'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest calculation summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_summary( $summary ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Storage strategy', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) ( $summary['storage_strategy'] ?? __( 'Draft rows use ranking snapshots. Live activation is deferred.', 'mma-future-data-engine' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tie-breaker policy', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::tie_breaker_policy_text( $latest_formula_version ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest activation summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_activation_summary( $activation ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current ranking integrity', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_integrity( $integrity ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $latest_run_id > 0 && 0 === $draft_rows_count ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No ranked rows in latest draft. Imported legacy fighters are not rankable by default.', 'mma-future-data-engine' ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_activation_guidance( $latest_run, $draft_rows_count, $latest_summary ); ?>

			<?php self::render_warning_diagnostics( __( 'Latest Draft Warning Breakdown', 'mma-future-data-engine' ), $latest_run, $latest_warnings ); ?>
			<?php self::render_warning_diagnostics( __( 'Active Live Warning Breakdown', 'mma-future-data-engine' ), $active_run, $active_warnings ); ?>

			<form method="post" style="margin-top: 18px;">
				<?php wp_nonce_field( 'mmaf_calculate_ranking_draft', 'mmaf_rankings_nonce' ); ?>
				<input type="hidden" name="mmaf_action" value="calculate_draft">
				<?php submit_button( __( 'Calculate Ranking Draft', 'mma-future-data-engine' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $recent_runs ) ) : ?>
				<h2><?php echo esc_html__( 'Recent Ranking Runs', 'mma-future-data-engine' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Run ID', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Formula', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Reference date', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Calculated at', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Active', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Draft rows', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_runs as $run ) : ?>
							<?php
							$run_id         = (int) $run['id'];
							$run_draft_rows = $rankings->snapshot_count_for_run( $run_id );
							$can_activate   = 'completed' === (string) $run['status'] && $run_draft_rows > 0;
							?>
							<tr>
								<td><?php echo esc_html( (string) $run_id ); ?></td>
								<td><?php echo esc_html( (string) $run['status'] ); ?></td>
								<td><?php echo esc_html( (string) $run['formula_version'] ); ?></td>
								<td><?php echo esc_html( (string) $run['reference_date'] ); ?></td>
								<td><?php echo esc_html( (string) $run['calculated_at'] ); ?></td>
								<td><?php echo esc_html( 1 === (int) $run['is_active'] ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' ) ); ?></td>
								<td><?php echo esc_html( (string) $run_draft_rows ); ?></td>
								<td>
									<?php if ( $can_activate ) : ?>
										<form method="post" onsubmit="return window.confirm('<?php echo esc_js( __( 'Activate this ranking run and replace live current rankings?', 'mma-future-data-engine' ) ); ?>');">
											<?php wp_nonce_field( 'mmaf_activate_ranking_' . $run_id, 'mmaf_rankings_activation_nonce' ); ?>
											<input type="hidden" name="mmaf_action" value="activate_run">
											<input type="hidden" name="ranking_run_id" value="<?php echo esc_attr( (string) $run_id ); ?>">
											<?php submit_button( __( 'Activate', 'mma-future-data-engine' ), 'secondary small', 'submit', false ); ?>
											<p class="description"><?php echo esc_html__( 'Manual only. Activation is technically allowed because draft rows exist, but it does not certify production readiness.', 'mma-future-data-engine' ); ?></p>
										</form>
									<?php else : ?>
										<?php echo esc_html__( 'Not activatable', 'mma-future-data-engine' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $available_boards ) ) : ?>
				<h2><?php echo esc_html__( 'Latest Draft Preview', 'mma-future-data-engine' ); ?></h2>
				<form method="get" style="margin: 8px 0 12px;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label for="mmaf-board-filter"><strong><?php echo esc_html__( 'Board', 'mma-future-data-engine' ); ?></strong></label>
					<select id="mmaf-board-filter" name="mmaf_board">
						<?php foreach ( $available_boards as $board ) : ?>
							<?php $board_key = (string) $board['board_key']; ?>
							<option value="<?php echo esc_attr( $board_key ); ?>" <?php selected( $selected_board, $board_key ); ?>>
								<?php
								echo esc_html(
									sprintf(
										'%1$s (%2$d rows, %3$d fighters)',
										$board_key,
										(int) $board['rows_count'],
										(int) $board['unique_fighters']
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php self::render_preview_per_page_select( $preview_per_page ); ?>
					<?php submit_button( __( 'Filter', 'mma-future-data-engine' ), 'secondary small', 'submit', false ); ?>
				</form>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: run ID, 2: run status, 3: active/draft label, 4: board key, 5: board rows, 6: unique fighters. */
							__( 'Showing latest draft run %1$d (%2$s, %3$s) for board %4$s: %5$d board rows, %6$d unique fighters. Default board is overall; ranks are board-specific.', 'mma-future-data-engine' ),
							$latest_run_id,
							$latest_run ? (string) $latest_run['status'] : '',
							$latest_run_id > 0 && $latest_run_id === $active_run_id ? __( 'also active live run', 'mma-future-data-engine' ) : __( 'not active live run', 'mma-future-data-engine' ),
							$selected_board,
							$selected_board_rows_count,
							$selected_board_unique_fighters
						)
					);
					?>
				</p>
				<p class="description"><?php echo esc_html( self::preview_score_description( $latest_formula_version ) ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $preview_rows ) ) : ?>
				<?php self::render_preview_pagination( $selected_board_rows_count, $preview_paged, $preview_per_page, $selected_board ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Rank', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Fighter', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Board', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html( $latest_uses_direct_scores ? __( 'Direct score', 'mma-future-data-engine' ) : __( 'Total (0-100)', 'mma-future-data-engine' ) ); ?></th>
							<?php if ( ! $latest_uses_direct_scores ) : ?>
								<th scope="col"><?php echo esc_html__( 'Performance Raw', 'mma-future-data-engine' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Adjusted Raw', 'mma-future-data-engine' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Confidence', 'mma-future-data-engine' ); ?></th>
							<?php endif; ?>
							<th scope="col"><?php echo esc_html__( 'Sample', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Score breakdown', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Eligibility', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Quality flags', 'mma-future-data-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $preview_rows as $row ) : ?>
							<?php
							$eligibility = json_decode( (string) $row['eligibility_json'], true );
							$warnings    = json_decode( (string) $row['warnings_json'], true );
							$breakdown   = json_decode( (string) ( $row['breakdown_json'] ?? '' ), true );
							$quality_flags = json_decode( (string) ( $row['quality_flags_json'] ?? '' ), true );
							?>
							<tr>
								<td><?php echo esc_html( (string) $row['rank_position'] ); ?></td>
								<td><?php echo esc_html( (string) ( $row['display_name'] ?? ( 'Fighter #' . $row['fighter_id'] ) ) ); ?></td>
								<td><?php echo esc_html( (string) $row['board_key'] ); ?></td>
								<td><?php echo esc_html( (string) $row['total_score'] ); ?></td>
								<?php if ( ! $latest_uses_direct_scores ) : ?>
									<td><?php echo esc_html( self::format_score_part( $breakdown['performance_raw_score'] ?? $breakdown['raw_score_before_confidence'] ?? null ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['raw_score'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['confidence_score'] ?? '' ) ); ?></td>
								<?php endif; ?>
								<td><?php echo esc_html( (string) ( $row['sample_size'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( self::format_score_breakdown( (string) ( $row['breakdown_json'] ?? '' ), $latest_formula_version ) ); ?></td>
								<td><?php echo esc_html( ! empty( $eligibility['eligible'] ) ? __( 'Eligible', 'mma-future-data-engine' ) : __( 'Ineligible', 'mma-future-data-engine' ) ); ?></td>
								<td><?php echo esc_html( (string) count( is_array( $warnings['warnings'] ?? null ) ? $warnings['warnings'] : array() ) ); ?></td>
								<td><?php echo esc_html( empty( $quality_flags ) || ! is_array( $quality_flags ) ? '-' : implode( ', ', $quality_flags ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::render_preview_pagination( $selected_board_rows_count, $preview_paged, $preview_per_page, $selected_board ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function selected_board( array $available_boards ): string {
		if ( empty( $available_boards ) ) {
			return '';
		}

		$available = array();
		foreach ( $available_boards as $board ) {
			$available[] = (string) $board['board_key'];
		}

		$requested = isset( $_GET['mmaf_board'] ) ? sanitize_key( wp_unslash( $_GET['mmaf_board'] ) ) : 'overall';
		if ( in_array( $requested, $available, true ) ) {
			return $requested;
		}

		return in_array( 'overall', $available, true ) ? 'overall' : (string) $available[0];
	}

	private static function current_preview_per_page(): int {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 25;

		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 25;
	}

	private static function render_preview_per_page_select( int $per_page ): void {
		?>
		<label for="mmaf-rankings-per-page" style="margin-left: 8px;"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-rankings-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function render_preview_pagination( int $total, int $paged, int $per_page, string $board ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args        = array(
			'page'     => self::PAGE_SLUG,
			'per_page' => $per_page,
		);

		if ( '' !== $board ) {
			$args['mmaf_board'] = $board;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s ranked row', '%s ranked rows', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

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

	private static function render_activation_guidance( ?array $latest_run, int $draft_rows_count, ?array $summary ): void {
		$eligible = (int) ( $summary['eligible_fighters'] ?? 0 );
		$status   = $latest_run ? (string) $latest_run['status'] : '';
		$activatable = $latest_run && 'completed' === $status && $draft_rows_count > 0;

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Activation guidance:', 'mma-future-data-engine' ) . '</strong> ';
		echo esc_html__( 'No ranking is activated automatically by this page. Draft calculation writes draft snapshots only; live rankings change only after manual activation.', 'mma-future-data-engine' );
		echo '</p>';

		if ( $activatable && $eligible < 30 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: eligible fighter count. */
					__( 'This ranking is activatable but still not production-ready because only %d fighters are eligible. Recommended before frontend integration: expand eligible fighters to at least 30-50 and inspect warning breakdown.', 'mma-future-data-engine' ),
					$eligible
				)
			) . '</p>';
		} elseif ( $activatable ) {
			echo '<p>' . esc_html__( 'This ranking is technically activatable because completed draft rows exist. Inspect the latest draft and warning breakdown before manual activation.', 'mma-future-data-engine' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Activation is technically allowed only when a completed ranking run has draft snapshot rows.', 'mma-future-data-engine' ) . '</p>';
		}

		echo '</div>';
	}

	private static function render_warning_diagnostics( string $title, ?array $run, ?array $diagnostics ): void {
		?>
		<h2><?php echo esc_html( $title ); ?></h2>
		<?php if ( ! $run || ! is_array( $diagnostics ) ) : ?>
			<p><?php echo esc_html__( 'No run is available for this warning breakdown.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: run ID, 2: status, 3: active flag. */
					__( 'Warning breakdown for run %1$d, status %2$s, active=%3$d.', 'mma-future-data-engine' ),
					(int) $run['id'],
					(string) $run['status'],
					(int) $run['is_active']
				)
			);
			?>
		</p>
		<table class="widefat striped" style="max-width: 920px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Rows checked / rows with warnings', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) (int) $diagnostics['rows_checked'] . ' / ' . (string) (int) $diagnostics['rows_with_warnings'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Unique fighters with warnings', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) (int) $diagnostics['unique_fighters_with_warnings'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Eligible / ineligible stored rows with warnings', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) (int) $diagnostics['eligible_rows_with_warnings'] . ' / ' . (string) (int) $diagnostics['ineligible_rows_with_warnings'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Warning groups', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( self::format_warning_groups( (array) $diagnostics['group_counts'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Storage note', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) $diagnostics['storage_note'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( empty( $diagnostics['warning_counts'] ) ) : ?>
			<p><?php echo esc_html__( 'No row-level warnings are stored for this run.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped" style="margin-top: 10px;">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Warning', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Group', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Row occurrences', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Sample affected rows', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $diagnostics['warning_counts'] as $warning => $count ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $warning ); ?></td>
						<td><?php echo esc_html( self::warning_group_label( (string) $warning ) ); ?></td>
						<td><?php echo esc_html( (string) (int) $count ); ?></td>
						<td><?php echo esc_html( self::format_warning_samples( (array) ( $diagnostics['samples'][ $warning ] ?? array() ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function handle_post(): array {
		$action = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';

		if ( 'calculate_draft' === $action ) {
			return self::handle_calculate_post();
		}

		if ( 'activate_run' === $action ) {
			return self::handle_activate_post();
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Invalid rankings action.', 'mma-future-data-engine' ),
		);
	}

	private static function handle_calculate_post(): array {
		if ( ! isset( $_POST['mmaf_rankings_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_calculate_ranking_draft', 'mmaf_rankings_nonce' );

		try {
			$service = new RankingCalculatorService();
			$summary = $service->calculate_draft( get_current_user_id() );

			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: ranking run ID, 2: eligible fighters, 3: ineligible fighters, 4: ranked rows, 5: board count, 6: warnings, 7: activatable yes/no. */
					__( 'Ranking draft calculated. Run: %1$d. Eligible: %2$d. Ineligible: %3$d. Draft rows: %4$d. Boards: %5$d. Warnings: %6$d. Activatable: %7$s.', 'mma-future-data-engine' ),
					(int) $summary['ranking_run_id'],
					(int) $summary['eligible_fighters'],
					(int) $summary['ineligible_fighters'],
					(int) $summary['ranked_rows'],
					count( (array) ( $summary['boards_generated'] ?? array() ) ),
					(int) $summary['warnings_count'],
					(int) $summary['ranked_rows'] > 0 ? __( 'yes', 'mma-future-data-engine' ) : __( 'no', 'mma-future-data-engine' )
				),
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function handle_activate_post(): array {
		$ranking_run_id = isset( $_POST['ranking_run_id'] ) ? absint( wp_unslash( $_POST['ranking_run_id'] ) ) : 0;

		if ( ! isset( $_POST['mmaf_rankings_activation_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_activate_ranking_' . $ranking_run_id, 'mmaf_rankings_activation_nonce' );

		try {
			$service = new RankingActivationService();
			$summary = $service->activate( $ranking_run_id, get_current_user_id() );

			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: ranking run ID, 2: rows written, 3: boards count. */
					__( 'Ranking run %1$d activated. Current rows written: %2$d. Boards: %3$d.', 'mma-future-data-engine' ),
					(int) $summary['ranking_run_id'],
					(int) $summary['current_rows_written'],
					(int) $summary['boards_count']
				),
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function summary_from_run( array $run ): ?array {
		$notes = json_decode( (string) ( $run['notes'] ?? '' ), true );

		return is_array( $notes ) ? $notes : null;
	}

	private static function format_warning_groups( array $groups ): string {
		return sprintf(
			'serious readiness=%d, scoring context=%d, other=%d',
			(int) ( $groups['serious_readiness'] ?? 0 ),
			(int) ( $groups['scoring_context'] ?? 0 ),
			(int) ( $groups['other'] ?? 0 )
		);
	}

	private static function warning_group_label( string $warning ): string {
		if ( in_array( $warning, array( 'missing_prefight_record', 'missing_method_category', 'skipped_non_scoring_bout', 'birth_year_only_age_estimate' ), true ) ) {
			return __( 'Scoring context', 'mma-future-data-engine' );
		}

		if ( in_array( $warning, array( 'missing_date_of_birth', 'invalid_date_of_birth', 'missing_last_fight_date', 'missing_stats_row', 'rankable_missing_gender', 'rankable_missing_weight_class', 'inconsistent_rankability_status_vs_is_rankable', 'no_countable_fights' ), true ) ) {
			return __( 'Serious readiness', 'mma-future-data-engine' );
		}

		return __( 'Other', 'mma-future-data-engine' );
	}

	private static function format_warning_samples( array $samples ): string {
		$parts = array();

		foreach ( $samples as $sample ) {
			$extra = '';
			if ( null !== ( $sample['countable_bouts'] ?? null ) || null !== ( $sample['prefight_missing'] ?? null ) ) {
				$extra = sprintf(
					' bouts=%s prefight_missing=%s',
					null === ( $sample['countable_bouts'] ?? null ) ? '-' : (string) (int) $sample['countable_bouts'],
					null === ( $sample['prefight_missing'] ?? null ) ? '-' : (string) (int) $sample['prefight_missing']
				);
			}

			$parts[] = sprintf(
				'#%d %s (%s rank %d%s)',
				(int) ( $sample['fighter_id'] ?? 0 ),
				(string) ( $sample['display_name'] ?? '' ),
				(string) ( $sample['board_key'] ?? '' ),
				(int) ( $sample['rank_position'] ?? 0 ),
				$extra
			);
		}

		return empty( $parts ) ? '-' : implode( '; ', $parts );
	}

	private static function format_run( array $run ): string {
		return sprintf(
			'id=%d formula=%s reference_date=%s status=%s active=%d calculated_at=%s',
			(int) $run['id'],
			(string) $run['formula_version'],
			(string) $run['reference_date'],
			(string) $run['status'],
			(int) $run['is_active'],
			(string) $run['calculated_at']
		);
	}

	private static function format_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'run=%d formula=%s reference_date=%s status=%s eligible=%d ineligible=%d rows=%d boards=%s warnings=%d',
			(int) ( $summary['ranking_run_id'] ?? 0 ),
			(string) ( $summary['formula_version'] ?? '' ),
			(string) ( $summary['reference_date'] ?? '' ),
			(string) ( $summary['status'] ?? '' ),
			(int) ( $summary['eligible_fighters'] ?? 0 ),
			(int) ( $summary['ineligible_fighters'] ?? 0 ),
			(int) ( $summary['ranked_rows'] ?? 0 ),
			implode( ',', (array) ( $summary['boards_generated'] ?? array() ) ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}

	private static function format_activation_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'run=%d activated_at=%s rows=%d boards=%d previous_active=%s status=%s warnings=%d',
			(int) ( $summary['ranking_run_id'] ?? 0 ),
			(string) ( $summary['activated_at'] ?? '' ),
			(int) ( $summary['current_rows_written'] ?? 0 ),
			(int) ( $summary['boards_count'] ?? 0 ),
			null === ( $summary['previous_active_ranking_run_id'] ?? null ) ? 'none' : (string) $summary['previous_active_ranking_run_id'],
			(string) ( $summary['status'] ?? '' ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}

	private static function format_integrity( array $integrity ): string {
		return sprintf(
			'rows=%d boards=%d duplicate_board_fighters=%d missing_fighters=%d malformed=%s',
			(int) ( $integrity['current_rows_count'] ?? 0 ),
			(int) ( $integrity['current_boards_count'] ?? 0 ),
			(int) ( $integrity['duplicate_board_fighters'] ?? 0 ),
			(int) ( $integrity['missing_fighters'] ?? 0 ),
			! empty( $integrity['is_malformed'] ) ? 'yes' : 'no'
		);
	}

	private static function format_score_breakdown( string $breakdown_json, string $formula_version ): string {
		$breakdown = json_decode( $breakdown_json, true );
		if ( ! is_array( $breakdown ) ) {
			return '-';
		}

		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return sprintf(
				'base=%s + finish=%s + age=%s + OD=%s + loss quality=%s = total=%s',
				self::format_score_part( $breakdown['base_record_points'] ?? null ),
				self::format_score_part( $breakdown['finishes_points'] ?? null ),
				self::format_score_part( $breakdown['age_adjustment_points'] ?? null ),
				self::format_score_part( $breakdown['opponent_differential_points'] ?? null ),
				self::format_score_part( $breakdown['loss_quality_penalty_points'] ?? null ),
				self::format_score_part( $breakdown['total_score'] ?? null )
			);
		}

		return sprintf(
			'base=%s, finish=%s, age=%s, OD=%s, loss quality=%s, performance raw=%s, confidence=%s%%, factor=%s, adjusted raw=%s, total=%s',
			self::format_score_part( $breakdown['base_record_points'] ?? null ),
			self::format_score_part( $breakdown['finishes_points'] ?? null ),
			self::format_score_part( $breakdown['age_adjustment_points'] ?? null ),
			self::format_score_part( $breakdown['opponent_differential_points'] ?? null ),
			self::format_score_part( $breakdown['loss_quality_penalty_points'] ?? null ),
			self::format_score_part( $breakdown['performance_raw_score'] ?? $breakdown['raw_score_before_confidence'] ?? null ),
			self::format_score_part( $breakdown['confidence_score'] ?? null ),
			self::format_score_part( $breakdown['confidence_factor'] ?? null ),
			self::format_score_part( $breakdown['raw_score'] ?? null ),
			self::format_score_part( $breakdown['normalized_score'] ?? null )
		);
	}

	private static function format_score_part( $value ): string {
		return is_numeric( $value ) ? number_format( (float) $value, 3, '.', '' ) : '-';
	}

	private static function tie_breaker_policy_text( string $formula_version ): string {
		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return __( 'Equal totals are ordered by wins, finish rate, younger age, most recent last fight, then fighter ID.', 'mma-future-data-engine' );
		}

		return __( 'Equal totals are ordered by adjusted raw score, wins, finish rate, confidence, younger age, most recent last fight, then fighter ID.', 'mma-future-data-engine' );
	}

	private static function preview_score_description( string $formula_version ): string {
		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return __( 'Formula v1.5 uses a direct score contract: base + finish + age + opponent differential + loss quality = total. Sample size is informational only. Tie-breakers: wins, finish rate, younger age, most recent last fight, then fighter ID.', 'mma-future-data-engine' );
		}

		return __( 'Total (0-100) is normalized from Adjusted Raw. Adjusted Raw is Performance Raw pulled toward neutral zero by Confidence. Tie-breakers: Adjusted Raw, wins, finish rate, confidence, younger age, most recent last fight, then fighter ID.', 'mma-future-data-engine' );
	}
}
