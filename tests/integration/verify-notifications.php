<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\EventChanges;
use MMAF\DataEngine\Services\Notifications\Repository as NotificationRepository;
use MMAF\DataEngine\Services\Notifications\Schema as NotificationSchema;
use MMAF\DataEngine\Services\Notifications\Subscriptions;
use MMAF\DataEngine\Services\Notifications\Tokens;
use MMAF\DataEngine\Services\Notifications\Transport;
use MMAF\DataEngine\Services\Notifications\Worker;
use MMAF\DataEngine\Services\Upcoming\Schema as CalendarSchema;

function assert_notifications( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
$n = NotificationSchema::tables(); $c = CalendarSchema::tables(); $canonical = CanonicalSchema::table_names();
$old_pause = get_option( 'mmaf_notifications_paused', null ); $old_mailbox=get_option('mmaf_notification_fake_mailbox',null);update_option( 'mmaf_notifications_paused', false, false );update_option('mmaf_notification_fake_mailbox',array(),false);
$suffix = strtolower( wp_generate_password( 8, false, false ) ); $user_ids=array();$fighter_ids=array();$post_ids=array();$event_id=0;$bout_id=0;$transport_filter=null;

try {
	foreach ( array( 'one','two' ) as $name ) {
		$id=wp_create_user('notification_'.$name.'_'.$suffix,wp_generate_password(24,true,true),'notification-'.$name.'-'.$suffix.'@example.test');
		if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$user_ids[]=(int)$id;$user=get_userdata($id);$user->set_role('subscriber');
		update_user_meta($id,'_mmaf_email_verified','1');update_user_meta($id,'_mmaf_notification_timezone','Europe/Belgrade');
	}
	$privileged_id=wp_create_user('notification_editor_'.$suffix,wp_generate_password(24,true,true),'notification-editor-'.$suffix.'@example.test');if(is_wp_error($privileged_id)){throw new RuntimeException($privileged_id->get_error_message());}$user_ids[]=(int)$privileged_id;$privileged=get_userdata($privileged_id);$privileged->set_role('editor');$token_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['tokens']}");(new Auth())->request_link($privileged->user_email,array(),'127.0.0.2');assert_notifications($token_count===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['tokens']}"),'Passwordless login exposed a privileged account.');assert_notifications(home_url('/account/')===(new Auth())->safe_return('https://outside.example/steal'),'An external post-login redirect was accepted.');
	foreach(array('Fixture Alpha','Fixture Beta') as $name){$post_id=wp_insert_post(array('post_type'=>'mmaf_fighter','post_status'=>'publish','post_title'=>$name));$post_ids[]=(int)$post_id;$wpdb->insert($canonical['fighters'],array('wp_post_id'=>$post_id,'display_name'=>$name,'normalized_name'=>sanitize_title($name),'status'=>'active','rankability_status'=>'eligible','is_public'=>1,'is_rankable'=>1,'tracking_mode'=>'normal','created_at'=>gmdate('Y-m-d H:i:s')));$fighter_ids[]=(int)$wpdb->insert_id;}
	$date=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d');$now=gmdate('Y-m-d H:i:s');
	$wpdb->insert($c['events'],array('stable_id'=>'notification_event_'.$suffix,'source_type'=>'manual','title'=>'Notification Fixture','promotion'=>'Fixture MMA','event_date'=>$date,'status'=>'published','is_manual'=>1,'scope_confirmed'=>1,'hidden'=>0,'created_at'=>$now,'updated_at'=>$now));$event_id=(int)$wpdb->insert_id;
	$wpdb->insert($c['bouts'],array('event_id'=>$event_id,'stable_id'=>'notification_bout_'.$suffix,'bout_order'=>1,'status'=>'scheduled','scope_status'=>'confirmed','fighter_a_name'=>'Fixture Alpha','fighter_a_id'=>$fighter_ids[0],'fighter_b_name'=>'Fixture Beta','fighter_b_id'=>$fighter_ids[1],'is_manual'=>1,'hidden'=>0,'created_at'=>$now,'updated_at'=>$now));$bout_id=(int)$wpdb->insert_id;

	$subscriptions=new Subscriptions();$subscriptions->follow($user_ids[0],$fighter_ids[0]);$subscriptions->follow($user_ids[0],$fighter_ids[1]);$subscriptions->follow($user_ids[1],$fighter_ids[0]);
	assert_notifications(2===count($subscriptions->listing($user_ids[0])),'A user could not follow multiple fighters.');
	assert_notifications(1===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$n['items']} WHERE user_id=%d",$user_ids[0])),'Following both participants duplicated the same bout announcement.');

	$result=(new Worker())->run(25);assert_notifications('ok'===$result['status'],'The notification worker did not run.');
	assert_notifications(2===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['messages']} WHERE user_id IN (".implode(',',$user_ids).") AND status='accepted'"),'The first relevant state did not create one accepted message per user.');
	assert_notifications(2===count((array)get_option('mmaf_notification_fake_mailbox',array())),'Local delivery did not use the fake transport.');

	$before=array('id'=>$event_id,'title'=>'Notification Fixture','promotion'=>'Fixture MMA','event_date'=>$date,'event_datetime_utc'=>null,'source_time_text'=>null,'status'=>'published','hidden'=>0,'scope_confirmed'=>1,'bouts'=>array(array('id'=>$bout_id,'stable_id'=>'notification_bout_'.$suffix,'status'=>'scheduled','scope_status'=>'confirmed','hidden'=>0,'fighter_a_id'=>$fighter_ids[0],'fighter_a_name'=>'Fixture Alpha','fighter_b_id'=>$fighter_ids[1],'fighter_b_name'=>'Fixture Beta')));
	$after=$before;$after['event_date']=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
	assert_notifications(1===(new EventChanges())->record($before,$after,'test',null),'A significant date change was not recorded exactly once.');
	assert_notifications(0===(new EventChanges())->record($before,$after,'test',null),'The same significant change was not idempotent.');
	(new Worker())->run(25);
	assert_notifications(2===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['messages']} WHERE user_id IN (".implode(',',$user_ids).")"),'A later import created a second same-day message.');
	assert_notifications((int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['items']} WHERE kind='schedule_changed' AND status='pending' AND user_id IN (".implode(',',$user_ids).")")===2,'Later updates were not retained for the next daily slot.');

	$token_service=new Tokens();$raw=$token_service->issue('test_action',$user_ids[0],null,array('bout_id'=>$bout_id),60);
	assert_notifications(false===strpos((string)$wpdb->get_var($wpdb->prepare("SELECT token_hash FROM {$n['tokens']} WHERE purpose='test_action' AND user_id=%d ORDER BY id DESC LIMIT 1",$user_ids[0])),$raw),'A raw secure token was stored in the database.');
	assert_notifications(null!==$token_service->inspect('test_action',$raw),'A fresh token could not be inspected.');
	assert_notifications(null!==$token_service->consume('test_action',$raw)&&null===$token_service->consume('test_action',$raw),'A one-time token was reusable.');

	update_user_meta($user_ids[0],'_mmaf_notification_timezone','America/Los_Angeles');
	assert_notifications((new NotificationRepository())->user_has_daily_message($user_ids[0],(new DateTimeImmutable('now',new DateTimeZone('America/Los_Angeles')))->format('Y-m-d')),'Changing timezone reset the consumed daily slot.');

	$retry_user=wp_create_user('notification_retry_'.$suffix,wp_generate_password(24,true,true),'notification-retry-'.$suffix.'@example.test');
	if(is_wp_error($retry_user)){throw new RuntimeException($retry_user->get_error_message());}$user_ids[]=(int)$retry_user;$retry_user_data=get_userdata($retry_user);$retry_user_data->set_role('subscriber');update_user_meta($retry_user,'_mmaf_email_verified','1');update_user_meta($retry_user,'_mmaf_notification_timezone','Europe/Belgrade');
	$retry_transport=new class implements Transport { public int $calls=0; public function send(string $to,string $subject,string $html,string $text,array $headers=array()):array{$this->calls++;return 1===$this->calls?array('status'=>'retry','error'=>'rate_limited','retry_after'=>60,'http_code'=>429):array('status'=>'accepted','provider_id'=>'retry-fixture','http_code'=>202);} };
	$transport_filter=static function()use($retry_transport){return $retry_transport;};add_filter('mmaf_notifications_transport',$transport_filter);
	$subscriptions->follow((int)$retry_user,$fighter_ids[0]);(new Worker())->run(25);
	$retry_message=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$n['messages']} WHERE user_id=%d AND status='retry'",$retry_user));assert_notifications($retry_message>0,'A known rate limit was not reserved for retry.');
	$wpdb->update($n['messages'],array('local_day'=>(new DateTimeImmutable('yesterday'))->format('Y-m-d'),'next_attempt_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$retry_message));(new Worker())->run(25);
	assert_notifications('accepted'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['messages']} WHERE id=%d",$retry_message)),'A retry crossing the local-day boundary became stuck.');remove_filter('mmaf_notifications_transport',$transport_filter);$subscriptions->unfollow((int)$retry_user,$fighter_ids[0]);
	$revive_user=wp_create_user('notification_revive_'.$suffix,wp_generate_password(24,true,true),'notification-revive-'.$suffix.'@example.test');if(is_wp_error($revive_user)){throw new RuntimeException($revive_user->get_error_message());}$user_ids[]=(int)$revive_user;$revive_user_data=get_userdata($revive_user);$revive_user_data->set_role('subscriber');update_user_meta($revive_user,'_mmaf_email_verified','1');update_user_meta($revive_user,'_mmaf_notification_timezone','Europe/Belgrade');$subscriptions->follow((int)$revive_user,$fighter_ids[0]);$subscriptions->unfollow((int)$revive_user,$fighter_ids[0]);(new Worker())->run(25);assert_notifications('cancelled'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['items']} WHERE user_id=%d",$revive_user)),'An unfollowed pending announcement was not cancelled.');$subscriptions->follow((int)$revive_user,$fighter_ids[0]);(new Worker())->run(25);assert_notifications('accepted'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['messages']} WHERE user_id=%d",$revive_user)),'A never-sent announcement could not be restored after refollowing.');$subscriptions->unfollow((int)$revive_user,$fighter_ids[0]);

	$bulk_user_base=900000000;$bulk=array();for($i=0;$i<1000;$i++){$bulk[]="(".($bulk_user_base+$i).",".$fighter_ids[0].",'active','$now','$now')";}
	$wpdb->query("INSERT INTO {$n['subscriptions']} (user_id,fighter_id,status,created_at,updated_at) VALUES ".implode(',',$bulk));
	assert_notifications(1002===count((new NotificationRepository())->subscribers_for(array($fighter_ids[0]))),'The subscriber lookup did not handle a 1,000-user synthetic set.');
	$wpdb->query($wpdb->prepare("DELETE FROM {$n['subscriptions']} WHERE user_id>=%d",$bulk_user_base));

	add_option('mmaf_notifications_worker_lock',wp_json_encode(array('token'=>'fixture','expires'=>time()+60)),'',false);
	assert_notifications('locked'===(new Worker())->run(1)['status'],'Two workers were not prevented from running concurrently.');delete_option('mmaf_notifications_worker_lock');
	echo wp_json_encode(array('ok'=>true,'daily_messages'=>2,'synthetic_subscribers'=>1000,'retry_crossed_day'=>true,'worker'=>$result),JSON_PRETTY_PRINT).PHP_EOL;
} finally {
	if($transport_filter){remove_filter('mmaf_notifications_transport',$transport_filter);}
	delete_option('mmaf_notifications_worker_lock');if(null===$old_mailbox){delete_option('mmaf_notification_fake_mailbox');}else{update_option('mmaf_notification_fake_mailbox',$old_mailbox,false);}
	if($user_ids){$ids=implode(',',array_map('intval',$user_ids));$message_ids=$wpdb->get_col("SELECT id FROM {$n['messages']} WHERE user_id IN ($ids)")?:array();if($message_ids){$wpdb->query("DELETE FROM {$n['attempts']} WHERE message_id IN (".implode(',',array_map('intval',$message_ids)).")");}$wpdb->query("DELETE FROM {$n['tokens']} WHERE user_id IN ($ids)");$wpdb->query("DELETE FROM {$n['items']} WHERE user_id IN ($ids)");$wpdb->query("DELETE FROM {$n['messages']} WHERE user_id IN ($ids)");$wpdb->query("DELETE FROM {$n['reminders']} WHERE user_id IN ($ids)");$wpdb->query("DELETE FROM {$n['subscriptions']} WHERE user_id IN ($ids)");require_once ABSPATH.'wp-admin/includes/user.php';foreach($user_ids as $id){wp_delete_user($id);}}
	if($bout_id){$wpdb->delete($n['changes'],array('bout_id'=>$bout_id));$wpdb->delete($c['bouts'],array('id'=>$bout_id));}if($event_id){$wpdb->delete($c['events'],array('id'=>$event_id));}foreach($fighter_ids as $id){$wpdb->delete($canonical['fighters'],array('id'=>$id));}foreach($post_ids as $id){wp_delete_post($id,true);}
	if(null===$old_pause){delete_option('mmaf_notifications_paused');}else{update_option('mmaf_notifications_paused',$old_pause,false);}remove_filter('mmaf_notifications_feature_enabled','__return_true');
}
