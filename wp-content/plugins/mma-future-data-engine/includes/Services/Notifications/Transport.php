<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Transport { public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array; }

final class TransportFactory {
	public static function make(): Transport {
		$override = apply_filters( 'mmaf_notifications_transport', null );
		if ( $override instanceof Transport ) { return $override; }
		if ( in_array( Config::environment(), array( 'local','development' ), true ) ) { return new FakeTransport(); }
		if ( Config::sender_token() ) { return new SenderTransport(); }
		return new WpMailTransport();
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
	public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array {
		if ( ! Config::recipient_allowed( $to ) ) { return array( 'status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0 ); }
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		return wp_mail( $to, $subject, $html, $headers ) ? array( 'status' => 'accepted', 'provider_id' => null, 'http_code' => 200 ) : array( 'status' => 'rejected', 'error' => 'wp_mail_failed', 'http_code' => 500 );
	}
}

final class SenderTransport implements Transport {
	public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array {
		if ( ! Config::recipient_allowed( $to ) ) { return array( 'status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0 ); }
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

final class ServiceMailer {
	public static function send( string $to, string $subject, string $html, string $text ): array { return TransportFactory::make()->send( $to, $subject, $html, $text ); }
}
