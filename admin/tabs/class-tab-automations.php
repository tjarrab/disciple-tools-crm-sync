<?php
/**
 * Automations tab class for Disciple.Tools - CRM Sync.
 *
 * @package Disciple_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tab 3: Automations (scheduled filter polling)

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Tab_Automations' ) ) {
    /**
     * Automations tab — create, schedule, and delete saved filter automations.
     *
     * Each saved filter maps a set of connector-defined filter parameters to a
     * WP-Cron schedule. Creating a filter calls Disciple_Tools_CRM_Sync::create_filter()
     * so that the cron scheduling logic is shared with the REST endpoint.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Tab_Automations {

        /**
         * Handle filter create/delete POSTs and render the automation management UI.
         *
         * Each saved filter maps a set of connector filter parameters to a cron
         * schedule. Creating a filter schedules the unified poll hook; deleting
         * one unschedules it and removes the saved option.
         */
        public function content(): void {
            $notice = '';

// POST handler — must run before any HTML output
            // Dispatch on nonce-field presence so nonce verification runs before
            // any $_POST value is used to drive the code path.
            if ( isset( $_POST['dt_crm_sync_automations_nonce'] ) ) {
                check_admin_referer( 'dt_crm_sync_save_filter', 'dt_crm_sync_automations_nonce' );

                if ( ! current_user_can( 'manage_dt' ) ) {
                    wp_die( esc_html__( 'You do not have permission to perform this action.', 'disciple-tools-crm-sync' ) );
                }

                $name      = sanitize_text_field( wp_unslash( $_POST['filter_name'] ?? '' ) );
                $interval  = sanitize_key( wp_unslash( $_POST['interval'] ?? 'daily' ) );
                $poll_time = sanitize_text_field( wp_unslash( $_POST['filter_poll_time'] ?? '00:00' ) );
                // Normalise poll_time: must be HH:MM 24-hour; fall back to midnight if malformed.
                if ( ! preg_match( '/^(0\d|1\d|2[0-3]):[0-5]\d$/', $poll_time ) ) {
                    $poll_time = '00:00';
                }

                $valid_intervals = [ 'hourly', 'every_2_hours', 'every_4_hours', 'every_8_hours', 'daily' ];

                if ( '' === $name ) {
                    $notice = 'filter_name_required';
                } elseif ( ! in_array( $interval, $valid_intervals, true ) ) {
                    $notice = 'invalid_interval';
                } else {
                    // Collect filter_params from connector-defined filter fields.
                    $active_connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();
                    $filter_params    = [];
                    if ( $active_connector ) {
                        foreach ( $active_connector->get_filter_fields() as $ff ) {
                            $slug = $ff['slug'] ?? '';
                            if ( $slug ) {
                                $filter_params[ $slug ] = sanitize_text_field( wp_unslash( $_POST[ 'filter_params_' . $slug ] ?? '' ) );
                            }
                        }
                    }
                    $filter_id = Disciple_Tools_CRM_Sync::create_filter( $name, $interval, $filter_params, $poll_time );
                    $notice    = 'filter_created';
                }
            } elseif ( isset( $_POST['dt_crm_sync_delete_nonce'] ) ) {
                // Nonce verification must precede any $_POST access.
                // The action is a fixed string so it does not depend on user-supplied input.
                check_admin_referer( 'dt_crm_sync_delete_filter', 'dt_crm_sync_delete_nonce' );

                if ( ! current_user_can( 'manage_dt' ) ) {
                    wp_die( esc_html__( 'You do not have permission to perform this action.', 'disciple-tools-crm-sync' ) );
                }

                $filter_id = sanitize_key( wp_unslash( $_POST['filter_id'] ?? '' ) );

                // Validate that the ID exists in the manifest before acting.
                $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
                $manifest = is_array( $manifest ) ? $manifest : [];
                if ( ! in_array( $filter_id, $manifest, true ) ) {
                    wp_die( esc_html__( 'Invalid filter ID.', 'disciple-tools-crm-sync' ) );
                }

                wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
                wp_clear_scheduled_hook( 'dt_crm_sync_poll_' . $filter_id ); // legacy
                delete_option( 'dt_crm_sync_saved_filter_' . $filter_id );

                $manifest = array_values( array_filter( $manifest, fn( $id ) => $id !== $filter_id ) );
                update_option( 'dt_crm_sync_saved_filters', $manifest );

                $notice = 'filter_deleted';
            }

// Admin notices
            if ( 'filter_created' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__( 'Filter saved and scheduled.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            } elseif ( 'filter_deleted' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__( 'Filter deleted.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            } elseif ( 'filter_name_required' === $notice ) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__( 'Filter name is required.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            } elseif ( 'invalid_interval' === $notice ) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__( 'Invalid schedule interval selected.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            }

// DISABLE_WP_CRON notice
            // Show when WordPress built-in pseudo-cron is active (unreliable on
            // low-traffic sites). The notice disappears once DISABLE_WP_CRON is
            // set to true in wp-config.php and a real system cron is configured.
            if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
                echo '<div class="notice notice-warning inline"><p>'
                    . esc_html__( 'Scheduled syncs may be delayed on low-traffic sites. For reliable scheduling, disable WordPress built-in cron (set DISABLE_WP_CRON in wp-config.php) and configure a real system cron job. See the README for instructions.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            }
            ?>

            <h2><?php esc_html_e( 'Scheduled Polling', 'disciple-tools-crm-sync' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Uses your API Access Token — no premium plan required. Each saved filter fetches all matching contacts from Respond.io on a recurring schedule and queues them for import.', 'disciple-tools-crm-sync' ); ?>
            </p>

            <?php
// Saved filter list
            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            $manifest = is_array( $manifest ) ? $manifest : [];

            if ( empty( $manifest ) ) {
                echo '<p>' . esc_html__( 'No saved filters yet.', 'disciple-tools-crm-sync' ) . '</p>';
            } else {
                ?>
                <table class="widefat striped" style="max-width: 800px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'disciple-tools-crm-sync' ); ?></th>
                            <th><?php esc_html_e( 'Interval', 'disciple-tools-crm-sync' ); ?></th>
                            <th><?php esc_html_e( 'Next Run', 'disciple-tools-crm-sync' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'disciple-tools-crm-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $manifest as $fid ) :
                            $fid = sanitize_key( $fid );
                            $raw = get_option( 'dt_crm_sync_saved_filter_' . $fid );
                            if ( ! $raw ) {
                                continue;
                            }
                            $envelope = json_decode( $raw, true );
                            if ( ! is_array( $envelope ) ) {
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( sprintf( 'dt-crm-sync: Saved filter "%s" contains invalid JSON and could not be loaded.', $fid ) );
                                }
                                continue;
                            }
                            $name = $envelope['name'] ?? $fid;
                            $interval = $envelope['interval'] ?? __( '—', 'disciple-tools-crm-sync' );
                            $next_ts  = wp_next_scheduled( 'dt_crm_sync_poll', [ $fid ] )
                                    ?: wp_next_scheduled( 'dt_crm_sync_poll_' . $fid ); // legacy fallback
                            ?>
                            <tr>
                                <td><?php echo esc_html( $name ); ?></td>
                                <td><?php echo esc_html( $interval ); ?></td>
                                <td>
                                    <?php if ( $next_ts ) : ?>
                                        <?php echo esc_html( wp_date( 'Y-m-d H:i', $next_ts ) ); ?>
                                    <?php else : ?>
                                        <em><?php esc_html_e( 'Not scheduled', 'disciple-tools-crm-sync' ); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <button type="button"
                                            class="button button-small dt-rio-run-now"
                                            data-filter-id="<?php echo esc_attr( $fid ); ?>"
                                            style="margin-right: 6px;">
                                        <?php esc_html_e( 'Run Now', 'disciple-tools-crm-sync' ); ?>
                                    </button>
                                    <span id="dt-rio-run-status-<?php echo esc_attr( $fid ); ?>"
                                            style="margin-right: 6px; font-size: 0.85em;"></span>
                                    <form method="post"
                                            action=""
                                            style="display: inline;"
                                            class="dt-crm-sync-delete-form">
                                        <?php wp_nonce_field( 'dt_crm_sync_delete_filter', 'dt_crm_sync_delete_nonce' ); ?>
                                        <input type="hidden" name="action"    value="delete_filter">
                                        <input type="hidden" name="filter_id" value="<?php echo esc_attr( $fid ); ?>">
                                        <input type="hidden" name="tab"       value="automations">
                                        <button type="submit" class="button button-small button-link-delete">
                                            <?php esc_html_e( 'Delete', 'disciple-tools-crm-sync' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }
            ?>

            <h3><?php esc_html_e( 'Add New Filter', 'disciple-tools-crm-sync' ); ?></h3>

            <form method="post" action="" style="max-width: 600px;">
                <?php wp_nonce_field( 'dt_crm_sync_save_filter', 'dt_crm_sync_automations_nonce' ); ?>
                <input type="hidden" name="action" value="save_filter">
                <input type="hidden" name="tab"    value="automations">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="dt_rio_filter_name">
                                <?php esc_html_e( 'Name', 'disciple-tools-crm-sync' ); ?>
                                <span aria-hidden="true" style="color:#d63638;">*</span>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                    id="dt_rio_filter_name"
                                    name="filter_name"
                                    class="regular-text"
                                    required>
                        </td>
                    </tr>
                    <?php
                    // Dynamic filter fields from the active connector.
                    $form_connector     = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();
                    $form_filter_fields = $form_connector ? $form_connector->get_filter_fields() : [];
                    foreach ( $form_filter_fields as $ff ) :
                        $ff_slug  = sanitize_key( $ff['slug'] ?? '' );
                        $ff_label = $ff['label'] ?? $ff_slug;
                        $ff_desc  = $ff['description'] ?? '';
                        $ff_group = sanitize_key( $ff['exclusive_group'] ?? '' );
                        if ( ! $ff_slug ) { continue; }
                        ?>
                        <tr>
                            <th scope="row">
                                <label for="dt_crm_filter_<?php echo esc_attr( $ff_slug ); ?>">
                                    <?php echo esc_html( $ff_label ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                        id="dt_crm_filter_<?php echo esc_attr( $ff_slug ); ?>"
                                        name="filter_params_<?php echo esc_attr( $ff_slug ); ?>"
                                        class="regular-text"
                                        <?php if ( $ff_group ) : ?>data-exclusive-group="<?php echo esc_attr( $ff_group ); ?>"<?php endif; ?>>
                                <?php if ( $ff_desc ) : ?>
                                    <p class="description"><?php echo esc_html( $ff_desc ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row">
                            <label for="dt_rio_filter_interval">
                                <?php esc_html_e( 'Interval', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="dt_rio_filter_interval" name="interval">
                                <option value="hourly"><?php esc_html_e( 'Hourly', 'disciple-tools-crm-sync' ); ?></option>
                                <option value="every_2_hours"><?php esc_html_e( 'Every 2 Hours', 'disciple-tools-crm-sync' ); ?></option>
                                <option value="every_4_hours"><?php esc_html_e( 'Every 4 Hours', 'disciple-tools-crm-sync' ); ?></option>
                                <option value="every_8_hours"><?php esc_html_e( 'Every 8 Hours', 'disciple-tools-crm-sync' ); ?></option>
                                <option value="daily" selected><?php esc_html_e( 'Daily', 'disciple-tools-crm-sync' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="dt_rio_poll_time_row">
                        <th scope="row">
                            <label for="dt_rio_filter_poll_time">
                                <?php esc_html_e( 'Time of Day', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="dt_rio_filter_poll_time" name="filter_poll_time">
                                <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                    <option value="<?php echo esc_attr( sprintf( '%02d:00', $h ) ); ?>">
                                        <?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Time to run the daily poll (site timezone).', 'disciple-tools-crm-sync' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(
                    __( 'Add Filter', 'disciple-tools-crm-sync' ),
                    'secondary',
                    'save_filter_btn'
                ); ?>
            </form>

            <hr style="margin: 24px 0;">

            <?php
// Webhook panel (Optional — Premium Plan)
            $webhook_url = esc_url( rest_url( 'disciple-tools-crm-sync/v1/webhook' ) );
            ?>
            <h2>
                <?php esc_html_e( 'Webhook', 'disciple-tools-crm-sync' ); ?>
                <em style="font-size: 0.75em; font-weight: normal;">(<?php esc_html_e( 'Optional — Premium Plan', 'disciple-tools-crm-sync' ); ?>)</em>
            </h2>
            <p>
                <?php esc_html_e( 'Webhooks deliver real-time contact updates but require a premium Respond.io plan that includes Webhook support. If you are on the free or standard plan, use Scheduled Polling above instead.', 'disciple-tools-crm-sync' ); ?>
            </p>
            <p><?php esc_html_e( 'Configure a Respond.io workflow to POST events to this URL:', 'disciple-tools-crm-sync' ); ?></p>

            <p>
                <input type="text"
                        id="dt-rio-webhook-url"
                        class="regular-text"
                        value="<?php echo esc_attr( $webhook_url ); ?>"
                        readonly
                        style="width: 420px;">
                <button type="button"
                        class="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('dt-rio-webhook-url').value).then(function(){ this.textContent='<?php echo esc_js( __( 'Copied!', 'disciple-tools-crm-sync' ) ); ?>'; }.bind(this))">
                    <?php esc_html_e( 'Copy URL', 'disciple-tools-crm-sync' ); ?>
                </button>
            </p>

            <h3><?php esc_html_e( 'Setup Instructions', 'disciple-tools-crm-sync' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'In Respond.io, navigate to Settings → Integrations → Webhooks.', 'disciple-tools-crm-sync' ); ?></li>
                <li><?php esc_html_e( 'Click "Add Webhook" and paste the URL above.', 'disciple-tools-crm-sync' ); ?></li>
                <li><?php esc_html_e( 'Select the events you want to trigger imports (see list below).', 'disciple-tools-crm-sync' ); ?></li>
                <li><?php esc_html_e( 'Copy the signing secret and save it in the Configuration tab under "Webhook Signing Key".', 'disciple-tools-crm-sync' ); ?></li>
                <li><?php esc_html_e( 'Save the webhook in Respond.io. Each event will trigger an immediate background import.', 'disciple-tools-crm-sync' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Supported Events', 'disciple-tools-crm-sync' ); ?></h3>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ( [
                    'new_contact',
                    'contact_updated',
                    'contact_tag_updated',
                    'contact_assignee_updated',
                    'contact_lifecycle_updated',
                    'new_incoming_message',
                    'new_outgoing_message',
                    'new_comment',
                ] as $event ) : ?>
                    <li><code><?php echo esc_html( $event ); ?></code></li>
                <?php endforeach; ?>
            </ul>

            <?php
            // Interaction scripts for this tab are loaded externally via
            // wp_enqueue_script( 'dt-crm-sync-tab-automations' ) in enqueue_scripts().
            // window.dtCrmSync (populated by wp_localize_script) provides API data.
            ?>

            <hr style="margin: 24px 0;">

            <h2><?php esc_html_e( 'Cron Event Diagnostics', 'disciple-tools-crm-sync' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'A snapshot of scheduled WordPress cron events for this plugin and DT core. Reload the page to refresh.', 'disciple-tools-crm-sync' ); ?>
            </p>

            <?php
            $cron_all = _get_cron_array();
            $cron_all = is_array( $cron_all ) ? $cron_all : [];

            $plugin_poll_events = [];
            $batch_count        = 0;
            foreach ( $cron_all as $ts => $hooks ) {
                if ( isset( $hooks['dt_crm_sync_poll'] ) ) {
                    foreach ( $hooks['dt_crm_sync_poll'] as $hook_data ) {
                        $args      = $hook_data['args'] ?? [];
                        $filter_id = is_array( $args ) && isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
                        $plugin_poll_events[] = [
                            'filter_id'   => $filter_id,
                            'next_ts'     => $ts,
                            'in_manifest' => in_array( $filter_id, $manifest, true ),
                        ];
                    }
                }
                if ( isset( $hooks['dt_crm_sync_process_batch'] ) ) {
                    $batch_count += count( $hooks['dt_crm_sync_process_batch'] );
                }
            }

            // DT core hooks we want to show in the read-only section.
            $dt_core_hook_labels = [
                'dt_daily_notification_schedule' => __( 'DT Notifications Scheduler (daily)', 'disciple-tools-crm-sync' ),
                'update-required'                => __( 'DT Contact Update Checker', 'disciple-tools-crm-sync' ),
            ];
            $dt_core_events = [];
            foreach ( $cron_all as $ts => $hooks ) {
                foreach ( $dt_core_hook_labels as $hook_name => $label ) {
                    if ( isset( $hooks[ $hook_name ] ) && ! isset( $dt_core_events[ $hook_name ] ) ) {
                        $dt_core_events[ $hook_name ] = [
                            'label'   => $label,
                            'next_ts' => $ts,
                        ];
                    }
                }
            }
            ?>

            <h3><?php esc_html_e( "This Plugin's Events", 'disciple-tools-crm-sync' ); ?></h3>

            <?php if ( empty( $plugin_poll_events ) && 0 === $batch_count ) : ?>
                <p><?php esc_html_e( 'No plugin cron events are currently scheduled.', 'disciple-tools-crm-sync' ); ?></p>
            <?php else : ?>
                <?php if ( ! empty( $plugin_poll_events ) ) : ?>
                    <table class="widefat striped" style="max-width: 700px; margin-bottom: 12px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Filter ID', 'disciple-tools-crm-sync' ); ?></th>
                                <th><?php esc_html_e( 'Next Run', 'disciple-tools-crm-sync' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'disciple-tools-crm-sync' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $plugin_poll_events as $ev ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html( $ev['filter_id'] ); ?></code></td>
                                    <td><?php echo esc_html( wp_date( 'Y-m-d H:i', $ev['next_ts'] ) ); ?></td>
                                    <td>
                                        <?php if ( $ev['in_manifest'] ) : ?>
                                            <span style="color: #46b450;">&#10003; <?php esc_html_e( 'Active', 'disciple-tools-crm-sync' ); ?></span>
                                        <?php else : ?>
                                            <span style="color: #dc3232;">&#9888; <?php esc_html_e( 'Orphaned — no matching saved filter', 'disciple-tools-crm-sync' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ( $batch_count > 0 ) : ?>
                    <p>
                        <?php printf(
                            esc_html( _n(
                                '%d pending batch import event queued.',
                                '%d pending batch import events queued.',
                                $batch_count,
                                'disciple-tools-crm-sync'
                            ) ),
                            absint( $batch_count )
                        ); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <p>
                <button type="button"
                        id="dt-crm-sync-purge-all"
                        class="button button-secondary">
                    <?php esc_html_e( 'Emergency Purge All Plugin Events', 'disciple-tools-crm-sync' ); ?>
                </button>
                <span id="dt-crm-sync-purge-status" style="margin-left: 10px; font-size: 0.85em;"></span>
            </p>

            <?php if ( ! empty( $dt_core_events ) ) : ?>
                <h3 style="margin-top: 24px;"><?php esc_html_e( 'DT Core Events (read-only)', 'disciple-tools-crm-sync' ); ?></h3>
                <div class="notice notice-info inline" style="margin: 0 0 12px;">
                    <p>
                        <?php esc_html_e( "These events are scheduled by the DT core plugin when contacts are created or updated. They're expected — removing them would break DT notifications and contact update checks. This plugin does not manage them.", 'disciple-tools-crm-sync' ); ?>
                    </p>
                </div>
                <table class="widefat striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Hook', 'disciple-tools-crm-sync' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'disciple-tools-crm-sync' ); ?></th>
                            <th><?php esc_html_e( 'Next Run', 'disciple-tools-crm-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $dt_core_events as $hook_name => $ev ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $hook_name ); ?></code></td>
                                <td><?php echo esc_html( $ev['label'] ); ?></td>
                                <td><?php echo esc_html( wp_date( 'Y-m-d H:i', $ev['next_ts'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php
        }
    }
}
