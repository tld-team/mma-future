<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\System\SystemCheckService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SystemCheckPage {
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$service = new SystemCheckService();
		$result  = null;
		$notice  = null;

		if ( isset( $_POST['mmaf_run_system_check'] ) ) {
			check_admin_referer( 'mmaf_run_system_check' );
			$result = $service->run();
			$notice = __( 'Backend system check completed.', 'mma-future-data-engine' );
		}

		if ( null === $result ) {
			$result = $service->latest();
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MMA Future System Check', 'mma-future-data-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<p><?php echo esc_html__( 'Run a read-only backend integrity check before handoff, deployment, or major follow-up work.', 'mma-future-data-engine' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mmaf_run_system_check' ); ?>
				<?php if ( function_exists( 'submit_button' ) ) : ?>
					<?php submit_button( __( 'Run System Check', 'mma-future-data-engine' ), 'primary', 'mmaf_run_system_check' ); ?>
				<?php else : ?>
					<p class="submit">
						<input type="submit" name="mmaf_run_system_check" class="button button-primary" value="<?php echo esc_attr__( 'Run System Check', 'mma-future-data-engine' ); ?>">
					</p>
				<?php endif; ?>
			</form>

			<?php if ( ! $result ) : ?>
				<p><?php echo esc_html__( 'No backend system check has been run yet.', 'mma-future-data-engine' ); ?></p>
			<?php else : ?>
				<?php self::render_summary( $result ); ?>
				<?php self::render_checks( $result ); ?>
				<?php self::render_report( $result ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_summary( array $result ): void {
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$status  = (string) ( $result['status'] ?? 'unknown' );
		?>
		<h2><?php echo esc_html__( 'Latest Summary', 'mma-future-data-engine' ); ?></h2>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Checked at', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) ( $result['checked_at'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<td><strong><?php echo esc_html( strtoupper( $status ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Critical failures', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) ( $summary['critical_failures'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) ( $summary['warnings'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Tables checked', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) ( $summary['tables_checked'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Integrity checks', 'mma-future-data-engine' ); ?></th>
					<td><?php echo esc_html( (string) ( $summary['integrity_checks'] ?? 0 ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_checks( array $result ): void {
		$checks = is_array( $result['checks'] ?? null ) ? $result['checks'] : array();
		?>
		<h2><?php echo esc_html__( 'Checks', 'mma-future-data-engine' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Check', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Value', 'mma-future-data-engine' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Expected', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><strong><?php echo esc_html( strtoupper( (string) ( $check['status'] ?? '' ) ) ); ?></strong></td>
						<td>
							<?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?><br>
							<code><?php echo esc_html( (string) ( $check['key'] ?? '' ) ); ?></code>
						</td>
						<td><?php echo esc_html( self::string_value( $check['value'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $check['expected'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_report( array $result ): void {
		?>
		<h2><?php echo esc_html__( 'Copyable Report', 'mma-future-data-engine' ); ?></h2>
		<textarea class="large-text code" rows="18" readonly><?php echo esc_textarea( wp_json_encode( $result, JSON_PRETTY_PRINT ) ); ?></textarea>
		<?php
	}

	private static function string_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}
}
