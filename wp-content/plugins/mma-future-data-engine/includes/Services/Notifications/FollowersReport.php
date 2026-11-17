<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only views of who follows whom, for the admin overview and its CSV exports.
 *
 * Every query joins the WordPress users table, so a subscription whose account has been deleted never counts
 * and the fighter view and the reader view always agree on the totals.
 */
final class FollowersReport {
	/** Sort key => SQL expression. Keys come from the URL; only these expressions ever reach the query. */
	public const FIGHTER_SORTS = array( 'followers' => 'followers', 'name' => 'f.display_name', 'last_followed' => 'last_followed', 'next_fight' => 'next_fight IS NULL, next_fight' );
	public const USER_SORTS    = array( 'follows' => 'follows', 'email' => 'u.user_email', 'registered' => 'u.user_registered', 'last_followed' => 'last_followed' );

	private array $t; private string $fighters; private string $users;

	public function __construct() {
		global $wpdb;
		$this->t        = Schema::tables();
		$this->fighters = \MMAF\DataEngine\Migrations\Schema::table_names()['fighters'];
		$this->users    = $wpdb->users;
	}

	public function summary(): array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT COUNT(*) follows,COUNT(DISTINCT s.user_id) readers,COUNT(DISTINCT s.fighter_id) fighters FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE s.status='active'", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top = $this->fighters( array( 'per_page' => 1 ) );
		return array( 'follows' => (int) ( $row['follows'] ?? 0 ), 'readers' => (int) ( $row['readers'] ?? 0 ), 'fighters' => (int) ( $row['fighters'] ?? 0 ), 'top' => $top[0] ?? null );
	}

	/**
	 * Followed fighters with their follower counts.
	 *
	 * @param array $args search, user_id (only fighters this reader follows), orderby, order, per_page (0 = all), paged.
	 */
	public function fighters( array $args = array() ): array {
		global $wpdb;
		list( $where, $params ) = $this->fighter_where( $args );
		$u    = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$next = "(SELECT MIN(e.event_date) FROM {$u['bouts']} b JOIN {$u['events']} e ON e.id=b.event_id WHERE (b.fighter_a_id=f.id OR b.fighter_b_id=f.id) AND e.hidden=0 AND e.scope_confirmed=1 AND e.status IN ('published','scheduled','postponed') AND e.event_date>=UTC_DATE() AND b.hidden=0 AND b.scope_status='confirmed' AND b.status NOT IN ('cancelled','hidden'))";
		$sql  = "SELECT f.id fighter_id,f.display_name,f.weight_class,f.wp_post_id,COUNT(*) followers,MAX(s.updated_at) last_followed,$next next_fight FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE $where GROUP BY f.id,f.display_name,f.weight_class,f.wp_post_id " . $this->order_sql( self::FIGHTER_SORTS, $args, 'followers', 'f.id' ) . $this->limit_sql( $args );
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$row ) { $row['profile_url'] = $row['wp_post_id'] ? (string) get_permalink( (int) $row['wp_post_id'] ) : ''; }
		return $rows;
	}

	public function count_fighters( array $args = array() ): int {
		global $wpdb;
		list( $where, $params ) = $this->fighter_where( $args );
		$sql = "SELECT COUNT(DISTINCT s.fighter_id) FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE $where";
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Readers with the fighters they follow and their delivery state.
	 *
	 * @param array $args search, fighter_id (only followers of this fighter), orderby, order, per_page (0 = all), paged.
	 */
	public function users( array $args = array() ): array {
		global $wpdb;
		list( $where, $params ) = $this->user_where( $args );
		// The default 1024-byte GROUP_CONCAT limit would silently cut the fighter list of a reader who follows many.
		$wpdb->query( 'SET SESSION group_concat_max_len = 100000' );
		$sql  = "SELECT u.ID user_id,u.user_email,u.display_name,u.user_registered,COUNT(*) follows,MAX(s.updated_at) last_followed,GROUP_CONCAT(f.display_name ORDER BY f.display_name SEPARATOR '; ') fighters FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE $where GROUP BY u.ID,u.user_email,u.display_name,u.user_registered " . $this->order_sql( self::USER_SORTS, $args, 'follows', 'u.ID' ) . $this->limit_sql( $args );
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->with_delivery_state( $rows );
	}

	public function count_users( array $args = array() ): int {
		global $wpdb;
		list( $where, $params ) = $this->user_where( $args );
		$sql = "SELECT COUNT(DISTINCT s.user_id) FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE $where";
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** One row per reader–fighter pair: the complete relation, for analysis outside the dashboard. */
	public function pairs( array $args = array() ): array {
		global $wpdb;
		$where = array( "s.status='active'" ); $params = array();
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(f.display_name LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)'; array_push( $params, $like, $like, $like ); }
		if ( ! empty( $args['fighter_id'] ) ) { $where[] = 's.fighter_id=%d'; $params[] = (int) $args['fighter_id']; }
		if ( ! empty( $args['user_id'] ) ) { $where[] = 's.user_id=%d'; $params[] = (int) $args['user_id']; }
		$sql = "SELECT u.user_email,u.display_name user_name,f.display_name fighter,f.weight_class,s.created_at first_followed,s.updated_at last_followed FROM {$this->t['subscriptions']} s JOIN {$this->users} u ON u.ID=s.user_id JOIN {$this->fighters} f ON f.id=s.fighter_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY f.display_name,u.user_email';
		return $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Headers and rows for one export, as plain strings.
	 *
	 * @param string $view fighters | users | pairs
	 */
	public function export( string $view, array $args = array() ): array {
		$args['per_page'] = 0;
		if ( 'users' === $view ) {
			$rows = array_map( static fn( array $r ): array => array( $r['user_email'], $r['display_name'], $r['user_registered'], $r['delivery_label'], $r['verified'] ? 'da' : 'ne', $r['timezone'], $r['follows'], $r['fighters'], $r['reminders'], (string) $r['last_sent'], $r['last_followed'] ), $this->users( $args ) );
			return array( 'headers' => array( 'Email', 'Ime', 'Registrovan (UTC)', 'Obaveštenja', 'Mejl potvrđen', 'Vremenska zona', 'Prati boraca', 'Borci', 'Aktivni podsetnici', 'Poslednji poslat mejl (UTC)', 'Poslednje praćenje (UTC)' ), 'rows' => $rows );
		}
		if ( 'pairs' === $view ) {
			$rows = array_map( static fn( array $r ): array => array( $r['user_email'], $r['user_name'], $r['fighter'], (string) $r['weight_class'], $r['first_followed'], $r['last_followed'] ), $this->pairs( $args ) );
			return array( 'headers' => array( 'Email', 'Ime', 'Borac', 'Kategorija', 'Prati od (UTC)', 'Poslednja izmena (UTC)' ), 'rows' => $rows );
		}
		$rows = array_map( static fn( array $r ): array => array( $r['display_name'], (string) $r['weight_class'], $r['followers'], $r['last_followed'], (string) $r['next_fight'], $r['profile_url'] ), $this->fighters( $args ) );
		return array( 'headers' => array( 'Borac', 'Kategorija', 'Pratilaca', 'Poslednje praćenje (UTC)', 'Sledeća borba', 'Profil' ), 'rows' => $rows );
	}

	/**
	 * CSV text for Excel: a UTF-8 byte order mark so č, ć, š and đ survive, and a leading apostrophe on any cell
	 * a spreadsheet would run as a formula (fighter and display names are not ours to trust).
	 */
	public static function csv( array $headers, array $rows ): string {
		$out = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $headers, ',', '"', '' );
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $value ) { $value = (string) $value; $cells[] = preg_match( '/^[=+@\-\t\r]/', $value ) ? "'" . $value : $value; }
			fputcsv( $out, $cells, ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	private function fighter_where( array $args ): array {
		global $wpdb;
		$where = array( "s.status='active'" ); $params = array();
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) { $where[] = 'f.display_name LIKE %s'; $params[] = '%' . $wpdb->esc_like( $search ) . '%'; }
		if ( ! empty( $args['user_id'] ) ) { $where[] = "s.fighter_id IN (SELECT fighter_id FROM {$this->t['subscriptions']} WHERE user_id=%d AND status='active')"; $params[] = (int) $args['user_id']; }
		return array( implode( ' AND ', $where ), $params );
	}

	private function user_where( array $args ): array {
		global $wpdb;
		$where = array( "s.status='active'" ); $params = array();
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)'; array_push( $params, $like, $like ); }
		// Filtering by fighter narrows the readers, not their rows: each reader still shows everything they follow.
		if ( ! empty( $args['fighter_id'] ) ) { $where[] = "s.user_id IN (SELECT user_id FROM {$this->t['subscriptions']} WHERE fighter_id=%d AND status='active')"; $params[] = (int) $args['fighter_id']; }
		return array( implode( ' AND ', $where ), $params );
	}

	private function order_sql( array $sorts, array $args, string $default, string $tiebreak ): string {
		$key       = isset( $sorts[ $args['orderby'] ?? '' ] ) ? $args['orderby'] : $default;
		$direction = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		// The direction applies to the last term only, so "next_fight IS NULL, next_fight" keeps fighters without a fight last either way.
		return 'ORDER BY ' . $sorts[ $key ] . ' ' . $direction . ', ' . $tiebreak . ' ASC';
	}

	private function limit_sql( array $args ): string {
		$per_page = (int) ( $args['per_page'] ?? 50 );
		if ( $per_page < 1 ) { return ''; }
		$per_page = min( 200, $per_page );
		return ' LIMIT ' . $per_page . ' OFFSET ' . ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per_page;
	}

	/** Adds what decides whether a reader actually receives email, plus their reminders and last delivery. */
	private function with_delivery_state( array $rows ): array {
		global $wpdb;
		if ( ! $rows ) { return array(); }
		$ids = array_map( static fn( array $r ): int => (int) $r['user_id'], $rows ); $in = implode( ',', $ids );
		update_meta_cache( 'user', $ids );
		$reminders = wp_list_pluck( $wpdb->get_results( "SELECT user_id,COUNT(*) total FROM {$this->t['reminders']} WHERE status='active' AND user_id IN ($in) GROUP BY user_id", ARRAY_A ) ?: array(), 'total', 'user_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent      = wp_list_pluck( $wpdb->get_results( "SELECT user_id,MAX(accepted_at) last_sent FROM {$this->t['messages']} WHERE status IN ('accepted','delivered') AND user_id IN ($in) GROUP BY user_id", ARRAY_A ) ?: array(), 'last_sent', 'user_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$row ) {
			$id = (int) $row['user_id'];
			$row['verified']  = Config::email_verified( $id );
			$row['timezone']  = Config::timezone( $id );
			$row['reminders'] = (int) ( $reminders[ $id ] ?? 0 );
			$row['last_sent'] = $sent[ $id ] ?? null;
			// Same order of checks as Repository::notification_allowed(), so the label names the reason a reader gets nothing.
			if ( get_user_meta( $id, '_mmaf_notifications_unsubscribed_at', true ) ) { $row['delivery'] = 'unsubscribed'; $row['delivery_label'] = 'Odjavljen'; }
			elseif ( ! Config::user_enabled( $id ) ) { $row['delivery'] = 'disabled'; $row['delivery_label'] = 'Isključio obaveštenja'; }
			elseif ( ! $row['verified'] ) { $row['delivery'] = 'unverified'; $row['delivery_label'] = 'Mejl nije potvrđen'; }
			else { $row['delivery'] = 'active'; $row['delivery_label'] = 'Prima mejlove'; }
		}
		return $rows;
	}
}
