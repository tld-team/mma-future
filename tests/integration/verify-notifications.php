<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\EmailComposer;
use MMAF\DataEngine\Services\Notifications\EventChanges;
use MMAF\DataEngine\Services\Notifications\Reminders;
use MMAF\DataEngine\Services\Notifications\Repository as NotificationRepository;
use MMAF\DataEngine\Services\Notifications\Schema as NotificationSchema;
use MMAF\DataEngine\Services\Notifications\Subscriptions;
use MMAF\DataEngine\Services\Notifications\Tokens;
use MMAF\DataEngine\Services\Notifications\Transport;
use MMAF\DataEngine\Services\Notifications\Worker;
use MMAF\DataEngine\Services\Upcoming\Repository as CalendarRepository;
use MMAF\DataEngine\Services\Upcoming\Schema as CalendarSchema;

function assert_notifications( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
$n = NotificationSchema::tables(); $c = CalendarSchema::tables(); $canonical = CanonicalSchema::table_names();
$old_pause = get_option( 'mmaf_notifications_paused', null ); $old_mailbox=get_option('mmaf_notification_fake_mailbox',null);update_option( 'mmaf_notifications_paused', false, false );update_option('mmaf_notification_fake_mailbox',array(),false);
$suffix = strtolower( wp_generate_password( 8, false, false ) ); $user_ids=array();$fighter_ids=array();$post_ids=array();$event_id=0;$bout_id=0;$transport_filter=null;$extra_events=array();$extra_bouts=array();

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
	$first_mail=null;foreach((array)get_option('mmaf_notification_fake_mailbox',array()) as $mail){if(str_contains((string)$mail['to'],'notification-one-')){$first_mail=$mail;}}
	assert_notifications(null!==$first_mail&&'New fight announced: Fixture Alpha vs Fixture Beta'===$first_mail['subject'],'A single announced fight is not named in the subject.');
	assert_notifications(str_contains($first_mail['html'],'Notification Fixture')&&str_contains($first_mail['html'],'Fixture MMA')&&str_contains($first_mail['html'],'You follow'),'The email does not summarise the event and the followed fighter.');
	assert_notifications(str_contains($first_mail['html'],'purpose=reminder')&&str_contains($first_mail['html'],'Remind me the day before'),'The announcement has no reminder call to action.');
	assert_notifications(str_contains($first_mail['html'],'new on MMA Future')&&str_contains($first_mail['html'],esc_url(home_url('/'))),'The announcement has no call to action back to the site.');
	assert_notifications(str_contains($first_mail['html'],'purpose=unsubscribe')&&str_contains($first_mail['text'],'Unsubscribe: ')&&2===count($first_mail['headers']),'The email lost its unsubscribe link or one-click headers.');
	$preview_items=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$n['items']} WHERE user_id=%d",$user_ids[0]),ARRAY_A);$tokens_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['tokens']}");$preview=(new EmailComposer())->compose($user_ids[0],$preview_items,true);
	assert_notifications($tokens_before===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$n['tokens']}")&&!str_contains($preview['html'],'token=')&&array()===$preview['headers'],'An admin preview issued secure action links.');
	$reminder_item=$preview_items[0];$reminder_payload=json_decode((string)$reminder_item['payload_json'],true);$reminder_payload['kind']='reminder';$reminder_item['kind']='reminder';$reminder_item['payload_json']=wp_json_encode($reminder_payload);$reminder_mail=(new EmailComposer())->compose($user_ids[0],array($reminder_item),true);
	assert_notifications('Fight reminder: Fixture Alpha vs Fixture Beta'===$reminder_mail['subject']&&!str_contains($reminder_mail['html'],'Remind me'),'A reminder email offers another reminder.');

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
	$yesterday=(new DateTimeImmutable('yesterday'))->format('Y-m-d');$wpdb->update($n['messages'],array('local_day'=>$yesterday,'digest_day'=>$yesterday,'next_attempt_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$retry_message));(new Worker())->run(25);
	assert_notifications('accepted'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['messages']} WHERE id=%d",$retry_message)),'A retry crossing the local-day boundary became stuck.');remove_filter('mmaf_notifications_transport',$transport_filter);$subscriptions->unfollow((int)$retry_user,$fighter_ids[0]);
	$revive_user=wp_create_user('notification_revive_'.$suffix,wp_generate_password(24,true,true),'notification-revive-'.$suffix.'@example.test');if(is_wp_error($revive_user)){throw new RuntimeException($revive_user->get_error_message());}$user_ids[]=(int)$revive_user;$revive_user_data=get_userdata($revive_user);$revive_user_data->set_role('subscriber');update_user_meta($revive_user,'_mmaf_email_verified','1');update_user_meta($revive_user,'_mmaf_notification_timezone','Europe/Belgrade');$subscriptions->follow((int)$revive_user,$fighter_ids[0]);$subscriptions->unfollow((int)$revive_user,$fighter_ids[0]);(new Worker())->run(25);assert_notifications('cancelled'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['items']} WHERE user_id=%d",$revive_user)),'An unfollowed pending announcement was not cancelled.');$subscriptions->follow((int)$revive_user,$fighter_ids[0]);(new Worker())->run(25);assert_notifications('accepted'===$wpdb->get_var($wpdb->prepare("SELECT status FROM {$n['messages']} WHERE user_id=%d",$revive_user)),'A never-sent announcement could not be restored after refollowing.');$subscriptions->unfollow((int)$revive_user,$fighter_ids[0]);

	// --- Reminders are timed from the event's start and delivered outside the daily announcement slot.
	$make_user = static function ( string $label ) use ( &$user_ids, $suffix ): int {
		$id = wp_create_user( 'notification_' . $label . '_' . $suffix, wp_generate_password( 24, true, true ), 'notification-' . $label . '-' . $suffix . '@example.test' );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$user_ids[] = (int) $id; get_userdata( $id )->set_role( 'subscriber' );
		update_user_meta( $id, '_mmaf_email_verified', '1' ); update_user_meta( $id, '_mmaf_notification_timezone', 'Europe/Belgrade' );
		return (int) $id;
	};
	// $start_utc null makes a date-only event five days out. Corners are array( fighter id or null, name ).
	$make_fight = static function ( string $label, ?string $start_utc, array $a, array $b ) use ( $wpdb, $c, $suffix, &$extra_events, &$extra_bouts ): array {
		$stamp = gmdate( 'Y-m-d H:i:s' ); $day = $start_utc ? substr( $start_utc, 0, 10 ) : gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS );
		$wpdb->insert( $c['events'], array( 'stable_id' => "notification_{$label}_event_$suffix", 'source_type' => 'manual', 'title' => 'Fixture ' . $label, 'promotion' => 'Fixture MMA', 'event_date' => $day, 'event_datetime_utc' => $start_utc, 'status' => 'published', 'is_manual' => 1, 'scope_confirmed' => 1, 'hidden' => 0, 'created_at' => $stamp, 'updated_at' => $stamp ) );
		$event = (int) $wpdb->insert_id; if ( ! $event ) { throw new RuntimeException( 'Fixture event failed: ' . $wpdb->last_error ); } $extra_events[] = $event;
		$wpdb->insert( $c['bouts'], array( 'event_id' => $event, 'stable_id' => "notification_{$label}_bout_$suffix", 'bout_order' => 1, 'status' => 'scheduled', 'scope_status' => 'confirmed', 'fighter_a_name' => $a[1], 'fighter_a_id' => $a[0], 'fighter_b_name' => $b[1], 'fighter_b_id' => $b[0], 'is_manual' => 1, 'hidden' => 0, 'created_at' => $stamp, 'updated_at' => $stamp ) );
		$bout = (int) $wpdb->insert_id; if ( ! $bout ) { throw new RuntimeException( 'Fixture bout failed: ' . $wpdb->last_error ); } $extra_bouts[] = $bout;
		return array( $event, $bout );
	};
	$move_start = static function ( int $event, string $start_utc ) use ( $wpdb, $c ): void {
		$wpdb->update( $c['events'], array( 'event_datetime_utc' => $start_utc, 'event_date' => substr( $start_utc, 0, 10 ) ), array( 'id' => $event ) );
		( new Reminders() )->reschedule_for_event( $event );
	};
	$in    = static fn( float $hours ): string => gmdate( 'Y-m-d H:i:s', time() + (int) round( $hours * HOUR_IN_SECONDS ) );
	$count = static fn( string $sql ): int => (int) $wpdb->get_var( $sql );
	$alpha = array( $fighter_ids[0], 'Fixture Alpha' );
	$reminders = new Reminders(); $calendar = new CalendarRepository(); $reader = $make_user( 'reminder' );

	assert_notifications( '2030-06-14 07:00:00' === Reminders::schedule( '2030-06-15', null, 'Europe/Belgrade' ), 'A date-only event is not reminded at 09:00 local on the day before.' );
	$start = $in( 25 ); list( $timed_event, $timed_bout ) = $make_fight( 'timed', $start, $alpha, array( null, 'Fixture Gamma' ) );
	$enabled = $reminders->enable( $reader, $timed_bout );
	assert_notifications( gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) - DAY_IN_SECONDS ) === $enabled['scheduled_at_utc'], 'A reminder is not scheduled 24 hours before a known start.' );
	list( , $soon_bout ) = $make_fight( 'soon', $in( 23 ), $alpha, array( null, 'Fixture Delta' ) );
	$refused = false; try { $reminders->enable( $reader, $soon_bout ); } catch ( InvalidArgumentException $e ) { $refused = str_contains( $e->getMessage(), 'too close' ); }
	assert_notifications( $refused, 'A reminder was promised less than 24 hours before the fight.' );

	$subscriptions->follow( $reader, $fighter_ids[0] ); ( new Worker() )->run( 25 );
	assert_notifications( 1 === $count( "SELECT COUNT(*) FROM {$n['messages']} WHERE user_id=$reader AND channel='digest' AND status='accepted'" ), 'The follow announcement was not sent as the daily digest.' );
	// The fight moves closer: its reminder is now due on a day whose announcement slot is already used, and on the source's own event date.
	$move_start( $timed_event, $in( 23.5 ) ); ( new Worker() )->run( 25 );
	assert_notifications( 1 === $count( "SELECT COUNT(*) FROM {$n['messages']} WHERE user_id=$reader AND channel='reminder' AND digest_day IS NULL AND status='accepted'" ), 'A due reminder was held back by the announcement already sent that day.' );
	assert_notifications( 'sent' === $wpdb->get_var( "SELECT status FROM {$n['reminders']} WHERE user_id=$reader AND bout_id=$timed_bout" ), 'A delivered reminder was not marked sent.' );
	$mailbox = (array) get_option( 'mmaf_notification_fake_mailbox', array() ); $last = end( $mailbox );
	assert_notifications( 'Fight reminder: Fixture Alpha vs Fixture Gamma' === $last['subject'] && ! str_contains( $last['html'], 'Remind me' ), 'The reminder email is not a reminder.' );

	list( $second_event, $second_bout ) = $make_fight( 'second', $in( 26 ), $alpha, array( null, 'Fixture Epsilon' ) );
	$reminders->enable( $reader, $second_bout ); $move_start( $second_event, $in( 23.7 ) ); ( new Worker() )->run( 25 );
	assert_notifications( 2 === $count( "SELECT COUNT(*) FROM {$n['messages']} WHERE user_id=$reader AND channel='reminder' AND status='accepted'" ), 'A second reminder on the same day was not sent.' );

	list( $later_event, $later_bout ) = $make_fight( 'later', null, $alpha, array( null, 'Fixture Zeta' ) );
	assert_notifications( 1 === ( new EventChanges() )->record( null, $calendar->find_event( $later_event ), 'test', null ), 'A new fight was not recorded.' );
	( new Worker() )->run( 25 );
	assert_notifications( 1 === $count( "SELECT COUNT(*) FROM {$n['messages']} WHERE user_id=$reader AND channel='digest'" ) && 1 === $count( "SELECT COUNT(*) FROM {$n['items']} WHERE user_id=$reader AND bout_id=$later_bout AND kind='new_bout' AND status='pending'" ), 'Reminders opened a second announcement slot on the same day.' );

	list( $shift_event, $shift_bout ) = $make_fight( 'shift', $in( 60 ), $alpha, array( null, 'Fixture Theta' ) );
	$reminders->enable( $reader, $shift_bout ); $shifted = $in( 61 ); $move_start( $shift_event, $shifted );
	assert_notifications( gmdate( 'Y-m-d H:i:s', strtotime( $shifted . ' UTC' ) - DAY_IN_SECONDS ) === $wpdb->get_var( "SELECT scheduled_at_utc FROM {$n['reminders']} WHERE user_id=$reader AND bout_id=$shift_bout AND status='active'" ), 'A changed start time did not move the reminder.' );

	list( , $past_bout ) = $make_fight( 'past', $in( -1 ), $alpha, array( null, 'Fixture Eta' ) );
	$wpdb->insert( $n['reminders'], array( 'user_id' => $reader, 'bout_id' => $past_bout, 'event_date_snapshot' => gmdate( 'Y-m-d' ), 'scheduled_at_utc' => $in( -25 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	( new Worker() )->run( 25 );
	assert_notifications( 'expired' === $wpdb->get_var( "SELECT status FROM {$n['reminders']} WHERE user_id=$reader AND bout_id=$past_bout" ), 'A reminder for a fight that already started stayed in the queue.' );

	$indexes = wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$n['messages']}", ARRAY_A ), 'Non_unique', 'Key_name' );
	assert_notifications( isset( $indexes['user_digest_day'] ) && 0 === (int) $indexes['user_digest_day'] && ! isset( $indexes['user_day'] ), 'The message slot index is not the digest-only unique key.' );

	// --- A fighter linked to a fight after it was first announced: only that fighter's followers hear about it, once.
	$both = $make_user( 'both' ); $beta_only = $make_user( 'betaonly' );
	list( $link_event, $link_bout ) = $make_fight( 'link', null, $alpha, array( null, 'Fixture Beta' ) );
	$subscriptions->follow( $both, $fighter_ids[0] ); $subscriptions->follow( $both, $fighter_ids[1] ); $subscriptions->follow( $beta_only, $fighter_ids[1] );
	$before_link = $calendar->find_event( $link_event );
	$wpdb->update( $c['bouts'], array( 'fighter_b_id' => $fighter_ids[1] ), array( 'id' => $link_bout ) );
	$after_link = $calendar->find_event( $link_event );
	assert_notifications( 1 === ( new EventChanges() )->record( $before_link, $after_link, 'test', null ), 'Linking a fighter to an announced fight was not recorded exactly once.' );
	$link_change = $wpdb->get_row( "SELECT change_type,after_json FROM {$n['changes']} WHERE bout_id=$link_bout ORDER BY id DESC LIMIT 1", ARRAY_A );
	$link_payload = json_decode( (string) $link_change['after_json'], true );
	assert_notifications( 'new_bout' === $link_change['change_type'] && array( $fighter_ids[1] ) === array_map( 'intval', (array) ( $link_payload['notify_fighter_ids'] ?? array() ) ), 'A newly linked fighter was reported as an opponent change.' );
	( new Worker() )->run( 25 );
	assert_notifications( 1 === $count( "SELECT COUNT(*) FROM {$n['items']} WHERE user_id=$beta_only AND bout_id=$link_bout AND kind='new_bout'" ), 'Followers of the newly linked fighter were not told about the fight.' );
	assert_notifications( 1 === $count( "SELECT COUNT(*) FROM {$n['items']} WHERE user_id=$both AND bout_id=$link_bout" ), 'A reader who was already told about the fight was told again.' );
	$swapped = $after_link; $corner = $swapped['bouts'][0];
	foreach ( array( 'name', 'id' ) as $field ) { $swapped['bouts'][0][ "fighter_a_$field" ] = $corner[ "fighter_b_$field" ]; $swapped['bouts'][0][ "fighter_b_$field" ] = $corner[ "fighter_a_$field" ]; }
	assert_notifications( 0 === ( new EventChanges() )->record( $after_link, $swapped, 'test', null ), 'Listing the same two fighters in the other order was reported as a change.' );
	$renamed = $after_link; $renamed['bouts'][0]['fighter_b_name'] = 'Fixture Omega'; $renamed['bouts'][0]['fighter_b_id'] = 0;
	assert_notifications( 1 === ( new EventChanges() )->record( $after_link, $renamed, 'test', null ) && 'opponent_changed' === $wpdb->get_var( "SELECT change_type FROM {$n['changes']} WHERE bout_id=$link_bout ORDER BY id DESC LIMIT 1" ), 'A replaced opponent was not reported.' );
	// Leave the follower count of the first fixture fighter as the lookup check below expects it.
	$subscriptions->unfollow( $reader, $fighter_ids[0] ); $subscriptions->unfollow( $both, $fighter_ids[0] );

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
	foreach($extra_bouts as $id){$wpdb->delete($n['changes'],array('bout_id'=>$id));$wpdb->delete($c['bouts'],array('id'=>$id));}foreach($extra_events as $id){$wpdb->delete($c['events'],array('id'=>$id));}
	if($bout_id){$wpdb->delete($n['changes'],array('bout_id'=>$bout_id));$wpdb->delete($c['bouts'],array('id'=>$bout_id));}if($event_id){$wpdb->delete($c['events'],array('id'=>$event_id));}foreach($fighter_ids as $id){$wpdb->delete($canonical['fighters'],array('id'=>$id));}foreach($post_ids as $id){wp_delete_post($id,true);}
	if(null===$old_pause){delete_option('mmaf_notifications_paused');}else{update_option('mmaf_notifications_paused',$old_pause,false);}remove_filter('mmaf_notifications_feature_enabled','__return_true');
}
