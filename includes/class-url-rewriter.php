<?php
/**
 * URL Rewriter for H Bucket
 *
 * This class is responsible for rewriting local WordPress media URLs
 * to their corresponding Hetzner Object Storage URLs if the media
 * has been offloaded.
 *
 * @package H_Bucket
 * @since   1.0.0
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
     * Implements a singleton pattern.
     *
     * @since 1.0.0
     */
    class URLRewriter {

        /**
         * Plugin options.
         *
         * @since 1.0.0
         * @var   array|false Stores the plugin options retrieved from the database.
         */
        private $options;

        /**
         * The single instance of the class.
         *
         * @since 1.0.0
         * @var   URLRewriter|null
         */
        private static $instance = null;

        /**
         * Constructor.
         * Loads the plugin options and hooks into the option update action
         * to ensure options are current. Private to prevent direct instantiation.
         *
         * @since 1.0.0
         */
        private function __construct() {
            $this->options = get_option( 'h_bucket_options' );
            // Ensure options are reloaded if they change after object instantiation.
            add_action( 'update_option_h_bucket_options', array( $this, 'reload_options_on_update' ), 10, 3 );
        }
        
        /**
         * Callback for the 'update_option_h_bucket_options' action.
         * Reloads the options when they are updated in the database.
         *
         * @since 1.0.0
         * @param mixed $old_value The old option value.
         * @param mixed $value     The new option value.
         * @param string $option_name The name of the option being updated.
         */
        public function reload_options_on_update( $old_value, $value, $option_name ) {
            $this->reload_options();
        }


        /**
         * Get class instance (Singleton pattern).
         * Ensures only one instance of the URLRewriter exists.
         *
         * @since 1.0.0
         * @return URLRewriter The singleton instance of the URLRewriter class.
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
         * This method checks if offloading is enabled and if the necessary
         * configuration (endpoint, bucket name) is present. It then attempts
         * to construct the S3 URL based on either stored post meta (if attachment ID
         * is provided and meta exists) or by deriving the S3 key from the URL structure.
         *
         * @since 1.0.0
         * @param string $url The original WordPress attachment URL.
         * @param int    $attachment_id Optional. The attachment ID. If provided, used to look up S3 metadata.
         * @return string The original URL if conditions for rewrite are not met, or the rewritten S3 URL.
         */
        public function rewrite_url( $url, $attachment_id = 0 ) {
            // Check if offloading is enabled in plugin settings.
            if ( empty( $this->options['enable_offload'] ) || $this->options['enable_offload'] != 1 ) {
                return $url;
            }

            // Check for essential configuration: endpoint and bucket name.
            if ( empty( $this->options['endpoint'] ) || empty( $this->options['bucket_name'] ) ) {
                if (class_exists('\HBucket\Logger') && $attachment_id > 0) { // Log only if specific attachment context
                    Logger::get_instance()->warn("URLRewriter: Endpoint or Bucket Name not set. Cannot rewrite URL for attachment ID: {$attachment_id}");
                }
                return $url;
            }

            // Get WordPress upload directory information.
            $upload_dir_info = wp_get_upload_dir();
            if ( !empty($upload_dir_info['error']) ) {
                 if (class_exists('\HBucket\Logger')) {
                    Logger::get_instance()->error("URLRewriter: Could not get upload directory info. Error: " . print_r($upload_dir_info['error'], true));
                }
                return $url;
            }
            $base_upload_url = $upload_dir_info['baseurl'];
            
            // Only rewrite URLs that are part of the local WordPress uploads.
            if ( strpos( $url, $base_upload_url ) !== 0 ) {
                return $url;
            }
            
            $s3_key = '';
            // If an attachment ID is provided, try to get its S3 key from post meta.
            // This is more reliable as it confirms the file was successfully offloaded.
            if ( $attachment_id > 0 ) {
                $s3_key = get_post_meta( $attachment_id, '_h_bucket_s3_key', true );
                if ( empty( $s3_key ) ) {
                    // If no S3 key meta, it might not be offloaded or is an older version not processed.
                    // Strict approach: if ID is present but no meta, don't rewrite, as it implies it's not (yet) on S3.
                     return $url; 
                }
            } else {
                // No attachment ID, derive S3 key from URL structure.
                // This is necessary for general URL filtering (e.g., in post content via regex).
                $s3_object_path = str_replace( $base_upload_url . '/', '', $url ); // Ensure leading slash is removed from path part
                $s3_key = $s3_object_path;
            }

            // If no S3 key could be determined, return the original URL.
            if ( empty( $s3_key ) ) {
                return $url; 
            }
            
            $hetzner_endpoint = rtrim( esc_url_raw( $this->options['endpoint'] ), '/' );
            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            // Construct the S3 URL (path-style).
            $new_url = sprintf( '%s/%s/%s', $hetzner_endpoint, $bucket_name, $s3_key );
            
            /**
             * Filters the final S3 URL before it's returned by the URLRewriter.
             *
             * @since 1.0.0
             * @param string $new_url       The S3 URL constructed by the plugin.
             * @param string $url           The original WordPress URL.
             * @param int    $attachment_id The attachment ID (0 if not available).
             * @param string $s3_key        The S3 object key.
             * @param array  $options       The H Bucket plugin options.
             */
            $new_url = apply_filters( 'h_bucket_final_s3_url', $new_url, $url, $attachment_id, $s3_key, $this->options );

            return $new_url;
        }

        /**
         * Reloads plugin options from the database.
         * Useful if options are changed after the singleton is instantiated.
         *
         * @since 1.0.0
         */
        public function reload_options() {
            $this->options = get_option( 'h_bucket_options' );
        }
    }
}
?>
