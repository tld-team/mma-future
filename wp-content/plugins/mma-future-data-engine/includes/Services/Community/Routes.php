<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Services\Notifications\Module as Accounts;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * /account/profile/ and /account/top-10/ (the reader's own pages) and /fan/{handle}/ (the public profile).
 * Neither has a post behind it, so this class also owns their status, caching, title and robots.
 */
final class Routes {
	/** Bump when the rules below change, so they are flushed once on the next request. */
	public const REWRITE_VERSION = '2';

	/** View => the area that must be on for it to exist. */
	private const VIEWS = array( 'account-profile' => 'profiles', 'account-top-10' => 'fan', 'profile' => 'profiles' );
	private const TEMPLATES = array( 'account-profile' => 'community-account.php', 'account-top-10' => 'community-top10.php', 'profile' => 'community-profile.php' );

	private static bool $missing = false;
	/** @var array<string,mixed>|null|false False until looked up. */
	private static $profile = false;

	/**
	 * Hooked on init at priority 9. The account routes flush the rewrite rules from their own init callback at priority 10
	 * whenever the schema version changes; rules added after that flush would be missing from the stored set.
	 */
	public static function rewrites(): void {
		add_rewrite_rule( '^account/profile/?$', 'index.php?mmaf_community_page=account-profile', 'top' );
		add_rewrite_rule( '^account/top-10/?$', 'index.php?mmaf_community_page=account-top-10', 'top' );
		add_rewrite_rule( '^fan/([A-Za-z0-9_]{' . Handles::MIN . ',' . Handles::MAX . '})/?$', 'index.php?mmaf_community_page=profile&mmaf_community_handle=$matches[1]', 'top' );
	}

	public static function maybe_flush(): void {
		if ( get_option( 'mmaf_community_rewrite_version' ) === self::REWRITE_VERSION ) { return; }
		flush_rewrite_rules( false );
		update_option( 'mmaf_community_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'mmaf_community_page';
		$vars[] = 'mmaf_community_handle';
		return $vars;
	}

	public static function view(): string {
		$view = (string) get_query_var( 'mmaf_community_page' );
		return isset( self::VIEWS[ $view ] ) ? $view : '';
	}

	/** The profile behind /fan/{handle}/ when it may be shown, otherwise null. */
	public static function public_profile(): ?array {
		if ( false === self::$profile ) {
			$row           = 'profile' === self::view() ? ( new Profiles() )->find_by_handle( (string) get_query_var( 'mmaf_community_handle' ) ) : null;
			self::$profile = $row && 'active' === $row['status'] ? $row : null;
		}
		return self::$profile;
	}

	public static function guard(): void {
		$view = self::view();
		if ( '' === $view ) { return; }
		self::no_cache();
		if ( ! Config::area_enabled( self::VIEWS[ $view ] ) ) { self::not_found(); return; }
		if ( 'profile' !== $view ) {
			$own = 'account-top-10' === $view ? '/account/top-10/' : '/account/profile/';
			if ( ! is_user_logged_in() ) { wp_safe_redirect( Accounts::login_url( array( 'return_to' => home_url( $own ) ) ) ); exit; }
			return;
		}
		$row = self::public_profile();
		if ( ! $row ) { self::not_found(); return; }
		if ( (string) get_query_var( 'mmaf_community_handle' ) !== $row['handle'] ) { wp_safe_redirect( Profiles::url( (string) $row['handle'] ), 301 ); exit; }
	}

	public static function template( string $template ): string {
		$view = self::view();
		if ( '' === $view ) { return $template; }
		if ( self::$missing ) { return get_404_template() ?: $template; }
		return MMAF_PLUGIN_DIR . 'templates/' . self::TEMPLATES[ $view ];
	}

	public static function title( array $parts ): array {
		$title = self::view_title();
		if ( '' !== $title ) { $parts['title'] = $title; unset( $parts['page'] ); }
		return $parts;
	}

	/** @param string $title */
	public static function document_title( $title ): string {
		$view = self::view_title();
		return '' !== $view ? $view . ' - ' . wp_strip_all_tags( get_bloginfo( 'name', 'display' ) ) : (string) $title;
	}

	public static function robots( array $robots ): array {
		if ( '' !== self::view() ) { $robots['noindex'] = true; $robots['nofollow'] = true; unset( $robots['max-image-preview'] ); }
		return $robots;
	}

	private static function view_title(): string {
		$view = self::view();
		if ( '' === $view || self::$missing ) { return ''; }
		if ( 'account-profile' === $view ) { return 'Your profile'; }
		if ( 'account-top-10' === $view ) { return 'My Top 10'; }
		$row = self::public_profile();
		if ( ! $row ) { return ''; }
		$name = '' !== (string) $row['display_name'] ? (string) $row['display_name'] : (string) $row['handle'];
		return $name . ' (@' . $row['handle'] . ')';
	}

	/** The request resolved to a rewrite rule, so WordPress believes it found the blog. Hand it back as a real 404. */
	private static function not_found(): void {
		global $wp_query;
		self::$missing = true;
		$wp_query->set_404();
		status_header( 404 );
	}

	/** Mirrors the account pages: a buffering page cache would otherwise serve one reader's page to everyone. */
	private static function no_cache(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( function_exists( 'wpfc_exclude_current_page' ) ) { wpfc_exclude_current_page(); }
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		do_action( 'litespeed_control_set_nocache', 'MMA Future community page' );
	}
}
