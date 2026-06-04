<?php
/**
 * Contact Matcher — duplicate detection for incoming CRM contacts.
 *
 * Provides two lookup strategies:
 *   1. Fast indexed path: match on the connector's ID meta key.
 *   2. Slow fallback: LIKE-pattern search against serialized phone/email meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Contact_Matcher' ) ) {
    /**
     * Detects whether an incoming CRM contact already exists in DT.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Contact_Matcher {

        /**
         * @param string $meta_key_prefix Connector-specific meta key prefix,
         *                                e.g. '_respond_io_' from get_meta_key_prefix().
         */
        public function __construct( private readonly string $meta_key_prefix ) {}

        /**
         * Find a DT contact whose connector-ID meta matches $connector_id.
         *
         * @param string $connector_id The CRM contact ID to look up.
         * @return int|null Post ID on match, null if no match.
         */
        public function find_by_connector_id( string $connector_id ): int|null {
            $posts = get_posts( [
                'post_type'      => 'contacts',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for duplicate contact detection by connector ID.
                    [
                        'key'   => $this->meta_key_prefix . 'id',
                        'value' => $connector_id,
                    ],
                ],
            ] );

            return ! empty( $posts ) ? (int) $posts[0] : null;
        }

        /**
         * Sequential phone → email fallback duplicate check.
         *
         * DT stores communication channel values (phone, email) as PHP-serialized
         * arrays in wp_postmeta — a plain meta_value equality check will never match.
         *
         * We search using a LIKE pattern against the serialized string representation:
         *   s:{byte-length}:"{value}"
         * The byte-length prefix prevents a shorter value (e.g. "12") from matching
         * inside a longer serialized string (e.g. "1234567890").
         *
         * @return int|null Post ID on match, null if neither value produces a hit.
         */
        public function find_by_phone_or_email( string $phone, string $email ): int|null {
            global $wpdb;

            if ( ! empty( $phone ) ) {
                $phone_like = '%s:' . strlen( $phone ) . ':"' . $wpdb->esc_like( $phone ) . '"%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'contact_phone'
                       AND pm.meta_value LIKE %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $phone_like
                ) );

                if ( ! empty( $post_id ) ) {
                    return (int) $post_id;
                }
            }

            if ( ! empty( $email ) ) {
                $email_like = '%s:' . strlen( $email ) . ':"' . $wpdb->esc_like( $email ) . '"%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'contact_email'
                       AND pm.meta_value LIKE %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $email_like
                ) );

                if ( ! empty( $post_id ) ) {
                    return (int) $post_id;
                }
            }

            return null;
        }
    }
}
