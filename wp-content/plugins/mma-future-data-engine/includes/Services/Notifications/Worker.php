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
			$fighter_ids = array_filter( array( (int) ( $p['fighter_a_id'] ?? 0 ), (int) ( $p['fighter_b_id'] ?? 0 ) ) );
			$users = array_unique( array_map( 'intval', wp_list_pluck( $this->repo->subscribers_for( $fighter_ids ), 'user_id' ) ) );
			foreach ( $users as $user_id ) {
				if ( ! $this->repo->notification_allowed( $user_id ) ) { continue; }
				$day = $this->local_day( $user_id );
				$due = $this->repo->user_has_daily_message( $user_id, $day ) ? $this->repo->next_local_nine_utc( $user_id, 1 ) : gmdate( 'Y-m-d H:i:s' );
				$key = 'new_bout' === $row['change_type'] ? hash( 'sha256', 'new_bout|' . $p['bout_stable_id'] . '|' . $p['event_date'] . '|' . $p['fighter_a_id'] . '|' . $p['fighter_b_id'] ) : (string) $row['fingerprint'];
				if ( $this->repo->add_item( $user_id, (int) $row['bout_id'], (string) $row['change_type'], $key, $p, (int) $row['id'], $due ) ) { ++$count; }
			}
			$wpdb->update( $this->t['changes'], array( 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) );
		}
		return $count;
	}

	private function queue_due_reminders( int $limit ): int {
		global $wpdb; $count = 0;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['reminders']} WHERE status='active' AND scheduled_at_utc IS NOT NULL AND scheduled_at_utc<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR) ORDER BY scheduled_at_utc LIMIT %d", $limit * 4 ), ARRAY_A ) ?: array();
		$reminders = new Reminders(); $detector = new EventChanges();
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id']; if ( ! $this->repo->notification_allowed( $user_id ) ) { continue; }
			$zone = new \DateTimeZone( Config::timezone( $user_id ) ); $local_today = ( new \DateTimeImmutable( 'now', $zone ) )->format( 'Y-m-d' ); $scheduled_day = ( new \DateTimeImmutable( $row['scheduled_at_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d' );
			$has_announcement = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['items']} WHERE user_id=%d AND status='pending' AND kind<>'reminder' AND due_at<=UTC_TIMESTAMP() LIMIT 1", $user_id ) );
			if ( $row['scheduled_at_utc'] > gmdate( 'Y-m-d H:i:s' ) && ! ( $scheduled_day === $local_today && $has_announcement ) ) { continue; }
			$c = $reminders->bout_context( (int) $row['bout_id'] ); if ( ! $c || $c['event_date'] <= $local_today || $c['event_date'] !== $row['event_date_snapshot'] || in_array($c['event_status'],array('cancelled','postponed'),true) || $c['event_hidden'] || 'cancelled' === $c['status'] || $c['hidden'] ) { continue; }
			$event = array( 'id'=>$c['event_id'],'title'=>$c['event_title'],'promotion'=>$c['promotion'],'event_date'=>$c['event_date'],'event_datetime_utc'=>$c['event_datetime_utc'],'source_time_text'=>$c['source_time_text'],'status'=>$c['event_status'],'hidden'=>$c['event_hidden'] );
			$c['id'] = $row['bout_id']; $p = $detector->payload( $event, $c, 'reminder' );
			$key = hash( 'sha256', 'reminder|' . $row['bout_id'] . '|' . $row['event_date_snapshot'] . '|' . $row['updated_at'] );
			if ( $this->repo->add_item( $user_id, (int) $row['bout_id'], 'reminder', $key, $p, null, gmdate( 'Y-m-d H:i:s' ) ) ) { ++$count; }
		}
		return $count;
	}

	private function send_due_users( int $limit ): int {
		global $wpdb; $sent = 0;
		$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM (SELECT user_id,MIN(due_at) due FROM {$this->t['items']} WHERE status='pending' AND due_at<=UTC_TIMESTAMP() GROUP BY user_id UNION SELECT user_id,MIN(next_attempt_at) due FROM {$this->t['messages']} WHERE status='retry' AND next_attempt_at<=UTC_TIMESTAMP() GROUP BY user_id) q GROUP BY user_id ORDER BY MIN(due) LIMIT %d", $limit ) ) ?: array() );
		foreach ( $users as $user_id ) {
			if ( ! $this->repo->notification_allowed( $user_id ) ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status IN ('pending','reserved')", $user_id ) ); continue; }
			$day = $this->local_day( $user_id );
			$retry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['messages']} WHERE user_id=%d AND status='retry' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY created_at,id LIMIT 1", $user_id ), ARRAY_A );
			$today = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['messages']} WHERE user_id=%d AND local_day=%s", $user_id, $day ), ARRAY_A );
			if ( $retry && $today && (int) $retry['id'] !== (int) $today['id'] && in_array( $today['status'], array( 'accepted','delivered','needs_review','sending' ), true ) ) {
				$wpdb->update( $this->t['messages'], array( 'next_attempt_at'=>$this->repo->next_local_nine_utc( $user_id, 1 ), 'updated_at'=>gmdate( 'Y-m-d H:i:s' ) ), array( 'id'=>(int) $retry['id'] ) );
				continue;
			}
			$existing = $retry ?: $today;
			if ( ! $existing && $this->repo->user_has_daily_message( $user_id, $day ) ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET due_at=%s,updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status='pending' AND due_at<=UTC_TIMESTAMP()", $this->repo->next_local_nine_utc( $user_id, 1 ), $user_id ) ); continue; }
			if ( $existing && in_array( $existing['status'], array( 'accepted','delivered','needs_review','sending','failed' ), true ) ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET due_at=%s,updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status='pending' AND due_at<=UTC_TIMESTAMP()", $this->repo->next_local_nine_utc( $user_id, 1 ), $user_id ) ); continue; }
			if ( $existing && 'cancelled' === $existing['status'] ) { $wpdb->update( $this->t['messages'], array( 'status'=>'reserved','next_attempt_at'=>gmdate('Y-m-d H:i:s'),'error_code'=>null,'error_message'=>null,'updated_at'=>gmdate('Y-m-d H:i:s') ), array( 'id'=>(int)$existing['id'] ) ); $existing['status']='reserved'; }
			$message_id = $existing ? (int) $existing['id'] : $this->reserve_message( $user_id, $day );
			if ( ! $message_id ) { continue; }
			$items = $this->valid_items( $user_id, $this->reserve_items( $user_id, $message_id ) ); if ( ! $items ) { $wpdb->update( $this->t['messages'], array( 'status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s') ), array( 'id'=>$message_id ) ); continue; }
			if ( $this->send_message( $message_id, $user_id, $items ) ) { ++$sent; }
		}
		return $sent;
	}

	private function reserve_message( int $user_id, string $day ): int {
		global $wpdb; $now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$this->t['messages']} (user_id,local_day,timezone,status,next_attempt_at,created_at,updated_at) VALUES (%d,%s,%s,'reserved',%s,%s,%s)", $user_id, $day, Config::timezone( $user_id ), $now, $now, $now ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['messages']} WHERE user_id=%d AND local_day=%s", $user_id, $day ) );
	}

	private function reserve_items( int $user_id, int $message_id ): array {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='reserved',message_id=%d,updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status='pending' AND due_at<=UTC_TIMESTAMP()", $message_id, $user_id ) );
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['items']} WHERE message_id=%d AND status='reserved' ORDER BY id", $message_id ), ARRAY_A ) ?: array();
			$wpdb->query( 'COMMIT' ); return $items;
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
	}

	private function send_message( int $message_id, int $user_id, array $items ): bool {
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
			$zone = new \DateTimeZone( Config::timezone( $user_id ) ); $guard = ( new \DateTimeImmutable( 'tomorrow 00:00:00', $zone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); update_user_meta( $user_id, '_mmaf_notification_slot_guard_until', $guard );
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
			if('reminder'===$item['kind']){$r=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->t['reminders']} WHERE user_id=%d AND bout_id=%d",$user_id,(int)$item['bout_id']));$ok=$ok&&'active'===$r&&!$context['event_hidden']&&!$context['hidden']&&!in_array($context['event_status'],array('cancelled','postponed'),true);}
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
