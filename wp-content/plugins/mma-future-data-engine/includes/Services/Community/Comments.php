<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Services\Notifications\Module as Accounts;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Article comments by readers with a profile. They are ordinary WordPress comments (so wp-admin moderation and
 * comment_count keep working), posted through the community REST route and shown under the article by a script, so
 * nothing per-reader lands in the cached article HTML. A comment is visible at once; reports or a low score hold it
 * for a moderator.
 */
final class Comments {
	public const MAX_LENGTH     = 2000;
	/** Replies nest two levels deep; an answer to a second-level reply joins that conversation instead of going deeper. */
	public const MAX_DEPTH      = 2;
	public const HOLD_REPORTS   = 3;
	public const HOLD_SCORE     = -5;
	public const COLLAPSE_SCORE = -3;
	public const DAILY_XP       = 3;

	/** The empty section the article prints; the script fills it. '' while the area is off, so the article is unchanged. */
	public static function shell( int $post_id ): string {
		if ( $post_id < 1 || ! Config::area_enabled( 'comments' ) || 'post' !== get_post_type( $post_id ) ) { return ''; }
		if ( ! comments_open( $post_id ) && ! self::count( $post_id ) ) { return ''; }
		return '<section class="mmafc-page mmafc-comments" id="comments" data-mmafc-comments="' . (int) $post_id . '" aria-labelledby="mmafc-comments-title">'
			. '<div class="mmafc-comments__head"><h2 class="mmafc-comments__title" id="mmafc-comments-title">Discussion</h2>'
			. '<div class="mmafc-comments__sort" role="group" aria-label="Sort comments"><button type="button" class="mmafc-comments__sort-btn is-current" data-mmafc-sort="best" aria-pressed="true">Best</button><button type="button" class="mmafc-comments__sort-btn" data-mmafc-sort="new" aria-pressed="false">New</button></div></div>'
			. '<div data-mmafc-comments-compose></div><p class="mmafc-status" data-mmafc-comments-status role="status" aria-live="polite">Loading the discussion…</p><ol class="mmafc-thread" data-mmafc-thread></ol>'
			. '<noscript><p class="mmafc-note">The discussion needs JavaScript.</p></noscript></section>';
	}

	/** Comments shown in the discussion: approved, by a signed-in account. Older anonymous comments stay in wp-admin only. */
	public static function count( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_approved='1' AND user_id>0 AND comment_type IN ('','comment')", $post_id ) );
	}

	/**
	 * The discussion as the script draws it.
	 *
	 * @param string $sort 'best' (score, then newest) or 'new'. Replies always read oldest first.
	 */
	public function thread( int $post_id, string $sort, int $viewer ): array {
		$rows = get_comments( array( 'post_id' => $post_id, 'status' => 'approve', 'type' => 'comment', 'number' => 500, 'orderby' => 'comment_date_gmt', 'order' => 'ASC', 'update_comment_meta_cache' => true ) );
		$rows = array_values( array_filter( $rows, static fn( $c ): bool => (int) $c->user_id > 0 ) );
		$mine = $viewer > 0 ? CommentVotes::mine( $viewer, array_map( static fn( $c ): int => (int) $c->comment_ID, $rows ) ) : array();
		$down = $viewer > 0 && CommentVotes::can_vote_down( $viewer );
		$items = array();
		foreach ( $rows as $comment ) {
			$author = self::author( (int) $comment->user_id );
			if ( ! $author ) { continue; }
			$id    = (int) $comment->comment_ID;
			$score = (int) get_comment_meta( $id, '_mmafc_score', true );
			$items[] = array(
				'id'        => $id,
				'parent'    => (int) $comment->comment_parent,
				'author'    => $author,
				'content'   => (string) $comment->comment_content,
				'date'      => gmdate( 'c', (int) strtotime( $comment->comment_date_gmt . ' UTC' ) ),
				'date_text' => sprintf( '%s ago', human_time_diff( (int) strtotime( $comment->comment_date_gmt . ' UTC' ) ) ),
				'score'     => $score,
				'mine'      => $mine[ $id ] ?? 0,
				'own'       => $viewer > 0 && (int) $comment->user_id === $viewer,
				'collapsed' => $score <= self::COLLAPSE_SCORE,
				'depth'     => 0,
			);
		}
		// Depth from the parent chain; a reply whose parent is not shown becomes a top-level comment.
		$by_id = array();
		foreach ( $items as $index => $item ) { $by_id[ $item['id'] ] = $index; }
		foreach ( $items as $index => $item ) {
			$depth = 0; $parent = $item['parent'];
			while ( $parent && isset( $by_id[ $parent ] ) && $depth < 10 ) { $depth++; $parent = $items[ $by_id[ $parent ] ]['parent']; }
			if ( $item['parent'] && ! isset( $by_id[ $item['parent'] ] ) ) { $items[ $index ]['parent'] = 0; }
			$items[ $index ]['depth'] = min( self::MAX_DEPTH, $depth );
		}
		$top = array_values( array_filter( $items, static fn( array $c ): bool => 0 === $c['parent'] ) );
		usort( $top, 'new' === $sort
			? static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) ?: $b['id'] <=> $a['id']
			: static fn( array $a, array $b ): int => ( $b['score'] <=> $a['score'] ) ?: ( strcmp( $b['date'], $a['date'] ) ?: $b['id'] <=> $a['id'] ) );
		$problem = $this->problem( $viewer, $post_id );
		return array(
			'post_id'     => $post_id,
			'count'       => count( $items ),
			'order'       => array_column( $top, 'id' ),
			'comments'    => $items,
			'can_post'    => '' === $problem,
			'reason'      => $problem,
			'can_vote'    => $viewer > 0 && null !== self::author( $viewer ),
			'can_down'    => $down,
			'login_url'   => Accounts::login_url( array( 'return_to' => get_permalink( $post_id ) . '#comments' ) ),
			'profile_url' => home_url( '/account/profile/' ),
			'max_length'  => self::MAX_LENGTH,
		);
	}

	/**
	 * How an author is shown: a reader by their profile, staff by their WordPress name. A reader without an active
	 * profile is not shown (their sign-in name is derived from their email and is never printed).
	 */
	public static function author( int $user_id ): ?array {
		static $cache = array();
		if ( array_key_exists( $user_id, $cache ) ) { return $cache[ $user_id ]; }
		$cache[ $user_id ] = null;
		$user = get_userdata( $user_id );
		if ( ! $user ) { return null; }
		$profile = ( new Profiles() )->find_by_user( $user_id );
		if ( $profile && 'hidden' !== (string) $profile['status'] ) {
			$view  = ( new Profiles() )->present( $profile );
			$level = Config::area_enabled( 'reputation' ) && Config::is_reader( $user_id ) ? Reputation::summary( $user_id, $profile )['level'] : null;
			return $cache[ $user_id ] = array( 'name' => $view['display_name'], 'handle' => $view['handle'], 'url' => $view['url'], 'avatar' => $view['avatar_url'], 'badge' => $level ? Badge::svg( $level, 'sm' ) : '', 'level' => $level ? $level['level'] . ' · ' . $level['name'] : '', 'staff' => ! Config::is_reader( $user_id ) );
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return $cache[ $user_id ] = array( 'name' => (string) $user->display_name, 'handle' => '', 'url' => '', 'avatar' => Avatars::url( '' ), 'badge' => '', 'level' => '', 'staff' => true );
		}
		return null;
	}

	/** Why this account cannot comment on this article, or '' when it can. */
	public function problem( int $user_id, int $post_id ): string {
		if ( ! comments_open( $post_id ) ) { return 'Comments are closed on this article.'; }
		if ( $user_id < 1 ) { return 'Sign in to join the discussion.'; }
		if ( ! self::author( $user_id ) ) { return 'Set up your profile to join the discussion: other fans see your handle, never your email.'; }
		return '';
	}

	/**
	 * Post a comment or a reply. Visible at once.
	 *
	 * @throws ValidationFailed
	 */
	public function post( int $user_id, int $post_id, string $content, int $parent = 0 ): array {
		if ( 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) { throw new ValidationFailed( array( 'content' => 'This article doesn’t take comments.' ) ); }
		$problem = $this->problem( $user_id, $post_id );
		if ( '' !== $problem ) { throw new ValidationFailed( array( 'content' => $problem ) ); }
		$content = trim( (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( $content ) ) ) );
		$length  = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
		if ( '' === $content ) { throw new ValidationFailed( array( 'content' => 'Write something first.' ) ); }
		if ( $length > self::MAX_LENGTH ) { throw new ValidationFailed( array( 'content' => 'Keep it to ' . self::MAX_LENGTH . ' characters.' ) ); }
		$parent = $this->reply_parent( $post_id, $parent );
		$user   = get_userdata( $user_id );
		$data   = array( 'comment_post_ID' => $post_id, 'comment_parent' => $parent, 'comment_content' => $content, 'comment_type' => 'comment', 'user_id' => $user_id, 'comment_author' => (string) ( self::author( $user_id )['name'] ?? '' ), 'comment_author_email' => (string) $user->user_email, 'comment_author_url' => '', 'comment_author_IP' => '', 'comment_agent' => '' );
		// Visible at once, unless WordPress's own disallowed-words check calls it spam. Moderation comes after.
		$approve = static fn( $approved ) => in_array( $approved, array( 'spam', 'trash' ), true ) || is_wp_error( $approved ) ? $approved : 1;
		add_filter( 'pre_comment_approved', $approve, 99 );
		$id = wp_new_comment( wp_slash( $data ), true );
		remove_filter( 'pre_comment_approved', $approve, 99 );
		if ( is_wp_error( $id ) ) { throw new ValidationFailed( array( 'content' => 'comment_duplicate' === $id->get_error_code() ? 'You already said that.' : ( 'comment_flood' === $id->get_error_code() ? 'You’re commenting quickly. Wait a moment and try again.' : 'Your comment couldn’t be posted. Try again.' ) ) ); }
		update_comment_meta( (int) $id, '_mmafc_score', 0 );
		if ( '1' === (string) wp_get_comment_status( (int) $id ) || 'approved' === wp_get_comment_status( (int) $id ) ) { self::award( $user_id, (int) $id ); }
		return array( 'id' => (int) $id, 'status' => wp_get_comment_status( (int) $id ) );
	}

	/** +1 XP for a comment, at most DAILY_XP a day. */
	private static function award( int $user_id, int $comment_id ): void {
		global $wpdb;
		if ( ! Config::area_enabled( 'reputation' ) || ! Config::is_reader( $user_id ) ) { return; }
		$table = Schema::tables()['rep_events'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND kind='comment' AND created_at>=%s", $user_id, gmdate( 'Y-m-d 00:00:00' ) ) );
		if ( $today >= self::DAILY_XP ) { return; }
		Reputation::award( $user_id, 'comment', 'comment_' . $comment_id, 1 );
		Reputation::recalculate( $user_id );
	}

	/** A comment that leaves the discussion takes its XP and its votes' XP with it. */
	public static function status_changed( $new_status, $old_status, $comment ): void {
		if ( ! $comment instanceof \WP_Comment || (int) $comment->user_id < 1 || ! Config::reputation_schema_ready() || $new_status === $old_status ) { return; }
		if ( 'approved' !== $new_status ) {
			global $wpdb;
			$wpdb->delete( Schema::tables()['rep_events'], array( 'user_id' => (int) $comment->user_id, 'dedupe_key' => 'comment_' . (int) $comment->comment_ID ) );
		}
		Reputation::recalculate( (int) $comment->user_id );
	}

	/** Replies go at most MAX_DEPTH deep: an answer to the deepest level joins that level's conversation. */
	private function reply_parent( int $post_id, int $parent ): int {
		if ( $parent < 1 ) { return 0; }
		$chain = array();
		$node  = get_comment( $parent );
		if ( ! $node || (int) $node->comment_post_ID !== $post_id || '1' !== (string) $node->comment_approved ) { throw new ValidationFailed( array( 'content' => 'The comment you replied to is gone. Reload the discussion.' ) ); }
		while ( $node && count( $chain ) < 10 ) { $chain[] = (int) $node->comment_ID; $node = (int) $node->comment_parent ? get_comment( (int) $node->comment_parent ) : null; }
		// $chain is the parent, its parent, … up to the top-level comment. The reply sits at depth count($chain).
		return count( $chain ) > self::MAX_DEPTH ? $chain[ count( $chain ) - self::MAX_DEPTH ] : $parent;
	}

	/**
	 * Report a comment. Enough reports from different accounts hold it for a moderator.
	 *
	 * @throws ValidationFailed
	 */
	public function report( int $user_id, int $comment_id ): bool {
		$comment = get_comment( $comment_id );
		if ( ! $comment || '1' !== (string) $comment->comment_approved || ! self::author( $user_id ) ) { throw new ValidationFailed( array( 'report' => 'That comment can’t be reported.' ) ); }
		if ( (int) $comment->user_id === $user_id ) { throw new ValidationFailed( array( 'report' => 'You can’t report your own comment.' ) ); }
		$reporters = array_map( 'intval', (array) get_comment_meta( $comment_id, '_mmafc_reports', true ) );
		if ( ! in_array( $user_id, $reporters, true ) ) { $reporters[] = $user_id; update_comment_meta( $comment_id, '_mmafc_reports', $reporters ); }
		if ( count( $reporters ) >= self::HOLD_REPORTS ) { wp_set_comment_status( $comment_id, 'hold' ); return true; }
		return false;
	}

	/** While the area is on, comments come only through the article's discussion; moderators keep wp-admin. */
	public static function refuse_core_form( $post_id ): void {
		if ( Config::area_enabled( 'comments' ) && ! current_user_can( 'moderate_comments' ) ) { wp_die( 'Comments are posted from the discussion under each article.', 'Comments', array( 'response' => 403, 'back_link' => true ) ); }
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 */
	public static function refuse_core_rest( $result, $server, $request ) {
		if ( null === $result && $request instanceof \WP_REST_Request && 'POST' === $request->get_method() && 0 === strpos( $request->get_route(), '/wp/v2/comments' ) && Config::area_enabled( 'comments' ) && ! current_user_can( 'moderate_comments' ) ) {
			return new \WP_Error( 'mmafc_comments_route', 'Comments are posted from the discussion under each article.', array( 'status' => 403 ) );
		}
		return $result;
	}
}
