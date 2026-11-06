<?php
namespace MMAF\DataEngine\Services\Notifications;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Privacy {
	public static function export( string $email, int $page = 1 ): array {
		$user=get_user_by('email',$email); if(!$user){return array('data'=>array(),'done'=>true);} $t=Schema::tables(); global $wpdb;
		$subs=$wpdb->get_results($wpdb->prepare("SELECT fighter_id,status,created_at FROM {$t['subscriptions']} WHERE user_id=%d",$user->ID),ARRAY_A)?:array();
		$rem=$wpdb->get_results($wpdb->prepare("SELECT bout_id,status,scheduled_at_utc FROM {$t['reminders']} WHERE user_id=%d",$user->ID),ARRAY_A)?:array();
		return array('data'=>array(array('group_id'=>'mmaf-notifications','group_label'=>'MMA Future notifications','item_id'=>'mmaf-notifications-'.$user->ID,'data'=>array(array('name'=>'Fighter subscriptions','value'=>wp_json_encode($subs)),array('name'=>'Reminders','value'=>wp_json_encode($rem)),array('name'=>'Timezone','value'=>Config::timezone((int)$user->ID))))),'done'=>true);
	}
	public static function erase( string $email, int $page = 1 ): array {
		$user=get_user_by('email',$email); if(!$user){return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);} global $wpdb;$t=Schema::tables();
		foreach(array('subscriptions','reminders','items','tokens') as $key){$wpdb->delete($t[$key],array('user_id'=>(int)$user->ID));}
		$wpdb->query($wpdb->prepare("UPDATE {$t['messages']} SET html_body=NULL,text_body=NULL,subject=NULL,error_message=NULL WHERE user_id=%d",$user->ID));
		delete_user_meta($user->ID,'_mmaf_notification_timezone');delete_user_meta($user->ID,'_mmaf_notifications_enabled');delete_user_meta($user->ID,'_mmaf_email_verified');
		return array('items_removed'=>true,'items_retained'=>true,'messages'=>array('Minimal delivery records are retained to prevent duplicate sends.'),'done'=>true);
	}
}
