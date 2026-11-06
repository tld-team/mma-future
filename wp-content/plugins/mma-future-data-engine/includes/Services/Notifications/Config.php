<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Config {
	public static function feature_enabled(): bool {
		$schema_ready = version_compare( (string) get_option( 'mmaf_db_version', '0' ), '1.18.0', '>=' );
		$enabled = $schema_ready && defined( 'MMAF_NOTIFICATIONS_ENABLED' ) && true === MMAF_NOTIFICATIONS_ENABLED;
		return (bool) apply_filters( 'mmaf_notifications_feature_enabled', $enabled );
	}

	public static function sending_paused(): bool {
		return (bool) get_option( 'mmaf_notifications_paused', true );
	}

	public static function timezone( int $user_id ): string {
		$value = (string) get_user_meta( $user_id, '_mmaf_notification_timezone', true );
		try { new \DateTimeZone( $value ?: 'Europe/Belgrade' ); } catch ( \Throwable $e ) { $value = 'Europe/Belgrade'; }
		return $value ?: 'Europe/Belgrade';
	}

	public static function user_enabled( int $user_id ): bool {
		$value = get_user_meta( $user_id, '_mmaf_notifications_enabled', true );
		return '' === $value || '1' === (string) $value;
	}

	public static function email_verified( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, '_mmaf_email_verified', true );
	}

	public static function monthly_quota(): int {
		return max( 1, (int) apply_filters( 'mmaf_notifications_monthly_quota', 15000 ) );
	}

	public static function environment(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) { return wp_get_environment_type(); }
		return defined( 'WP_ENVIRONMENT_TYPE' ) ? (string) WP_ENVIRONMENT_TYPE : 'production';
	}

	public static function sender_token(): string { return defined( 'MMAF_SENDER_API_TOKEN' ) ? trim( (string) MMAF_SENDER_API_TOKEN ) : ''; }
	public static function from_email(): string { return defined( 'MMAF_SENDER_FROM_EMAIL' ) ? sanitize_email( (string) MMAF_SENDER_FROM_EMAIL ) : sanitize_email( (string) get_option( 'admin_email' ) ); }
	public static function from_name(): string { return defined( 'MMAF_SENDER_FROM_NAME' ) ? sanitize_text_field( (string) MMAF_SENDER_FROM_NAME ) : get_bloginfo( 'name' ); }
	public static function staging_allowlist(): array {
		$raw = defined( 'MMAF_NOTIFICATION_STAGING_ALLOWLIST' ) ? (string) MMAF_NOTIFICATION_STAGING_ALLOWLIST : '';
		return array_values( array_filter( array_map( 'sanitize_email', preg_split( '/\s*,\s*/', $raw ) ?: array() ) ) );
	}
	public static function recipient_allowed( string $email ): bool {
		return 'staging' !== self::environment() || in_array( strtolower( sanitize_email( $email ) ), array_map( 'strtolower', self::staging_allowlist() ), true );
	}
}
