<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The public @handle. Sign-in names are derived from email addresses, so a handle is the only identifier a profile URL
 * may carry. Pure string rules live here; availability (taken, fighter names) needs the database and lives in Profiles.
 */
final class Handles {
	public const MIN = 3;
	public const MAX = 20;

	/** Names that would read as the site, its staff or one of its routes. */
	public const RESERVED = array(
		'about', 'account', 'admin', 'administrator', 'api', 'author', 'blog', 'contact', 'editor', 'fan', 'fans',
		'fighter', 'fighters', 'help', 'home', 'login', 'logout', 'me', 'mod', 'moderator', 'news', 'null', 'official',
		'profile', 'ranking', 'rankings', 'register', 'root', 'settings', 'staff', 'support', 'system', 'team', 'ufc',
		'undefined', 'user', 'users', 'wp_admin',
	);

	/** Handles beginning with the brand would pass for an official account. */
	private const RESERVED_PREFIXES = array( 'mmafuture', 'mma_future' );

	public static function normalize( string $raw ): string {
		return ltrim( strtolower( trim( $raw ) ), '@' );
	}

	/**
	 * @param string[] $also_reserved Extra reserved handles (site-specific additions).
	 * @return string The reason the handle cannot be used, or '' when its form is fine.
	 */
	public static function problem( string $handle, array $also_reserved = array() ): string {
		if ( '' === $handle ) { return 'Choose a handle.'; }
		if ( ! preg_match( '/^[a-z0-9_]+\z/', $handle ) ) { return 'Use only letters, numbers and underscores.'; }
		if ( strlen( $handle ) < self::MIN ) { return 'Use at least ' . self::MIN . ' characters.'; }
		if ( strlen( $handle ) > self::MAX ) { return 'Use ' . self::MAX . ' characters or fewer.'; }
		if ( ! preg_match( '/[a-z]/', $handle ) ) { return 'Add at least one letter.'; }
		if ( in_array( $handle, self::RESERVED, true ) || in_array( $handle, $also_reserved, true ) ) { return 'That handle isn’t available. Try another.'; }
		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $handle, $prefix ) ) { return 'That handle isn’t available. Try another.'; }
		}
		return '';
	}

	/** A neutral starting point for the form, never derived from the reader's email or name. */
	public static function suggestion( int $seed ): string {
		return 'scout_' . str_pad( (string) ( abs( $seed ) % 100000 ), 5, '0', STR_PAD_LEFT );
	}
}
