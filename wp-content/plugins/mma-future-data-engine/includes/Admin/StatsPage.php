<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterStatsRepository;
use MMAF\DataEngine\Services\StatsRebuildService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatsPage {
	private const PAGE_SLUG = 'mmaf-stats';
	private const NONCE_ACTION = 'mmaf_rebuild_stats';
	private const NONCE_NAME = 'mmaf_stats_nonce';
	private const ACTION_REBUILD_ALL = 'rebuild_all';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice      = self::notice_from_request();
		$stats        = new FighterStatsRepository();
		$fighters     = new FighterRepository();
		$bouts        = new BoutRepository();
		$participants = new BoutParticipantRepository();
		$summary      = $stats->get_last_rebuild_summary();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Fighter Stats', 'mma-future-data-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="<?php echo esc_attr( 'error' === $notice['type'] ? 'notice notice-error' : 'notice notice-success' ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Current Stats State', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 820px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current stats rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $stats->count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total canonical fighters', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $fighters->count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total canonical bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $bouts->count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Participant rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $participants->count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Malformed bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $participants->malformed_bout_count() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last rebuild summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_summary( $summary ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 18px;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="mmaf_rebuild_stats">
				<input type="hidden" name="mmaf_action" value="<?php echo esc_attr( self::ACTION_REBUILD_ALL ); ?>">
				<?php submit_button( __( 'Rebuild All Fighter Stats', 'mma-future-data-engine' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_admin_post(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to rebuild fighter stats.', 'mma-future-data-engine' ) );
		}

		self::handle_post_and_redirect();
	}

	private static function handle_post_and_redirect(): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			self::redirect_with_notice( 'error', __( 'Stats rebuild was not run because the security token is missing. Reload the Stats page and try again.', 'mma-future-data-engine' ) );
		}

		$raw_nonce = wp_unslash( $_POST[ self::NONCE_NAME ] );
		$nonce     = is_scalar( $raw_nonce ) ? sanitize_text_field( (string) $raw_nonce ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::redirect_with_notice( 'error', __( 'Stats rebuild was not run because the security token is invalid or expired. Reload the Stats page and try again.', 'mma-future-data-engine' ) );
		}

		$action = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';
		if ( self::ACTION_REBUILD_ALL !== $action ) {
			self::redirect_with_notice( 'error', __( 'Stats rebuild was not run because the requested action is invalid.', 'mma-future-data-engine' ) );
		}

		try {
			$service = new StatsRebuildService();
			$summary = $service->rebuild_all( get_current_user_id(), 'Manual stats rebuild from admin' );

			self::redirect_with_notice(
				'success',
				sprintf(
					/* translators: 1: stats rows, 2: countable bouts, 3: warnings count. */
					__( 'Stats rebuild complete. Rows written: %1$d. Countable bouts: %2$d. Warnings: %3$d.', 'mma-future-data-engine' ),
					(int) $summary['stats_rows_written'],
					(int) $summary['countable_bouts'],
					(int) $summary['warnings_count']
				)
			);
		} catch ( \Throwable $error ) {
			self::redirect_with_notice( 'error', $error->getMessage() );
		}
	}

	private static function notice_from_request(): ?array {
		$raw_type    = isset( $_GET['mmaf_stats_notice'] ) ? wp_unslash( $_GET['mmaf_stats_notice'] ) : '';
		$raw_message = isset( $_GET['mmaf_stats_message'] ) ? wp_unslash( $_GET['mmaf_stats_message'] ) : '';
		$type        = is_scalar( $raw_type ) ? sanitize_key( (string) $raw_type ) : '';
		$message     = is_scalar( $raw_message ) ? sanitize_text_field( (string) $raw_message ) : '';

		if ( '' === $message || ! in_array( $type, array( 'success', 'error' ), true ) ) {
			return null;
		}

		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	private static function redirect_with_notice( string $type, string $message ): void {
		wp_safe_redirect(
			self::page_url(
				array(
					'mmaf_stats_notice'  => $type,
					'mmaf_stats_message' => $message,
				)
			)
		);
		exit;
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private static function format_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'rebuilt_at=%s fighters=%d rows=%d countable_bouts=%d skipped_bouts=%d malformed_bouts=%d participants=%d warnings=%d',
			(string) ( $summary['rebuilt_at'] ?? '' ),
			(int) ( $summary['fighters_total'] ?? 0 ),
			(int) ( $summary['stats_rows_written'] ?? 0 ),
			(int) ( $summary['countable_bouts'] ?? 0 ),
			(int) ( $summary['skipped_bouts'] ?? 0 ),
			(int) ( $summary['malformed_bouts'] ?? 0 ),
			(int) ( $summary['participants_processed'] ?? 0 ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}
}
