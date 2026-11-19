<?php
/**
 * Uninstall: only removes data when the user opted in (backups are precious).
 *
 * @package Lifeboat_Backup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lifeboat_settings = get_option( 'lifeboat_settings', array() );
foreach ( array( 'lifeboat_tick', 'lifeboat_scheduled_full', 'lifeboat_scheduled_db', 'lifeboat_after_update' ) as $lifeboat_hook ) {
	wp_clear_scheduled_hook( $lifeboat_hook );
}
if ( empty( $lifeboat_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lifeboat\\_%'" ); // phpcs:ignore

foreach ( (array) glob( WP_CONTENT_DIR . '/lifeboat-backups-*', GLOB_ONLYDIR ) as $lifeboat_dir ) {
	$lifeboat_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $lifeboat_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $lifeboat_it as $lifeboat_f ) {
		$lifeboat_f->isDir() ? @rmdir( $lifeboat_f->getPathname() ) : @unlink( $lifeboat_f->getPathname() ); // phpcs:ignore
	}
	@rmdir( $lifeboat_dir ); // phpcs:ignore
}
