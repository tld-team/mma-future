<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Repositories\PublicReadRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\PublicCacheService;
use MMAF\DataEngine\Services\PublicReadService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The fan ranking: every counted Top 10 gives its fighters points by position, and the totals rank the fighters.
 *
 * There is no stored total per fighter. The standings are one GROUP BY over the list items, cached until the next save,
 * so they cannot drift from the lists, and a deleted account, a moderated reader or a fighter who signs with the UFC
 * needs no recalculation. A fighter is ranked only while the official ranking would list them.
 */
final class FanRanking {
	public const BOARD = 'overall';

	/** Points for positions 1 to 10. */
	private const POINTS = array( 25, 18, 15, 12, 10, 8, 6, 4, 2, 1 );

	/** @return array<int,int> Position (1-10) => points. */
	public static function points(): array {
		$custom = apply_filters( 'mmaf_community_fan_points', self::POINTS );
		$values = is_array( $custom ) && count( $custom ) === FanLists::SIZE ? array_values( $custom ) : self::POINTS;
		$points = array();
		foreach ( $values as $index => $value ) { $points[ $index + 1 ] = max( 0, (int) $value ); }
		return $points;
	}

	/** Called after anything that changes which picks count: a save, a removed account, a moderation decision. */
	public static function changed(): void {
		update_option( 'mmaf_fan_revision', wp_generate_uuid4(), false );
	}

	/**
	 * Order aggregated rows: most points first, then most first places, then most lists, then the better official rank.
	 *
	 * @param array<int,array{fighter_id:int,points:int,picks:int,firsts:int}> $rows
	 * @param array<int,int>                                                    $official Fighter id => official rank; fighters missing from it are dropped.
	 * @return array<int,array{fighter_id:int,points:int,picks:int,firsts:int}>
	 */
	public static function order( array $rows, array $official ): array {
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => isset( $official[ $row['fighter_id'] ] ) ) );
		usort( $rows, static function ( array $a, array $b ) use ( $official ): int {
			return array( $b['points'], $b['firsts'], $b['picks'], $official[ $a['fighter_id'] ] ) <=> array( $a['points'], $a['firsts'], $a['picks'], $official[ $b['fighter_id'] ] );
		} );
		return $rows;
	}

	/**
	 * @return array{lists:int,rows:array<int,array{fighter_id:int,points:int,picks:int,firsts:int}>} Overall standings, best first.
	 */
	public function standings(): array {
		$points = self::points();
		return PublicCacheService::remember( 'fan_standings', array( self::BOARD, (string) get_option( 'mmaf_fan_revision', '0' ), $points, Config::fan_min_account_hours() ), function () use ( $points ): array {
			global $wpdb;
			$tables = Schema::tables();
			$cases  = '';
			foreach ( $points as $position => $value ) { $cases .= ' WHEN ' . (int) $position . ' THEN ' . (int) $value; }
			// Counted: active lists of accounts old enough to count, unless a moderator has hidden or excluded the reader.
			$from = "FROM {$tables['fan_list_items']} i JOIN {$tables['fan_lists']} l ON l.id=i.list_id JOIN {$wpdb->users} u ON u.ID=l.user_id LEFT JOIN {$tables['profiles']} p ON p.user_id=l.user_id WHERE i.board_key=%s AND l.status='active' AND u.user_registered<=%s AND (p.status IS NULL OR p.status NOT IN ('hidden','shadow'))";
			$args = array( self::BOARD, gmdate( 'Y-m-d H:i:s', time() - Config::fan_min_account_hours() * HOUR_IN_SECONDS ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and integer CASE arms built above.
			$raw   = $wpdb->get_results( $wpdb->prepare( "SELECT i.fighter_id, SUM(CASE i.position{$cases} ELSE 0 END) AS points, COUNT(*) AS picks, SUM(CASE WHEN i.position=1 THEN 1 ELSE 0 END) AS firsts {$from} GROUP BY i.fighter_id", $args ), ARRAY_A ) ?: array();
			$lists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT l.id) {$from}", $args ) );
			// phpcs:enable
			$rows = array_map( static fn( array $row ): array => array( 'fighter_id' => (int) $row['fighter_id'], 'points' => (int) $row['points'], 'picks' => (int) $row['picks'], 'firsts' => (int) $row['firsts'] ), $raw );
			return array( 'lists' => $lists, 'rows' => self::order( $rows, self::official( self::BOARD ) ) );
		} );
	}

	/**
	 * One page of the fan ranking for a board. Division, gender and age boards are the overall standings narrowed to the
	 * fighters on that official board: a fighter has one fan score, wherever it is shown.
	 *
	 * @return array{board:string,lists:int,total:int,page:int,per_page:int,total_pages:int,items:array<int,array<string,mixed>>}
	 */
	public function board( string $board, int $page = 1, int $per_page = 25 ): array {
		$board     = isset( PublicReadService::board_labels()[ $board ] ) ? $board : self::BOARD;
		$standings = $this->standings();
		$official  = self::official( $board );
		$rows      = array_values( array_filter( $standings['rows'], static fn( array $row ): bool => isset( $official[ $row['fighter_id'] ] ) ) );
		$per_page  = max( 1, min( 100, $per_page ) );
		$pages     = max( 1, (int) ceil( count( $rows ) / $per_page ) );
		$page      = max( 1, min( $pages, $page ) );
		$slice     = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$fighters  = $this->fighters( array_column( $slice, 'fighter_id' ) );
		$items     = array();
		foreach ( $slice as $index => $row ) {
			$fighter = $fighters[ $row['fighter_id'] ] ?? null;
			if ( ! $fighter ) { continue; }
			$fan_rank = ( $page - 1 ) * $per_page + $index + 1;
			$items[]  = $fighter + $row + array( 'fan_rank' => $fan_rank, 'board_rank' => $official[ $row['fighter_id'] ], 'difference' => $official[ $row['fighter_id'] ] - $fan_rank );
		}
		return array( 'board' => $board, 'lists' => $standings['lists'], 'total' => count( $rows ), 'page' => $page, 'per_page' => $per_page, 'total_pages' => $pages, 'items' => $items );
	}

	/**
	 * @param int[] $fighter_ids
	 * @return array<int,array{rank:int,points:int,picks:int,firsts:int}> Fighters without a counted pick are absent.
	 */
	public function scores( array $fighter_ids ): array {
		$wanted = array_flip( array_map( 'intval', $fighter_ids ) );
		$scores = array();
		foreach ( $this->standings()['rows'] as $index => $row ) {
			if ( isset( $wanted[ $row['fighter_id'] ] ) ) { $scores[ $row['fighter_id'] ] = array( 'rank' => $index + 1, 'points' => $row['points'], 'picks' => $row['picks'], 'firsts' => $row['firsts'] ); }
		}
		return $scores;
	}

	/**
	 * What a list or the board shows for each fighter, from the same public card data as the fighter directory.
	 *
	 * @param int[] $fighter_ids
	 * @return array<int,array<string,mixed>> Keyed by fighter id; fighters that are no longer public are absent.
	 */
	public function fighters( array $fighter_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $fighter_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$table = CanonicalSchema::table_names()['fighters'];
		$posts = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name; the ids are integers.
		foreach ( $wpdb->get_results( "SELECT id,wp_post_id FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) ?: array() as $row ) {
			if ( (int) $row['wp_post_id'] > 0 ) { $posts[ (int) $row['wp_post_id'] ] = (int) $row['id']; }
		}
		$out = array();
		foreach ( $posts ? ( new PublicReadService() )->fighter_cards( array_keys( $posts ) ) : array() as $card ) {
			$fighter = $card['fighter'];
			$stats   = $card['stats'] ?? null;
			$rank    = isset( $card['rankings'][0]['rank'] ) ? (int) $card['rankings'][0]['rank'] : null;
			if ( empty( $card['profile']['permalink'] ) ) { continue; }
			$out[ (int) $fighter['id'] ] = array(
				'fighter_id'    => (int) $fighter['id'],
				'display_name'  => (string) $fighter['display_name'],
				'nickname'      => (string) ( $fighter['nickname'] ?? '' ),
				'permalink'     => (string) $card['profile']['permalink'],
				'image_url'     => (string) ( $card['profile']['image']['url'] ?? '' ),
				'weight_class'  => (string) ( $fighter['weight_class'] ?? '' ),
				'nationality'   => (string) ( $fighter['nationality'] ?? '' ),
				'record'        => $stats ? $stats['wins'] . '-' . $stats['losses'] . ( $stats['draws'] ? '-' . $stats['draws'] : '' ) : '',
				'official_rank' => $rank,
				'eligible'      => null !== $rank,
				'in_ufc'        => ! empty( $fighter['in_ufc'] ),
			);
		}
		return $out;
	}

	/** @return array<int,int> Fighter id => rank on the official board, exactly as the public ranking numbers them. */
	public static function official( string $board ): array {
		$run = ( new RankingRunRepository() )->get_active_ranking_run_id();
		return $run ? ( new PublicReadRepository() )->rank_map( (int) $run, $board ) : array();
	}
}
