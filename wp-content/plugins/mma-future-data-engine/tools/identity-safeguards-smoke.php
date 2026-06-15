<?php
/**
 * Read-only smoke checks for manual fighter identity safeguards.
 *
 * Usage: php tools/identity-safeguards-smoke.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require_once __DIR__ . '/bootstrap-wp.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;
use MMAF\DataEngine\Support\TapologyFighterUrl;

global $wpdb;

$tables = Schema::table_names();
$checks = array();

$parsed = TapologyFighterUrl::parse( 'http://tapology.com/fightcenter/fighters/12345-Test-Fighter?x=1' );
$checks['tapology_parse'] = is_array( $parsed )
	&& 'tapology_fighter_12345' === $parsed['source_fighter_id']
	&& 'https://www.tapology.com/fightcenter/fighters/12345-test-fighter' === $parsed['source_url'];

$source_row = $wpdb->get_row(
	"SELECT * FROM {$tables['fighter_sources']} WHERE source_type = 'tapology' AND source_url IS NOT NULL AND source_url <> '' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);
$checks['existing_tapology_url_lookup'] = false;
if ( $source_row ) {
	$matches = ( new FighterSourceRepository() )->find_by_normalized_source_url( 'tapology', (string) $source_row['source_url'] );
	$checks['existing_tapology_url_lookup'] = 1 === count(
		array_unique(
			array_map(
				static fn( array $row ): int => (int) $row['fighter_id'],
				$matches
			)
		)
	);
}

$fighter = $wpdb->get_row(
	"SELECT * FROM {$tables['fighters']} WHERE normalized_name IS NOT NULL AND normalized_name <> '' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);
wp_set_current_user( 1 );

$_GET = array(
	'page'   => 'mmaf-fighters',
	'action' => 'new',
);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
\MMAF\DataEngine\Admin\FightersPage::render();
$fighters_html = ob_get_clean();
$checks['fighters_new_render'] = false !== strpos( $fighters_html, 'name="source_url"' )
	&& false !== strpos( $fighters_html, 'Tapology Profile URL' )
	&& false !== strpos( $fighters_html, 'required' );

$_GET = array(
	'page' => 'mmaf-data-audit',
	'tab'  => 'identity_safeguards',
);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
\MMAF\DataEngine\Admin\DataAuditPage::render();
$identity_html = ob_get_clean();
$checks['identity_audit_render'] = false !== strpos( $identity_html, 'Manual Fighter Identity Safeguards' );

if ( $fighter ) {
	$_GET = array(
		'page'   => 'mmaf-fighters',
		'action' => 'new',
	);
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array(
		'mmaf_fighter_nonce'  => wp_create_nonce( 'mmaf_save_fighter' ),
		'mmaf_action'         => 'create',
		'display_name'        => (string) $fighter['display_name'],
		'weight_class'        => 'unknown',
		'status'              => 'provisional',
		'rankability_status'  => 'pending_review',
	);
	$_REQUEST = $_POST;
	ob_start();
	\MMAF\DataEngine\Admin\FightersPage::render();
	$missing_url_html = ob_get_clean();
	$checks['manual_save_requires_tapology_url'] = false !== strpos( $missing_url_html, 'Tapology Profile URL is required before a fighter can be saved manually.' );
}

$results_path = dirname( __DIR__, 6 ) . '/scraper/data/latest/results.json';
if ( is_file( $results_path ) ) {
	$dry_run = ( new ScraperJsonDryRunService() )->analyze_file( $results_path, 0, false );
	$checks['import_dry_run_read_only_valid'] = ! empty( $dry_run['is_valid'] ) && ! empty( $dry_run['summary']['dry_run_only'] );
}

$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );

echo wp_json_encode(
	array(
		'ok'     => empty( $failed ),
		'checks' => $checks,
		'failed' => $failed,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
