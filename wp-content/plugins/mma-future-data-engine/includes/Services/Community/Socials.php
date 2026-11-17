<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Social links are stored as usernames on an allow-list of platforms and the URL is always composed here.
 * A reader never supplies a URL that the site then prints, so a profile cannot carry an arbitrary link.
 */
final class Socials {
	public const PLATFORMS = array(
		'instagram' => array( 'label' => 'Instagram', 'url' => 'https://www.instagram.com/%s/', 'pattern' => '/^[a-z0-9._]{1,30}\z/i', 'hosts' => array( 'instagram.com' ) ),
		'x'         => array( 'label' => 'X', 'url' => 'https://x.com/%s', 'pattern' => '/^[a-z0-9_]{1,15}\z/i', 'hosts' => array( 'x.com', 'twitter.com' ) ),
		'youtube'   => array( 'label' => 'YouTube', 'url' => 'https://www.youtube.com/@%s', 'pattern' => '/^[a-z0-9._-]{3,30}\z/i', 'hosts' => array( 'youtube.com' ) ),
		'tiktok'    => array( 'label' => 'TikTok', 'url' => 'https://www.tiktok.com/@%s', 'pattern' => '/^[a-z0-9._]{2,24}\z/i', 'hosts' => array( 'tiktok.com' ) ),
		'facebook'  => array( 'label' => 'Facebook', 'url' => 'https://www.facebook.com/%s', 'pattern' => '/^[a-z0-9.]{5,50}\z/i', 'hosts' => array( 'facebook.com', 'fb.com' ) ),
	);

	/**
	 * Reduce what was typed or pasted (a username, an @name or the profile URL) to the bare username.
	 *
	 * @return string|null '' when nothing was entered, null when it is not a username on that platform.
	 */
	public static function username( string $platform, string $raw ): ?string {
		$rule  = self::PLATFORMS[ $platform ] ?? null;
		$value = trim( $raw );
		if ( ! $rule ) { return null; }
		if ( '' === $value ) { return ''; }
		$has_scheme = (bool) preg_match( '#^(?:https?:)?//#i', $value );
		if ( $has_scheme || preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}/#i', $value ) ) {
			$parts = parse_url( $has_scheme ? $value : 'https://' . $value );
			$host  = strtolower( (string) preg_replace( '/^(?:www|m|mobile)\./i', '', (string) ( $parts['host'] ?? '' ) ) );
			if ( ! in_array( $host, $rule['hosts'], true ) ) { return null; }
			$value = (string) ( explode( '/', trim( (string) ( $parts['path'] ?? '' ), '/' ) )[0] ?? '' );
		}
		$value = ltrim( $value, '@' );
		return preg_match( $rule['pattern'], $value ) ? $value : null;
	}

	/**
	 * @param mixed $input Platform => whatever the reader entered.
	 * @return array{0:array<string,string>,1:array<string,string>} Clean usernames, then field errors keyed `socials.{platform}`.
	 */
	public static function clean( $input ): array {
		$values = array();
		$errors = array();
		foreach ( self::PLATFORMS as $platform => $rule ) {
			$raw      = is_array( $input ) && is_scalar( $input[ $platform ] ?? null ) ? (string) $input[ $platform ] : '';
			$username = self::username( $platform, $raw );
			if ( null === $username ) { $errors[ 'socials.' . $platform ] = 'Enter your ' . $rule['label'] . ' username.'; continue; }
			if ( '' !== $username ) { $values[ $platform ] = $username; }
		}
		return array( $values, $errors );
	}

	/**
	 * @param mixed $values Stored platform => username map; anything that no longer validates is dropped.
	 * @return array<int,array{platform:string,label:string,username:string,url:string}>
	 */
	public static function links( $values ): array {
		$links = array();
		foreach ( self::PLATFORMS as $platform => $rule ) {
			$username = is_array( $values ) && is_string( $values[ $platform ] ?? null ) ? $values[ $platform ] : '';
			if ( '' === $username || ! preg_match( $rule['pattern'], $username ) ) { continue; }
			$links[] = array( 'platform' => $platform, 'label' => $rule['label'], 'username' => $username, 'url' => sprintf( $rule['url'], $username ) );
		}
		return $links;
	}
}
