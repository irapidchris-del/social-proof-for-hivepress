<?php
/**
 * Uninstall cleanup.
 *
 * Retains the owner's settings and preferences by default so an accidental
 * delete, or a delete-and-reinstall, loses nothing. Everything is removed only
 * when the "Delete all data" box on the settings screen was ticked. Runtime
 * junk that regenerates itself (the event queue, caches, the scheduled task)
 * is always cleared, because it would otherwise linger for a plugin that no
 * longer exists.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Regenerable runtime data: always removed, whatever the setting says.
delete_option( 'hpsp_event_queue' );
delete_option( 'hpsp_db_version' );
delete_transient( 'hpsp_events_payload' );
delete_site_transient( 'hpsp_github_release' );
wp_clear_scheduled_hook( 'hpsp_cleanup' );

$hpsp_settings = get_option( 'hpsp_settings' );

if ( ! is_array( $hpsp_settings ) || empty( $hpsp_settings['delete_data'] ) ) {
	return;
}

// The owner ticked "Delete all data": remove every trace.
// Per-user opt-out flags.
delete_metadata( 'user', 0, 'hp_spf_hide_popups', '', true );

// The settings option carries the delete_data flag itself, so it goes last:
// if anything above fails part-way, the flag survives and a second delete
// finishes the job instead of silently reverting to "retain".
delete_option( 'hpsp_settings' );
