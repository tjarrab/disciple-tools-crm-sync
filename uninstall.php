<?php
/**
 * Uninstall routine for Disciple.Tools - CRM Sync.
 *
 * WordPress calls this file when the user chooses "Delete" from the Plugins
 * screen. All options, cron hooks, and (optionally) contact metadata are
 * removed. The encryption key is intentionally retained so that
 * re-activating the plugin can decrypt previously stored credentials without
 * requiring the admin to re-enter the API key.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Clear all scheduled cron hooks

wp_clear_scheduled_hook( 'dt_crm_sync_process_batch' );

// Clear all scheduled instances of the unified poll hook (one entry per saved filter_id).
wp_clear_scheduled_hook( 'dt_crm_sync_poll' );

$dt_crm_sync_filter_manifest = get_option( 'dt_crm_sync_saved_filters', [] );
if ( is_array( $dt_crm_sync_filter_manifest ) ) {
    foreach ( $dt_crm_sync_filter_manifest as $dt_crm_sync_filter_id ) {
        $dt_crm_sync_filter_id = sanitize_key( $dt_crm_sync_filter_id );
        delete_option( 'dt_crm_sync_saved_filter_' . $dt_crm_sync_filter_id );
        // Also clear the legacy per-filter hook format for installs that pre-date the unified hook.
        wp_clear_scheduled_hook( 'dt_crm_sync_poll_' . $dt_crm_sync_filter_id );
    }
}

// Read settings before deleting them
// Must be done BEFORE any delete_option() calls, otherwise purge_on_uninstall
// is always empty because the option has already been removed.

$dt_crm_sync_settings = get_option( 'dt_crm_sync_settings', [] );

// Delete plugin options

delete_option( 'dt_crm_sync_saved_filters' );
delete_option( 'dt_crm_sync_settings' );
delete_option( 'dt_crm_sync_field_mapping' );
// Transient key: Disciple_Tools_CRM_Sync_API_Client::FIELD_SCHEMA_TRANSIENT
// (String literal used directly — uninstall.php runs standalone without plugin classes loaded.)
delete_transient( 'dt_crm_sync_field_schema_respond_io' );

// Intentionally NOT deleting dt_crm_sync_encryption_key — see file docblock.

// Drop log table

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup; caching is inappropriate for destructive DDL operations.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dt_crm_sync_logs' );

// Optional: purge contact metadata

if ( ! empty( $dt_crm_sync_settings['purge_on_uninstall'] ) ) {
    delete_post_meta_by_key( '_respond_io_id' );
    delete_post_meta_by_key( '_respond_io_merged_ids' );
    delete_post_meta_by_key( '_respond_io_last_sync' );
    delete_post_meta_by_key( '_respond_io_notes_comment_id' );

    // _respond_io_message_id is comment meta, NOT post meta.
    // delete_post_meta_by_key() only touches wp_postmeta and would silently
    // do nothing here. Use a direct wpdb delete on wp_commentmeta instead.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup; caching is inappropriate for destructive operations.
    $wpdb->delete(
        $wpdb->commentmeta,
        [ 'meta_key' => '_respond_io_message_id' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup; performance is not a concern here.
        [ '%s' ]
    );
}
