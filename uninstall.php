<?php
/**
 * Uninstall cleanup: remove all plugin data.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hpsp_settings' );
delete_option( 'hpsp_event_queue' );
delete_transient( 'hpsp_events_payload' );

// Per-user opt-out flags.
delete_metadata( 'user', 0, 'hp_spf_hide_popups', '', true );

// Scheduled cleanup task.
wp_clear_scheduled_hook( 'hpsp_cleanup' );
