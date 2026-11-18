<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Transport { public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array; }

final class TransportFactory {
	/**
	 * Sports email on staging goes only to the allowlist, so test data never reaches real readers. Account email is
	 * different: it goes to the one address its recipient just typed, and without it nobody outside the allowlist could
	 * register on staging at all. So account email is not held to the allowlist.
	 */
	public static function make( bool $account_mail = false ): Transport {
		$override = apply_filters( 'mmaf_notifications_transport', null );
		if ( $override instanceof Transport ) { return $override; }
		if ( in_array( Config::environment(), array( 'local','development' ), true ) ) { return new FakeTransport(); }
		if ( Config::sender_token() ) { return new SenderTransport( $account_mail ); }
		return new WpMailTransport( $account_mail );
	}
}

final class FakeTransport implements Transport {
	public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array {
		$messages = get_option( 'mmaf_notification_fake_mailbox', array() );
		$messages[] = array( 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => $headers, 'created_at' => gmdate( 'c' ) );
		update_option( 'mmaf_notification_fake_mailbox', array_slice( $messages, -100 ), false );
		return array( 'status' => 'accepted', 'provider_id' => 'fake-' . wp_generate_uuid4(), 'http_code' => 200 );
	}
}

final class WpMailTransport implements Transport {
	private bool $account_mail;
	public function __construct( bool $account_mail = false ) { $this->account_mail = $account_mail; }
	public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array {
		if ( ! $this->account_mail && ! Config::recipient_allowed( $to ) ) { return array( 'status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0 ); }
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		// The host's mailer would otherwise send as wordpress@<site>. The configured sender is named in the From line and
		// as the envelope sender, so bounces return to that mailbox, and the plain text travels beside the HTML.
		$from = Config::from_email();
		if ( '' !== $from ) { $headers[] = 'From: ' . trim( preg_replace( '/[<>"\r\n]+/', ' ', Config::from_name() ) ) . ' <' . $from . '>'; }
		$prepare = static function ( $mailer ) use ( $from, $text ): void {
			if ( '' !== $from ) { $mailer->Sender = $from; }
			if ( '' !== $text ) { $mailer->AltBody = $text; }
		};
		add_action( 'phpmailer_init', $prepare );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', $prepare );
		return $sent ? array( 'status' => 'accepted', 'provider_id' => null, 'http_code' => 200 ) : array( 'status' => 'rejected', 'error' => 'wp_mail_failed', 'http_code' => 500 );
	}
}

final class SenderTransport implements Transport {
	private bool $account_mail;
	public function __construct( bool $account_mail = false ) { $this->account_mail = $account_mail; }
	public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array {
		if ( ! $this->account_mail && ! Config::recipient_allowed( $to ) ) { return array( 'status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0 ); }
		$custom_headers=array();foreach($headers as $header){$parts=explode(':',(string)$header,2);if(2===count($parts)){$custom_headers[trim($parts[0])]=trim($parts[1]);}}
		$body = array( 'from' => array( 'email' => Config::from_email(), 'name' => Config::from_name() ), 'to' => array( 'email' => $to ), 'subject' => $subject, 'html' => $html, 'text' => $text );
		if($custom_headers){$body['headers']=$custom_headers;}
		$response = wp_remote_post( 'https://api.sender.net/v2/message/send', array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . Config::sender_token(), 'Content-Type' => 'application/json', 'Accept'=>'application/json' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) { return array( 'status' => 'uncertain', 'error' => $response->get_error_message(), 'http_code' => 0 ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
		$id = $data['emailId'] ?? $data['id'] ?? $data['email_id'] ?? $data['message_id'] ?? null;
		if ( $code >= 200 && $code < 300 ) { return array( 'status' => 'accepted', 'provider_id' => $id, 'http_code' => $code ); }
		if ( 429 === $code ) { return array( 'status' => 'retry', 'error' => 'rate_limited', 'retry_after' => max( 60, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ), 'http_code' => $code ); }
		if ( $code >= 500 ) { return array( 'status' => 'retry', 'error' => 'provider_' . $code, 'http_code' => $code ); }
		return array( 'status' => 'rejected', 'error' => sanitize_text_field( $data['message'] ?? ( 'provider_' . $code ) ), 'http_code' => $code );
	}
}

/**
 * Account email: confirm an address, reset a password. It is sent to any address, on staging too. The outcome of each send is kept (the last 30, with the address
 * masked) so an administrator can see why a reader did not get a message instead of guessing.
 */
final class ServiceMailer {
	private const LOG = 'mmaf_service_mail_log';

	public static function send( string $to, string $subject, string $html, string $text, string $kind = 'service' ): array {
		$result = self::within_hourly_limit() ? TransportFactory::make( true )->send( $to, $subject, $html, $text ) : array( 'status' => 'rejected', 'error' => 'hourly_limit', 'http_code' => 0 );
		$log    = self::log();
		$log[]  = array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'kind' => sanitize_key( $kind ), 'to' => self::mask( $to ), 'status' => (string) ( $result['status'] ?? 'unknown' ), 'error' => (string) ( $result['error'] ?? '' ), 'http_code' => (int) ( $result['http_code'] ?? 0 ) );
		update_option( self::LOG, array_slice( $log, -30 ), false );
		if ( 'accepted' !== ( $result['status'] ?? '' ) ) { error_log( 'MMAF account email (' . sanitize_key( $kind ) . ') not accepted: ' . ( $result['error'] ?? 'unknown' ) ); }
		return $result;
	}

	/**
	 * Account email is sent to whatever address a visitor types, and on staging it may leave through a mail server shared
	 * with other sites. A ceiling on the whole site per clock hour keeps a flooded form from spending that server's
	 * reputation or its own hourly allowance. The per-address and per-network limits in Auth still apply first.
	 */
	private static function within_hourly_limit(): bool {
		$limit = Config::account_mail_hourly_limit();
		if ( $limit <= 0 ) { return true; }
		$key  = 'mmaf_account_mail_' . gmdate( 'YmdH' );
		$sent = (int) get_transient( $key );
		if ( $sent >= $limit ) { return false; }
		set_transient( $key, $sent + 1, 2 * HOUR_IN_SECONDS );
		return true;
	}

	/** @return array<int,array{at:string,kind:string,to:string,status:string,error:string,http_code:int}> Oldest first. */
	public static function log(): array {
		return array_values( array_filter( (array) get_option( self::LOG, array() ), 'is_array' ) );
	}

	/** j***@example.com: enough to recognise a test address, not enough to list readers. */
	public static function mask( string $email ): string {
		$parts = explode( '@', $email, 2 );
		return 2 === count( $parts ) && '' !== $parts[0] ? substr( $parts[0], 0, 1 ) . '***@' . $parts[1] : '***';
	}
}
