<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuditLogService {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['audit_log'];
	}

	public function write( string $action, string $entity_type, int $entity_id, ?array $before, ?array $after, string $reason, int $user_id ): void {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table,
			array(
				'actor_user_id' => $user_id > 0 ? $user_id : null,
				'action'        => $action,
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'before_json'   => null === $before ? null : wp_json_encode( $before ),
				'after_json'    => null === $after ? null : wp_json_encode( $after ),
				'reason'        => $reason,
				'created_at'    => DateTime::mysql_now(),
			)
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not write audit log entry.', 'mma-future-data-engine' ) );
		}
	}
}
