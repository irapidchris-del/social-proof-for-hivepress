<?php
/**
 * Plugin Name:       Social Proof for HivePress
 * Plugin URI:        https://github.com/irapidchris-del/social-proof-for-hivepress
 * Description:       Live, highly customisable social-proof toast popups for HivePress marketplaces — recent sign-ups, listings, bookings, reviews, sales and more.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Chris B.
 * Author URI:        https://community.hivepress.io/u/chrisb
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-proof-for-hivepress
 * Domain Path:       /languages
 * Update URI:        https://github.com/irapidchris-del/social-proof-for-hivepress
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HPSP_VERSION', '1.1.0' );
define( 'HPSP_FILE', __FILE__ );
define( 'HPSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'HPSP_URL', plugin_dir_url( __FILE__ ) );

require_once HPSP_DIR . 'includes/class-hpsp-settings.php';
require_once HPSP_DIR . 'includes/class-hpsp-events.php';
require_once HPSP_DIR . 'includes/class-hpsp-rest.php';
require_once HPSP_DIR . 'includes/class-hpsp-frontend.php';
require_once HPSP_DIR . 'includes/class-hpsp-admin.php';
require_once HPSP_DIR . 'includes/class-hpsp-updater.php';

/**
 * Boot the plugin once all plugins (including HivePress) are loaded.
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'social-proof-for-hivepress', false, dirname( plugin_basename( HPSP_FILE ) ) . '/languages' );

	HPSP_Events::init();
	HPSP_Rest::init();
	HPSP_Frontend::init();

	if ( is_admin() ) {
		HPSP_Admin::init();
	}

	// Native GitHub updater. Registering its filters is cheap; the GitHub API
	// is only queried when WordPress actually runs an update check.
	HPSP_Updater::init();
} );

/**
 * Activation: seed defaults and schedule the queue cleanup task.
 */
register_activation_hook( __FILE__, function () {
	if ( false === get_option( HPSP_Settings::OPTION_KEY, false ) ) {
		add_option( HPSP_Settings::OPTION_KEY, HPSP_Settings::defaults(), '', false );
	}

	if ( ! wp_next_scheduled( 'hpsp_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'hpsp_cleanup' );
	}
} );

/**
 * Deactivation: unschedule the cleanup task.
 */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'hpsp_cleanup' );
} );
