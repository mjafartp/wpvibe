<?php
/**
 * Fired when the plugin is uninstalled.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'wpvibe_theme_versions',
	$wpdb->prefix . 'wpvibe_messages',
	$wpdb->prefix . 'wpvibe_sessions',
);

foreach ( $tables as $table ) {
	// Table names come from the hardcoded whitelist above + $wpdb->prefix,
	// so they are safe. Use esc_sql as defense-in-depth.
	$safe_table = esc_sql( $table );
	$wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all plugin options using prepared statement.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'wpvibe\_%'
	)
);

// Optionally remove upload directories.
$upload_dir = wp_upload_dir();
$vb_dir     = $upload_dir['basedir'] . '/wpvibe';

if ( is_dir( $vb_dir ) ) {
	// Recursive delete.
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vb_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}

	rmdir( $vb_dir );
}
