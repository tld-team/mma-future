<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Up and down votes on comments. One per account per comment, changeable. Voting down needs level 2, like props.
 * A comment's score is cached in comment meta `_mmafc_score`; a score at Comments::HOLD_SCORE holds it for a moderator.
 * The author's XP counts each comment's score between -5 and +25, so one comment can neither sink nor carry a reader.
 */
final class CommentVotes {
	public const MIN_LEVEL_DOWN = 2;
	public const AUTHOR_FLOOR   = -5;
	public const AUTHOR_CAP     = 25;

	/** @return array<int,int> comment id => this account's vote. */
	public static function mine( int $user_id, array $comment_ids ): array {
		global $wpdb;
		$comment_ids = array_values( array_filter( array_map( 'intval', $comment_ids ) ) );
		if ( $user_id < 1 || ! $comment_ids || ! Config::reputation_schema_ready() ) { return array(); }
		$table = Schema::tables()['comment_votes'];
		$in    = implode( ',', $comment_ids );
		$out   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a list of integers.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT comment_id, value FROM {$table} WHERE user_id=%d AND comment_id IN ({$in})", $user_id ), ARRAY_A ) ?: array() as $row ) { $out[ (int) $row['comment_id'] ] = (int) $row['value']; }
		return $out;
	}

	public static function can_vote_down( int $user_id ): bool {
		if ( ! Config::is_reader( $user_id ) ) { return null !== Comments::author( $user_id ); }
		$row = ( new Profiles() )->find_by_user( $user_id );
		return $row && Reputation::summary( $user_id, $row )['level']['level'] >= self::MIN_LEVEL_DOWN;
	}

	/**
	 * Vote 1 (up), -1 (down) or 0 (take the vote back).
	 *
	 * @return array{score:int,mine:int,status:string}
	 * @throws ValidationFailed
	 */
	public function vote( int $user_id, int $comment_id, int $value ): array {
		global $wpdb;
		$comment = get_comment( $comment_id );
		if ( ! $comment || '1' !== (string) $comment->comment_approved || (int) $comment->user_id < 1 ) { throw new ValidationFailed( array( 'vote' => 'That comment can’t take votes.' ) ); }
		if ( ! in_array( $value, array( -1, 0, 1 ), true ) ) { throw new ValidationFailed( array( 'vote' => 'Vote up or down.' ) ); }
		if ( ! Comments::author( $user_id ) ) { throw new ValidationFailed( array( 'vote' => 'Set up your profile to vote.' ) ); }
		if ( (int) $comment->user_id === $user_id ) { throw new ValidationFailed( array( 'vote' => 'You can’t vote on your own comment.' ) ); }
		if ( -1 === $value && ! self::can_vote_down( $user_id ) ) { throw new ValidationFailed( array( 'vote' => 'Reach level ' . self::MIN_LEVEL_DOWN . ' (' . Levels::NAMES[ self::MIN_LEVEL_DOWN ] . ') to vote down.' ) ); }
		$table = Schema::tables()['comment_votes'];
		$now   = gmdate( 'Y-m-d H:i:s' );
		if ( 0 === $value ) {
			$wpdb->delete( $table, array( 'comment_id' => $comment_id, 'user_id' => $user_id ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (comment_id,user_id,author_user_id,value,created_at,updated_at) VALUES (%d,%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)", $comment_id, $user_id, (int) $comment->user_id, $value, $now, $now ) );
		}
		$score = self::refresh( $comment_id );
		Reputation::recalculate( (int) $comment->user_id );
		if ( $score <= Comments::HOLD_SCORE ) { wp_set_comment_status( $comment_id, 'hold' ); }
		return array( 'score' => $score, 'mine' => $value, 'status' => (string) wp_get_comment_status( $comment_id ) );
	}

	/** Recount a comment's score from the votes that count (not from accounts a moderator hid or excluded). */
	public static function refresh( int $comment_id ): int {
		global $wpdb;
		$t     = Schema::tables();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$score = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(v.value),0) FROM {$t['comment_votes']} v LEFT JOIN {$t['profiles']} p ON p.user_id=v.user_id WHERE v.comment_id=%d AND (p.status IS NULL OR p.status NOT IN ('hidden','shadow'))", $comment_id ) );
		update_comment_meta( $comment_id, '_mmafc_score', $score );
		return $score;
	}

	/** The author's XP from votes: each approved comment's score, kept between AUTHOR_FLOOR and AUTHOR_CAP. */
	public static function points( int $author_user_id ): int {
		global $wpdb;
		$t = Schema::tables();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LEAST(%d, GREATEST(%d, x.s))),0) FROM (SELECT v.comment_id, SUM(v.value) AS s FROM {$t['comment_votes']} v JOIN {$wpdb->comments} c ON c.comment_ID=v.comment_id AND c.comment_approved='1' LEFT JOIN {$t['profiles']} p ON p.user_id=v.user_id WHERE v.author_user_id=%d AND v.user_id<>v.author_user_id AND (p.status IS NULL OR p.status NOT IN ('hidden','shadow')) GROUP BY v.comment_id) x", self::AUTHOR_CAP, self::AUTHOR_FLOOR, $author_user_id ) );
	}

	/** A moderator changed a voter's status: refresh the comments they voted on and those comments' authors. */
	public static function voter_changed( $user_id ): void {
		global $wpdb;
		if ( ! Config::reputation_schema_ready() ) { return; }
		$table = Schema::tables()['comment_votes'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT comment_id, author_user_id FROM {$table} WHERE user_id=%d", (int) $user_id ), ARRAY_A ) ?: array();
		foreach ( $rows as $row ) { self::refresh( (int) $row['comment_id'] ); }
		foreach ( array_unique( array_map( 'intval', array_column( $rows, 'author_user_id' ) ) ) as $author ) { Reputation::recalculate( $author ); }
	}
}
