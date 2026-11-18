<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Props: a reader rates another reader's Top 10 as Solid, Sharp or Elite (1, 2 or 3 points to its owner). One rating
 * per reader per list; it can be changed or withdrawn. Only established readers may give props, never to themselves.
 */
final class Ratings {
	public const VALUES = array( 1 => 'Solid', 2 => 'Sharp', 3 => 'Elite' );
	/** Spotter: a complete profile and a full Top 10 are enough to get there. */
	public const MIN_LEVEL = 2;

	/** @return array{1:int,2:int,3:int,count:int} Props on this reader's list that count. */
	public function totals( int $owner_user_id ): array {
		global $wpdb;
		$t      = Schema::tables();
		$totals = array( 1 => 0, 2 => 0, 3 => 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT r.value, COUNT(*) AS n FROM {$t['ratings']} r LEFT JOIN {$t['profiles']} p ON p.user_id=r.rater_user_id WHERE r.owner_user_id=%d AND (p.status IS NULL OR p.status NOT IN ('hidden','shadow')) GROUP BY r.value", $owner_user_id ), ARRAY_A ) ?: array() as $row ) {
			if ( isset( $totals[ (int) $row['value'] ] ) ) { $totals[ (int) $row['value'] ] = (int) $row['n']; }
		}
		return $totals + array( 'count' => array_sum( $totals ) );
	}

	public function mine( int $rater_user_id, int $owner_user_id ): ?int {
		global $wpdb;
		$table = Schema::tables()['ratings'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE rater_user_id=%d AND owner_user_id=%d", $rater_user_id, $owner_user_id ) );
		return null === $value ? null : (int) $value;
	}

	/** Why this reader cannot give props to this profile's list, or '' when they can. */
	public function problem( int $rater_user_id, array $owner_profile ): string {
		$owner = (int) $owner_profile['user_id'];
		if ( 'hidden' === (string) $owner_profile['status'] || ! Config::is_reader( $owner ) ) { return 'This Top 10 can’t receive props.'; }
		if ( ! ( new FanLists() )->fighter_ids( $owner ) ) { return 'There is no Top 10 to rate yet.'; }
		if ( $rater_user_id < 1 ) { return 'Sign in to give props.'; }
		if ( $rater_user_id === $owner ) { return 'You can’t give props to your own Top 10.'; }
		if ( ! Config::is_reader( $rater_user_id ) ) { return 'Staff accounts don’t give props.'; }
		$rater = ( new Profiles() )->find_by_user( $rater_user_id );
		// A shadowed reader may still rate, so nothing tells them; their props simply do not count.
		if ( ! $rater || 'hidden' === (string) $rater['status'] ) { return 'Set up your profile to give props.'; }
		if ( Reputation::summary( $rater_user_id, $rater )['level']['level'] < self::MIN_LEVEL ) { return 'Reach level ' . self::MIN_LEVEL . ' (' . Levels::NAMES[ self::MIN_LEVEL ] . ') to give props: add a photo, a name and a bio to your profile, and fill your Top 10.'; }
		return '';
	}

	/** What a viewer sees under a Top 10: the totals, their own rating, and whether (or why not) they can rate. */
	public function state( int $viewer_user_id, array $owner_profile ): array {
		$owner   = (int) $owner_profile['user_id'];
		$problem = $this->problem( $viewer_user_id, $owner_profile );
		return array(
			'totals'   => $this->totals( $owner ),
			'mine'     => $viewer_user_id > 0 && $viewer_user_id !== $owner ? $this->mine( $viewer_user_id, $owner ) : null,
			'can_rate' => '' === $problem,
			'reason'   => $problem,
			'labels'   => self::VALUES,
		);
	}

	/**
	 * Give, change (or, with 0, withdraw) props on the list of the profile with this handle.
	 *
	 * @throws ValidationFailed When the rating is not allowed.
	 */
	public function rate( int $rater_user_id, string $handle, int $value ): array {
		global $wpdb;
		$owner_profile = ( new Profiles() )->find_by_handle( $handle );
		if ( ! $owner_profile ) { throw new ValidationFailed( array( 'props' => 'That profile doesn’t exist.' ) ); }
		if ( 0 !== $value && ! isset( self::VALUES[ $value ] ) ) { throw new ValidationFailed( array( 'props' => 'Choose Solid, Sharp or Elite.' ) ); }
		$owner = (int) $owner_profile['user_id'];
		$table = Schema::tables()['ratings'];
		if ( 0 === $value ) {
			$wpdb->delete( $table, array( 'rater_user_id' => $rater_user_id, 'owner_user_id' => $owner ) );
		} else {
			$problem = $this->problem( $rater_user_id, $owner_profile );
			if ( '' !== $problem ) { throw new ValidationFailed( array( 'props' => $problem ) ); }
			$list = ( new FanLists() )->find( $owner );
			$now  = gmdate( 'Y-m-d H:i:s' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (list_id,owner_user_id,rater_user_id,value,list_revision,created_at,updated_at) VALUES (%d,%d,%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE value=VALUES(value), list_revision=VALUES(list_revision), updated_at=VALUES(updated_at)", (int) $list['id'], $owner, $rater_user_id, $value, (int) $list['revision'], $now, $now ) );
		}
		Reputation::recalculate( $owner );
		return $this->state( $rater_user_id, $owner_profile );
	}
}
