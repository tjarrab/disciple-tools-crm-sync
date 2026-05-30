<?php
/**
 * Manual Contact Import tab class for Disciple.Tools - CRM Sync.
 *
 * @package Disciple_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tab 2: Importer SPA — React mount point

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Tab_Importer' ) ) {
    /**
     * Manual Contact Import tab — React SPA mount point.
     *
     * Renders the #dt-crm-sync-importer-app container. The React application
     * (dist/importer-app.js) is enqueued by Disciple_Tools_CRM_Sync_Menu and
     * mounts into this element on page load.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Tab_Importer {
        /**
         * Render the React SPA mount point.
         *
         * The compiled dist/importer-app.js is enqueued by the menu class and
         * mounts into #dt-crm-sync-importer-app on page load.
         */
        public function content(): void {
            ?>
            <div id="dt-crm-sync-importer-root">
                <p class="description">
                    <?php esc_html_e( 'Loading importer…', 'disciple-tools-crm-sync' ); ?>
                </p>
            </div>
            <?php
        }
    }
}
