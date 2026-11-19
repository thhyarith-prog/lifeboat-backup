<?php
/**
 * Plugin Name:       Lifeboat Backup
 * Description:       Automatic backups to Google Drive, OneDrive, Dropbox, Box and MEGA. Daily, weekly or monthly schedules, automatic backup after updates, optional AES-256 encryption, and a background engine with Easy mode (gentle on your site) or Rush mode (as fast as possible).
 * Version:           1.0.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/thhyarith-prog/lifeboat-backup
 * Author:            Prasat Digital
 * Author URI:        https://www.prasatdigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lifeboat-backup
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'LIFEBOAT_VERSION', '1.0.7' );
define( 'LIFEBOAT_FILE', __FILE__ );
define( 'LIFEBOAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIFEBOAT_URL', plugin_dir_url( __FILE__ ) );

// Updates come from the GitHub releases of this plugin.
require_once LIFEBOAT_DIR . 'includes/prasat-updater.php';
Prasat_Updater::register( __FILE__, 'thhyarith-prog/lifeboat-backup' );

/*
 * Publisher setting: address of your sign-in relay (see the lifeboat-relay project).
 * With it, Google Drive / OneDrive / Dropbox / Box connect with one click on every site.
 * Sites can override it under Schedule & settings → Advanced, or in wp-config.php.
 */
if ( ! defined( 'LIFEBOAT_RELAY_URL' ) ) {
	define( 'LIFEBOAT_RELAY_URL', 'https://lifeboat-relay.riththon.workers.dev' );
}

$lifeboat_files = array(
	'class-lifeboat-util',
	'class-lifeboat-settings',
	'class-lifeboat-crypto',
	'class-lifeboat-vault',
	'class-lifeboat-archive',
	'class-lifeboat-db',
	'storage/class-lifeboat-destination',
	'storage/class-lifeboat-oauth-destination',
	'storage/class-lifeboat-gdrive',
	'storage/class-lifeboat-dropbox',
	'storage/class-lifeboat-onedrive',
	'storage/class-lifeboat-box',
	'storage/class-lifeboat-mega',
	'class-lifeboat-history',
	'class-lifeboat-backup',
	'class-lifeboat-restore',
	'class-lifeboat-runner',
	'class-lifeboat-scheduler',
	'class-lifeboat-snapshots',
	'class-lifeboat-plugin',
);
foreach ( $lifeboat_files as $lifeboat_file ) {
	require_once LIFEBOAT_DIR . 'includes/' . $lifeboat_file . '.php';
}
unset( $lifeboat_files, $lifeboat_file );

if ( is_admin() || wp_doing_ajax() ) {
	require_once LIFEBOAT_DIR . 'includes/class-lifeboat-admin.php';
	require_once LIFEBOAT_DIR . 'includes/prasat-hub.php';
	Prasat_Hub::register( 'lifeboat', array( 'assets' => LIFEBOAT_URL . 'assets/', 'health' => array( 'Lifeboat_Admin', 'health' ), 'dashboard' => array( 'Lifeboat_Admin', 'dashboard' ) ) );
}

register_activation_hook( __FILE__, array( 'Lifeboat_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lifeboat_Plugin', 'deactivate' ) );

Lifeboat_Plugin::instance();
