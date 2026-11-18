<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Services\Notifications\Config as Accounts;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Config {
	/** The schema the profile and fan-list code reads and writes. */
	public const SCHEMA_VERSION = '1.20.0';

	/**
	 * Props and levels read tables added in 1.21.0. Only that area waits for them, so profiles and the Top 10 keep
	 * working between a release's deploy and its migration.
	 */
	public const REPUTATION_SCHEMA_VERSION = '1.21.0';

	/** Every area this release knows. Each one is switched on separately, after its release has been checked on staging. */
	public const AREAS = array( 'profiles', 'fan', 'reputation', 'comments' );

	/** Areas that are on when the `mmaf_community_areas` option has never been saved. */
	private const DEFAULT_AREAS = array( 'profiles' );

	/** Releases deploy code before they migrate. Until the migration has run, the feature stays off rather than querying tables that do not exist yet. */
	public static function schema_ready(): bool {
		return version_compare( (string) get_option( 'mmaf_db_version', '0' ), self::SCHEMA_VERSION, '>=' );
	}

	/** Community is built on reader accounts, so it is never on without them. */
	public static function feature_enabled(): bool {
		$enabled = self::schema_ready() && defined( 'MMAF_COMMUNITY_ENABLED' ) && true === MMAF_COMMUNITY_ENABLED && Accounts::feature_enabled();
		return (bool) apply_filters( 'mmaf_community_feature_enabled', $enabled );
	}

	/**
	 * Why the feature is off, as codes an admin page can explain: `schema`, `constant_missing`, `constant_value`
	 * (defined, but not the boolean true), `accounts` (reader accounts are off), `filter`. Empty when it is on.
	 *
	 * @return string[]
	 */
	public static function disabled_reasons(): array {
		if ( self::feature_enabled() ) { return array(); }
		$reasons = array();
		if ( ! self::schema_ready() ) { $reasons[] = 'schema'; }
		if ( ! defined( 'MMAF_COMMUNITY_ENABLED' ) ) { $reasons[] = 'constant_missing'; }
		elseif ( true !== MMAF_COMMUNITY_ENABLED ) { $reasons[] = 'constant_value'; }
		if ( ! Accounts::feature_enabled() ) { $reasons[] = 'accounts'; }
		return $reasons ?: array( 'filter' );
	}

	public static function area_enabled( string $area ): bool {
		if ( ! in_array( $area, self::AREAS, true ) || ! self::feature_enabled() ) { return false; }
		// Votes on comments live in a 1.21.0 table too.
		if ( in_array( $area, array( 'reputation', 'comments' ), true ) && ! self::reputation_schema_ready() ) { return false; }
		$enabled = in_array( $area, self::areas(), true );
		return (bool) apply_filters( 'mmaf_community_area_enabled', $enabled, $area );
	}

	public static function reputation_schema_ready(): bool {
		return version_compare( (string) get_option( 'mmaf_db_version', '0' ), self::REPUTATION_SCHEMA_VERSION, '>=' );
	}

	/** @return string[] The areas the `mmaf_community_areas` option lists, before the feature switch and the filter. */
	public static function areas(): array {
		return array_values( array_intersect( self::AREAS, (array) get_option( 'mmaf_community_areas', self::DEFAULT_AREAS ) ) );
	}

	/**
	 * Store which areas are on. Unknown names are dropped, and every later area needs profiles: a Top 10 and a level
	 * belong to a profile, so choosing either switches profiles on too.
	 *
	 * @param string[] $areas
	 * @return string[] What was stored.
	 */
	public static function save_areas( array $areas ): array {
		$areas = array_values( array_intersect( self::AREAS, $areas ) );
		if ( $areas && ! in_array( 'profiles', $areas, true ) ) { array_unshift( $areas, 'profiles' ); }
		update_option( 'mmaf_community_areas', $areas, false );
		return $areas;
	}

	/** A reader is a subscriber without editorial rights; staff accounts keep their WordPress identity. */
	public static function is_reader( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && in_array( 'subscriber', (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' );
	}

	/** Days a reader waits between handle changes, so a handle cannot be passed around or used to dodge moderation. */
	public static function handle_change_days(): int {
		return max( 0, (int) apply_filters( 'mmaf_community_handle_change_days', 90 ) );
	}

	/** Hours an account must exist before its Top 10 counts, so a batch of fresh accounts cannot move the fan ranking at once. */
	public static function fan_min_account_hours(): int {
		return max( 0, (int) apply_filters( 'mmaf_community_fan_min_account_hours', 24 ) );
	}

	public static function avatar_max_bytes(): int {
		return max( 1, (int) apply_filters( 'mmaf_community_avatar_max_bytes', 2 * 1024 * 1024 ) );
	}
}
