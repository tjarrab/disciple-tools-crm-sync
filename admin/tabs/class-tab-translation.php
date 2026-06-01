<?php
/**
 * Translation tab class
 *
 * @package Disciple_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Tab_Translation' ) ) {
    /**
     * Translation tab:AI provider configuration, prompt customisation, and rate limiting.
     *
     * Handles the settings form POST (API key encryption, model/prompt/limit persistence)
     * and renders the translation settings UI.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Tab_Translation {

        /** Default prompt sent to the AI provider before each message. */
        private const DEFAULT_PROMPT = 'If non-English, translate to English. Maintain formatting. Return ONLY the text, no explanations: ';

        /**
         * Handle settings POST and render the translation configuration form.
         */
        public function content(): void {
            $notice = '';

// POST handler, must run before any HTML output
            if ( isset( $_POST['dt_crm_sync_translation_nonce'] ) ) {
                check_admin_referer( 'dt_crm_sync_translation_form', 'dt_crm_sync_translation_nonce' );

                if ( ! current_user_can( 'manage_dt' ) ) {
                    wp_die( esc_html__( 'You do not have permission to perform this action.', 'disciple-tools-crm-sync' ) );
                }

                $values   = dt_recursive_sanitize_array( $_POST );
                $existing = get_option( 'dt_crm_sync_translation_settings', [] );

                // API key: encrypt new value or preserve existing.
                $submitted_key = wp_unslash( $_POST['translation_api_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Used only for length check; actual value processed below after nonce is verified.
                $new_api_key   = $existing['api_key'] ?? '';
                if ( ! empty( $submitted_key ) ) {
                    try {
                        $new_api_key = Disciple_Tools_CRM_Sync::encrypt_value( $submitted_key );
                    } catch ( \RuntimeException $e ) {
                        $notice = 'encrypt_error';
                    }
                }

                $new_settings = [
                    'enabled'     => ! empty( $values['translation_enabled'] ),
                    'provider'    => sanitize_key( $values['translation_provider'] ?? 'gemini' ),
                    'model'       => sanitize_text_field( $values['translation_model'] ?? '' ),
                    'api_key'     => $new_api_key,
                    'prompt'      => sanitize_textarea_field( $values['translation_prompt'] ?? self::DEFAULT_PROMPT ),
                    'daily_limit' => absint( $values['translation_daily_limit'] ?? 0 ),
                ];

                update_option( 'dt_crm_sync_translation_settings', $new_settings );

                if ( '' === $notice ) {
                    $notice = 'success';
                }
            }

// Load current settings for page render
            $settings = get_option( 'dt_crm_sync_translation_settings', [] );
            $settings = wp_parse_args( $settings, [
                'enabled'     => false,
                'provider'    => 'gemini',
                'model'       => '',
                'api_key'     => '',
                'prompt'      => self::DEFAULT_PROMPT,
                'daily_limit' => 0,
            ] );

// Fetch model list (from transient cache or live API)
            $models        = [];
            $models_error  = '';
            $api_key_set   = ! empty( $settings['api_key'] );
            $decrypted_key = '';

            if ( $api_key_set ) {
                $decrypted_key = Disciple_Tools_CRM_Sync::decrypt_value( $settings['api_key'] );
                if ( false === $decrypted_key ) {
                    $models_error  = __( 'API key could not be decrypted — please re-enter it below.', 'disciple-tools-crm-sync' );
                    $decrypted_key = '';
                }
            }

            if ( ! empty( $decrypted_key ) ) {
                $provider = new Disciple_Tools_CRM_Sync_Gemini_Translation_Provider( $decrypted_key, $settings['model'] );
                $result   = $provider->get_models();
                if ( is_wp_error( $result ) ) {
                    $models_error = $result->get_error_message();
                } else {
                    $models = $result;
                }
            }

// Rate limiter stats for display
            $rate_limiter  = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
            $window_count  = $rate_limiter->get_count();
            $window_start  = $rate_limiter->get_window_start();
            $window_resets = $window_start + DAY_IN_SECONDS;
            $secs_left     = max( 0, $window_resets - time() );
            $hours_left    = floor( $secs_left / 3600 );
            $mins_left     = floor( ( $secs_left % 3600 ) / 60 );

// Admin notices
            // .inline prevents WordPress admin JS from moving these notices to the top of the page.
            if ( 'success' === $notice ) {
                echo '<div class="notice notice-success is-dismissible inline"><p>'
                    . esc_html__( 'Settings saved.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            } elseif ( 'encrypt_error' === $notice ) {
                echo '<div class="notice notice-error inline"><p>'
                    . esc_html__( 'Encryption failed — existing API key was preserved. Verify OpenSSL is functional.', 'disciple-tools-crm-sync' )
                    . '</p></div>';
            }

            if ( ! empty( $models_error ) ) {
                echo '<div class="notice notice-warning inline"><p>'
                    . esc_html( $models_error )
                    . '</p></div>';
            }

// Form
            ?>
            <form method="post" action="">
                <?php wp_nonce_field( 'dt_crm_sync_translation_form', 'dt_crm_sync_translation_nonce' ); ?>

                <h2 class="tab-title screen-reader-text"><?php esc_html_e( 'Translation', 'disciple-tools-crm-sync' ); ?></h2>

                <h2><?php esc_html_e( 'AI Provider & Credentials', 'disciple-tools-crm-sync' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="dt_translation_provider">
                                <?php esc_html_e( 'AI Provider', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="dt_translation_provider" name="translation_provider">
                                <option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>>
                                    <?php esc_html_e( 'Google Gemini', 'disciple-tools-crm-sync' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dt_translation_api_key">
                                <?php esc_html_e( 'API Key', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="password"
                                   id="dt_translation_api_key"
                                   name="translation_api_key"
                                   class="regular-text"
                                   value=""
                                   autocomplete="new-password"
                                   placeholder="<?php echo $api_key_set && false !== $decrypted_key
                                       ? esc_attr__( 'Currently set — leave blank to keep', 'disciple-tools-crm-sync' )
                                       : esc_attr__( 'Enter Gemini API key', 'disciple-tools-crm-sync' ); ?>">
                            <?php if ( $api_key_set && false !== $decrypted_key ) : ?>
                                <span class="dashicons dashicons-yes-alt"
                                      style="color:#46b450;vertical-align:middle;"
                                      title="<?php esc_attr_e( 'API key is set', 'disciple-tools-crm-sync' ); ?>"></span>
                            <?php endif; ?>
                            <button type="submit" name="save_translation_settings" class="button"
                                    style="vertical-align:middle; margin-left:6px;">
                                <?php esc_html_e( 'Save', 'disciple-tools-crm-sync' ); ?>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dt_translation_model">
                                <?php esc_html_e( 'Model', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="dt_translation_model" name="translation_model">
                                <?php if ( empty( $models ) ) : ?>
                                    <option value=""><?php esc_html_e( '— save API key and refresh —', 'disciple-tools-crm-sync' ); ?></option>
                                <?php else : ?>
                                    <?php foreach ( $models as $m ) : ?>
                                        <option value="<?php echo esc_attr( $m['value'] ); ?>"
                                            <?php selected( $settings['model'], $m['value'] ); ?>>
                                            <?php echo esc_html( $m['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            &nbsp;
                            <button type="button" id="dt-translation-refresh-models" class="button">
                                <?php esc_html_e( 'Refresh Models', 'disciple-tools-crm-sync' ); ?>
                            </button>
                            <span id="dt-translation-models-result" style="margin-left:8px;"></span>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Settings', 'disciple-tools-crm-sync' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Enable Translation', 'disciple-tools-crm-sync' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="translation_enabled"
                                       value="1"
                                       <?php checked( $settings['enabled'] ); ?>>
                                <?php esc_html_e( 'Enable message translation on import', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dt_translation_prompt">
                                <?php esc_html_e( 'Translation Prompt', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <textarea id="dt_translation_prompt"
                                      name="translation_prompt"
                                      rows="4"
                                      class="large-text"><?php echo esc_textarea( $settings['prompt'] ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'This instruction is prepended to each message before sending to the AI provider. The message text is appended directly after.', 'disciple-tools-crm-sync' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dt_translation_daily_limit">
                                <?php esc_html_e( 'Max Translations per 24 Hours', 'disciple-tools-crm-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   id="dt_translation_daily_limit"
                                   name="translation_daily_limit"
                                   min="0"
                                   class="small-text"
                                   value="<?php echo esc_attr( (string) $settings['daily_limit'] ); ?>">
                            <p class="description">
                                <?php esc_html_e( '0 = unlimited.', 'disciple-tools-crm-sync' ); ?>
                            </p>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: 1: count of translations sent, 2: hours remaining, 3: minutes remaining */
                                    esc_html__( '%1$d translations sent in current window — resets in %2$dh %3$dm.', 'disciple-tools-crm-sync' ),
                                    (int) $window_count,
                                    (int) $hours_left,
                                    (int) $mins_left
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="save_translation_settings" class="button-primary">
                        <?php esc_html_e( 'Save Settings', 'disciple-tools-crm-sync' ); ?>
                    </button>
                    &nbsp;
                    <button type="button" id="dt-translation-test" class="button">
                        <?php esc_html_e( 'Test Translation', 'disciple-tools-crm-sync' ); ?>
                    </button>
                    <span id="dt-translation-test-result" style="margin-left:8px;"></span>
                </p>

            </form>
            <?php
        }
    }
}
