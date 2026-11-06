<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Subscriptions {
	private Repository $repo;
	private array $t;
	public function __construct() { $this->repo = new Repository(); $this->t = Schema::tables(); }

	public function follow( int $user_id, int $fighter_id ): array {
		global $wpdb;
		if ( ! $this->repo->public_fighter( $fighter_id ) ) { throw new \InvalidArgumentException( 'Fighter is not available for following.' ); }
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->t['subscriptions']} (user_id,fighter_id,status,created_at,updated_at) VALUES (%d,%d,'active',%s,%s) ON DUPLICATE KEY UPDATE status='active',updated_at=VALUES(updated_at)", $user_id, $fighter_id, $now, $now ) );
		$this->queue_known_bouts( $user_id, $fighter_id );
		return array( 'fighter_id' => $fighter_id, 'following' => true );
	}

	public function unfollow( int $user_id, int $fighter_id ): array {
		global $wpdb;
		$wpdb->update( $this->t['subscriptions'], array( 'status' => 'inactive', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'user_id' => $user_id, 'fighter_id' => $fighter_id ) );
		return array( 'fighter_id' => $fighter_id, 'following' => false );
	}

	public function listing( int $user_id ): array {
		global $wpdb;
		$f = \MMAF\DataEngine\Migrations\Schema::table_names()['fighters'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.fighter_id,f.display_name,f.wp_post_id FROM {$this->t['subscriptions']} s JOIN $f f ON f.id=s.fighter_id WHERE s.user_id=%d AND s.status='active' ORDER BY f.display_name", $user_id ), ARRAY_A ) ?: array();
		foreach ( $rows as &$row ) { $row['profile_url'] = $row['wp_post_id'] ? get_permalink( (int) $row['wp_post_id'] ) : null; unset( $row['wp_post_id'] ); }
		return $rows;
	}

	public function upcoming( int $user_id ): array {
		global $wpdb; $u=\MMAF\DataEngine\Services\Upcoming\Schema::tables(); $s=$this->t['subscriptions'];
		return $wpdb->get_results($wpdb->prepare("SELECT DISTINCT b.id bout_id,e.title event_title,e.event_date,e.event_datetime_utc,e.status event_status,b.status bout_status,b.fighter_a_name,b.fighter_b_name FROM $s s JOIN {$u['bouts']} b ON (b.fighter_a_id=s.fighter_id OR b.fighter_b_id=s.fighter_id) JOIN {$u['events']} e ON e.id=b.event_id WHERE s.user_id=%d AND s.status='active' AND e.hidden=0 AND e.scope_confirmed=1 AND e.status IN ('published','scheduled','postponed') AND e.event_date>=UTC_DATE() AND b.hidden=0 AND b.scope_status='confirmed' AND b.status NOT IN ('cancelled','hidden') ORDER BY e.event_date,e.event_datetime_utc IS NULL,e.event_datetime_utc LIMIT 100",$user_id),ARRAY_A)?:array();
	}

	private function queue_known_bouts( int $user_id, int $fighter_id ): void {
		global $wpdb;
		$u = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id event_row_id,e.title,e.promotion,e.event_date,e.event_datetime_utc,e.source_time_text,e.status event_status,e.hidden event_hidden,b.* FROM {$u['events']} e JOIN {$u['bouts']} b ON b.event_id=e.id WHERE e.hidden=0 AND e.scope_confirmed=1 AND e.status IN ('published','scheduled','postponed') AND e.event_date>=UTC_DATE() AND b.hidden=0 AND b.scope_status='confirmed' AND b.status NOT IN ('cancelled','hidden') AND (b.fighter_a_id=%d OR b.fighter_b_id=%d)",
			$fighter_id, $fighter_id
		), ARRAY_A ) ?: array();
		$detector = new EventChanges();
		foreach ( $rows as $row ) {
			$event = $row; $event['id'] = (int) $row['event_row_id']; $event['status']=$row['event_status']; $event['hidden']=$row['event_hidden'];
			$bout = $row; $bout['id'] = (int) $row['id'];
			$payload = $detector->payload( $event, $bout, 'new_bout' );
			$payload['change_note'] = 'This upcoming fight was already known when you followed the fighter.';
			$key = hash( 'sha256', 'new_bout|' . $bout['stable_id'] . '|' . $event['event_date'] . '|' . $bout['fighter_a_id'] . '|' . $bout['fighter_b_id'] );
			$this->repo->add_item( $user_id, (int) $bout['id'], 'new_bout', $key, $payload );
		}
	}
}
