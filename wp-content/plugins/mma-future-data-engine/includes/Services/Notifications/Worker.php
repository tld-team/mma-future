<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Worker {
	private array $t; private Repository $repo; private string $lock_token = '';
	public function __construct() { $this->t = Schema::tables(); $this->repo = new Repository(); }

	public function run( int $limit = 25 ): array {
		if ( ! Config::feature_enabled() || Config::sending_paused() ) { return array( 'status' => 'paused', 'processed' => 0 ); }
		if ( ! $this->acquire_lock() ) { return array( 'status' => 'locked', 'processed' => 0 ); }
		$processed = 0;
		try {
			$this->check_quota();
			$this->mark_stale_submissions();
			$processed += $this->fan_out_changes( $limit );
			$processed += $this->queue_due_reminders( $limit );
			$processed += $this->send_due_users( $limit );
			$this->cleanup();
			update_option( 'mmaf_notifications_worker_last_run', gmdate( 'Y-m-d H:i:s' ), false );
			return array( 'status' => 'ok', 'processed' => $processed );
		} finally { $this->release_lock(); }
	}

	private function fan_out_changes( int $limit ): int {
		global $wpdb; $count = 0;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['changes']} WHERE processed_at IS NULL ORDER BY id LIMIT %d", $limit ), ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			$p = json_decode( (string) $row['after_json'], true ) ?: array();
			// A change may name its own audience (a fighter linked to an already-announced fight); otherwise both corners' followers hear about it.
			$fighter_ids = array_filter( array_map( 'intval', (array) ( $p['notify_fighter_ids'] ?? array( $p['fighter_a_id'] ?? 0, $p['fighter_b_id'] ?? 0 ) ) ) );
			$users = array_unique( array_map( 'intval', wp_list_pluck( $this->repo->subscribers_for( $fighter_ids ), 'user_id' ) ) );
			foreach ( $users as $user_id ) {
				if ( ! $this->repo->notification_allowed( $user_id ) ) { continue; }
				$day = $this->local_day( $user_id );
				$due = $this->repo->user_has_daily_message( $user_id, $day ) ? $this->repo->next_local_nine_utc( $user_id, 1 ) : gmdate( 'Y-m-d H:i:s' );
				$added = 'new_bout' === $row['change_type']
					? $this->repo->add_new_bout_item( $user_id, (int) $row['bout_id'], $p, (int) $row['id'], $due )
					: $this->repo->add_item( $user_id, (int) $row['bout_id'], (string) $row['change_type'], (string) $row['fingerprint'], $p, (int) $row['id'], $due );
				if ( $added ) { ++$count; }
			}
			$wpdb->update( $this->t['changes'], array( 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) );
		}
		return $count;
	}

	private function queue_due_reminders( int $limit ): int {
		global $wpdb; $count = 0; $now = gmdate( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['reminders']} WHERE status='active' AND scheduled_at_utc IS NOT NULL AND scheduled_at_utc<=UTC_TIMESTAMP() ORDER BY scheduled_at_utc LIMIT %d", $limit * 4 ), ARRAY_A ) ?: array();
		$reminders = new Reminders(); $detector = new EventChanges();
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id']; $zone = Config::timezone( $user_id );
			$c = $reminders->bout_context( (int) $row['bout_id'] );
			// Every dead end leaves the queue. A row left 'active' with a past send time is re-read on every run, and enough of them push live reminders out of the LIMIT.
			if ( ! $c || ! get_userdata( $user_id ) || Reminders::started( $c, $zone ) ) { $this->close_reminder( (int) $row['id'], 'expired' ); continue; }
			if ( $c['event_hidden'] || $c['hidden'] || 'cancelled' === $c['event_status'] || in_array( $c['status'], array( 'cancelled','hidden' ), true ) ) { $this->close_reminder( (int) $row['id'], 'cancelled' ); continue; }
			if ( 'postponed' === $c['event_status'] ) { $this->close_reminder( (int) $row['id'], 'waiting_date' ); continue; }
			if ( ! $this->repo->notification_allowed( $user_id ) ) { continue; }
			$start = $c['event_datetime_utc'] ?: null;
			$expected = Reminders::schedule( (string) $c['event_date'], $start, $zone );
			if ( $expected !== $row['scheduled_at_utc'] ) {
				// The event or the reader's timezone changed after scheduling: correct the row and let it come due on its own.
				$wpdb->update( $this->t['reminders'], array( 'event_date_snapshot' => $c['event_date'], 'event_start_snapshot' => $start, 'scheduled_at_utc' => $expected, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
				if ( $expected > $now ) { continue; }
				$row['scheduled_at_utc'] = $expected;
			}
			$event = array( 'id'=>$c['event_id'],'title'=>$c['event_title'],'promotion'=>$c['promotion'],'event_date'=>$c['event_date'],'event_datetime_utc'=>$c['event_datetime_utc'],'source_time_text'=>$c['source_time_text'],'venue'=>$c['venue'],'location'=>$c['location'],'status'=>$c['event_status'],'hidden'=>$c['event_hidden'] );
			$c['id'] = $row['bout_id']; $p = $detector->payload( $event, $c, 'reminder' );
			// Keyed on the send time, not on updated_at: every calendar import touches the row, and each touch used to mint a new reminder.
			$key = hash( 'sha256', 'reminder|' . $row['bout_id'] . '|' . $row['scheduled_at_utc'] );
			if ( $this->repo->add_item( $user_id, (int) $row['bout_id'], 'reminder', $key, $p, null, $now ) ) { ++$count; }
		}
		return $count;
	}

	private function close_reminder( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( $this->t['reminders'], array( 'status' => $status, 'scheduled_at_utc' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
	}

	/** Reminders go first and outside the daily slot: an announcement already sent today must never delay or drop one. */
	private function send_due_users( int $limit ): int {
		return $this->send_reminders( $limit ) + $this->send_digests( $limit );
	}

	private function send_reminders( int $limit ): int {
		global $wpdb; $sent = 0;
		$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM (SELECT user_id,MIN(due_at) due FROM {$this->t['items']} WHERE status='pending' AND kind='reminder' AND due_at<=UTC_TIMESTAMP() GROUP BY user_id UNION SELECT user_id,MIN(next_attempt_at) due FROM {$this->t['messages']} WHERE status='retry' AND channel='reminder' AND next_attempt_at<=UTC_TIMESTAMP() GROUP BY user_id) q GROUP BY user_id ORDER BY MIN(due) LIMIT %d", $limit ) ) ?: array() );
		foreach ( $users as $user_id ) {
			if ( ! $this->repo->notification_allowed( $user_id ) ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND kind='reminder' AND status IN ('pending','reserved')", $user_id ) ); continue; }
			$retry = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$this->t['messages']} WHERE user_id=%d AND channel='reminder' AND status='retry' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY created_at,id LIMIT 1", $user_id ), ARRAY_A );
			$message_id = $retry ? (int) $retry['id'] : $this->reserve_message( $user_id, $this->local_day( $user_id ), 'reminder' );
			if ( ! $message_id ) { continue; }
			$items = $this->valid_items( $user_id, $this->reserve_items( $user_id, $message_id, 'reminder' ) ); if ( ! $items ) { $wpdb->update( $this->t['messages'], array( 'status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s') ), array( 'id'=>$message_id ) ); continue; }
			if ( $this->send_message( $message_id, $user_id, $items, 'reminder' ) ) { ++$sent; }
		}
		return $sent;
	}

	private function send_digests( int $limit ): int {
		global $wpdb; $sent = 0;
		$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM (SELECT user_id,MIN(due_at) due FROM {$this->t['items']} WHERE status='pending' AND kind<>'reminder' AND due_at<=UTC_TIMESTAMP() GROUP BY user_id UNION SELECT user_id,MIN(next_attempt_at) due FROM {$this->t['messages']} WHERE status='retry' AND channel='digest' AND next_attempt_at<=UTC_TIMESTAMP() GROUP BY user_id) q GROUP BY user_id ORDER BY MIN(due) LIMIT %d", $limit ) ) ?: array() );
		foreach ( $users as $user_id ) {
			if ( ! $this->repo->notification_allowed( $user_id ) ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status IN ('pending','reserved')", $user_id ) ); continue; }
			$day = $this->local_day( $user_id );
			$retry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['messages']} WHERE user_id=%d AND channel='digest' AND status='retry' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY created_at,id LIMIT 1", $user_id ), ARRAY_A );
			$today = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['messages']} WHERE user_id=%d AND digest_day=%s", $user_id, $day ), ARRAY_A );
			if ( $retry && $today && (int) $retry['id'] !== (int) $today['id'] && in_array( $today['status'], array( 'accepted','delivered','needs_review','sending' ), true ) ) {
				$wpdb->update( $this->t['messages'], array( 'next_attempt_at'=>$this->repo->next_local_nine_utc( $user_id, 1 ), 'updated_at'=>gmdate( 'Y-m-d H:i:s' ) ), array( 'id'=>(int) $retry['id'] ) );
				continue;
			}
			$existing = $retry ?: $today;
			if ( ! $existing && $this->repo->user_has_daily_message( $user_id, $day ) ) { $this->defer_digest_items( $user_id ); continue; }
			if ( $existing && in_array( $existing['status'], array( 'accepted','delivered','needs_review','sending','failed' ), true ) ) { $this->defer_digest_items( $user_id ); continue; }
			if ( $existing && 'cancelled' === $existing['status'] ) { $wpdb->update( $this->t['messages'], array( 'status'=>'reserved','next_attempt_at'=>gmdate('Y-m-d H:i:s'),'error_code'=>null,'error_message'=>null,'updated_at'=>gmdate('Y-m-d H:i:s') ), array( 'id'=>(int)$existing['id'] ) ); $existing['status']='reserved'; }
			$message_id = $existing ? (int) $existing['id'] : $this->reserve_message( $user_id, $day, 'digest' );
			if ( ! $message_id ) { continue; }
			$items = $this->valid_items( $user_id, $this->reserve_items( $user_id, $message_id, 'digest' ) ); if ( ! $items ) { $wpdb->update( $this->t['messages'], array( 'status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s') ), array( 'id'=>$message_id ) ); continue; }
			if ( $this->send_message( $message_id, $user_id, $items, 'digest' ) ) { ++$sent; }
		}
		return $sent;
	}

	/** Today's announcement slot is used: hold further announcements for tomorrow morning. Reminders are never held. */
	private function defer_digest_items( int $user_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET due_at=%s,updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND kind<>'reminder' AND status='pending' AND due_at<=UTC_TIMESTAMP()", $this->repo->next_local_nine_utc( $user_id, 1 ), $user_id ) );
	}

	/**
	 * Announcements keep one message per reader per local day, enforced by the unique (user_id, digest_day) key.
	 * Reminder rows leave digest_day NULL, so any number can exist on the same day.
	 */
	private function reserve_message( int $user_id, string $day, string $channel ): int {
		global $wpdb; $now = gmdate( 'Y-m-d H:i:s' );
		if ( 'reminder' === $channel ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->t['messages']} (user_id,local_day,digest_day,channel,timezone,status,next_attempt_at,created_at,updated_at) VALUES (%d,%s,NULL,'reminder',%s,'reserved',%s,%s,%s)", $user_id, $day, Config::timezone( $user_id ), $now, $now, $now ) );
			return (int) $wpdb->insert_id;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$this->t['messages']} (user_id,local_day,digest_day,channel,timezone,status,next_attempt_at,created_at,updated_at) VALUES (%d,%s,%s,'digest',%s,'reserved',%s,%s,%s)", $user_id, $day, $day, Config::timezone( $user_id ), $now, $now, $now ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['messages']} WHERE user_id=%d AND digest_day=%s", $user_id, $day ) );
	}

	private function reserve_items( int $user_id, int $message_id, string $channel ): array {
		global $wpdb;
		$kind = 'reminder' === $channel ? "kind='reminder'" : "kind<>'reminder'";
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='reserved',message_id=%d,updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND $kind AND status='pending' AND due_at<=UTC_TIMESTAMP()", $message_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Not filtered by kind: a batch reserved before reminders had their own channel may still hold both, and must go out whole.
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['items']} WHERE message_id=%d AND status='reserved' ORDER BY id", $message_id ), ARRAY_A ) ?: array();
			$wpdb->query( 'COMMIT' ); return $items;
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
	}

	private function send_message( int $message_id, int $user_id, array $items, string $channel ): bool {
		global $wpdb; $user = get_userdata( $user_id ); if ( ! $user ) { return false; }
		if(!$this->repo->notification_allowed($user_id)){$wpdb->query($wpdb->prepare("UPDATE {$this->t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE message_id=%d",$message_id));$wpdb->update($this->t['messages'],array('status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$message_id));return false;}
		$body = ( new EmailComposer() )->compose( $user_id, $items );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['messages']} SET status='sending',subject=%s,html_body=%s,text_body=%s,attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status IN ('reserved','retry')", $body['subject'], $body['html'], $body['text'], $message_id ) );
		if ( 1 !== (int) $claimed ) { return false; }
		$attempt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$this->t['messages']} WHERE id=%d", $message_id ) );
		$result = TransportFactory::make()->send( $user->user_email, $body['subject'], $body['html'], $body['text'], $body['headers'] );
		$wpdb->insert( $this->t['attempts'], array( 'message_id'=>$message_id,'attempt_no'=>$attempt,'status'=>$result['status'],'http_code'=>$result['http_code'] ?? null,'provider_id'=>$result['provider_id'] ?? null,'response_excerpt'=>substr( (string) ( $result['error'] ?? '' ), 0, 1000 ),'created_at'=>gmdate( 'Y-m-d H:i:s' ) ) );
		if ( 'accepted' === $result['status'] ) {
			$wpdb->update( $this->t['messages'], array( 'status'=>'accepted','provider_id'=>$result['provider_id'] ?? null,'accepted_at'=>gmdate( 'Y-m-d H:i:s' ),'updated_at'=>gmdate( 'Y-m-d H:i:s' ) ), array( 'id'=>$message_id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='sent',updated_at=UTC_TIMESTAMP() WHERE message_id=%d", $message_id ) );
			// The daily slot belongs to announcements only.
			if ( 'digest' === $channel ) { $zone = new \DateTimeZone( Config::timezone( $user_id ) ); $guard = ( new \DateTimeImmutable( 'tomorrow 00:00:00', $zone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); update_user_meta( $user_id, '_mmaf_notification_slot_guard_until', $guard ); }
			foreach ( $items as $item ) { if ( 'reminder' === $item['kind'] ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['reminders']} SET last_sent_date=event_date_snapshot,status='sent',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND bout_id=%d", $user_id, (int) $item['bout_id'] ) ); } }
			return true;
		}
		if ( 'retry' === $result['status'] && $attempt < 5 ) { $next = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) ( $result['retry_after'] ?? min( 3600, 60 * pow( 2, $attempt ) ) ) ) ); $wpdb->update( $this->t['messages'], array( 'status'=>'retry','next_attempt_at'=>$next,'error_code'=>$result['error'] ?? 'retry','error_message'=>$result['error'] ?? 'retry','updated_at'=>gmdate( 'Y-m-d H:i:s' ) ), array( 'id'=>$message_id ) ); return false; }
		$status = 'uncertain' === $result['status'] ? 'needs_review' : 'failed';
		$wpdb->update( $this->t['messages'], array( 'status'=>$status,'error_code'=>$result['error'] ?? $status,'error_message'=>$result['error'] ?? $status,'updated_at'=>gmdate( 'Y-m-d H:i:s' ) ), array( 'id'=>$message_id ) );
		return false;
	}

	private function valid_items( int $user_id, array $items ): array {
		global $wpdb; $valid=array(); $active=array_flip($this->repo->active_fighter_ids($user_id)); $reminders=new Reminders();
		foreach($items as $item){$p=json_decode((string)$item['payload_json'],true)?:array();$context=$reminders->bout_context((int)$item['bout_id']);$ok=(bool)$context;
			if('reminder'===$item['kind']){$r=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->t['reminders']} WHERE user_id=%d AND bout_id=%d",$user_id,(int)$item['bout_id']));$ok=$ok&&'active'===$r&&!$context['event_hidden']&&!$context['hidden']&&!in_array($context['event_status'],array('cancelled','postponed'),true)&&!Reminders::started($context,Config::timezone($user_id));}
			else{$matches=isset($active[(int)($p['fighter_a_id']??0)])||isset($active[(int)($p['fighter_b_id']??0)]);$ok=$ok&&$matches;if(!in_array($item['kind'],array('cancelled','postponed'),true)){$ok=$ok&&!$context['event_hidden']&&!$context['hidden']&&!in_array($context['event_status'],array('cancelled','draft'),true)&&!in_array($context['status'],array('cancelled','hidden'),true);}}
			if($ok){$valid[]=$item;}else{$wpdb->update($this->t['items'],array('status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>(int)$item['id']));}
		}return $valid;
	}

	private function check_quota(): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->t['messages']} WHERE status IN ('accepted','delivered') AND accepted_at>=DATE_FORMAT(UTC_DATE(),'%Y-%m-01')" );
		update_option( 'mmaf_notifications_monthly_sent', $count, false );
		if ( $count >= (int) floor( Config::monthly_quota() * .9 ) ) { update_option( 'mmaf_notifications_paused', true, false ); throw new \RuntimeException( 'Sports email quota safety threshold reached.' ); }
	}
	private function mark_stale_submissions(): void { global $wpdb; $wpdb->query( "UPDATE {$this->t['messages']} SET status='retry',next_attempt_at=UTC_TIMESTAMP(),error_code='stale_reservation',error_message='A reserved batch was safely recovered before provider submission.',updated_at=UTC_TIMESTAMP() WHERE status='reserved' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)" ); $wpdb->query( "UPDATE {$this->t['messages']} SET status='needs_review',error_code='stale_sending',error_message='The worker stopped after submission began; review provider logs before retrying.',updated_at=UTC_TIMESTAMP() WHERE status='sending' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)" ); }
	private function acquire_lock(): bool {
		$key='mmaf_notifications_worker_lock';$this->lock_token=wp_generate_uuid4();$value=wp_json_encode(array('token'=>$this->lock_token,'expires'=>time()+120));
		if(add_option($key,$value,'',false)){return true;}$current=json_decode((string)get_option($key,''),true);
		if((int)($current['expires']??0)<time()){delete_option($key);return add_option($key,$value,'',false);}return false;
	}
	private function release_lock(): void { $key='mmaf_notifications_worker_lock';$current=json_decode((string)get_option($key,''),true);if($this->lock_token&&hash_equals($this->lock_token,(string)($current['token']??''))){delete_option($key);} }
	private function local_day( int $user_id ): string { return ( new \DateTimeImmutable( 'now', new \DateTimeZone( Config::timezone( $user_id ) ) ) )->format( 'Y-m-d' ); }
	private function cleanup(): void { global $wpdb; $wpdb->query( "DELETE FROM {$this->t['tokens']} WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)" ); $wpdb->query( "UPDATE {$this->t['messages']} SET html_body=NULL,text_body=NULL WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)" ); $wpdb->query( "DELETE FROM {$this->t['attempts']} WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)" ); }
}
