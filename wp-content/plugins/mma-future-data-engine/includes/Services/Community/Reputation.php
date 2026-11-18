<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Credibility. A reader's points are their one-off awards (a complete profile, a first full Top 10) plus the props other
 * readers gave their list, never below zero, cached in `mmaf_profiles.rep_points`. Tenure is added when read, so it
 * needs no scheduled job. Recalculating is one query per reader and always starts from the stored facts, so the cache
 * cannot drift.
 */
final class Reputation {
	public const PROFILE_POINTS = 10;
	public const TOP10_POINTS   = 15;

	/**
	 * Grant whatever one-off awards the reader has earned and refresh their points. Safe to call as often as a
	 * trigger fires: each award is stored once.
	 *
	 * @param mixed $user_id
	 */
	public static function sync( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! Config::area_enabled( 'reputation' ) || ! Config::is_reader( $user_id ) ) { return; }
		$row = ( new Profiles() )->find_by_user( $user_id );
		if ( $row && self::profile_complete( $row ) ) { self::award( $user_id, 'profile', 'profile_complete', self::PROFILE_POINTS ); }
		if ( count( ( new FanLists() )->fighter_ids( $user_id ) ) >= FanLists::SIZE ) { self::award( $user_id, 'top10', 'top10_complete', self::TOP10_POINTS ); }
		self::recalculate( $user_id );
	}

	/** Photo, bio and a name of the reader's own choosing (an empty name falls back to the handle). */
	public static function profile_complete( array $row ): bool {
		return '' !== (string) $row['avatar_path'] && '' !== trim( (string) $row['bio'] ) && '' !== (string) $row['display_name'] && (string) $row['display_name'] !== (string) $row['handle'];
	}

	public static function award( int $user_id, string $kind, string $dedupe_key, int $points ): bool {
		global $wpdb;
		$table = Schema::tables()['rep_events'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id,kind,dedupe_key,points,created_at) VALUES (%d,%s,%s,%d,%s)", $user_id, sanitize_key( $kind ), substr( $dedupe_key, 0, 64 ), $points, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** Earned points from the stored facts. Props from a reader a moderator has hidden or excluded do not count. */
	public static function points( int $user_id ): int {
		global $wpdb;
		$t = Schema::tables();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$awards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points),0) FROM {$t['rep_events']} WHERE user_id=%d", $user_id ) );
		$props  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.value),0) FROM {$t['ratings']} r LEFT JOIN {$t['profiles']} p ON p.user_id=r.rater_user_id WHERE r.owner_user_id=%d AND r.rater_user_id<>r.owner_user_id AND (p.status IS NULL OR p.status NOT IN ('hidden','shadow'))", $user_id ) );
		// phpcs:enable
		return max( 0, $awards + $props );
	}

	public static function recalculate( int $user_id ): int {
		global $wpdb;
		$points = self::points( $user_id );
		$wpdb->update( Schema::tables()['profiles'], array( 'rep_points' => $points ), array( 'user_id' => $user_id ) );
		Profiles::forget_cached( $user_id );
		return $points;
	}

	/**
	 * A moderator changed a reader's status: the props that reader gave now count, or stop counting, for every list
	 * they rated.
	 *
	 * @param mixed $user_id
	 */
	public static function rater_changed( $user_id ): void {
		global $wpdb;
		if ( ! Config::reputation_schema_ready() ) { return; }
		$table = Schema::tables()['ratings'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		foreach ( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner_user_id FROM {$table} WHERE rater_user_id=%d", (int) $user_id ) ) ?: array() ) as $owner ) { self::recalculate( $owner ); }
	}

	/**
	 * What a page shows: the level and where the XP came from. Built from the cached points, so it costs no query
	 * beyond the profile row.
	 *
	 * @return array{xp:int,points:int,tenure:int,level:array,breakdown:array<string,int>}
	 */
	public static function summary( int $user_id, ?array $row = null ): array {
		$row    = $row ?? ( new Profiles() )->find_by_user( $user_id );
		$user   = get_userdata( $user_id );
		$tenure = $user ? Levels::tenure( (int) strtotime( (string) $user->user_registered . ' UTC' ), time() ) : 0;
		$points = $row ? max( 0, (int) $row['rep_points'] ) : 0;
		return array( 'xp' => $points + $tenure, 'points' => $points, 'tenure' => $tenure, 'level' => Levels::for_xp( $points + $tenure ) );
	}

	/** The reader's own breakdown, for their account page. */
	public static function breakdown( int $user_id ): array {
		global $wpdb;
		$t      = Schema::tables();
		$awards = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT kind, SUM(points) AS points FROM {$t['rep_events']} WHERE user_id=%d GROUP BY kind", $user_id ), ARRAY_A ) ?: array() as $award ) { $awards[ (string) $award['kind'] ] = (int) $award['points']; }
		$summary = self::summary( $user_id );
		$props   = max( 0, $summary['points'] - array_sum( $awards ) );
		return $summary + array( 'breakdown' => array( 'profile' => $awards['profile'] ?? 0, 'top10' => $awards['top10'] ?? 0, 'props' => $props, 'tenure' => $summary['tenure'] ) );
	}

	/** Remove a reader's awards, the props they gave (refreshing those lists' owners) and the props their list got. */
	public static function forget( int $user_id ): void {
		global $wpdb;
		if ( ! Config::reputation_schema_ready() ) { return; }
		$t      = Schema::tables();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$owners = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner_user_id FROM {$t['ratings']} WHERE rater_user_id=%d", $user_id ) ) ?: array() );
		$wpdb->delete( $t['ratings'], array( 'rater_user_id' => $user_id ) );
		$wpdb->delete( $t['ratings'], array( 'owner_user_id' => $user_id ) );
		$wpdb->delete( $t['rep_events'], array( 'user_id' => $user_id ) );
		$wpdb->delete( $t['comment_votes'], array( 'user_id' => $user_id ) );
		foreach ( $owners as $owner ) { if ( $owner !== $user_id ) { self::recalculate( $owner ); } }
	}
}
