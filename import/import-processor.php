<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Processor' ) ) {
    /**
     * Executes the per-contact import lifecycle for a batch of Respond.io IDs.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Processor {

        private static ?self $instance = null;

        /**
         * Active connector instance for the current batch, set at the top of process_batch()
         * and valid for the lifetime of that WP-Cron invocation.
         *
         * @var Disciple_Tools_CRM_Sync_Abstract_Connector|null
         */
        protected ?Disciple_Tools_CRM_Sync_Abstract_Connector $connector = null;

        /**
         * Collaborator for duplicate contact detection. Instantiated per-batch in process_batch()
         * once the connector (and therefore its meta key prefix) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Contact_Matcher $matcher = null;

        /**
         * Collaborator for profile-to-DT field mapping. Instantiated per-batch in process_batch()
         * once the connector (needed for get_dt_source_slug()) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Field_Mapper $mapper = null;

        /**
         * Collaborator for message history import. Instantiated per-batch in process_batch()
         * after the connector and sideloader are available.
         */
        protected ?Disciple_Tools_CRM_Sync_Message_Importer $message_importer = null;

        /**
         * Collaborator for activity-feed note upsert. Instantiated per-batch in process_batch()
         * once the connector (and therefore its meta key prefix) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Activity_Feed_Writer $activity_feed_writer = null;

        /**
         * Returns the singleton instance, creating it on first call.
         *
         * @return self
         */
        public static function instance(): self {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Registers the WP-Cron action hook that drives batch processing.
         *
         * @return void
         */
        protected function __construct() {
            add_action( 'dt_crm_sync_process_batch', [ $this, 'process_batch' ], 10, 1 );
        }

        /**
         * WP-Cron callback. Processes a batch of Respond.io contact IDs.
         *
         * @param array $args {
         *     @type int[]  $ids      Respond.io contact IDs to import.
         *     @type string $_token   Unique deduplication token (ignored by processor).
         *     @type string $_trigger 'manual' | 'scheduled' | 'webhook'
         * }
         */
        public function process_batch( array $args ): void {
            // Prevent PHP timeout on large batches with message history + media sideloading.
            if ( function_exists( 'set_time_limit' ) ) {
                set_time_limit( 300 );
            }

            $ids          = array_values( array_filter( $args['ids'] ?? [], 'is_scalar' ) );
            $trigger_type = sanitize_key( $args['_trigger'] ?? 'manual' );

            if ( empty( $ids ) ) {
                return;
            }

            $this->connector = null;
            $connector_result = $this->get_active_connector();
            if ( is_wp_error( $connector_result ) ) {
                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, 'batch', null, 'failed',
                    $connector_result->get_error_message()
                );
                return;
            }
            $this->connector      = $connector_result;
            $this->matcher        = new Disciple_Tools_CRM_Sync_Contact_Matcher( $this->connector->get_meta_key_prefix() );
            $this->mapper         = new Disciple_Tools_CRM_Sync_Field_Mapper( $this->connector );
            $this->message_importer = new Disciple_Tools_CRM_Sync_Message_Importer(
                $this->connector,
                new Disciple_Tools_CRM_Sync_Media_Sideloader()
            );
            $this->activity_feed_writer = new Disciple_Tools_CRM_Sync_Activity_Feed_Writer();

            $processed_count = 0;

            foreach ( $ids as $respond_id ) {
                $respond_id = (string) $respond_id;
                ++$processed_count;

                $result = $this->process_single_contact( $respond_id, $trigger_type );

                if ( is_null( $result ) ) {
                    // Success or intentional skip — continue to next contact.
                    continue;
                }

                if ( is_wp_error( $result ) ) {
                    $code = $result->get_error_code();

                    if ( 'rate_limited' === $code ) {
                        $retry_after = (int) ( $result->get_error_data()['retry_after'] ?? 60 );
                        // Use $processed_count - 1 so the contact that triggered the 429
                        // is included in the reschedule, not dropped.
                        $remaining = array_slice( $ids, $processed_count - 1 );
                        if ( ! empty( $remaining ) ) {
                            wp_schedule_single_event(
                                time() + $retry_after,
                                'dt_crm_sync_process_batch',
                                [ [ 'ids' => $remaining, '_token' => uniqid( '', true ), '_trigger' => $trigger_type ] ]
                            );
                        }
                        break;
                    }

                    if ( 'resource_pending' === $code ) {
                        // Include the pending contact itself in the reschedule.
                        $remaining = array_slice( $ids, $processed_count - 1 );
                        if ( ! empty( $remaining ) ) {
                            wp_schedule_single_event(
                                time() + 180,
                                'dt_crm_sync_process_batch',
                                [ [ 'ids' => $remaining, '_token' => uniqid( '', true ), '_trigger' => $trigger_type ] ]
                            );
                        }
                        break;
                    }

                    // Any other per-contact error: log and continue with remaining contacts.
                    Disciple_Tools_CRM_Sync_Logger::write(
                        $trigger_type, $respond_id, null, 'failed',
                        $result->get_error_message()
                    );
                }
            }
        }

        /**
         * Process a single contact: match or create the DT post, map fields,
         * and import message history.
         *
         * Returns null on success or intentional skip, WP_Error on failure or
         * 429/449 (caller reschedules the remaining batch).
         *
         * @return WP_Error|null
         */
        protected function process_single_contact(
            string $respond_id,
            string $trigger_type
        ): WP_Error|null {
            try {
                // Check by connector ID meta first — fastest path.
                $dt_post_id = $this->matcher->find_by_connector_id( $respond_id );
                $action     = $dt_post_id ? 'update' : 'create';

                $profile = $this->connector->get_contact( $respond_id );
                if ( is_wp_error( $profile ) ) {
                    return $profile;
                }
                $phone = sanitize_text_field( $profile['phone'] ?? '' );

                // Fall back to phone/email lookup if meta hasn't been written yet.
                if ( ! $dt_post_id ) {
                    $email      = sanitize_email( $profile['email'] ?? '' );
                    $dt_post_id = $this->matcher->find_by_phone_or_email( $phone, $email );
                    $action     = $dt_post_id ? 'update' : 'create';
                }

                // Handle merged contacts: if the returned profile ID differs from
                // what we requested, the original contact was absorbed by another.
                if ( ! empty( $profile['id'] ) && (string) $profile['id'] !== (string) $respond_id ) {
                    $canonical_id         = (string) $profile['id'];
                    $canonical_dt_post_id = $this->matcher->find_by_connector_id( $canonical_id );

                    if ( $canonical_dt_post_id && $dt_post_id && $canonical_dt_post_id !== $dt_post_id ) {
                        // Canonical contact already exists in DT, sync to it instead.
                        $dt_post_id = $canonical_dt_post_id;
                        $action     = 'update';
                    } elseif ( $dt_post_id ) {
                        // Old one exists, canonical doesn't. Replace ID meta.
                        update_post_meta( $dt_post_id, '_respond_io_id', sanitize_text_field( $canonical_id ) );
                        $action = 'update';
                    }
                    $respond_id = $canonical_id;
                }

                $fields = array_merge(
                    $this->mapper->map_core_fields( $profile, 'create' === $action ),
                    $this->mapper->map_custom_fields( $profile )
                );

                if ( 'create' === $action ) {
                    $result = DT_Posts::create_post( 'contacts', $fields, true, false );
                } else {
                    $result = DT_Posts::update_post( 'contacts', $dt_post_id, $fields, true, false );
                }

                if ( is_wp_error( $result ) ) {
                    return new WP_Error(
                        'dt_write_failed',
                        $result->get_error_message(),
                        [ 'respond_id' => $respond_id ]
                    );
                }

                // On create: capture the new post ID from the result.
                // On update via phone/email fallback: the meta was never written
                // (find_existing_post() checks meta, not phone/email), so write it
                // now so subsequent polls use the fast indexed meta lookup instead
                // of the expensive LIKE query.
                if ( 'create' === $action ) {
                    $dt_post_id = (int) $result['ID'];
                    add_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', $respond_id, true );
                } elseif ( ! get_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', true ) ) {
                    add_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', $respond_id, true );
                }

                // Upsert activity-feed note for mapped fields.
                $activity_fields = $this->mapper->get_activity_feed_fields( $profile );
                if ( ! empty( $activity_fields ) ) {
                    $this->activity_feed_writer->upsert(
                        $dt_post_id,
                        $activity_fields,
                        $this->connector->get_meta_key_prefix()
                    );
                }

                // Import message history.
                $msg_error = $this->message_importer->import( $respond_id, $dt_post_id );
                if ( is_wp_error( $msg_error ) ) {
                    // Propagate 429 / 449 for batch rescheduling.
                    return $msg_error;
                }

                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, $respond_id, $dt_post_id, 'success', $action
                );

                return null;

            } catch ( \Throwable $e ) {
                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, $respond_id, null, 'failed',
                    get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
                return new WP_Error(
                    'unexpected_exception',
                    $e->getMessage(),
                    [ 'file' => $e->getFile(), 'line' => $e->getLine() ]
                );
            }
        }

        /**
         * Resolve the active connector from the registry.
         *
         * @return Disciple_Tools_CRM_Sync_Abstract_Connector|WP_Error
         */
        private function get_active_connector(): Disciple_Tools_CRM_Sync_Abstract_Connector|\WP_Error {
            $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

            if ( null === $connector ) {
                return new \WP_Error(
                    'missing_credentials',
                    __( 'No CRM connector is configured or credentials could not be decrypted. Please configure a connector on the Configuration tab.', 'disciple-tools-crm-sync' )
                );
            }

            return $connector;
        }
    }
}
