<?php
/**
 * Plugin Name:       Social Proof for HivePress
 * Plugin URI:        https://github.com/irapidchris-del/social-proof-for-hivepress
 * Description:       Live, highly customisable social-proof toast popups for HivePress marketplaces: recent sign-ups, listings, bookings, reviews, sales and more.
 * Version:           1.3.7
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  hivepress
 * Author:            ChrisB @ HivePress Community
 * Author URI:        https://community.hivepress.io/u/chrisb/summary
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-proof-for-hivepress
 * Domain Path:       /languages/
 * Update URI:        https://github.com/irapidchris-del/social-proof-for-hivepress
 *
 * @package Social_Proof_For_HivePress
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HPSP_VERSION', '1.3.7' );
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
 *
 * No load_plugin_textdomain() call: like HivePress core and every official
 * extension, translations load just in time from the Text Domain header, and
 * users translate into wp-content/languages/plugins via Loco Translate.
 */
add_action(
	'plugins_loaded',
	function () {
		Hpsp_Events::init();
		Hpsp_Rest::init();
		Hpsp_Frontend::init();

		if ( is_admin() ) {
			Hpsp_Admin::init();

			// One-off upgrade pass, version-gated so it costs one option read.
			if ( version_compare( (string) get_option( 'hpsp_db_version', '0' ), HPSP_VERSION, '<' ) ) {
				Hpsp_Settings::upgrade();
				update_option( 'hpsp_db_version', HPSP_VERSION, false );
			}
		}

		// Native GitHub updater. Registering its filters is cheap; the GitHub API
		// is only queried when WordPress actually runs an update check.
		Hpsp_Updater::init();
	}
);

/**
 * Donate link on the Plugins-screen row. House spec: fixed label and icon.
 */
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="https://ko-fi.com/chrisbathivepresscommunity" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'social-proof-for-hivepress' )
			. '</a>';

		return $links;
	},
	10,
	2
);

/**
 * Activation: seed defaults and schedule the queue cleanup task.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( Hpsp_Settings::OPTION_KEY, false ) ) {
			add_option( Hpsp_Settings::OPTION_KEY, Hpsp_Settings::defaults(), '', false );
		}

		if ( ! wp_next_scheduled( 'hpsp_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'hpsp_cleanup' );
		}
	}
);

/**
 * Deactivation: unschedule the cleanup task. Deactivating loses nothing else.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'hpsp_cleanup' );
	}
);
