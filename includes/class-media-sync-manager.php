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
            // Hook for AJAX actions related to migration will be added later
            // add_action( 'wp_ajax_h_bucket_get_library_status', array( $this, 'ajax_get_library_status' ) );
            // add_action( 'wp_ajax_h_bucket_migrate_batch', array( $this, 'ajax_migrate_batch' ) );
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
    }
}
?>
