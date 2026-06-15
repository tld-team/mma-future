<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostImportAuditService {
	public function run(): array {
		$report = ( new DataQualityReportService() )->build_report();
		$this->store_summary( $report['system_summary'] );

		return $report;
	}

	private function store_summary( array $summary ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$now    = DateTime::mysql_now();

		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$tables['system_state']} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), autoload = VALUES(autoload), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'last_post_import_audit_summary',
				wp_json_encode( $summary ),
				'no',
				$now
			)
		);
	}
}
