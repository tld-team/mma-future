<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Notifications\Config;
use MMAF\DataEngine\Services\Notifications\EmailComposer;
use MMAF\DataEngine\Services\Notifications\EventChanges;
use MMAF\DataEngine\Services\Notifications\TransportFactory;
use MMAF\DataEngine\Services\Notifications\Schema;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NotificationsPage {
	public static function register(): void {
		add_action( 'admin_post_mmaf_notifications_pause', array( self::class, 'pause' ) );
		add_action( 'admin_post_mmaf_notifications_retry', array( self::class, 'retry' ) );
		add_action( 'admin_post_mmaf_notifications_test', array( self::class, 'send_test' ) );
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
		?><div class="wrap"><h1>Sports notifications</h1><?php if(!Config::schema_ready()):?><div class="notice notice-error"><p>The database schema is older than <code><?php echo esc_html(Config::SCHEMA_VERSION);?></code>, so notifications are off. Run <code>wp mmaf system migrate</code>.</p></div><?php elseif(!Config::feature_enabled()):?><div class="notice notice-warning"><p>Feature flag is off. Define <code>MMAF_NOTIFICATIONS_ENABLED</code> as <code>true</code> only after staging verification.</p></div><?php endif;?><?php if($sent>=$quota*.8):?><div class="notice notice-warning"><p>Monthly quota usage is above 80%.</p></div><?php endif;?><p>Sending: <strong><?php echo Config::sending_paused()?'paused':'active';?></strong> · Last worker: <?php echo esc_html((string)get_option('mmaf_notifications_worker_last_run','never'));?> · Oldest pending: <?php echo esc_html($oldest?:'none');?></p><p>Environment: <strong><?php echo esc_html(Config::environment());?></strong> · Transport: <strong><?php echo esc_html(in_array(Config::environment(),array('local','development'),true)?'fake':(Config::sender_token()?'Sender API':'wp_mail fallback'));?></strong> · From address: <strong><?php echo Config::from_email()?'configured':'missing';?></strong> · Google login: <strong><?php echo shortcode_exists('nextend_social_login')?'available':'Nextend not configured';?></strong></p><div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;max-width:900px"><?php foreach($metrics as $label=>$value):?><div class="card"><h2><?php echo esc_html((string)$value);?></h2><p><?php echo esc_html($label);?></p></div><?php endforeach;?></div><p>Monthly sports messages: <strong><?php echo esc_html("$sent / $quota");?></strong></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="mmaf_notifications_pause"><input type="hidden" name="paused" value="<?php echo Config::sending_paused()?'0':'1';?>"><?php wp_nonce_field('mmaf_notifications_pause');submit_button(Config::sending_paused()?'Resume sending':'Pause sending','secondary','submit',false);?></form>
		<?php self::render_account_emails(); ?>
		<h2>Next reminders</h2><table class="widefat striped"><thead><tr><th>User</th><th>Bout</th><th>Scheduled UTC</th></tr></thead><tbody><?php $upcoming=$wpdb->get_results("SELECT user_id,bout_id,scheduled_at_utc FROM {$t['reminders']} WHERE status='active' AND scheduled_at_utc IS NOT NULL ORDER BY scheduled_at_utc LIMIT 20",ARRAY_A)?:array();foreach($upcoming as $reminder):?><tr><td><?php echo self::reader_link((int)$reminder['user_id']);?></td><td><?php echo (int)$reminder['bout_id'];?></td><td><?php echo esc_html($reminder['scheduled_at_utc']);?></td></tr><?php endforeach;if(!$upcoming):?><tr><td colspan="3">No active reminders.</td></tr><?php endif;?></tbody></table>
		<h2>Recent problems</h2><table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Status</th><th>Attempts</th><th>Error</th><th>Updated</th></tr></thead><tbody><?php $rows=$wpdb->get_results("SELECT id,user_id,status,attempts,error_code,error_message,updated_at FROM {$t['messages']} WHERE status IN ('failed','needs_review','retry') ORDER BY updated_at DESC LIMIT 50",ARRAY_A)?:array();foreach($rows as $row):?><tr><td><?php echo (int)$row['id'];?></td><td><?php echo self::reader_link((int)$row['user_id']);?></td><td><?php echo esc_html($row['status']);?></td><td><?php echo (int)$row['attempts'];?></td><td><?php echo esc_html($row['error_code'].' '.$row['error_message']);?></td><td><?php echo esc_html($row['updated_at']);?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6">No problems recorded.</td></tr><?php endif;?></tbody></table><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="mmaf_notifications_retry"><?php wp_nonce_field('mmaf_notifications_retry');submit_button('Retry known failures','secondary','submit',false);?></form><?php self::render_preview(); ?></div><?php
	}

	/** Why a reader did not get their confirmation or password link: the last account emails and what the transport answered. */
	private static function render_account_emails(): void {
		$log       = array_reverse( \MMAF\DataEngine\Services\Notifications\ServiceMailer::log() );
		$allowlist = Config::staging_allowlist();
		$labels    = array( 'register' => 'Confirm registration', 'account_exists' => 'Already registered', 'password_reset' => 'Password link' );
		echo '<h2>Account emails</h2>';
		if ( 'staging' === Config::environment() ) { echo '<div class="notice notice-info inline"><p>Staging sends account emails to any address, so anyone can register and reset a password here. Sports notifications and test sends still go only to the ' . count( $allowlist ) . ' address' . ( 1 === count( $allowlist ) ? '' : 'es' ) . ' in <code>MMAF_NOTIFICATION_STAGING_ALLOWLIST</code>.</p></div>'; }
		echo '<table class="widefat striped"><thead><tr><th>Sent (UTC)</th><th>Email</th><th>To</th><th>Result</th><th>Reason</th></tr></thead><tbody>';
		if ( ! $log ) { echo '<tr><td colspan="5">No account email has been sent yet.</td></tr>'; }
		foreach ( $log as $entry ) {
			echo '<tr><td>' . esc_html( (string) ( $entry['at'] ?? '' ) ) . '</td><td>' . esc_html( $labels[ $entry['kind'] ?? '' ] ?? (string) ( $entry['kind'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['to'] ?? '' ) ) . '</td><td>' . ( 'accepted' === ( $entry['status'] ?? '' ) ? 'Accepted' : '<strong>' . esc_html( ucfirst( (string) ( $entry['status'] ?? 'unknown' ) ) ) . '</strong>' ) . '</td><td>' . esc_html( (string) ( $entry['error'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Shows both email types exactly as the composer builds them, from the next real fight on the calendar. */
	private static function render_preview(): void {
		$user_id = get_current_user_id();
		$status  = sanitize_key( $_GET['mmaf_test'] ?? '' );
		echo '<h2>Email preview</h2><p>Built by the same code that sends real notifications, using the next upcoming fight on the calendar. Action links in a preview open the account page; no secure links are issued.</p>';
		if ( 'accepted' === $status ) { echo '<div class="notice notice-success inline"><p>Test email handed to the ' . esc_html( self::transport_label() ) . ' transport for ' . esc_html( wp_get_current_user()->user_email ) . '.</p></div>'; }
		elseif ( $status ) { echo '<div class="notice notice-error inline"><p>Test email was not sent: ' . esc_html( sanitize_text_field( wp_unslash( $_GET['mmaf_test_error'] ?? $status ) ) ) . '. On staging the recipient must be on <code>MMAF_NOTIFICATION_STAGING_ALLOWLIST</code>.</p></div>'; }
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;max-width:1400px">';
		foreach ( array( 'new_bout' => 'Fight announcement', 'reminder' => 'Reminder' ) as $kind => $label ) {
			$mail = ( new EmailComposer() )->compose( $user_id, self::sample_items( $kind ), true );
			echo '<div><p><strong>' . esc_html( $label ) . '</strong> · subject: ' . esc_html( $mail['subject'] ) . '</p>';
			echo '<iframe title="' . esc_attr( $label ) . ' preview" sandbox srcdoc="' . esc_attr( $mail['html'] ) . '" style="width:100%;height:820px;border:1px solid #c3c4c7;background:#fff"></iframe>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mmaf_notifications_test"><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '">';
			wp_nonce_field( 'mmaf_notifications_test' );
			submit_button( 'Send this test to ' . wp_get_current_user()->user_email, 'secondary', 'submit', false );
			echo '</form></div>';
		}
		echo '</div>';
	}

	public static function send_test(): void {
		self::authorize( 'mmaf_notifications_test' );
		$kind = 'reminder' === sanitize_key( $_POST['kind'] ?? '' ) ? 'reminder' : 'new_bout';
		$user = wp_get_current_user();
		$mail = ( new EmailComposer() )->compose( (int) $user->ID, self::sample_items( $kind ), true );
		$sent = TransportFactory::make()->send( $user->user_email, '[Test] ' . $mail['subject'], $mail['html'], $mail['text'] );
		$args = array( 'page' => 'mmaf-notifications', 'mmaf_test' => 'accepted' === $sent['status'] ? 'accepted' : 'failed' );
		if ( 'accepted' !== $sent['status'] ) { $args['mmaf_test_error'] = rawurlencode( (string) ( $sent['error'] ?? $sent['status'] ) ); }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function transport_label(): string {
		return in_array( Config::environment(), array( 'local', 'development' ), true ) ? 'fake (local mailbox)' : ( Config::sender_token() ? 'Sender API' : 'wp_mail' );
	}

	/** Queue-shaped items for a preview: up to two real upcoming fights, or a labelled placeholder when the calendar is empty. */
	private static function sample_items( string $kind ): array {
		global $wpdb;
		$u    = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$rows = $wpdb->get_results( "SELECT e.id event_row_id,e.title,e.promotion,e.event_date,e.event_datetime_utc,e.source_time_text,e.venue,e.location,e.status event_status,e.hidden event_hidden,b.* FROM {$u['events']} e JOIN {$u['bouts']} b ON b.event_id=e.id WHERE e.hidden=0 AND e.scope_confirmed=1 AND e.status IN ('published','scheduled') AND e.event_date>UTC_DATE() AND b.hidden=0 AND b.scope_status='confirmed' AND b.status NOT IN ('cancelled','hidden') AND (b.fighter_a_id>0 OR b.fighter_b_id>0) ORDER BY e.event_date,b.bout_order,b.id LIMIT " . ( 'reminder' === $kind ? 1 : 2 ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			$start = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
			$rows  = array( array( 'event_row_id' => 0, 'id' => 0, 'title' => 'Sample Fight Night (placeholder)', 'promotion' => 'Sample promotion', 'event_date' => substr( $start, 0, 10 ), 'event_datetime_utc' => $start, 'source_time_text' => null, 'venue' => 'Sample Arena', 'location' => 'Belgrade, Serbia', 'event_status' => 'scheduled', 'event_hidden' => 0, 'stable_id' => 'sample', 'status' => 'scheduled', 'hidden' => 0, 'fighter_a_id' => 0, 'fighter_a_name' => 'Alex Sample', 'fighter_b_id' => 0, 'fighter_b_name' => 'Jordan Example', 'weight_class' => 'Lightweight', 'bout_order' => 1 ) );
		}
		$items = array(); $detector = new EventChanges();
		foreach ( $rows as $row ) {
			$event = $row; $event['id'] = (int) $row['event_row_id']; $event['status'] = $row['event_status']; $event['hidden'] = $row['event_hidden'];
			$items[] = array( 'kind' => $kind, 'bout_id' => (int) $row['id'], 'payload_json' => wp_json_encode( $detector->payload( $event, $row, $kind ) ) );
		}
		return $items;
	}
	public static function pause(): void { self::authorize('mmaf_notifications_pause');update_option('mmaf_notifications_paused',!empty($_POST['paused']),false);wp_safe_redirect(admin_url('admin.php?page=mmaf-notifications'));exit; }
	public static function retry(): void { self::authorize('mmaf_notifications_retry');global $wpdb;$t=Schema::tables();$wpdb->query("UPDATE {$t['messages']} SET status='retry',next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE status='failed' AND attempts<5");wp_safe_redirect(admin_url('admin.php?page=mmaf-notifications'));exit; }
	/** A reader's email linked to the fighters they follow; escaped here, so callers echo it as is. */
	private static function reader_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return '#' . $user_id . ' (deleted)'; }
		return '<a href="' . esc_url( admin_url( 'admin.php?page=mmaf-followers&view=fighters&user_id=' . $user_id ) ) . '">' . esc_html( $user->user_email ) . '</a>';
	}
	private static function authorize(string $action):void{if(!current_user_can(Capabilities::MANAGE)){wp_die('Forbidden','',array('response'=>403));}check_admin_referer($action);}
}
