<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Services\Notifications\Config as Accounts;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A reader's own Top 10. Only the ten ranked positions are stored; everything below them is simply the official order.
 * Writes go straight to the community tables and never take the canonical write lock.
 */
final class FanLists {
	public const SIZE = 10;

	public function find( int $user_id ): ?array {
		global $wpdb;
		$table = Schema::tables()['fan_lists'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $user_id > 0 ? ( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d AND board_key=%s", $user_id, FanRanking::BOARD ), ARRAY_A ) ?: null ) : null;
	}

	/** @return int[] Fighter ids in ranked order. */
	public function fighter_ids( int $user_id ): array {
		global $wpdb;
		$list = $this->find( $user_id );
		if ( ! $list ) { return array(); }
		$table = Schema::tables()['fan_list_items'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT fighter_id FROM {$table} WHERE list_id=%d ORDER BY position", (int) $list['id'] ) ) ?: array() );
	}

	/** Whether this reader's picks count toward the fan ranking. Staff can keep a list, but it is not a fan's vote. */
	public function counts( int $user_id ): bool {
		return Config::is_reader( $user_id ) && Accounts::email_verified( $user_id );
	}

	/**
	 * The list as a page shows it: each position with the fighter's public card data and the points it earns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items( int $user_id ): array {
		$ids      = $this->fighter_ids( $user_id );
		$fighters = ( new FanRanking() )->fighters( $ids );
		$points   = FanRanking::points();
		$items    = array();
		foreach ( $ids as $index => $id ) {
			$position = $index + 1;
			$fighter  = $fighters[ $id ] ?? array( 'fighter_id' => $id, 'display_name' => '', 'nickname' => '', 'permalink' => '', 'image_url' => '', 'weight_class' => '', 'nationality' => '', 'record' => '', 'official_rank' => null, 'eligible' => false, 'in_ufc' => false );
			$listed   = isset( $fighters[ $id ] );
			$items[]  = $fighter + array(
				'position' => $position,
				'points'   => $fighter['eligible'] ? $points[ $position ] : 0,
				// A pick that no longer scores stays where the reader put it, with the reason shown beside it.
				'note'     => $fighter['eligible'] ? '' : ( ! $listed ? 'No longer listed' : ( $fighter['in_ufc'] ? 'Signed with the UFC' : 'Not ranked right now' ) ),
			);
		}
		return $items;
	}

	/** The reader's own view for the editor. */
	public function editable( int $user_id ): array {
		$list = $this->find( $user_id );
		return array(
			'revision' => $list ? (int) $list['revision'] : 0,
			'size'     => self::SIZE,
			'points'   => array_values( FanRanking::points() ),
			'items'    => $this->items( $user_id ),
			'counts'   => $this->counts( $user_id ),
		);
	}

	/**
	 * Replace the reader's Top 10.
	 *
	 * @param mixed $fighter_ids The fighters in ranked order, best first. An empty list clears it.
	 * @param int   $revision    The revision the reader started from; a save from an older copy is refused.
	 * @throws ValidationFailed When the list itself is not acceptable.
	 * @throws Conflict         When the stored list has moved on.
	 */
	public function save( int $user_id, $fighter_ids, int $revision ): void {
		$ids = is_array( $fighter_ids ) ? array_values( array_map( 'absint', array_filter( $fighter_ids, 'is_scalar' ) ) ) : array();
		if ( ! is_array( $fighter_ids ) || count( $ids ) !== count( $fighter_ids ) || in_array( 0, $ids, true ) ) { throw new ValidationFailed( array( 'fighters' => 'That list couldn’t be read. Reload and try again.' ) ); }
		if ( count( $ids ) > self::SIZE ) { throw new ValidationFailed( array( 'fighters' => 'A Top 10 holds ' . self::SIZE . ' fighters.' ) ); }
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) { throw new ValidationFailed( array( 'fighters' => 'A fighter can appear only once.' ) ); }
		// Only a fighter the official ranking lists can be added. One already on the list may stay after leaving it.
		$added = array_diff( $ids, $this->fighter_ids( $user_id ) );
		if ( $added && array_diff( $added, array_keys( FanRanking::official( FanRanking::BOARD ) ) ) ) { throw new ValidationFailed( array( 'fighters' => 'One of those fighters can’t be ranked right now. Reload and try again.' ) ); }

		try { $this->write( $user_id, $ids, $revision ); }
		catch ( Deadlocked $e ) { $this->write( $user_id, $ids, $revision ); }
		FanRanking::changed();
	}

	/** One transaction: lock the list row, check the revision, replace the positions. */
	private function write( int $user_id, array $ids, int $revision ): void {
		global $wpdb;
		$tables = Schema::tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$status = $this->counts( $user_id ) ? 'active' : 'unlisted';
		$quiet  = $wpdb->suppress_errors( true ); // A lost race is reported to the reader, not printed into the response.
		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
			$list = $wpdb->get_row( $wpdb->prepare( "SELECT id,revision FROM {$tables['fan_lists']} WHERE user_id=%d AND board_key=%s FOR UPDATE", $user_id, FanRanking::BOARD ), ARRAY_A );
			self::ensure( null !== $list || '' === (string) $wpdb->last_error );
			if ( ! $list ) {
				// Two first saves at once: the unique key lets one through and the other is told its copy is stale.
				if ( 0 !== $revision || false === $wpdb->insert( $tables['fan_lists'], array( 'user_id' => $user_id, 'board_key' => FanRanking::BOARD, 'revision' => 0, 'status' => $status, 'created_at' => $now, 'updated_at' => $now ) ) ) {
					self::ensure( false === stripos( (string) $wpdb->last_error, 'deadlock' ) );
					throw new Conflict( 'Your Top 10 was changed somewhere else.' );
				}
				$list = array( 'id' => (int) $wpdb->insert_id, 'revision' => 0 );
			}
			if ( (int) $list['revision'] !== $revision ) { throw new Conflict( 'Your Top 10 was changed somewhere else.' ); }
			$list_id = (int) $list['id'];
			$picked  = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT fighter_id,picked_at FROM {$tables['fan_list_items']} WHERE list_id=%d", $list_id ), ARRAY_A ) ?: array() as $row ) { $picked[ (int) $row['fighter_id'] ] = (string) $row['picked_at']; }
			self::ensure( false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['fan_list_items']} WHERE list_id=%d", $list_id ) ) );
			foreach ( $ids as $index => $fighter_id ) {
				// picked_at survives a reorder: it records since when the reader has backed this fighter.
				self::ensure( false !== $wpdb->insert( $tables['fan_list_items'], array( 'list_id' => $list_id, 'board_key' => FanRanking::BOARD, 'position' => $index + 1, 'fighter_id' => $fighter_id, 'picked_at' => $picked[ $fighter_id ] ?? $now ) ) );
			}
			self::ensure( false !== $wpdb->query( $wpdb->prepare( "UPDATE {$tables['fan_lists']} SET revision=revision+1,status=%s,updated_at=%s WHERE id=%d", $status, $now, $list_id ) ) );
			self::ensure( false !== $wpdb->query( 'COMMIT' ) );
			// phpcs:enable
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
	}

	/** Turn a failed statement into an exception; a deadlock is the one failure worth a second attempt. */
	private static function ensure( bool $ok ): void {
		global $wpdb;
		if ( $ok ) { return; }
		if ( false !== stripos( (string) $wpdb->last_error, 'deadlock' ) ) { throw new Deadlocked( 'Deadlock while saving a Top 10.' ); }
		throw new \RuntimeException( 'Your Top 10 could not be saved. Try again.' );
	}
}
