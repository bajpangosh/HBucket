<?php
/**
 * URL Rewriter for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'URLRewriter' ) ) {
    /**
     * Class URLRewriter.
     * Handles rewriting local media URLs to their Hetzner Object Storage counterparts.
     */
    class URLRewriter {

        private $options;
        private static $instance = null;

        /**
         * Constructor.
         * Loads the plugin options.
         */
        private function __construct() {
            $this->options = get_option( 'h_bucket_options' );
            // Ensure options are reloaded if they change after object instantiation
            add_action( 'update_option_h_bucket_options', array( $this, 'reload_options_on_update' ), 10, 3 );
        }
        
        /**
         * Callback for when options are updated.
         */
        public function reload_options_on_update( $old_value, $value, $option_name ) {
            $this->reload_options();
        }


        /**
         * Get class instance (Singleton pattern).
         *
         * @return URLRewriter
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Rewrites a local WordPress media URL to its Hetzner equivalent.
         *
         * @param string $url The original WordPress attachment URL.
         * @param int $attachment_id The attachment ID.
         * @return string The original or rewritten URL.
         */
        public function rewrite_url( $url, $attachment_id = 0 ) {
            if ( empty( $this->options['enable_offload'] ) || $this->options['enable_offload'] != 1 ) {
                return $url;
            }

            if ( empty( $this->options['endpoint'] ) || empty( $this->options['bucket_name'] ) ) {
                error_log("H Bucket URL Rewriter: Endpoint or Bucket Name not set. Cannot rewrite URL for attachment ID: " . $attachment_id);
                return $url;
            }

            // Get upload directory information.
            // Using wp_get_upload_dir() is more reliable than wp_upload_dir() in some contexts.
            $upload_dir_info = wp_get_upload_dir();
            if ( $upload_dir_info['error'] ) {
                error_log("H Bucket URL Rewriter: Could not get upload directory info. Error: " . $upload_dir_info['error']);
                return $url;
            }
            $base_upload_url = $upload_dir_info['baseurl'];
            
            if ( strpos( $url, $base_upload_url ) !== 0 ) {
                return $url;
            }
            
            // If an attachment ID is provided, try to get its S3 key from post meta first.
            // This is more reliable as it confirms the file was successfully offloaded
            // and what its key is (in case of sanitization or modification).
            if ( $attachment_id > 0 ) {
                $s3_key = get_post_meta( $attachment_id, '_h_bucket_s3_key', true );
                if ( empty( $s3_key ) ) {
                    // If no S3 key meta, it might not be offloaded or it's an older version.
                    // Fallback to path generation or simply don't rewrite?
                    // For now, let's be strict: if we have an ID, we expect a meta key.
                    // This prevents rewriting URLs for files that failed to upload or were not processed.
                    // However, for broad URL filtering (like in content), attachment_id might be 0.
                    // So, we need a strategy for that.
                    // For now, if ID is present but no meta, don't rewrite.
                     return $url; 
                }
            } else {
                // No attachment ID, derive S3 key from URL structure.
                // This is less reliable but necessary for general URL filtering (e.g. in post content).
                $s3_object_path = str_replace( $base_upload_url, '', $url );
                $s3_key = ltrim( $s3_object_path, '/' );
            }


            if ( empty( $s3_key ) ) {
                return $url; 
            }
            
            $hetzner_endpoint = rtrim( esc_url_raw( $this->options['endpoint'] ), '/' );
            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            // Consistent with S3Client 'use_path_style_endpoint' => true
            $new_url = sprintf( '%s/%s/%s', $hetzner_endpoint, $bucket_name, $s3_key );
            
            // Allow developers to filter the final S3 URL
            $new_url = apply_filters( 'h_bucket_final_s3_url', $new_url, $url, $attachment_id, $s3_key, $this->options );

            return $new_url;
        }

        /**
         * Reloads plugin options.
         */
        public function reload_options() {
            $this->options = get_option( 'h_bucket_options' );
        }
    }
}
?>
