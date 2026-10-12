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

	/** Queue calculation and redirect before WordPress renders the admin header. */
	public static function handle_calculation_request(): void {
		if ( ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_GET['page'] ?? '') !== self::PAGE_SLUG || ($_POST['mmaf_action'] ?? '') !== 'calculate_draft' ) { return; }
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		$notice = self::handle_calculate_post();
		wp_die( esc_html( $notice['message'] ), 'Ranking calculation', array( 'response' => 409, 'back_link' => true ) );
	}

	public static function enqueue_assets(): void {
		if ( sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) !== self::PAGE_SLUG ) { return; }
		wp_enqueue_style( 'mmaf-rankings-admin', plugins_url( 'assets/admin-rankings.css', MMAF_PLUGIN_FILE ), array(), (string) filemtime( MMAF_PLUGIN_DIR . 'assets/admin-rankings.css' ) );
	}

	public static function render(): void {
        if (!current_user_can(Capabilities::MANAGE)) { wp_die(esc_html__('You do not have permission to access this page.', 'mma-future-data-engine')); }
        $notice = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? self::handle_post() : null;
        $runs = new RankingRunRepository(); $rankings = new RankingCurrentRepository();
        $latest = $runs->latest(); $active = $runs->active(); $recent = $runs->recent(10);
        $latest_id = (int) ($latest['id'] ?? 0); $active_id = (int) ($active['id'] ?? 0);
        $requested = absint(wp_unslash($_GET['mmaf_run'] ?? 0)) ?: $latest_id;
        $selected = $requested === $latest_id ? $latest : ($requested === $active_id ? $active : $runs->find($requested));
        $selected_id = (int) ($selected['id'] ?? 0);
        $boards = $selected_id ? $rankings->snapshot_boards_for_run($selected_id) : [];
        $board = self::selected_board($boards); $board_count = 0;
        foreach ($boards as $item) { if ($item['board_key'] === $board) { $board_count = (int) $item['rows_count']; } }
        $per_page = self::current_preview_per_page();
        $paged = min(max(1, absint($_GET['paged'] ?? 1)), max(1, (int) ceil($board_count / $per_page)));
        $rows = $selected_id && $board !== '' ? $rankings->latest_preview($selected_id, $per_page, $board, ($paged - 1) * $per_page) : [];
        $formula = (string) ($selected['formula_version'] ?? FormulaRegistry::current_version());
        $summary = $latest ? self::summary_from_run($latest) : null;
        $latest_boards = $selected_id === $latest_id ? $boards : ($latest_id ? $rankings->snapshot_boards_for_run($latest_id) : []);
        $latest_rows = array_sum(array_column($latest_boards, 'rows_count'));
        $current_rows = $rankings->current_count(); $current_boards = $rankings->board_count();
        $activation = $runs->get_last_activation_summary();
        $choices = [];
        foreach (array_merge([$latest, $active, $selected], $recent) as $run) { if ($run) { $choices[(int) $run['id']] = $run; } }
        krsort($choices);
        $diagnostics_requested = ($_GET['mmaf_diagnostics'] ?? '') === '1';
        ?>
        <div class="wrap mmaf-rankings-admin">
            <h1 class="wp-heading-inline">Rankings</h1>
            <hr class="wp-header-end">
            <?php if ($notice): ?><div class="notice inline <?php echo $notice['type'] === 'error' ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
            <p class="mmaf-page-intro">Pregledaj sačuvane obračune i kategorije pre ručne aktivacije. <a href="<?php echo esc_url(admin_url('admin.php?page=mmaf-operations')); ?>">Operations and results</a></p>
            <nav class="mmaf-section-nav" aria-label="Rankings sections"><a href="#mmaf-ranking-preview">Ranked fighters</a><a href="#mmaf-ranking-history">Calculation history</a><a href="#mmaf-ranking-diagnostics">Diagnostics</a></nav>
            <section class="mmaf-run-overview" aria-label="Ranking overview">
                <?php self::render_run_card('Live ranking', $active, $current_rows, $current_boards, $active_id, $activation); ?>
                <?php self::render_run_card('Latest calculation', $latest, (int) $latest_rows, count($latest_boards), $active_id); ?>
            </section>
            <div class="mmaf-calculation-toolbar">
                <p>Calculate pravi novi neaktivan draft u pozadini. Sam pregled ili izbor liste ne menja javno rangiranje.</p>
                <form method="post"><?php wp_nonce_field('mmaf_calculate_ranking_draft', 'mmaf_rankings_nonce'); ?><input type="hidden" name="mmaf_action" value="calculate_draft"><button class="button" type="submit">Calculate Ranking Draft</button></form>
            </div>
            <section class="mmaf-ranking-panel" id="mmaf-ranking-preview" aria-labelledby="mmaf-preview-title">
                <div class="mmaf-panel-heading"><div><h2 id="mmaf-preview-title">Ranked fighters</h2><p>Snapshot pregled čuva izračunate rangove i skorove iz izabranog obračuna.</p></div><?php if ($selected): ?><span class="mmaf-status <?php echo $selected_id === $active_id ? 'mmaf-status-live' : ''; ?>"><?php echo esc_html('#' . $selected_id . ($selected_id === $active_id ? ' · Live' : ' · Not live')); ?></span><?php endif; ?></div>
                <?php if (!$selected && $requested): ?><div class="notice notice-warning inline"><p>Ranking run not found. <a href="<?php echo esc_url(self::preview_url($latest_id)); ?>">Open latest calculation</a></p></div><?php endif; ?>
                <?php if ($choices): ?>
                    <form method="get" class="mmaf-ranking-filters">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                        <div class="mmaf-filter-field"><label for="mmaf-run-filter">Ranking run</label><select id="mmaf-run-filter" name="mmaf_run"><?php if (!$selected): ?><option value="<?php echo esc_attr($requested); ?>" selected>Run not found</option><?php endif; ?><?php foreach ($choices as $id => $run): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($selected_id, $id); ?>><?php echo esc_html('#' . $id . ' · ' . ($id === $active_id ? 'Live' : ucfirst($run['status']) . ' · Not live') . ' · ' . $run['reference_date']); ?></option><?php endforeach; ?></select></div>
                        <div class="mmaf-filter-field"><label for="mmaf-board-filter">Category</label><select id="mmaf-board-filter" name="mmaf_board" <?php disabled(!$boards); ?>><?php if (!$boards): ?><option value="">No categories available</option><?php endif; ?><?php foreach ($boards as $item): ?><option value="<?php echo esc_attr($item['board_key']); ?>" <?php selected($board, $item['board_key']); ?>><?php echo esc_html(self::board_label($item['board_key']) . ' · ' . number_format_i18n($item['rows_count']) . ' fighters'); ?></option><?php endforeach; ?></select></div>
                        <div class="mmaf-filter-field"><?php self::render_preview_per_page_select($per_page); ?></div>
                        <button class="button button-primary" type="submit">Apply filters</button>
                    </form>
                <?php endif; ?>
                <?php if ($selected): ?>
                    <div class="mmaf-preview-summary"><span><strong><?php echo esc_html(self::board_label($board) ?: 'No category'); ?></strong> · <?php echo esc_html(number_format_i18n($board_count)); ?> fighters</span><span><?php echo esc_html($formula); ?> · <?php echo esc_html(ucfirst($selected['status'])); ?> · Calculated <?php echo esc_html(self::display_time($selected['calculated_at'])); ?></span></div>
                <?php endif; ?>
                <?php if ($selected && $selected['status'] !== 'completed'): ?><p class="mmaf-inline-warning">This calculation is <?php echo esc_html($selected['status']); ?>. Available snapshot rows may be incomplete; activation requires a completed calculation.</p><?php endif; ?>
                <?php if ($rows): ?>
                    <?php self::render_preview_pagination($board_count, $paged, $per_page, $board, $selected_id); ?>
                    <div class="mmaf-table-scroll" role="region" aria-label="Ranking results" tabindex="0"><table class="widefat striped mmaf-ranking-results"><caption class="screen-reader-text"><?php echo esc_html('Saved ranking #' . $selected_id . ', ' . self::board_label($board)); ?></caption><thead><tr><th scope="col" class="mmaf-rank-col">Rank</th><th scope="col" class="mmaf-name-col">Fighter</th><th scope="col" class="mmaf-score-col"><?php echo FormulaRegistry::uses_direct_scores($formula) ? 'Direct score' : 'Total (0–100)'; ?></th><th scope="col" class="mmaf-sample-col">Scoring bouts</th><th scope="col" class="mmaf-checks-col">Checks</th><th scope="col" class="mmaf-breakdown-col">Score details</th></tr></thead><tbody>
                        <?php foreach ($rows as $row): self::render_preview_row($row, $formula); endforeach; ?>
                    </tbody></table></div>
                    <?php self::render_preview_pagination($board_count, $paged, $per_page, $board, $selected_id); ?>
                    <details class="mmaf-method-details"><summary>How to read these scores</summary><p><?php echo esc_html(self::preview_score_description($formula)); ?></p></details>
                <?php elseif ($selected): ?>
                    <div class="mmaf-empty-state"><strong>No ranked fighters in this calculation</strong><p>Ovaj snapshot nema rezultate za prikaz. Proveri status obračuna i njegove podatke pre novog obračuna.</p></div>
                <?php elseif (!$requested): ?>
                    <div class="mmaf-empty-state"><strong>No ranking calculations yet</strong><p>Pripremi podatke boraca, zatim pokreni Calculate Ranking Draft. Aktivacija je zaseban ručni korak.</p></div>
                <?php endif; ?>
            </section>
            <details class="mmaf-ranking-panel mmaf-panel-disclosure" id="mmaf-ranking-history"><summary>Calculation history <span><?php echo esc_html(number_format_i18n($runs->count())); ?> runs</span></summary>
                <div class="mmaf-panel-content"><p>Preview samo otvara sačuvane rezultate. Activate zamenjuje javnu listu i zahteva postojeću ručnu potvrdu.</p>
                <?php if ($recent): ?>
                    <div class="mmaf-table-scroll" role="region" aria-label="Recent ranking calculations" tabindex="0"><table class="widefat striped mmaf-history-table"><thead><tr><th scope="col">Run / status</th><th scope="col">Formula</th><th scope="col">Reference date</th><th scope="col">Calculated</th><th scope="col">Snapshot rows</th><th scope="col">Actions</th></tr></thead><tbody>
                    <?php foreach ($recent as $run): $id = (int) $run['id']; $count = $rankings->snapshot_count_for_run($id); $can_activate = $run['status'] === 'completed' && $count > 0; ?>
                        <tr><td><strong>#<?php echo esc_html($id); ?></strong> <span class="mmaf-status <?php echo $id === $active_id ? 'mmaf-status-live' : ''; ?>"><?php echo $id === $active_id ? 'Live' : esc_html(ucfirst($run['status'])); ?></span></td><td><?php echo esc_html($run['formula_version']); ?></td><td><?php echo esc_html($run['reference_date']); ?></td><td><?php echo esc_html(self::display_time($run['calculated_at'])); ?></td><td><?php echo esc_html(number_format_i18n($count)); ?></td><td><a class="button button-small" href="<?php echo esc_url(self::preview_url($id, 'overall', $per_page)); ?>">Preview #<?php echo esc_html($id); ?></a>
                            <?php if ($id === $active_id): ?><span class="mmaf-muted mmaf-line">Currently live</span><?php elseif ($can_activate): ?>
                                <details class="mmaf-activation"><summary>Activate #<?php echo esc_html($id); ?></summary><p>Zamenjuje aktivnu javnu listu ovim snapshot-om. Pre toga pregledaj rezultate i upozorenja. Dostupnost akcije nije potvrda kvaliteta izvora.</p>
                                    <form method="post" onsubmit="return window.confirm('<?php echo esc_js('Activate ranking run #' . $id . ' and replace live current rankings?'); ?>');"><?php wp_nonce_field('mmaf_activate_ranking_' . $id, 'mmaf_rankings_activation_nonce'); ?><input type="hidden" name="mmaf_action" value="activate_run"><input type="hidden" name="ranking_run_id" value="<?php echo esc_attr($id); ?>"><button type="submit" class="button">Activate ranking #<?php echo esc_html($id); ?></button></form>
                                </details>
                            <?php else: ?><span class="mmaf-muted mmaf-line">Activation unavailable</span><?php endif; ?>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p class="description">Showing the ten most recent calculations. Raniji obračun možeš otvoriti iz njegovog sačuvanog admin linka.</p>
                <?php else: ?><p>No calculations are available.</p><?php endif; ?>
                </div>
            </details>
            <section class="mmaf-ranking-panel" id="mmaf-ranking-diagnostics" aria-labelledby="mmaf-diagnostics-title">
                <div class="mmaf-panel-heading"><div><h2 id="mmaf-diagnostics-title">Diagnostics</h2><p>Upozorenja o kvalitetu izvora i tehničke provere računaju se na eksplicitan zahtev.</p></div></div>
                <?php if ($selected): ?>
                    <div class="mmaf-diagnostic-summary"><span>Selected run <strong>#<?php echo esc_html($selected_id); ?></strong></span><span>Warnings recorded by calculation: <strong><?php $selected_summary = self::summary_from_run($selected); echo esc_html(self::display_count($selected_summary['warnings_count'] ?? null)); ?></strong></span></div>
                    <p class="description">Broj upozorenja nije broj jedinstvenih boraca. Jedan borac može imati više upozorenja i biti na više kategorija.</p>
                    <?php if ($diagnostics_requested): ?>
                        <div class="mmaf-diagnostic-content">
                            <?php self::render_warning_diagnostics('Selected snapshot warning breakdown', $selected, $rankings->snapshot_warning_diagnostics($selected_id)); ?>
                            <?php if ($selected_id === $active_id): self::render_warning_diagnostics('Live rows warning breakdown', $active, $rankings->current_warning_diagnostics($active_id)); endif; ?>
                            <?php $integrity = $rankings->current_integrity(); ?>
                            <h3>Live ranking integrity</h3><p><?php echo esc_html(self::format_integrity($integrity)); ?></p>
                            <details><summary>Calculation and activation metadata</summary><p><?php echo esc_html(self::format_summary($selected_summary)); ?></p><p><?php echo esc_html(self::format_activation_summary($activation)); ?></p><p><?php echo esc_html(self::tie_breaker_policy_text($formula)); ?></p></details>
                        </div>
                        <a class="button" href="<?php echo esc_url(self::preview_url($selected_id, $board, $per_page) . '#mmaf-ranking-diagnostics'); ?>">Close diagnostics</a>
                    <?php else: ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg('mmaf_diagnostics', '1', self::preview_url($selected_id, $board, $per_page)) . '#mmaf-ranking-diagnostics'); ?>">Compute diagnostics for #<?php echo esc_html($selected_id); ?></a>
                    <?php endif; ?>
                <?php else: ?><p>Choose an available calculation to inspect diagnostics.</p><?php endif; ?>
            </section>
        </div>
        <?php
    }

    private static function render_run_card(string $title, ?array $run, int $rows, int $boards, int $active_id, ?array $activation = null): void {
        $id = (int) ($run['id'] ?? 0); $live = $id && $id === $active_id; $summary = $run ? self::summary_from_run($run) : null;
        ?>
        <article class="mmaf-run-card"><div class="mmaf-card-heading"><h2><?php echo esc_html($title); ?></h2><?php if ($run): ?><span class="mmaf-status <?php echo $live ? 'mmaf-status-live' : ''; ?>"><?php echo $live ? 'Live' : 'Not live'; ?></span><?php endif; ?></div>
            <?php if ($run): ?>
                <div class="mmaf-run-id">#<?php echo esc_html($id); ?> <span><?php echo esc_html($run['formula_version']); ?> · <?php echo esc_html(ucfirst($run['status'])); ?></span></div>
                <p class="mmaf-run-counts"><strong><?php echo esc_html(number_format_i18n($rows)); ?></strong> board rows · <strong><?php echo esc_html(number_format_i18n($boards)); ?></strong> categories</p>
                <dl class="mmaf-run-dates"><dt>Reference date</dt><dd><?php echo esc_html($run['reference_date']); ?></dd><dt>Calculated</dt><dd><?php echo esc_html(self::display_time($run['calculated_at'])); ?></dd><?php if ($activation && (int) ($activation['ranking_run_id'] ?? 0) === $id): ?><dt>Activated</dt><dd><?php echo esc_html(self::display_time($activation['activated_at'] ?? null)); ?></dd><?php endif; ?></dl>
                <?php if (!$live): ?><p class="mmaf-muted">Eligible: <?php echo esc_html(self::display_count($summary['eligible_fighters'] ?? null)); ?> · Ineligible: <?php echo esc_html(self::display_count($summary['ineligible_fighters'] ?? null)); ?> · Warnings: <?php echo esc_html(self::display_count($summary['warnings_count'] ?? null)); ?></p><?php endif; ?>
                <a href="<?php echo esc_url(self::preview_url($id)); ?>"><?php echo $live ? 'View live snapshot' : 'Review calculation'; ?></a>
            <?php else: ?><p class="mmaf-muted"><?php echo $title === 'Live ranking' ? 'No ranking has been activated.' : 'No calculation is available.'; ?></p><?php endif; ?>
        </article>
        <?php
    }

    private static function render_preview_row(array $row, string $formula): void {
        $eligibility = json_decode((string) $row['eligibility_json'], true);
        $warnings = json_decode((string) $row['warnings_json'], true);
        $codes = is_array($warnings['warnings'] ?? null) ? $warnings['warnings'] : [];
        $flags = json_decode((string) ($row['quality_flags_json'] ?? ''), true); $flags = is_array($flags) ? $flags : [];
        $breakdown = json_decode((string) ($row['breakdown_json'] ?? ''), true); $breakdown = is_array($breakdown) ? $breakdown : [];
        ?>
        <tr><td class="mmaf-rank-col"><?php echo esc_html($row['rank_position']); ?></td><td><a class="mmaf-fighter-name" href="<?php echo esc_url(admin_url('admin.php?page=mmaf-fighters&action=edit&fighter_id=' . (int) $row['fighter_id'])); ?>"><?php echo esc_html($row['display_name'] ?? ('Fighter #' . $row['fighter_id'])); ?></a><small class="mmaf-muted mmaf-line">#<?php echo esc_html($row['fighter_id']); ?></small></td><td class="mmaf-score-col"><strong><?php echo esc_html(self::format_score_part($row['total_score'])); ?></strong></td><td><?php echo esc_html($row['sample_size'] ?? 'Unknown'); ?></td>
            <td><span><?php echo esc_html(isset($eligibility['eligible']) ? ($eligibility['eligible'] ? 'Eligible' : 'Ineligible') : 'Not recorded'); ?></span><span class="mmaf-line <?php echo $codes ? 'mmaf-warning-text' : 'mmaf-muted'; ?>"><?php echo esc_html(count($codes) . ' warnings'); ?></span><?php if ($codes || $flags): ?><details class="mmaf-row-details"><summary><?php echo $codes ? 'Warning details' : 'Quality details'; ?></summary><?php if ($codes): ?><ul><?php foreach ($codes as $code): ?><li><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $code))); ?></li><?php endforeach; ?></ul><?php endif; ?><?php if ($flags): ?><p>Quality flags: <?php echo esc_html(implode(', ', array_map(static fn($flag) => str_replace('_', ' ', (string) $flag), $flags))); ?></p><?php endif; ?></details><?php endif; ?></td>
            <td><details class="mmaf-row-details"><summary>Score breakdown</summary><dl class="mmaf-score-details">
                <?php foreach (['base_record_points' => 'Base record', 'finishes_points' => 'Finishes', 'age_adjustment_points' => 'Age adjustment', 'opponent_differential_points' => 'Opponent differential', 'loss_quality_penalty_points' => 'Loss quality'] as $key => $label): ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html(self::format_score_part($breakdown[$key] ?? null)); ?></dd><?php endforeach; ?>
                <?php if (!FormulaRegistry::uses_direct_scores($formula)): ?><dt>Performance raw</dt><dd><?php echo esc_html(self::format_score_part($breakdown['performance_raw_score'] ?? $breakdown['raw_score_before_confidence'] ?? null)); ?></dd><dt>Adjusted raw</dt><dd><?php echo esc_html(self::format_score_part($row['raw_score'] ?? null)); ?></dd><dt>Confidence (%)</dt><dd><?php echo esc_html(self::format_score_part($row['confidence_score'] ?? null)); ?></dd><?php endif; ?>
                <dt>Total score</dt><dd><strong><?php echo esc_html(self::format_score_part($row['total_score'])); ?></strong></dd></dl></details></td>
        </tr>
        <?php
    }

    private static function preview_url(int $run, string $board = 'overall', int $per_page = 25): string {
        return add_query_arg(['page' => self::PAGE_SLUG, 'mmaf_run' => $run, 'mmaf_board' => $board, 'per_page' => $per_page], admin_url('admin.php'));
    }

    private static function board_label(string $key): string {
        return ['overall' => 'Overall', 'male' => 'Men', 'female' => 'Women', 'under_30' => 'Under 30', 'over_30_to_34' => 'Age 30–34'][$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    private static function display_time(?string $value): string {
        $time = $value ? strtotime($value . ' UTC') : false;
        return $time === false ? 'Unknown' : wp_date('M j, Y · H:i', $time);
    }

    private static function display_count($value): string {
        return is_numeric($value) ? number_format_i18n((int) $value) : 'Unknown';
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
		<label for="mmaf-rankings-per-page"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-rankings-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function render_preview_pagination( int $total, int $paged, int $per_page, string $board, int $run_id ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args        = array(
			'page'     => self::PAGE_SLUG,
            'mmaf_run' => $run_id,
			'per_page' => $per_page,
		);

		if ( '' !== $board ) {
			$args['mmaf_board'] = $board;
		}

		echo '<div class="mmaf-pagination">';
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

		echo '</div>';
	}

	private static function render_activation_guidance( ?array $latest_run, int $draft_rows_count, ?array $summary ): void {
		$eligible = (int) ( $summary['eligible_fighters'] ?? 0 );
		$status   = $latest_run ? (string) $latest_run['status'] : '';
		$activatable = $latest_run && 'completed' === $status && $draft_rows_count > 0;

		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Activation guidance:', 'mma-future-data-engine' ) . '</strong> ';
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
            $id=\MMAF\DataEngine\Services\Operations\Calculation::create('ranking',0,get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=mmaf-operations&operation_id='.$id));exit;
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
