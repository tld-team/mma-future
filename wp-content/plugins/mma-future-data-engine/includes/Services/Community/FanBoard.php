<?php
namespace MMAF\DataEngine\Services\Community;

use MMAF\DataEngine\Services\PublicReadService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Where the fan ranking shows up on public pages: the "Fans" view of the rankings page, the Official / Fans switch,
 * and the fan-rank tag on a fighter's page. The theme calls these three methods behind class_exists(); each returns
 * nothing while the fan area is off, so the official ranking renders exactly as it did.
 */
final class FanBoard {
	public const QUERY_VAR  = 'mmaf_ranking';
	public const BOARD_VAR  = 'mmaf_fan_board';
	public const PAGE_VAR   = 'mmaf_fan_page';
	private const PER_PAGE  = 25;
	/** The same tag style as the fighter hero's own "#N Overall" tag, so no extra stylesheet loads on fighter pages. */
	private const CHIP_CLASS = 'inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-white/10 border border-white/20 text-white/90 text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm';

	/** True when this request asks for the fan view and the fan area is on. */
	public static function requested(): bool {
		return 'fans' === sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ?? '' ) ) && Config::area_enabled( 'fan' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Standings change with every saved list, so the fan view is never served from a page cache. Hooked on template_redirect. */
	public static function no_cache(): void {
		if ( ! self::requested() ) { return; }
		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( function_exists( 'wpfc_exclude_current_page' ) ) { wpfc_exclude_current_page(); }
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		do_action( 'litespeed_control_set_nocache', 'MMA Future fan ranking' );
	}

	/** The Official / Fans switch. Printed on both views; empty while the fan area is off. */
	public static function source_toggle( string $current ): string {
		if ( ! Config::area_enabled( 'fan' ) ) { return ''; }
		wp_enqueue_style( 'mmaf-community' ); // Late on purpose: covers a rankings block the early has_block() check cannot see.
		$html = '<nav class="mmafc-source" aria-label="Ranking source">';
		foreach ( array( 'official' => 'Official', 'fans' => 'Fans' ) as $key => $label ) {
			$active = $key === $current;
			$html  .= '<a class="mmafc-source__link' . ( $active ? ' is-current' : '' ) . '" href="' . esc_url( self::url( 'fans' === $key ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** The whole fan view, in place of the official rankings section. */
	public static function render(): void {
		$labels = PublicReadService::board_labels();
		$board  = PublicReadService::board( sanitize_text_field( wp_unslash( $_GET[ self::BOARD_VAR ] ?? FanRanking::BOARD ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$board  = isset( $labels[ $board ] ) ? $board : FanRanking::BOARD;
		$data   = ( new FanRanking() )->board( $board, max( 1, absint( $_GET[ self::PAGE_VAR ] ?? 1 ) ), self::PER_PAGE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_style( 'mmaf-community' );
		include MMAF_PLUGIN_DIR . 'templates/community-fan-board.php';
	}

	/**
	 * A hidden placeholder on the fighter hero. Fighter pages are page-cached and the score moves with every saved list,
	 * so the script fills it in from the public scores route and reveals it only when the fighter has fan points.
	 */
	public static function chip( int $fighter_id ): string {
		if ( $fighter_id < 1 || ! Config::area_enabled( 'fan' ) ) { return ''; }
		// The classes are applied by the script when it reveals the tag: a display utility on the element would defeat `hidden`.
		return '<a href="' . esc_url( self::url( true ) ) . '" data-mmafc-fan-chip="' . esc_attr( (string) $fighter_id ) . '" data-mmafc-class="' . esc_attr( self::CHIP_CLASS ) . '" hidden></a>';
	}

	/** @param array<string,scalar> $args Board and page for the fan view. */
	public static function url( bool $fans, array $args = array() ): string {
		$base = function_exists( 'mmaf_rankings_url' ) ? (string) mmaf_rankings_url() : home_url( '/rankings/' );
		return $fans ? add_query_arg( array( self::QUERY_VAR => 'fans' ) + $args, $base ) : $base;
	}

	public static function editor_url(): string {
		return home_url( '/account/top-10/' );
	}
}
