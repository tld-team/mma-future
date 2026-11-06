<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Repository {
	private array $t;
	public function __construct() { $this->t = Schema::tables(); }
	public function tables(): array { return $this->t; }

	public function active_fighter_ids( int $user_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT fighter_id FROM {$this->t['subscriptions']} WHERE user_id=%d AND status='active' ORDER BY id", $user_id ) ) ?: array() );
	}

	public function subscribers_for( array $fighter_ids ): array {
		global $wpdb;
		$fighter_ids = array_values( array_unique( array_filter( array_map( 'absint', $fighter_ids ) ) ) );
		if ( ! $fighter_ids ) { return array(); }
		$in = implode( ',', $fighter_ids );
		return $wpdb->get_results( "SELECT user_id,fighter_id FROM {$this->t['subscriptions']} WHERE status='active' AND fighter_id IN ($in)", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function add_item( int $user_id, int $bout_id, string $kind, string $dedupe_key, array $payload, ?int $change_id = null, ?string $due_at = null ): bool {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->t['items']} (user_id,change_id,bout_id,kind,dedupe_key,payload_json,due_at,status,created_at,updated_at) VALUES (%d,%s,%d,%s,%s,%s,%s,'pending',%s,%s) ON DUPLICATE KEY UPDATE change_id=IF(status='cancelled',VALUES(change_id),change_id),bout_id=IF(status='cancelled',VALUES(bout_id),bout_id),kind=IF(status='cancelled',VALUES(kind),kind),payload_json=IF(status='cancelled',VALUES(payload_json),payload_json),due_at=IF(status='cancelled',VALUES(due_at),due_at),message_id=IF(status='cancelled',NULL,message_id),status=IF(status='cancelled','pending',status),updated_at=IF(status='cancelled',VALUES(updated_at),updated_at)",
			$user_id, $change_id ?: null, $bout_id, $kind, $dedupe_key, wp_json_encode( $payload ), $due_at ?: $now, $now, $now
		);
		return (int) $wpdb->query( $sql ) > 0;
	}

	public function user_has_daily_message( int $user_id, string $local_day ): bool {
		global $wpdb;
		$guard = (string) get_user_meta( $user_id, '_mmaf_notification_slot_guard_until', true );
		if ( $guard && strtotime( $guard . ' UTC' ) > time() ) { return true; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['messages']} WHERE user_id=%d AND local_day=%s AND status IN ('reserved','sending','accepted','delivered','needs_review','retry') LIMIT 1", $user_id, $local_day ) );
	}

	public function next_local_nine_utc( int $user_id, int $days = 1 ): string {
		$zone = new \DateTimeZone( Config::timezone( $user_id ) );
		$local = new \DateTimeImmutable( 'now', $zone );
		$target = $local->modify( '+' . max( 0, $days ) . ' day' )->setTime( 9, 0 );
		if ( 0 === $days && $target <= $local ) { $target = $target->modify( '+1 day' ); }
		return $target->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public function public_fighter( int $fighter_id ): ?array {
		global $wpdb;
		$table = \MMAF\DataEngine\Migrations\Schema::table_names()['fighters'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,display_name,wp_post_id,is_public,tracking_mode FROM $table WHERE id=%d", $fighter_id ), ARRAY_A );
		if ( ! $row || empty( $row['is_public'] ) || 'normal' !== $row['tracking_mode'] || empty($row['wp_post_id']) || 'publish'!==get_post_status((int)$row['wp_post_id']) ) { return null; }
		$row['profile_url'] = get_permalink( (int) $row['wp_post_id'] );
		return $row;
	}

	public function notification_allowed( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && Config::feature_enabled() && Config::user_enabled( $user_id ) && Config::email_verified( $user_id ) && ! get_user_meta( $user_id, '_mmaf_notifications_unsubscribed_at', true );
	}
}
