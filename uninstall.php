<?php
/**
 * Uninstall routine for Disciple.Tools - CRM Sync.
 *
 * WordPress calls this file when the user chooses "Delete" from the Plugins
 * screen. All options, cron hooks, transients, and (optionally) contact
 * metadata are removed. The encryption key is intentionally retained so that
 * re-activating the plugin can decrypt previously stored credentials without
 * requiring the admin to re-enter the API key.
 *
 * This file runs standalone — plugin classes are not loaded. All transient
 * keys are handled via a wildcard $wpdb query rather than per-key
 * delete_transient() calls so new connectors don't require edits here.
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
        delete_transient( 'dt_crm_sync_poll_lock_' . $dt_crm_sync_filter_id );
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

// Delete all plugin transients in one pass. Using a wildcard query handles the
// Respond.io field-schema cache, Gemini model list, Metricool conversation cache,
// and anything a future connector might add — without hardcoding individual keys.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup; caching is inappropriate for destructive operations.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_dt_crm_sync_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_dt_crm_sync_' ) . '%'
    )
);

// Intentionally NOT deleting dt_crm_sync_encryption_key — see file docblock.

// Drop log tables

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup; caching is inappropriate for destructive DDL operations.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dt_crm_sync_logs' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup; caching is inappropriate for destructive DDL operations.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dt_crm_sync_translation_logs' );

// Optional: purge contact metadata

if ( ! empty( $dt_crm_sync_settings['purge_on_uninstall'] ) ) {
    // Respond.io post meta
    delete_post_meta_by_key( '_respond_io_id' );
    delete_post_meta_by_key( '_respond_io_merged_ids' );
    delete_post_meta_by_key( '_respond_io_last_sync' );
    delete_post_meta_by_key( '_respond_io_notes_comment_id' );
    delete_post_meta_by_key( '_respond_io_message_log_comment_id' );

    // All connector comment meta uses a connector-prefix naming convention, so a
    // LIKE query covers both connectors and any keys either connector may add later
    // without requiring this file to be updated. delete_post_meta_by_key() only
    // touches wp_postmeta and can't be used here.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup; caching is inappropriate for destructive operations.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
            $wpdb->esc_like( '_respond_io_' ) . '%',
            $wpdb->esc_like( '_metricool_' ) . '%'
        )
    );

    // Metricool post meta
    delete_post_meta_by_key( '_metricool_id' );
    delete_post_meta_by_key( '_metricool_merged_ids' );
    delete_post_meta_by_key( '_metricool_last_sync' );
    delete_post_meta_by_key( '_metricool_notes_comment_id' );
    // Included for forwards-compatibility — written by the message importer if
    // Metricool gains message-history support.
    delete_post_meta_by_key( '_metricool_message_log_comment_id' );
}
