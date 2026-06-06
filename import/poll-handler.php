<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Poll_Handler' ) ) {
    /**
     * Cursor-paginates a saved filter and queues batches for import.
     *
     * Invoked by the main class run_poll_for_filter() WP-Cron callback.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Poll_Handler {

        /**
         * Fetches all contacts matching a saved filter and schedules batch imports.
         *
         * Cursor-paginates via the active connector's get_contacts(), chunks results
         * into 25-contact batches, and schedules a dt_crm_sync_process_batch WP-Cron
         * event per chunk. All log entries use trigger_type 'scheduled'.
         *
         * A per-filter transient lock prevents overlapping executions. If a previous
         * run is still in progress when the next cron tick fires, the duplicate is
         * logged as skipped and exits immediately -- the in-flight run is unaffected.
         *
         * @param string $filter_id The sanitized filter ID from the manifest.
         */
        public function run_poll( string $filter_id ): void {
            $filter_id = sanitize_key( $filter_id );
            set_time_limit( 300 );

// Load and validate the stored filter envelope
            $raw = get_option( 'dt_crm_sync_saved_filter_' . $filter_id );
            if ( false === $raw || '' === $raw ) {
                // The filter was deleted but the cron event wasn't cleared, unschedule it
                // now so it doesn't keep firing and generating failed log entries.
                wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
                Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'failed', 'Saved filter not found. Recurring event has been unscheduled.' );
                return;
            }

            $envelope = json_decode( $raw, true );
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $envelope ) ) {
                Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'failed', 'Invalid filter envelope: ' . json_last_error_msg() );
                return;
            }

            // Support both 'filter_params' (current envelope format) and the legacy
            // 'filter_body' key for backwards compatibility with older saved filters.
            if ( isset( $envelope['filter_params'] ) && is_array( $envelope['filter_params'] ) ) {
                $filter_params = $envelope['filter_params'];
            } elseif ( isset( $envelope['filter_body'] ) && is_array( $envelope['filter_body'] ) ) {
                // Legacy envelope: promote filter_body fields to flat filter_params.
                $filter_params = [
                    'search' => $envelope['filter_body']['search'] ?? '',
                    'tag'    => $envelope['filter_body']['tag'] ?? '',
                ];
            } else {
                Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'failed', 'Filter envelope missing filter_params.' );
                return;
            }

            $skip_existing = $envelope['skip_existing'] ?? true;

            // Log the resolved params so the Logs tab shows exactly what each scheduled
            // run was filtering on — handy when a filter silently produces unexpected results.
            Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'running', 'Resolved filter_params: ' . wp_json_encode( $filter_params ) );

// Concurrency lock -- one execution per filter at a time
            // The lock value is the acquisition timestamp so it's easy to inspect if
            // something went wrong. TTL is double the PHP execution ceiling (300s) so
            // the transient self-expires well before the shortest recurrence window
            // (2 hours) even if a hard crash prevents the finally block from running.
            $lock_key = 'dt_crm_sync_poll_lock_' . $filter_id;

            if ( false !== get_transient( $lock_key ) ) {
                Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'skipped', 'Previous poll still running. Skipped to avoid duplicate imports.' );
                return;
            }

            set_transient( $lock_key, time(), 600 );

            try {

// Resolve the connector
                $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

                if ( null === $connector ) {
                    Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'failed', 'No connector configured or credentials could not be decrypted.' );
                    return;
                }

                Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'running', 'Poll started.' );

// Cursor-paginated contact collection
                $all_ids = [];
                $cursor  = null;

                do {
                    $result = $connector->get_contacts( $filter_params, $cursor );

                    if ( is_wp_error( $result ) ) {
                        if ( 'rate_limited' === $result->get_error_code() ) {
                            // Re-schedule the full poll after the back-off window so contacts
                            // on un-fetched pages are not silently dropped.
                            $retry_after = (int) ( $result->get_error_data()['retry_after'] ?? 60 );
                            $scheduled   = wp_schedule_single_event(
                                time() + $retry_after,
                                'dt_crm_sync_poll',
                                [ $filter_id ]
                            );
                            if ( false === $scheduled ) {
                                Disciple_Tools_CRM_Sync_Logger::write(
                                    'scheduled',
                                    $filter_id,
                                    null,
                                    'failed',
                                    'Rate limited during pagination. Reschedule failed — poll will not retry automatically.'
                                );
                            } else {
                                Disciple_Tools_CRM_Sync_Logger::write(
                                    'scheduled',
                                    $filter_id,
                                    null,
                                    'failed',
                                    'Rate limited during pagination. Poll rescheduled in ' . $retry_after . 's.'
                                );
                            }
                            // Return immediately — do not fall through to batch scheduling.
                            // The rescheduled poll will process all contacts from scratch.
                            return;
                        } else {
                            Disciple_Tools_CRM_Sync_Logger::write(
                                'scheduled',
                                $filter_id,
                                null,
                                'failed',
                                'API error during pagination: ' . $result->get_error_message()
                            );
                        }
                        // For non-rate-limited errors: schedule whatever was collected before
                        // the error so partial results are not discarded, then bail.
                        break;
                    }

                    $page_contacts = $result['data'] ?? [];
                    if ( ! empty( $page_contacts ) ) {
                        $all_ids = array_merge(
                            $all_ids,
                            array_values( array_filter( array_column( $page_contacts, 'id' ), fn( $id ) => null !== $id ) )
                        );
                    }

                    $cursor = $result['cursor']['next'] ?? null;

                // null is the agreed sentinel for "no more pages" (see abstract-connector.php).
                // !empty() would wrongly treat '0' as falsy and stop pagination a page early.
                } while ( null !== $cursor );

                if ( empty( $all_ids ) ) {
                    Disciple_Tools_CRM_Sync_Logger::write( 'scheduled', $filter_id, null, 'skipped', '0 contacts found.' );
                    return;
                }

                $chunks        = array_chunk( $all_ids, 25 );
                $batch_count   = count( $chunks );
                $failed_chunks = 0;

            // Stagger events by 3 seconds per chunk to avoid concurrent cron spikes.
                foreach ( $chunks as $i => $chunk ) {
                    $scheduled = wp_schedule_single_event(
                        time() + 5 + ( $i * 3 ),
                        'dt_crm_sync_process_batch',
                        [
                        [
                        'ids'            => array_map( 'intval', $chunk ),
                        '_token'         => uniqid( '', true ),
                        '_trigger'       => 'scheduled',
                        '_skip_existing' => $skip_existing,
                        ]
                        ]
                    );
                    if ( false === $scheduled ) {
                        $failed_chunks++;
                    }
                }

                $queued_count = $batch_count - $failed_chunks;

                if ( 0 === $queued_count ) {
                    Disciple_Tools_CRM_Sync_Logger::write(
                        'scheduled',
                        $filter_id,
                        null,
                        'failed',
                        count( $all_ids ) . ' contacts found but no batches could be scheduled — all ' . $batch_count . ' batch(es) failed to queue.'
                    );
                } elseif ( $failed_chunks > 0 ) {
                    Disciple_Tools_CRM_Sync_Logger::write(
                        'scheduled',
                        $filter_id,
                        null,
                        'failed',
                        count( $all_ids ) . ' contacts found, ' . $queued_count . ' of ' . $batch_count . ' batch(es) scheduled — ' . $failed_chunks . ' batch(es) failed to queue.'
                    );
                } else {
                    Disciple_Tools_CRM_Sync_Logger::write(
                        'scheduled',
                        $filter_id,
                        null,
                        'success',
                        count( $all_ids ) . ' contacts found, ' . $batch_count . ' batch(es) scheduled.'
                    );
                }
            } finally {
                delete_transient( $lock_key );
            }
        }
    }
}
