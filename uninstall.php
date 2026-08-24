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

/*
 * The updater's other two site transients and its background job, which used to be left behind.
 *
 * All three are regenerable runtime state belonging to the update check, not the owner's
 * configuration, so they go unconditionally alongside the release cache above. Core's daily sweep
 * clears expired site transients within about a day on single-site, which is why this read as
 * harmless; on multisite they live in wp_sitemeta and are only purged when something asks for
 * them, so on a network they simply stay. The scheduled refresh is worse than debris: it is a job
 * whose callback no longer exists.
 *
 * Unscheduled from both places it can be, because the refresh is queued through HivePress's
 * scheduler (Action Scheduler) when HivePress is present and through WP-Cron when it is not.
 */
delete_site_transient( 'hpsp_github_release_reason' );
delete_site_transient( 'hpsp_github_release_rate_limit' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hpsp_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'hpsp_github_release_refresh' );
}

wp_clear_scheduled_hook( 'hpsp_github_release_refresh' );
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
