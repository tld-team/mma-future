<?php
/**
 * DESTRUCTIVE: Reset MMAF plugin-owned data only.
 *
 * Truncates all 18 wp_mmaf_* canonical/source/audit tables and deletes every
 * post of post_type=mmaf_fighter (with its postmeta and term_relationships).
 *
 * Leaves untouched: other post types, wp_options, media, users, theme,
 * plugin code, migrations, ACF data, comments.
 *
 * Usage:
 *   MMAF_RESET_CONFIRM=1 [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/reset-canonical-data.php
 *
 * Without MMAF_RESET_CONFIRM=1 this prints a dry-run plan and exits 0.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;

global $wpdb;

$tables = Schema::table_names();
$confirm = (string) getenv( 'MMAF_RESET_CONFIRM' );

$before_counts = array();
foreach ( $tables as $key => $table ) {
	$before_counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$before_counts['__wp_posts_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$before_counts['__wp_postmeta_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$before_counts['__wp_term_relationships_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

if ( '1' !== $confirm ) {
	echo wp_json_encode(
		array(
			'mode'           => 'dry_run',
			'message'        => 'Refusing to execute. Re-run with MMAF_RESET_CONFIRM=1 to apply.',
			'plan' => array(
				'truncate_tables'             => array_values( $tables ),
				'delete_post_type'            => 'mmaf_fighter',
				'delete_postmeta_for_post_type' => 'mmaf_fighter',
				'delete_term_relationships_for_post_type' => 'mmaf_fighter',
			),
			'before_counts'  => $before_counts,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 0 );
}

$errors = array();

// Delete term_relationships, postmeta, then posts in that order.
$wpdb->query(
	"DELETE tr FROM {$wpdb->term_relationships} tr
	INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
	WHERE p.post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$wpdb->query(
	"DELETE pm FROM {$wpdb->postmeta} pm
	INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	WHERE p.post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$wpdb->query(
	"DELETE FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

// Truncate MMAF tables.
foreach ( $tables as $key => $table ) {
	$ok = $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( false === $ok ) {
		$errors[] = "TRUNCATE failed: {$table} :: " . $wpdb->last_error;
	}
}

$after_counts = array();
foreach ( $tables as $key => $table ) {
	$after_counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$after_counts['__wp_posts_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$after_counts['__wp_postmeta_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$after_counts['__wp_term_relationships_mmaf_fighter'] = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

$schemas_present = array();
foreach ( $tables as $key => $table ) {
	$schemas_present[ $key ] = (bool) $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
	);
}

$all_zero = true;
foreach ( $after_counts as $n ) {
	if ( 0 !== (int) $n ) {
		$all_zero = false;
		break;
	}
}

echo wp_json_encode(
	array(
		'mode'             => 'applied',
		'all_zero'         => $all_zero,
		'errors'           => $errors,
		'before_counts'    => $before_counts,
		'after_counts'     => $after_counts,
		'schemas_present'  => $schemas_present,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( $all_zero && empty( $errors ) ? 0 : 1 );
