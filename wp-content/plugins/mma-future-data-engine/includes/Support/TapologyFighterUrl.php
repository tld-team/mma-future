<?php
namespace MMAF\DataEngine\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TapologyFighterUrl {
	public static function parse( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, array( 'tapology.com', 'www.tapology.com' ), true ) ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( ! preg_match( '~^/fightcenter/fighters/([^/?#]+)/*$~i', $path, $matches ) ) {
			return null;
		}

		$path_slug   = sanitize_title( (string) $matches[1] );
		$numeric_id  = null;
		$source_slug = $path_slug;

		if ( '' === $path_slug ) {
			return null;
		}

		if ( preg_match( '~^(\d+)-(.+)$~', $path_slug, $slug_matches ) ) {
			$numeric_id  = (string) $slug_matches[1];
			$source_slug = sanitize_title( (string) $slug_matches[2] );
		}

		$normalized_url = 'https://www.tapology.com/fightcenter/fighters/' . $path_slug;

		return array(
			'canonical_url'     => $normalized_url,
			'source_url_hash'   => hash( 'sha256', $normalized_url ),
			'source_fighter_id' => null !== $numeric_id ? 'tapology_fighter_' . $numeric_id : null,
			'source_numeric_id' => $numeric_id,
			'source_slug'       => $source_slug,
			'source_url'        => $normalized_url,
			'normalized_url'    => $normalized_url,
			'is_valid'          => true,
		);
	}

	public static function source_url_hash( string $url ): ?string {
		$tapology = self::parse( $url );
		if ( ! $tapology ) {
			return null;
		}

		return (string) $tapology['source_url_hash'];
	}

	public static function normalize( string $url ): string {
		$tapology = self::parse( $url );
		if ( $tapology ) {
			return $tapology['normalized_url'];
		}

		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return rtrim( strtolower( $url ), '/' );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host   = strtolower( (string) $parts['host'] );
		$path   = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';

		return $scheme . '://' . $host . $path;
	}

	public static function is_tapology_fighter_url( string $url ): bool {
		return null !== self::parse( $url );
	}
}
