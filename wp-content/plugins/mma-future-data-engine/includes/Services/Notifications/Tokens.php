<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tokens {
	private array $t;
	public function __construct() { $this->t = Schema::tables(); }

	public function issue( string $purpose, ?int $user_id, ?string $email, array $payload, int $ttl ): string {
		global $wpdb;
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$wpdb->insert( $this->t['tokens'], array(
			'user_id' => $user_id ?: null, 'email' => $email ? sanitize_email( $email ) : null, 'purpose' => sanitize_key( $purpose ),
			'token_hash' => hash( 'sha256', $token ), 'payload_json' => wp_json_encode( $payload ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 60, $ttl ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( ! $wpdb->insert_id ) { throw new \RuntimeException( 'Could not create the secure action link.' ); }
		return $token;
	}

	public function inspect( string $purpose, string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,60}$/', $token ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['tokens']} WHERE token_hash=%s AND purpose=%s AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1", hash( 'sha256', $token ), sanitize_key( $purpose ) ), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['payload'] = json_decode( (string) $row['payload_json'], true ) ?: array();
		return $row;
	}

	public function consume( string $purpose, string $token ): ?array {
		global $wpdb;
		$row = $this->inspect( $purpose, $token );
		if ( ! $row ) { return null; }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['tokens']} SET used_at=UTC_TIMESTAMP() WHERE id=%d AND used_at IS NULL", (int) $row['id'] ) );
		return 1 === (int) $changed ? $row : null;
	}

	public function action_url( string $purpose, string $token ): string {
		return add_query_arg( array( 'purpose' => $purpose, 'token' => $token ), home_url( '/notification-action/' ) );
	}
}
