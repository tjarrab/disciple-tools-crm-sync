<?php
/**
 * Field Mapper — maps CRM profile data to DT contact field arrays.
 *
 * Handles both core field mapping (title, phone, email, sources, type, tags)
 * and admin-configured custom field mapping with type dispatch.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Field_Mapper' ) ) {
    /**
     * Converts a Respond.io contact profile into the DT fields array format.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Field_Mapper {

        /**
         * @param Disciple_Tools_CRM_Sync_Abstract_Connector $connector Used for get_dt_source_slug().
         */
        public function __construct(
            private readonly Disciple_Tools_CRM_Sync_Abstract_Connector $connector
        ) {}

        /**
         * Map core Respond.io profile fields to the DT fields array.
         *
         * @param array $profile   Decoded Respond.io contact profile.
         * @param bool  $is_create True on first import; false on subsequent updates.
         * @return array
         */
        public function map_core_fields( array $profile, bool $is_create ): array {
            $fields = [];

            $first = sanitize_text_field( $profile['firstName'] ?? '' );
            $last  = sanitize_text_field( $profile['lastName'] ?? '' );
            $title = trim( "$first $last" );
            $phone = sanitize_text_field( $profile['phone'] ?? '' );
            $email = sanitize_email( $profile['email'] ?? '' );
            if ( empty( $title ) ) {
                // Fallback: use phone, then email, then a placeholder.
                if ( ! empty( $phone ) ) {
                    $title = $phone;
                } elseif ( ! empty( $email ) ) {
                    $title = $email;
                } else {
                    $title = __( 'Unknown', 'disciple-tools-crm-sync' );
                }
            }
            $fields['title'] = $title;

            // Phone — only include if non-empty.
            if ( ! empty( $phone ) ) {
                $fields['contact_phone'] = [ 'values' => [ [ 'value' => $phone ] ] ];
            }

            // Email — only include if non-empty.
            if ( ! empty( $email ) ) {
                $fields['contact_email'] = [ 'values' => [ [ 'value' => $email ] ] ];
            }

            // Source: appends the connector's source slug without replacing existing sources.
            $fields['sources'] = [ 'values' => [ [ 'value' => $this->connector->get_dt_source_slug() ] ] ];

            // Type: set to 'access' on creation only — never override a manually-set type.
            if ( $is_create ) {
                $fields['type'] = 'access';
            }

            // Tags: map each Respond.io tag string to a DT multi-select tag value.
            // DT creates the tag option on-the-fly if it doesn't already exist.
            $respond_tags = array_values( array_filter(
                array_map( 'sanitize_text_field', (array) ( $profile['tags'] ?? [] ) )
            ) );
            if ( ! empty( $respond_tags ) ) {
                $fields['tags'] = [
                    'values' => array_map( fn( string $t ) => [ 'value' => $t ], $respond_tags ),
                ];
            }

            return $fields;
        }

        /**
         * Map admin-configured custom fields using the stored field mapping and type dispatch.
         *
         * @return array
         */
        public function map_custom_fields( array $profile ): array {
            // The mapping is stored as a plain PHP array by save_field_mapping() —
            // never JSON-encoded. Use get_option directly; json_decode is not needed
            // and would return null when given a PHP array, silently skipping all fields.
            $raw_mapping = get_option( 'dt_crm_sync_field_mapping', [] );
            if ( empty( $raw_mapping ) || ! is_array( $raw_mapping ) ) {
                return [];
            }

            $fields        = [];

            // Prepend synthetic entries for top-level contact profile fields that the
            // Respond.io API returns directly on the contact object (not in custom_fields).
            // This allows admins to map them via Tab 1 exactly like real custom fields.
            $synthetic_fields = [
                [ 'name' => 'Lifecycle', 'value' => $profile['lifecycle'] ?? null ],
            ];
            $custom_fields = array_merge( $synthetic_fields, $profile['custom_fields'] ?? [] );

            // custom_fields is an array of objects, not a dictionary.
            foreach ( $custom_fields as $cf ) {
                $field_name = $cf['name'] ?? '';
                $field_val  = $cf['value'] ?? null;

                if ( empty( $field_name ) || is_null( $field_val ) ) {
                    continue;
                }

                // save_field_mapping() applies sanitize_key() to field names before
                // storing them as array keys. Apply the same transformation here so
                // that field names with uppercase letters, spaces, or other characters
                // (e.g. "My Field" → "my-field") resolve correctly.
                $field_name_key = sanitize_key( $field_name );

                if ( ! isset( $raw_mapping[ $field_name_key ] ) ) {
                    continue;
                }

                // Keys are 'dt_key' and 'dt_type' — as stored by save_field_mapping().
                $dt_key  = $raw_mapping[ $field_name_key ]['dt_key'] ?? '';
                $dt_type = $raw_mapping[ $field_name_key ]['dt_type'] ?? 'text';

                if ( empty( $dt_key ) ) {
                    continue;
                }

                // Skip fields targeted at the activity-feed note — handled separately
                // by Disciple_Tools_CRM_Sync_Activity_Feed_Writer in the import processor.
                if ( '__activity_feed__' === $dt_key ) {
                    continue;
                }

                // Type-aware formatting: passing the wrong shape silently fails DT validation.
                switch ( $dt_type ) {
                    case 'multi_select':
                    case 'tags':
                        $fields[ $dt_key ] = [ 'values' => [ [ 'value' => (string) $field_val ] ] ];
                        break;
                    case 'number':
                        $fields[ $dt_key ] = (int) $field_val;
                        break;
                    case 'boolean':
                        $fields[ $dt_key ] = in_array( $field_val, [ true, 'true', '1', 1 ], true );
                        break;
                    case 'date':
                        $ts = strtotime( (string) $field_val );
                        if ( false !== $ts ) {
                            $fields[ $dt_key ] = gmdate( 'Y-m-d', $ts );
                        } else {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( sprintf( '[DT CRM Sync] date parse failed for field "%s": raw value "%s"', $dt_key, $field_val ) );
                            }
                        }
                        break;
                    default: // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- 'text', 'textarea', 'key_select', etc.
                        $fields[ $dt_key ] = sanitize_text_field( (string) $field_val );
                        break;
                }
            }

            return $fields;
        }

        /**
         * Collect all Respond.io fields mapped to the activity-feed note.
         *
         * Returns a label => value array for any CRM field whose admin mapping
         * target is the reserved '__activity_feed__' pseudo-key. Values are
         * sanitized as plain text since they will be rendered into HTML comment content.
         *
         * @param array $profile Decoded Respond.io contact profile.
         * @return array<string, string> [ 'Field Name' => 'sanitized value' ]
         */
        public function get_activity_feed_fields( array $profile ): array {
            $raw_mapping = get_option( 'dt_crm_sync_field_mapping', [] );
            if ( empty( $raw_mapping ) || ! is_array( $raw_mapping ) ) {
                return [];
            }

            $synthetic_fields = [
                [ 'name' => 'Lifecycle', 'value' => $profile['lifecycle'] ?? null ],
            ];
            $custom_fields = array_merge( $synthetic_fields, $profile['custom_fields'] ?? [] );

            $activity_fields = [];
            foreach ( $custom_fields as $cf ) {
                $field_name = $cf['name'] ?? '';
                $field_val  = $cf['value'] ?? null;

                if ( empty( $field_name ) || is_null( $field_val ) ) {
                    continue;
                }

                $field_name_key = sanitize_key( $field_name );

                if ( ! isset( $raw_mapping[ $field_name_key ] ) ) {
                    continue;
                }

                if ( '__activity_feed__' !== ( $raw_mapping[ $field_name_key ]['dt_key'] ?? '' ) ) {
                    continue;
                }

                $activity_fields[ $field_name ] = sanitize_text_field( (string) $field_val );
            }

            return $activity_fields;
        }
    }
}
