<?php
/**
 * Plugin configuration — repository-specific values.
 *
 * DT_CRM_SYNC_GITHUB_ORG and DT_CRM_SYNC_GITHUB_REPO are used by the Plugin
 * Update Checker to locate version-control.json and deliver automatic update
 * notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// GitHub repository (plugin update checker)

define( 'DT_CRM_SYNC_GITHUB_ORG', 'tjarrab' );
define( 'DT_CRM_SYNC_GITHUB_REPO', 'disciple-tools-crm-sync' );
