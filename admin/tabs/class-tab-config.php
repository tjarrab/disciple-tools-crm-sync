<?php
/**
 * Configuration tab class for Disciple.Tools - CRM Sync.
 *
 * @package Disciple_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tab 1: Configuration & Field Mapping

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Tab_Config' ) ) {
    /**
     * Configuration tab — connector selection, credential management, and field mapping.
     *
     * Handles the settings form POST (credential encryption, field mapping persistence)
     * and renders the two-column mapping table driven by the live Respond.io schema.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Tab_Config {

        /**
         * Handle settings POST and render the connector configuration form.
         *
         * Processes credential saves (with AES-256 encryption), connector
         * selection, and field mapping persistence before any HTML is output.
         */
        public function content(): void {
            $notice = '';

// POST handler — must run before any HTML output
            if ( isset( $_POST['dt_crm_sync_nonce'] ) ) {
                if ( ! current_user_can( 'manage_dt' ) ) {
                    wp_die( esc_html__( 'You do not have permission to perform this action.', 'disciple-tools-crm-sync' ) );
                }

                check_admin_referer( 'dt_crm_sync_admin_form', 'dt_crm_sync_nonce' );

                $values   = dt_recursive_sanitize_array( $_POST );
                $existing = get_option( 'dt_crm_sync_settings', [] );

                unset( $values['dt_crm_sync_nonce'], $values['save_settings'] );

                $connector_slug = sanitize_key( $values['active_connector'] ?? '' );

                // Determine the connector to get its credential field definitions.
                $connector_class = null;
                if ( $connector_slug ) {
                    $connector_class = Disciple_Tools_CRM_Sync_Connector_Registry::make( $connector_slug, [] );
                }

                // Build encrypted credentials array from submitted form values.
                $submitted_creds = is_array( $values['connectors'][ $connector_slug ] ?? null )
                    ? $values['connectors'][ $connector_slug ]
                    : [];
                $stored_creds    = $existing['connectors'][ $connector_slug ] ?? [];
                $new_creds       = [];

                if ( $connector_class ) {
                    foreach ( $connector_class->get_credential_fields() as $field ) {
                        $slug          = $field['slug'] ?? '';
                        $type          = $field['type'] ?? 'text';
                        $submitted     = $submitted_creds[ $slug ] ?? '';
                        $existing_cred = $stored_creds[ $slug ] ?? '';

                        if ( 'password' === $type ) {
                            // Skip $submitted_creds for password fields — that array came through
                            // dt_recursive_sanitize_array(), which runs sanitize_text_field() over every
                            // value. sanitize_text_field() strips +, /, and = without warning, which are
                            // all valid characters in API keys and silently corrupts them before they reach
                            // encrypt_value(). The nonce was already verified by check_admin_referer() above.
                            $submitted = wp_unslash( $_POST['connectors'][ $connector_slug ][ $slug ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitisation intentionally skipped; see comment above.

                            // Encrypt new value or preserve existing.
                            if ( ! empty( $submitted ) ) {
                                try {
                                    $new_creds[ $slug ] = Disciple_Tools_CRM_Sync::encrypt_value( $submitted );
                                } catch ( \RuntimeException $e ) {
                                    $new_creds[ $slug ] = $existing_cred;
                                    $notice             = 'encrypt_error';
                                }
                            } else {
                                $new_creds[ $slug ] = $existing_cred;
                            }
                        } elseif ( 'url' === $type ) {
                            $new_creds[ $slug ] = ! empty( $submitted ) ? esc_url_raw( $submitted ) : ( $existing_cred ?: esc_url_raw( $field['default'] ?? '' ) );
                        } else {
                            $new_creds[ $slug ] = ! empty( $submitted ) ? sanitize_text_field( $submitted ) : ( $existing_cred ?: '' );
                        }
                    }
                }

                $new_settings                                  = $existing;
                $new_settings['active_connector']              = $connector_slug;
                $new_settings['connectors'][ $connector_slug ] = $new_creds;
                $new_settings['purge_on_uninstall']            = ! empty( $values['purge_on_uninstall'] );
                // Remove legacy flat credential keys.
                unset( $new_settings['base_url'], $new_settings['api_token'], $new_settings['webhook_signing_key'] );

                update_option( 'dt_crm_sync_settings', $new_settings );

                // Field mapping is submitted in the same form.
                if ( isset( $values['field_mapping'] ) && is_array( $values['field_mapping'] ) ) {
                    $this->save_field_mapping( $values['field_mapping'] );
                }

                if ( '' === $notice ) {
                    $notice = 'success';
                }
            }

// Load current settings for page render
            $settings             = get_option( 'dt_crm_sync_settings', [] );
            $active_slug          = $settings['active_connector'] ?? '';
            $all_connector_labels = Disciple_Tools_CRM_Sync_Connector_Registry::get_labels();

            // Resolve the connector instance for rendering credential fields.
            $active_connector = $active_slug
                ? Disciple_Tools_CRM_Sync_Connector_Registry::make( $active_slug, [] )
                : null;

            // Fall back to first available connector if none configured.
            if ( ! $active_connector && ! empty( $all_connector_labels ) ) {
                $first_slug       = array_key_first( $all_connector_labels );
                $active_connector = Disciple_Tools_CRM_Sync_Connector_Registry::make( $first_slug, [] );
                $active_slug      = $active_slug ?: $first_slug;
            }

            $stored_creds    = $settings['connectors'][ $active_slug ] ?? [];
            $connector_label = $active_connector
                ? $active_connector->get_label()
                : __( 'CRM', 'disciple-tools-crm-sync' );

// Admin notices
            if ( 'success' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__( 'Settings saved.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            } elseif ( 'encrypt_error' === $notice ) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__( 'Encryption failed — existing credential was preserved. Verify OpenSSL is functional.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            }

// Form
            ?>
            <form method="post" action="">
                <?php wp_nonce_field( 'dt_crm_sync_admin_form', 'dt_crm_sync_nonce' ); ?>

                <h2 class="tab-title screen-reader-text"><?php esc_html_e( 'Configuration', 'disciple-tools-crm-sync' ); ?></h2>

                <h2><?php esc_html_e( 'Connector', 'disciple-tools-crm-sync' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="dt_crm_active_connector">
                                <?php esc_html_e( 'Active CRM', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="dt_crm_active_connector" name="active_connector">
                                <?php foreach ( $all_connector_labels as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"
                                        <?php selected( $active_slug, $slug ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ( empty( $all_connector_labels ) ) : ?>
                                    <option value=""><?php esc_html_e( 'No connectors registered', 'disciple-tools-crm-sync' ); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Credentials', 'disciple-tools-crm-sync' ); ?></h2>
                <table class="form-table" role="presentation">
                    <?php if ( $active_connector ) :
                        foreach ( $active_connector->get_credential_fields() as $field ) :
                            $slug       = $field['slug'] ?? '';
                            $field_type = $field['type'] ?? 'text';
                            $label      = $field['label'] ?? $slug;
                            $optional   = ! empty( $field['optional'] );
                            $stored_val = $stored_creds[ $slug ] ?? '';
                            $is_set     = ! empty( $stored_val );

                            // For password fields: try to detect decryption failure.
                            $decrypt_failed = false;
                            if ( 'password' === $field_type && $is_set ) {
                                $decrypt_failed = false === Disciple_Tools_CRM_Sync::decrypt_value( $stored_val );
                            }

                            if ( $decrypt_failed ) {
                                echo '<div class="notice notice-error"><p>'
                                    . esc_html( sprintf(
                                        /* translators: %s: credential field label */
                                        __( '%s could not be decrypted. Please re-enter the value below.', 'disciple-tools-crm-sync' ),
                                        $label
                                    ) )
                                    . '</p></div>';
                            }

                            $input_id = 'dt_crm_cred_' . esc_attr( $slug );
                            $name     = 'connectors[' . esc_attr( $active_slug ) . '][' . esc_attr( $slug ) . ']';
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr( $input_id ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                        <?php if ( $optional ) : ?>
                                            <em style="font-weight:normal;">(<?php esc_html_e( 'Optional', 'disciple-tools-crm-sync' ); ?>)</em>
                                        <?php endif; ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ( 'password' === $field_type ) : ?>
                                        <input type="password"
                                                id="<?php echo esc_attr( $input_id ); ?>"
                                                name="<?php echo esc_attr( $name ); ?>"
                                                class="regular-text"
                                                value=""
                                                autocomplete="new-password"
                                                placeholder="<?php echo ( $is_set && ! $decrypt_failed )
                                                    ? esc_attr__( 'Currently set — leave blank to keep', 'disciple-tools-crm-sync' )
                                                    : esc_attr__( 'Enter value', 'disciple-tools-crm-sync' ); ?>">
                                        <?php if ( $is_set && ! $decrypt_failed ) : ?>
                                            <span class="dashicons dashicons-yes-alt"
                                                    style="color:#46b450;vertical-align:middle;"
                                                    title="<?php esc_attr_e( 'Value is set', 'disciple-tools-crm-sync' ); ?>"></span>
                                        <?php endif; ?>
                                        <button type="submit" name="save_settings" class="button"
                                                style="vertical-align:middle; margin-left:6px;">
                                            <?php esc_html_e( 'Save', 'disciple-tools-crm-sync' ); ?>
                                        </button>
                                    <?php elseif ( 'url' === $field_type ) : ?>
                                        <input type="url"
                                                id="<?php echo esc_attr( $input_id ); ?>"
                                                name="<?php echo esc_attr( $name ); ?>"
                                                class="regular-text"
                                                value="<?php echo esc_attr( $stored_val ?: ( $field['default'] ?? '' ) ); ?>">
                                    <?php else : ?>
                                        <input type="text"
                                                id="<?php echo esc_attr( $input_id ); ?>"
                                                name="<?php echo esc_attr( $name ); ?>"
                                                class="regular-text"
                                                value="<?php echo esc_attr( $stored_val ); ?>">
                                    <?php endif; ?>
                                    <?php if ( ! empty( $field['description'] ) ) : ?>
                                        <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    else : ?>
                        <tr><td colspan="2"><p><?php esc_html_e( 'No connector selected.', 'disciple-tools-crm-sync' ); ?></p></td></tr>
                    <?php endif; ?>
                </table>

                <p style="margin-top: 0; margin-bottom: 20px;">
                    <button type="button" id="dt-rio-test-connection" class="button">
                        <?php esc_html_e( 'Test Connection', 'disciple-tools-crm-sync' ); ?>
                    </button>
                    <span id="dt-rio-test-result" role="status" style="margin-left: 8px;"></span>
                    &nbsp;&nbsp;
                    <button type="button" id="dt-rio-refresh-schema" class="button">
                        <?php esc_html_e( 'Refresh Schema', 'disciple-tools-crm-sync' ); ?>
                    </button>
                    <span id="dt-rio-schema-result" style="margin-left: 8px;"></span>
                </p>

                <pre id="dt-rio-test-log" style="display:none; margin:0 0 16px; background:#f8f8f8; border:1px solid #ddd; padding:8px 12px; font-size:12px; max-height:160px; overflow-y:auto; white-space:pre-wrap; word-break:break-all;"></pre>
                <pre id="dt-rio-schema-log" style="display:none; margin:0 0 16px; background:#f8f8f8; border:1px solid #ddd; padding:8px 12px; font-size:12px; max-height:160px; overflow-y:auto; white-space:pre-wrap; word-break:break-all;"></pre>

                <h2><?php esc_html_e( 'Field Mapping', 'disciple-tools-crm-sync' ); ?></h2>
                <p>
                    <?php echo esc_html( sprintf(
                        /* translators: %s: connector name, e.g. "Respond.io" */
                        __( 'Field Mapping controls how %s custom fields are written into Disciple.Tools contact fields during every import (manual, scheduled, and webhook).', 'disciple-tools-crm-sync' ),
                        $connector_label
                    ) ); ?>
                </p>
                <p><?php esc_html_e( 'How to use:', 'disciple-tools-crm-sync' ); ?></p>
                <ol style="margin: 0 0 16px 1.5em; list-style: decimal;">
                    <li><?php echo esc_html( sprintf(
                        /* translators: %s: connector name, e.g. "Respond.io" */
                        __( 'Save your API credentials above, then click "Refresh Schema" to fetch your %s custom field list.', 'disciple-tools-crm-sync' ),
                        $connector_label
                    ) ); ?></li>
                    <li><?php echo esc_html( sprintf(
                        /* translators: %s: connector name, e.g. "Respond.io" */
                        __( 'The table below shows each %s custom field on the left. Use the dropdown on the right to choose the DT contact field it should populate.', 'disciple-tools-crm-sync' ),
                        $connector_label
                    ) ); ?></li>
                    <li><?php esc_html_e( 'Select "— skip —" for any field you do not want imported.', 'disciple-tools-crm-sync' ); ?></li>
                    <li><?php esc_html_e( 'Click "Save Settings" at the bottom of this page to store your choices.', 'disciple-tools-crm-sync' ); ?></li>
                </ol>
                <p class="description">
                    <?php echo esc_html( sprintf(
                        /* translators: %s: connector name, e.g. "Respond.io" */
                        __( 'Note: if you rename or delete a custom field in %s, its mapping entry will be flagged as broken the next time you click "Refresh Schema". Re-save this page after adding a replacement mapping to clear the warning.', 'disciple-tools-crm-sync' ),
                        $connector_label
                    ) ); ?>
                </p>
                <?php $this->render_field_mapping( $active_connector ); ?>

                <h2><?php esc_html_e( 'Data Retention', 'disciple-tools-crm-sync' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Uninstall Behaviour', 'disciple-tools-crm-sync' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                        name="purge_on_uninstall"
                                        value="1"
                                        <?php checked( ! empty( $settings['purge_on_uninstall'] ) ); ?>>
                                <?php esc_html_e( 'Delete all CRM sync contact metadata from DT contacts when uninstalling', 'disciple-tools-crm-sync' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'When checked, uninstalling the plugin will permanently delete the following data from all DT contacts: _respond_io_id, _respond_io_merged_ids, _respond_io_last_sync, _respond_io_notes_comment_id, _respond_io_message_log_comment_id (Respond.io post meta); _metricool_id, _metricool_merged_ids, _metricool_last_sync, _metricool_notes_comment_id, _metricool_message_log_comment_id (Metricool post meta); and all comment meta with a _respond_io_ or _metricool_ prefix.', 'disciple-tools-crm-sync' ); ?>
                            </p>
                            <p class="description" style="color:#b32d2e;font-weight:600;">
                                <?php esc_html_e( '⚠ This action is irreversible. Contact history links will be permanently removed.', 'disciple-tools-crm-sync' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(
                    __( 'Save Settings', 'disciple-tools-crm-sync' ),
                    'primary',
                    'save_settings'
                ); ?>
            </form>

            <?php
            // Interaction scripts for this tab are loaded externally via
            // wp_enqueue_script( 'dt-crm-sync-tab-config' ) in enqueue_scripts().
            // window.dtCrmSync (populated by wp_localize_script) provides API data.
        }

        /**
         * Persist field mapping. Resolves the DT field type server-side so the
         * import processor has type information without an extra get_post_settings() call.
         *
         * @param array $raw_mapping Associative array [respond_field_key => dt_field_key].
         */
        private function save_field_mapping( array $raw_mapping ): void {
            $dt_fields = DT_Posts::get_post_settings( 'contacts' )['fields'] ?? [];

            $clean = [];
            foreach ( $raw_mapping as $respond_key => $dt_key ) {
                $respond_key = sanitize_key( $respond_key );
                $dt_key      = sanitize_text_field( wp_unslash( $dt_key ) );
                if ( '' === $respond_key || '' === $dt_key ) {
                    continue;
                }
                // Pseudo-keys don't correspond to real DT fields; skip the type lookup.
                if ( in_array( $dt_key, [ '__activity_feed__', '__dt_note__', '__skip__' ], true ) ) {
                    $clean[ $respond_key ] = [
                        'dt_key'  => $dt_key,
                        'dt_type' => 'text',
                    ];
                } else {
                    $clean[ $respond_key ] = [
                        'dt_key'  => $dt_key,
                        'dt_type' => $dt_fields[ $dt_key ]['type'] ?? 'text',
                    ];
                }
            }

            update_option( 'dt_crm_sync_field_mapping', $clean );
        }

        /**
         * Render the two-column field mapping table.
         *
         * Reads the schema transient (populated by "Refresh Schema") and merges in
         * any connector-specific synthetic fields via get_synthetic_schema_fields(),
         * which is credential-free and safe to call on a display-only connector instance.
         *
         * @param Disciple_Tools_CRM_Sync_Abstract_Connector|null $connector Active connector instance, or null.
         */
        private function render_field_mapping( ?Disciple_Tools_CRM_Sync_Abstract_Connector $connector = null ): void {
            $connector_label = $connector instanceof Disciple_Tools_CRM_Sync_Abstract_Connector
                ? $connector->get_label()
                : __( 'CRM', 'disciple-tools-crm-sync' );

            // Use the transient as a gate: if it is cold the admin has not yet clicked
            // "Refresh Schema", so prompt them rather than making a live API call.
            $schema = $connector instanceof Disciple_Tools_CRM_Sync_Abstract_Connector
                ? get_transient( $connector->get_schema_transient_key() )
                : false;

            // $empty_msg is set when there is no data to display; the table is always
            // rendered so the test suite (and the admin) can always find the <thead>.
            $empty_msg = null;
            if ( false === $schema || ! is_array( $schema ) ) {
                $empty_msg = esc_html( sprintf(
                    /* translators: %s: connector name, e.g. "Respond.io" */
                    __( 'No schema loaded. Save credentials then click "Refresh Schema" to fetch %s custom field definitions.', 'disciple-tools-crm-sync' ),
                    $connector_label
                ) );
                $schema    = [];
            }

            // Synthetic fields (e.g. Lifecycle) are already included in the transient
            // by get_field_schema() on the connector side — they're merged there so
            // detect_schema_drift() sees them too. Don't add them again here.

            // Schema is a flat array of field objects after API client normalisation.
            $respond_fields = $schema;

            if ( null === $empty_msg && empty( $respond_fields ) ) {
                $empty_msg = esc_html( sprintf(
                    /* translators: %s: connector name, e.g. "Respond.io" */
                    __( 'No custom fields found in the %s schema.', 'disciple-tools-crm-sync' ),
                    $connector_label
                ) );
            }

            $dt_fields = DT_Posts::get_post_settings( 'contacts' )['fields'] ?? [];

            // Build DT field options; exclude connection and internal types.
            $exclude_types = [ 'connection', 'post_user_meta', 'array' ];
            $dt_options    = [];
            foreach ( $dt_fields as $dk => $df ) {
                if ( in_array( $df['type'] ?? '', $exclude_types, true ) ) {
                    continue;
                }
                $dt_options[ $dk ] = ( $df['name'] ?? $dk ) . ' (' . ( $df['type'] ?? '?' ) . ')';
            }

            $saved_mapping = get_option( 'dt_crm_sync_field_mapping', [] );
            $msg_key       = $connector ? $connector->get_messages_field_key() : '__messages__';
            $msg_target    = $saved_mapping[ $msg_key ]['dt_key'] ?? '__dt_note__';
            // Narrow set for the message history row — only text/textarea fields make sense.
            $msg_history_dt_options = array_filter(
                $dt_options,
                fn( string $dk ) => in_array( $dt_fields[ $dk ]['type'] ?? '', [ 'text', 'textarea' ], true ),
                ARRAY_FILTER_USE_KEY
            );

// Broken-mapping notice
            // Broken entries are mapping rows whose Respond.io field name no
            // longer exists in the live schema (detected on the last Refresh
            // Schema). They are shown here as a warning; they will be dropped
            // from the stored mapping automatically on the next form save because
            // they no longer appear in the schema-driven dropdown.
            if ( is_array( $saved_mapping ) ) {
                $broken_entries = [];
                foreach ( $saved_mapping as $r_key => $entry ) {
                    if ( ! empty( $entry['broken'] ) ) {
                        // Try to find a human-readable DT field label.
                        $dt_label         = $dt_fields[ $entry['dt_key'] ?? '' ]['name'] ?? ( $entry['dt_key'] ?? $r_key );
                        $broken_entries[] = [
                            'respond_key' => $r_key,
                            'dt_label'    => $dt_label,
                        ];
                    }
                }

                if ( ! empty( $broken_entries ) ) {
                    echo '<div class="notice notice-warning inline" style="margin-bottom:16px;">';
                    echo '<p><strong>'
                        . esc_html__( 'Broken field mappings detected:', 'disciple-tools-crm-sync' )
                        . '</strong> '
                        . esc_html__( 'The following Respond.io fields no longer exist in the schema. Re-save this form after creating new mappings for any renamed fields — the broken entries will be removed automatically.', 'disciple-tools-crm-sync' )
                        . '</p>';
                    echo '<ul style="margin:.4em 0 .4em 1.5em; list-style:disc;">';
                    foreach ( $broken_entries as $be ) {
                        echo '<li>'
                            . '<span class="dashicons dashicons-warning" style="color:#dba617;vertical-align:middle;" aria-hidden="true"></span> '
                            . '<code>' . esc_html( $be['respond_key'] ) . '</code>'
                            . ' &rarr; '
                            . esc_html( $be['dt_label'] )
                            . ' &mdash; <em>'
                            . esc_html__( 'Field renamed or removed in Respond.io', 'disciple-tools-crm-sync' )
                            . '</em>'
                            . '</li>';
                    }
                    echo '</ul></div>';
                }
            }
            ?>
            <table class="widefat striped" style="max-width: 700px; margin-bottom: 16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Respond.io Field', 'disciple-tools-crm-sync' ); ?></th>
                        <th><?php esc_html_e( 'Maps to DT Contact Field', 'disciple-tools-crm-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background: #f9f9f9;">
                        <td>
                            <strong><?php esc_html_e( 'Message History', 'disciple-tools-crm-sync' ); ?></strong>
                            <p class="description" style="margin: 4px 0 0;"><?php esc_html_e( 'Full conversation log — all messages from the contact and all agent replies.', 'disciple-tools-crm-sync' ); ?></p>
                        </td>
                        <td>
                            <select name="field_mapping[<?php echo esc_attr( $msg_key ); ?>]">
                                <option value="__dt_note__" <?php selected( $msg_target, '__dt_note__' ); ?>>
                                    <?php esc_html_e( '— DT Note (default)', 'disciple-tools-crm-sync' ); ?>
                                </option>
                                <option value="__skip__" <?php selected( $msg_target, '__skip__' ); ?>>
                                    <?php esc_html_e( '— Skip (don\'t import messages)', 'disciple-tools-crm-sync' ); ?>
                                </option>
                                <?php foreach ( $msg_history_dt_options as $dk => $dl ) : ?>
                                    <option value="<?php echo esc_attr( $dk ); ?>"
                                        <?php selected( $msg_target, $dk ); ?>>
                                        <?php echo esc_html( $dl ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php if ( null !== $empty_msg ) : ?>
                    <tr><td colspan="2"><p class="description"><?php echo esc_html( $empty_msg ); ?></p></td></tr>
                    <?php else : foreach ( $respond_fields as $rf ) :
                        // Use 'slug' (the API identifier, e.g. 'notes_for_dt') as the stored key
                        // so it matches the name returned on each contact's custom_fields array.
                        // Synthetic fields (e.g. Lifecycle) have no slug, so fall back to sanitize_key(name).
                            $rf_key   = sanitize_key( $rf['slug'] ?? $rf['name'] ?? '' );
                            $rf_label = esc_html( $rf['label'] ?? $rf['name'] ?? $rf_key );
                            if ( '' === $rf_key ) {
                                continue;
                            }
                            $current_dt = $saved_mapping[ $rf_key ]['dt_key'] ?? '';
                            ?>
                        <tr>
                            <td><?php echo esc_html( $rf_label ); ?></td>
                            <td>
                                <select name="field_mapping[<?php echo esc_attr( $rf_key ); ?>]">
                                    <option value=""><?php esc_html_e( '— skip —', 'disciple-tools-crm-sync' ); ?></option>
                                    <option value="__activity_feed__"
                                        <?php selected( $current_dt, '__activity_feed__' ); ?>>
                                        <?php esc_html_e( 'Add to Activity Feed note', 'disciple-tools-crm-sync' ); ?>
                                    </option>
                                    <?php foreach ( $dt_options as $dk => $dl ) : ?>
                                        <option value="<?php echo esc_attr( $dk ); ?>"
                                            <?php selected( $current_dt, $dk ); ?>>
                                            <?php echo esc_html( $dl ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach;
endif; ?>
                </tbody>
            </table>
            <?php
        }
    }
}
