<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\Config;
use MMAF\DataEngine\Services\Notifications\Module;
use MMAF\DataEngine\Services\Notifications\Reminders;
use MMAF\DataEngine\Services\Notifications\Repository;
use MMAF\DataEngine\Services\Notifications\Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NotificationsController extends \WP_REST_Controller {
	public function __construct() { $this->namespace = RestServiceProvider::NAMESPACE; $this->rest_base = 'notifications'; }
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/subscriptions', array(
			array( 'methods'=>\WP_REST_Server::READABLE, 'callback'=>array($this,'subscriptions'), 'permission_callback'=>array($this,'private_permission') ),
			array( 'methods'=>\WP_REST_Server::CREATABLE, 'callback'=>array($this,'follow'), 'permission_callback'=>array($this,'private_permission'), 'args'=>array('fighter_id'=>array('required'=>true,'type'=>'integer','minimum'=>1)) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/subscriptions/(?P<fighter_id>\d+)', array( 'methods'=>\WP_REST_Server::DELETABLE, 'callback'=>array($this,'unfollow'), 'permission_callback'=>array($this,'private_permission') ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/state', array( 'methods'=>\WP_REST_Server::READABLE, 'callback'=>array($this,'state'), 'permission_callback'=>array($this,'private_permission') ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reminders', array(
			array( 'methods'=>\WP_REST_Server::READABLE, 'callback'=>array($this,'reminders'), 'permission_callback'=>array($this,'private_permission') ),
			array( 'methods'=>\WP_REST_Server::CREATABLE, 'callback'=>array($this,'enable_reminder'), 'permission_callback'=>array($this,'private_permission'), 'args'=>array('bout_id'=>array('required'=>true,'type'=>'integer','minimum'=>1)) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reminders/(?P<bout_id>\d+)', array( 'methods'=>\WP_REST_Server::DELETABLE, 'callback'=>array($this,'cancel_reminder'), 'permission_callback'=>array($this,'private_permission') ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/settings', array(
			array( 'methods'=>\WP_REST_Server::READABLE, 'callback'=>array($this,'settings'), 'permission_callback'=>array($this,'private_permission') ),
			array( 'methods'=>\WP_REST_Server::EDITABLE, 'callback'=>array($this,'update_settings'), 'permission_callback'=>array($this,'private_permission') ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/auth/email', array( 'methods'=>\WP_REST_Server::CREATABLE, 'callback'=>array($this,'request_login'), 'permission_callback'=>array($this,'public_auth_permission') ) );
	}

	public function private_permission(): bool { return is_user_logged_in() && Config::feature_enabled(); }
	public function public_auth_permission(): bool { return ! is_user_logged_in() && Config::feature_enabled(); }
	public function subscriptions(): \WP_REST_Response { return $this->response( ( new Subscriptions() )->listing( get_current_user_id() ) ); }
	public function follow( \WP_REST_Request $r ) { try{return $this->response((new Subscriptions())->follow(get_current_user_id(),absint($r['fighter_id'])));}catch(\Throwable $e){return new \WP_Error('mmaf_follow_failed',$e->getMessage(),array('status'=>400));} }
	public function unfollow( \WP_REST_Request $r ): \WP_REST_Response { return $this->response( (new Subscriptions())->unfollow(get_current_user_id(),absint($r['fighter_id'])) ); }
	public function state( \WP_REST_Request $r ): \WP_REST_Response { $ids=array_slice(array_values(array_unique(array_filter(array_map('absint',explode(',',(string)$r->get_param('fighter_ids')))))),0,200);$active=array_flip((new Repository())->active_fighter_ids(get_current_user_id()));$state=array();foreach($ids as $id){$state[(string)$id]=isset($active[$id]);}return $this->response(array('following'=>$state)); }
	public function reminders(): \WP_REST_Response { return $this->response( (new Reminders())->listing(get_current_user_id()) ); }
	public function enable_reminder( \WP_REST_Request $r ) { try{return $this->response((new Reminders())->enable(get_current_user_id(),absint($r['bout_id'])));}catch(\Throwable $e){return new \WP_Error('mmaf_reminder_failed',$e->getMessage(),array('status'=>400));} }
	public function cancel_reminder( \WP_REST_Request $r ): \WP_REST_Response { return $this->response((new Reminders())->cancel(get_current_user_id(),absint($r['bout_id']))); }
	public function settings(): \WP_REST_Response { $id=get_current_user_id();return $this->response(array('timezone'=>Config::timezone($id),'enabled'=>Config::user_enabled($id),'verified'=>Config::email_verified($id),'next_send'=>$this->next_send($id))); }
	public function update_settings( \WP_REST_Request $r ) {
		$id=get_current_user_id();$zone=sanitize_text_field((string)$r->get_param('timezone'));try{new \DateTimeZone($zone);}catch(\Throwable $e){return new \WP_Error('invalid_timezone','Use a valid IANA timezone.',array('status'=>400));}
		$enabled=(bool)$r->get_param('enabled');update_user_meta($id,'_mmaf_notification_timezone',$zone);update_user_meta($id,'_mmaf_notifications_enabled',$enabled?'1':'0');if($enabled){delete_user_meta($id,'_mmaf_notifications_unsubscribed_at');}else{Module::cancel_queued($id);}return $this->settings();
	}
	public function request_login( \WP_REST_Request $r ): \WP_REST_Response {
		if(!Config::feature_enabled()){return $this->response(array('message'=>'Sign-in by email is not available yet.'));}
		$email=(string)$r->get_param('email');$intent=array('fighter_id'=>absint($r->get_param('fighter_id')),'return_to'=>(string)$r->get_param('return_to'));$ip=(string)($_SERVER['REMOTE_ADDR']??'');
		(new Auth())->request_link($email,$intent,$ip);return $this->response(array('message'=>'If the address can use this sign-in method, a secure link is on its way.'));
	}
	private function next_send(int $id): ?string { $day=(new \DateTimeImmutable('now',new \DateTimeZone(Config::timezone($id))))->format('Y-m-d');return (new Repository())->user_has_daily_message($id,$day)?(new Repository())->next_local_nine_utc($id,1):null; }
	private function response($data): \WP_REST_Response { $response=new \WP_REST_Response($data,200);$response->header('Cache-Control','no-store, private, max-age=0');return $response; }
}
