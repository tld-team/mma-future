<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Notifications\Config;
use MMAF\DataEngine\Services\Notifications\Schema;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NotificationsPage {
	public static function register(): void {
		add_action( 'admin_post_mmaf_notifications_pause', array( self::class, 'pause' ) );
		add_action( 'admin_post_mmaf_notifications_retry', array( self::class, 'retry' ) );
	}
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		global $wpdb; $t=Schema::tables();
		$metrics=array(
			'Active subscriptions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['subscriptions']} WHERE status='active'"),
			'Active reminders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['reminders']} WHERE status='active'"),
			'Pending items'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['items']} WHERE status IN ('pending','reserved')"),
			'Failed messages'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['messages']} WHERE status IN ('failed','needs_review')"),
		);
		$oldest=$wpdb->get_var("SELECT MIN(created_at) FROM {$t['items']} WHERE status IN ('pending','reserved')");$sent=(int)get_option('mmaf_notifications_monthly_sent',0);$quota=Config::monthly_quota();
		?><div class="wrap"><h1>Sports notifications</h1><?php if(!Config::feature_enabled()):?><div class="notice notice-warning"><p>Feature flag is off. Define <code>MMAF_NOTIFICATIONS_ENABLED</code> as <code>true</code> only after staging verification.</p></div><?php endif;?><?php if($sent>=$quota*.8):?><div class="notice notice-warning"><p>Monthly quota usage is above 80%.</p></div><?php endif;?><p>Sending: <strong><?php echo Config::sending_paused()?'paused':'active';?></strong> · Last worker: <?php echo esc_html((string)get_option('mmaf_notifications_worker_last_run','never'));?> · Oldest pending: <?php echo esc_html($oldest?:'none');?></p><p>Environment: <strong><?php echo esc_html(Config::environment());?></strong> · Transport: <strong><?php echo esc_html(in_array(Config::environment(),array('local','development'),true)?'fake':(Config::sender_token()?'Sender API':'wp_mail fallback'));?></strong> · From address: <strong><?php echo Config::from_email()?'configured':'missing';?></strong> · Google login: <strong><?php echo shortcode_exists('nextend_social_login')?'available':'Nextend not configured';?></strong></p><div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;max-width:900px"><?php foreach($metrics as $label=>$value):?><div class="card"><h2><?php echo esc_html((string)$value);?></h2><p><?php echo esc_html($label);?></p></div><?php endforeach;?></div><p>Monthly sports messages: <strong><?php echo esc_html("$sent / $quota");?></strong></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="mmaf_notifications_pause"><input type="hidden" name="paused" value="<?php echo Config::sending_paused()?'0':'1';?>"><?php wp_nonce_field('mmaf_notifications_pause');submit_button(Config::sending_paused()?'Resume sending':'Pause sending','secondary','submit',false);?></form>
		<h2>Next reminders</h2><table class="widefat striped"><thead><tr><th>User</th><th>Bout</th><th>Scheduled UTC</th></tr></thead><tbody><?php $upcoming=$wpdb->get_results("SELECT user_id,bout_id,scheduled_at_utc FROM {$t['reminders']} WHERE status='active' AND scheduled_at_utc IS NOT NULL ORDER BY scheduled_at_utc LIMIT 20",ARRAY_A)?:array();foreach($upcoming as $reminder):?><tr><td><?php echo (int)$reminder['user_id'];?></td><td><?php echo (int)$reminder['bout_id'];?></td><td><?php echo esc_html($reminder['scheduled_at_utc']);?></td></tr><?php endforeach;if(!$upcoming):?><tr><td colspan="3">No active reminders.</td></tr><?php endif;?></tbody></table>
		<h2>Recent problems</h2><table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Status</th><th>Attempts</th><th>Error</th><th>Updated</th></tr></thead><tbody><?php $rows=$wpdb->get_results("SELECT id,user_id,status,attempts,error_code,error_message,updated_at FROM {$t['messages']} WHERE status IN ('failed','needs_review','retry') ORDER BY updated_at DESC LIMIT 50",ARRAY_A)?:array();foreach($rows as $row):?><tr><td><?php echo (int)$row['id'];?></td><td><?php echo (int)$row['user_id'];?></td><td><?php echo esc_html($row['status']);?></td><td><?php echo (int)$row['attempts'];?></td><td><?php echo esc_html($row['error_code'].' '.$row['error_message']);?></td><td><?php echo esc_html($row['updated_at']);?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6">No problems recorded.</td></tr><?php endif;?></tbody></table><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="mmaf_notifications_retry"><?php wp_nonce_field('mmaf_notifications_retry');submit_button('Retry known failures','secondary','submit',false);?></form></div><?php
	}
	public static function pause(): void { self::authorize('mmaf_notifications_pause');update_option('mmaf_notifications_paused',!empty($_POST['paused']),false);wp_safe_redirect(admin_url('admin.php?page=mmaf-notifications'));exit; }
	public static function retry(): void { self::authorize('mmaf_notifications_retry');global $wpdb;$t=Schema::tables();$wpdb->query("UPDATE {$t['messages']} SET status='retry',next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE status='failed' AND attempts<5");wp_safe_redirect(admin_url('admin.php?page=mmaf-notifications'));exit; }
	private static function authorize(string $action):void{if(!current_user_can(Capabilities::MANAGE)){wp_die('Forbidden','',array('response'=>403));}check_admin_referer($action);}
}
