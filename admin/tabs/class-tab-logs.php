<?php
/**
 * Sync Logs tab class for Disciple.Tools - CRM Sync.
 *
 * @package Disciple_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tab 4: Sync Logs

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Tab_Logs' ) ) {
    /**
     * Sync Logs tab — paginated view into the dt_crm_sync_logs table.
     *
     * Supports filtering by status and provides a clear-logs action. Direct
     * database queries are used here because the logs table is not a WP post
     * type and has no caching layer.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Tab_Logs {

        private const PER_PAGE = 25;

        /**
         * Handle the log-clear POST and render the paginated sync log table.
         *
         * Supports status filtering via $_GET['log_status']. Queries the
         * dt_crm_sync_logs table directly — it is not a WP post type.
         */
        public function content(): void {
            global $wpdb;

            $notice = '';
            $table  = $wpdb->prefix . 'dt_crm_sync_logs';

// POST handler — must run before any HTML output
            // Dispatch on nonce-field presence so nonce verification runs before
            // any $_POST value is used to drive the code path.
            if ( isset( $_POST['dt_crm_sync_clear_logs_nonce'] ) ) {
                check_admin_referer( 'dt_crm_sync_clear_logs', 'dt_crm_sync_clear_logs_nonce' );

                if ( ! current_user_can( 'manage_dt' ) ) {
                    wp_die( esc_html__( 'You do not have permission to perform this action.', 'disciple-tools-crm-sync' ) );
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted constant built from $wpdb->prefix.
                $wpdb->query( "DELETE FROM `{$table}`" );
                $notice = 'logs_cleared';
            }

            $valid_statuses = [ 'success', 'failed', 'merged', 'skipped' ];
            $status_filter  = sanitize_key( wp_unslash( $_GET['log_status'] ?? '' ) );
            if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
                $status_filter = '';
            }

            $paged  = max( 1, absint( wp_unslash( $_GET['paged'] ?? '1' ) ) );
            $offset = ( $paged - 1 ) * self::PER_PAGE;

// Queries ($table is a trusted constant, not user input)
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Logs tab queries; $table is a trusted constant from $wpdb->prefix, caching would return stale data.
            if ( '' !== $status_filter ) {
                $rows  = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `status` = %s ORDER BY `created_at` DESC LIMIT %d OFFSET %d",
                    $status_filter, self::PER_PAGE, $offset
                ) );
                $total = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE `status` = %s",
                    $status_filter
                ) );
            } else {
                $rows  = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` ORDER BY `created_at` DESC LIMIT %d OFFSET %d",
                    self::PER_PAGE, $offset
                ) );
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}`" ) );
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            $total_pages = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;

            // Preserve status filter across paginator clicks.
            $pager_args = [ 'page' => 'disciple-tools-crm-sync', 'tab' => 'logs' ];
            if ( '' !== $status_filter ) {
                $pager_args['log_status'] = $status_filter;
            }
            $pager_base = add_query_arg( $pager_args, admin_url( 'admin.php' ) );

// Admin notices
            if ( 'logs_cleared' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__( 'All sync logs have been cleared.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            }

// Clear All Logs button
            ?>
            <div style="margin-bottom: 12px; text-align: right;">
                <form method="post">
                    <?php wp_nonce_field( 'dt_crm_sync_clear_logs', 'dt_crm_sync_clear_logs_nonce' ); ?>
                    <input type="hidden" name="action" value="clear_logs">
                    <?php submit_button(
                        __( 'Clear All Logs', 'disciple-tools-crm-sync' ),
                        'secondary',
                        'clear_logs_btn',
                        false,
                        [ 'onclick' => "return confirm('" . esc_js( __( 'This will permanently delete all sync log entries. This cannot be undone. Continue?', 'disciple-tools-crm-sync' ) ) . "')" ]
                    ); ?>
                </form>
            </div>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 12px;">
                <input type="hidden" name="page" value="disciple-tools-crm-sync">
                <input type="hidden" name="tab"  value="logs">
                <label for="dt_rio_log_status">
                    <?php esc_html_e( 'Status:', 'disciple-tools-crm-sync' ); ?>
                </label>
                <select id="dt_rio_log_status" name="log_status">
                    <option value=""><?php esc_html_e( 'All', 'disciple-tools-crm-sync' ); ?></option>
                    <?php
                    $status_labels = [
                        'success' => __( 'Success', 'disciple-tools-crm-sync' ),
                        'failed'  => __( 'Failed', 'disciple-tools-crm-sync' ),
                        'merged'  => __( 'Merged', 'disciple-tools-crm-sync' ),
                        'skipped' => __( 'Skipped', 'disciple-tools-crm-sync' ),
                    ];
                    foreach ( $valid_statuses as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
                            <?php echo esc_html( $status_labels[ $s ] ?? ucfirst( $s ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'disciple-tools-crm-sync' ), 'secondary small', '', false ); ?>
            </form>

            <p><?php printf(
                /* translators: %d: total number of log entries */
                esc_html__( '%d entries found.', 'disciple-tools-crm-sync' ),
                (int) $total
            ); /* phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- false positive in mixed PHP/HTML template */ ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Timestamp (UTC)', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'Trigger', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'Respond ID', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'DT Contact', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'disciple-tools-crm-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No log entries found.', 'disciple-tools-crm-sync' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) :
                            $dt_post_id = (int) $row->dt_post_id;
                            $dt_link    = $dt_post_id > 0 ? get_permalink( $dt_post_id ) : false;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $row->created_at ); ?></td>
                                <td><?php echo esc_html( $row->trigger_type ); ?></td>
                                <td><?php echo esc_html( $row->respond_id ); ?></td>
                                <td>
                                    <?php if ( $dt_post_id > 0 && $dt_link ) : ?>
                                        <a href="<?php echo esc_url( $dt_link ); ?>"><?php echo esc_html( (string) $dt_post_id ); ?></a>
                                    <?php elseif ( $dt_post_id > 0 ) : ?>
                                        <?php echo esc_html( (string) $dt_post_id ); ?>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $row->status ); ?></td>
                                <td><?php echo esc_html( $row->message ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages" style="margin: 8px 0;">
                        <?php if ( $paged > 1 ) : ?>
                            <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $pager_base ) ); ?>">
                                &laquo; <?php esc_html_e( 'Previous', 'disciple-tools-crm-sync' ); ?>
                            </a>
                        <?php endif; ?>
                        <span class="displaying-num" style="margin: 0 8px;"><?php printf(
                            /* translators: 1: current page, 2: total pages */
                            esc_html__( 'Page %1$d of %2$d', 'disciple-tools-crm-sync' ),
                            (int) $paged, (int) $total_pages
                        ); /* phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- false positive in mixed PHP/HTML template */ ?></span>
                        <?php if ( $paged < $total_pages ) : ?>
                            <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $pager_base ) ); ?>">
                                <?php esc_html_e( 'Next', 'disciple-tools-crm-sync' ); ?> &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php
        }
    }
}
