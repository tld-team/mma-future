<?php
/**
 * Read-only verification of TapologyFighterUrl normalizer.
 *
 * Usage: php tools/verify-tapology-url-normalizer.php
 *
 * Exits 0 when all cases pass; 1 otherwise. Performs no DB writes.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Support\TapologyFighterUrl;

$cases = array();

$numeric = TapologyFighterUrl::parse( 'https://www.tapology.com/fightcenter/fighters/12345-some-fighter' );
$cases['numeric_valid'] = (
	is_array( $numeric )
	&& true === $numeric['is_valid']
	&& 'https://www.tapology.com/fightcenter/fighters/12345-some-fighter' === $numeric['canonical_url']
	&& 'tapology_fighter_12345' === $numeric['source_fighter_id']
	&& ! empty( $numeric['source_url_hash'] )
);

$slug = TapologyFighterUrl::parse( 'https://www.tapology.com/fightcenter/fighters/rin-nakai' );
$cases['slug_only_valid'] = (
	is_array( $slug )
	&& true === $slug['is_valid']
	&& 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $slug['canonical_url']
	&& null === $slug['source_fighter_id']
	&& ! empty( $slug['source_url_hash'] )
);

$query = TapologyFighterUrl::parse( 'https://www.tapology.com/fightcenter/fighters/rin-nakai?utm=x' );
$cases['query_stripped'] = is_array( $query ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $query['canonical_url'];

$fragment = TapologyFighterUrl::parse( 'https://www.tapology.com/fightcenter/fighters/rin-nakai#bio' );
$cases['fragment_stripped'] = is_array( $fragment ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $fragment['canonical_url'];

$trailing = TapologyFighterUrl::parse( 'https://www.tapology.com/fightcenter/fighters/rin-nakai/' );
$cases['trailing_slash_stripped'] = is_array( $trailing ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $trailing['canonical_url'];

$upper_host = TapologyFighterUrl::parse( 'https://www.TAPOLOGY.com/fightcenter/fighters/rin-nakai' );
$cases['host_lowercased'] = is_array( $upper_host ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $upper_host['canonical_url'];

$bare_host = TapologyFighterUrl::parse( 'https://tapology.com/fightcenter/fighters/rin-nakai' );
$cases['bare_host_accepted'] = is_array( $bare_host ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $bare_host['canonical_url'];

$hash_stable = (
	TapologyFighterUrl::source_url_hash( 'https://www.tapology.com/fightcenter/fighters/rin-nakai/' )
	=== TapologyFighterUrl::source_url_hash( 'https://www.TAPOLOGY.com/fightcenter/fighters/rin-nakai/?utm=1#bio' )
);
$cases['hash_stable_across_variants'] = (bool) $hash_stable;

$non_tap = TapologyFighterUrl::parse( 'https://example.com/foo' );
$cases['non_tapology_rejected'] = null === $non_tap;

$empty_path = TapologyFighterUrl::parse( 'https://www.tapology.com/' );
$cases['non_fighter_path_rejected'] = null === $empty_path;

$invalid_scheme = TapologyFighterUrl::parse( 'http://tapology.com/fightcenter/fighters/rin-nakai' );
$cases['http_scheme_accepted_normalized_to_https'] = is_array( $invalid_scheme ) && 'https://www.tapology.com/fightcenter/fighters/rin-nakai' === $invalid_scheme['canonical_url'];

$failed = array_keys( array_filter( $cases, static fn( bool $v ): bool => ! $v ) );

echo wp_json_encode(
	array(
		'ok' => empty( $failed ),
		'cases' => $cases,
		'failed' => $failed,
		'numeric' => $numeric,
		'slug' => $slug,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
