<?php
/**
 * Media Sync Manager for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'MediaSyncManager' ) ) {
    /**
     * Class MediaSyncManager.
     * Handles bulk migration of media to Hetzner Object Storage.
     */
    class MediaSyncManager {

        private static $instance = null;
        private $options;

        /**
         * Constructor.
         */
        private function __construct() {
            $this->options = get_option( 'h_bucket_options' );
                add_action( 'wp_ajax_h_bucket_migration_status', array( $this, 'ajax_get_migration_status' ) );
                add_action( 'wp_ajax_h_bucket_migrate_batch', array( $this, 'ajax_migrate_batch' ) );
        }

        /**
         * Get class instance (Singleton pattern).
         * @return MediaSyncManager
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Retrieves a list of local media attachments that might need offloading.
         * 
         * @param int $limit Number of attachments to retrieve.
         * @param int $offset Offset for retrieval (for pagination).
         * @param bool $only_not_offloaded If true, tries to fetch only items not yet offloaded.
         * @return array List of WP_Post objects representing media attachments.
         */
        public function get_local_media_attachments( $limit = -1, $offset = 0, $only_not_offloaded = false ) {
            $args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit', // 'inherit' is typical for attachments
                'posts_per_page' => $limit,
                'offset'         => $offset,
            );

            if ( $only_not_offloaded ) {
                // This meta query will find attachments that DO NOT HAVE the '_h_bucket_s3_key' meta key.
                // This means they haven't been successfully offloaded by this plugin.
                $args['meta_query'] = array(
                    array(
                        'key'     => '_h_bucket_s3_key',
                        'compare' => 'NOT EXISTS',
                    ),
                );
            }
            
            $query = new \WP_Query( $args );
            return $query->get_posts();
        }
        
        /**
         * Counts local media attachments.
         * @param bool $only_not_offloaded If true, counts only items not yet offloaded.
         * @return int Total number of attachments.
         */
        public function count_local_media_attachments( $only_not_offloaded = false ) {
            $args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1, // Count all
                'fields'         => 'ids', // Only need IDs for counting
            );

            if ( $only_not_offloaded ) {
                $args['meta_query'] = array(
                    array(
                        'key'     => '_h_bucket_s3_key',
                        'compare' => 'NOT EXISTS',
                    ),
                );
            }
            
            $query = new \WP_Query( $args );
            return $query->post_count;
        }

        /**
         * Counts offloaded media attachments.
         * @return int Total number of offloaded attachments.
         */
        public function count_offloaded_media_attachments() {
            $args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids', 
                'meta_query'     => array(
                    array(
                        'key'     => '_h_bucket_s3_key',
                        'compare' => 'EXISTS',
                    ),
                ),
            );
            $query = new \WP_Query( $args );
            return $query->post_count;
            }

            public function ajax_get_migration_status() {
                // Nonce check and capability check
                check_ajax_referer( 'h_bucket_migration_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => 'Permission denied.' ) );
                }

                $total_local = $this->count_local_media_attachments( false );
                $total_offloaded = $this->count_offloaded_media_attachments();
                $total_not_offloaded = $this->count_local_media_attachments( true ); // This is what we want to migrate

                wp_send_json_success( array(
                    'total_items' => $total_not_offloaded, // Total items that need processing for this migration run
                    'processed_items' => 0, // This will be updated by client or could be a separate count of 'in_progress_or_done_by_this_run'
                    'offloaded_overall' => $total_offloaded, // Overall offloaded count
                    'local_overall' => $total_local
                ) );
            }

            public function ajax_migrate_batch() {
                // Nonce check and capability check
                check_ajax_referer( 'h_bucket_migration_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => 'Permission denied.' ) );
                }

                $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 10; // Default batch size
                if ($batch_size <= 0) $batch_size = 10;

                $logger = class_exists('\HBucket\Logger') ? Logger::get_instance() : null;
                $uploader_service = class_exists('\HBucket\UploaderService') ? new UploaderService() : null;
                $cleanup_manager = class_exists('\HBucket\CleanupManager') ? CleanupManager::get_instance() : null;
                $options = get_option('h_bucket_options'); // For delete_local_copy

                if ( ! $uploader_service ) {
                    if ($logger) $logger->error("MediaSyncManager (AJAX): UploaderService not available.");
                    wp_send_json_error( array('message' => 'UploaderService not available.') );
                }
                
                $attachments = $this->get_local_media_attachments( $batch_size, 0, true /* only_not_offloaded */ );
                
                $processed_in_batch = 0;
                $success_in_batch = 0;
                $errors_in_batch = 0;
                $log_messages = array();

                if ( empty( $attachments ) ) {
                    wp_send_json_success( array(
                        'items_processed_in_batch' => 0,
                        'items_succeeded_in_batch' => 0,
                        'items_failed_in_batch' => 0,
                        'log_messages' => array('No more items to process.'),
                        'remaining_to_process' => 0 // No more items
                    ) );
                    return;
                }

                foreach ( $attachments as $attachment ) {
                    $processed_in_batch++;
                    $file_path = get_attached_file( $attachment->ID );

                    if ( ! $file_path || ! file_exists( $file_path ) ) {
                        $msg = "Skipped Attachment ID {$attachment->ID}: File not found at path: " . ($file_path ?: 'N/A');
                        if ($logger) $logger->warn($msg);
                        $log_messages[] = $msg;
                        $errors_in_batch++;
                        continue;
                    }

                    $file_name = basename( $file_path );
                    $mime_type = get_post_mime_type( $attachment->ID );
                    $upload_dir = wp_upload_dir();
                    $s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $file_path ) );
                    $s3_key = ( !empty($s3_key_path) && $s3_key_path !== '.' ? trailingslashit($s3_key_path) : '' ) . $file_name;
                    $s3_key = ltrim($s3_key, '/');

                    if ($logger) $logger->debug("MediaSyncManager (AJAX): Processing attachment ID {$attachment->ID}, Key: {$s3_key}");
                    $s3_url = $uploader_service->upload_file( $file_path, $s3_key, $mime_type );

                    if ( $s3_url ) {
                        update_post_meta( $attachment->ID, '_h_bucket_s3_key', $s3_key );
                        update_post_meta( $attachment->ID, '_h_bucket_s3_url', $s3_url );
                        update_post_meta( $attachment->ID, '_h_bucket_offloaded', '1' );
                        $msg = "Successfully offloaded Attachment ID {$attachment->ID}: {$s3_key}";
                        if ($logger) $logger->info($msg);
                        $log_messages[] = $msg;
                        $success_in_batch++;

                        // Handle thumbnails
                        $metadata = wp_get_attachment_metadata( $attachment->ID );
                        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                            $base_dir = dirname( $file_path );
                            foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                                $thumb_file_path = $base_dir . '/' . $size_info['file'];
                                $thumb_s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $thumb_file_path ) );
                                $thumb_s3_key = ( !empty($thumb_s3_key_path) && $thumb_s3_key_path !== '.' ? trailingslashit($thumb_s3_key_path) : '' ) . $size_info['file'];
                                $thumb_s3_key = ltrim($thumb_s3_key, '/');
                                if ( file_exists( $thumb_file_path ) ) {
                                    $thumb_s3_url = $uploader_service->upload_file( $thumb_file_path, $thumb_s3_key, $size_info['mime-type'] );
                                    if ($thumb_s3_url) {
                                        $log_messages[] = "Offloaded thumbnail {$size_name} for ID {$attachment->ID}";
                                    } else {
                                         $log_messages[] = "Failed to offload thumbnail {$size_name} for ID {$attachment->ID}";
                                    }
                                }
                            }
                        }

                        if ( !empty($options['delete_local_copy']) && $cleanup_manager ) {
                            if ($cleanup_manager->delete_local_attachment_files( $attachment->ID )) {
                                 $log_messages[] = "Local files deleted for ID {$attachment->ID}";
                            } else {
                                 $log_messages[] = "Failed to delete local files for ID {$attachment->ID}";
                            }
                        }
                    } else {
                        $msg = "Failed to offload Attachment ID {$attachment->ID}.";
                        if ($logger) $logger->error($msg . " File: {$file_path}");
                        $log_messages[] = $msg;
                        $errors_in_batch++;
                    }
                } // end foreach

                $remaining_to_process = $this->count_local_media_attachments( true );

                wp_send_json_success( array(
                    'items_processed_in_batch' => $processed_in_batch,
                    'items_succeeded_in_batch' => $success_in_batch,
                    'items_failed_in_batch' => $errors_in_batch,
                    'log_messages' => $log_messages,
                    'remaining_to_process' => $remaining_to_process
                ) );
        }
    }
}
?>
