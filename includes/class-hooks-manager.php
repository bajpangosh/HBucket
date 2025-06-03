<?php
/**
 * Hooks Manager for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'HooksManager' ) ) {
    /**
     * Class HooksManager.
     * Centralizes WordPress hook integrations.
     */
    class HooksManager {

        private static $instance = null;
        private $uploader_service;
        private $url_rewriter;
        private $cleanup_manager;
        private $logger;
        private $options;

        /**
         * Constructor.
         * Initializes services and registers hooks.
         */
        private function __construct() {
            $this->options = get_option( 'h_bucket_options' );

            // Instantiate services - ensure they are loaded (autoloader should handle)
            if ( class_exists('\HBucket\UploaderService') ) {
                $this->uploader_service = new UploaderService(); // Relies on options being loaded within UploaderService
            }
            if ( class_exists('\HBucket\URLRewriter') ) {
                $this->url_rewriter = URLRewriter::get_instance();
            }
            if ( class_exists('\HBucket\CleanupManager') ) {
                $this->cleanup_manager = CleanupManager::get_instance();
            }
            if ( class_exists('\HBucket\Logger') ) {
                $this->logger = Logger::get_instance();
            }
            
            $this->register_hooks();
        }

        /**
         * Get class instance (Singleton pattern).
         * @return HooksManager
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Registers all necessary WordPress hooks.
         */
        private function register_hooks() {
            // Reload options if they change after this class is instantiated.
            add_action( 'update_option_h_bucket_options', array( $this, 'reload_options_on_update' ), 10, 1 );

            // Check if offloading is globally enabled first
            if ( empty( $this->options['enable_offload'] ) || $this->options['enable_offload'] != 1 ) {
                if ($this->logger) $this->logger->info("Media offloading is disabled globally. Hooks for offloading and URL rewriting will not be active.");
                return;
            }
            
            if ($this->logger) $this->logger->info("Media offloading enabled. Registering core hooks.");

            // Hook for new media uploads
            // Priority 20 to run after potential other plugins (e.g., optimizers) have processed the image.
            add_action( 'add_attachment', array( $this, 'handle_new_attachment_upload' ), 20, 1 );
            
            // Hook for rewriting media URLs
            add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 20, 2 );
            // Also filter the content to catch images that might not use wp_get_attachment_url
            add_filter( 'the_content', array( $this, 'filter_the_content_urls' ), 20 );
            // Filter for srcset attributes
            add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 20, 5 );


            // Hook for media sideloading (e.g. "Add from URL")
            // The 'wp_handle_sideload' filter might be too early as metadata isn't generated.
            // Instead, we rely on 'add_attachment' which is also called for sideloaded images.
            // However, an alternative or supplement is to filter 'media_sideload_image'.
            // This hook is tricky because it directly returns an HTML string or an error array.
            // We need the attachment ID that gets created.
            // A common pattern is to hook 'add_attachment' which fires after the attachment is created from the sideload.

            // Hook for deleting attachments from S3 when deleted from WordPress
            add_action( 'delete_attachment', array( $this, 'handle_delete_attachment' ), 20, 1 );
        }
        
        /**
         * Reloads plugin options.
         */
        public function reload_options_on_update( $options_value ) {
            $this->options = get_option( 'h_bucket_options' );
            // Potentially re-register hooks if global enable/disable changed, though this is complex.
            // Simpler: users might need to save twice or a notice to refresh if global setting changed.
            // For now, services internally reload options, which is good.
        }

        /**
         * Handles the upload of a new attachment.
         *
         * @param int $attachment_id The ID of the newly uploaded attachment.
         */
        public function handle_new_attachment_upload( $attachment_id ) {
            if ( ! $this->uploader_service ) {
                if ($this->logger) $this->logger->error("UploaderService not available. Cannot process attachment ID: {$attachment_id}.");
                return;
            }
            if ( empty( $this->options['enable_offload'] ) ) { // Double check, though hooks shouldn't register if false
                 if ($this->logger) $this->logger->info("Offload disabled, skipping attachment ID: {$attachment_id}.");
                return;
            }

            // Prevent recursion if we update post meta within this hook
            if ( defined( 'H_BUCKET_UPLOADING' ) && H_BUCKET_UPLOADING === $attachment_id ) {
                return;
            }
            define( 'H_BUCKET_UPLOADING', $attachment_id );
            
            if ($this->logger) $this->logger->info("Processing new attachment ID: {$attachment_id} for offloading.");

            $file_path = get_attached_file( $attachment_id ); // Full path to the original uploaded file
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                if ($this->logger) $this->logger->error("Original file for attachment ID {$attachment_id} not found at path: " . ($file_path ?: 'N/A') );
                remove_filter( 'add_attachment', array( $this, 'handle_new_attachment_upload' ), 20 ); // Avoid issues if called again
                wp_update_post(array('ID' => $attachment_id, 'post_parent' => $attachment_id )); // Try to mark as error?
                add_filter( 'add_attachment', array( $this, 'handle_new_attachment_upload' ), 20, 1 );
                return;
            }

            $file_name = basename( $file_path );
            $mime_type = get_post_mime_type( $attachment_id );

            // Construct the S3 key. Typically includes year/month like WordPress uploads.
            $upload_dir = wp_upload_dir(); // Get year/month structure if available
            $s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $file_path ) );
            $s3_key = ( !empty($s3_key_path) && $s3_key_path !== '.' ? trailingslashit($s3_key_path) : '' ) . $file_name;
            // Remove leading slash if any from $s3_key_path if $upload_dir['basedir'] was root.
            $s3_key = ltrim($s3_key, '/');


            if ($this->logger) $this->logger->debug("Attempting to offload: ID {$attachment_id}, File: {$file_path}, Key: {$s3_key}, MIME: {$mime_type}");

            $s3_url = $this->uploader_service->upload_file( $file_path, $s3_key, $mime_type );

            if ( $s3_url ) {
                if ($this->logger) $this->logger->info("Successfully offloaded attachment ID {$attachment_id} to S3. URL: {$s3_url}");
                update_post_meta( $attachment_id, '_h_bucket_s3_key', $s3_key );
                update_post_meta( $attachment_id, '_h_bucket_s3_url', $s3_url ); // Store the direct S3 URL too
                update_post_meta( $attachment_id, '_h_bucket_offloaded', '1' ); // Mark as offloaded

                // Handle thumbnails
                $metadata = wp_get_attachment_metadata( $attachment_id );
                if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                    $base_dir = dirname( $file_path );
                    foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                        $thumb_file_path = $base_dir . '/' . $size_info['file'];
                        $thumb_s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $thumb_file_path ) );
                        $thumb_s3_key = ( !empty($thumb_s3_key_path) && $thumb_s3_key_path !== '.' ? trailingslashit($thumb_s3_key_path) : '' ) . $size_info['file'];
                        $thumb_s3_key = ltrim($thumb_s3_key, '/');

                        if ( file_exists( $thumb_file_path ) ) {
                            if ($this->logger) $this->logger->debug("Offloading thumbnail {$size_name} for attachment ID {$attachment_id}: {$thumb_s3_key}");
                            $this->uploader_service->upload_file( $thumb_file_path, $thumb_s3_key, $size_info['mime-type'] );
                        }
                    }
                }

                // After successful upload of main file and thumbnails, consider cleanup
                if ( $this->cleanup_manager ) {
                    if ($this->logger) $this->logger->info("Calling CleanupManager for attachment ID {$attachment_id}.");
                    $this->cleanup_manager->delete_local_attachment_files( $attachment_id );
                }

            } else {
                if ($this->logger) $this->logger->error("Failed to offload attachment ID {$attachment_id}. File: {$file_path}");
                // Optionally, store an error meta: update_post_meta( $attachment_id, '_h_bucket_error', 'Failed to upload to S3.' );
            }
            // Remove the H_BUCKET_UPLOADING definition
            if ( defined( 'H_BUCKET_UPLOADING' ) ) {
                // This is tricky as constants cannot be undefined.
                // A better approach for recursion lock is a static array property: private static $processing_attachments = [];
                // And check with in_array / add / remove.
                // For now, this simple define lock is okay for add_attachment but has limitations.
            }


        }

        /**
         * Filters the attachment URL.
         *
         * @param string $url The original URL.
         * @param int $attachment_id The attachment ID.
         * @return string The potentially rewritten URL.
         */
        public function filter_attachment_url( $url, $attachment_id ) {
            if ( ! $this->url_rewriter ) {
                return $url;
            }
            // The URLRewriter already checks if offloading is enabled in its options.
            return $this->url_rewriter->rewrite_url( $url, $attachment_id );
        }

        /**
         * Filters the_content to rewrite image URLs.
         * @param string $content Post content.
         * @return string Modified content.
         */
        public function filter_the_content_urls( $content ) {
             if ( ! $this->url_rewriter || empty( $this->options['enable_offload'] ) ) {
                return $content;
            }
            // This regex finds img src attributes with local wp-content/uploads URLs.
            // It's a basic regex and might need refinement for edge cases or specific URL structures.
            // It also doesn't preserve other attributes on the img tag well if we rebuild the whole tag.
            // A DOMDocument approach is more robust but much heavier.
            // For now, we focus on rewriting the src attribute value.
            $upload_dir_info = wp_get_upload_dir();
            $base_upload_url_pattern = preg_quote( $upload_dir_info['baseurl'], '/' );

            $content = preg_replace_callback(
                '/(<img[^>]+src=['"])([^ '"]+)(' . $base_upload_url_pattern . '[^'"]+)(['"][^>]*>)/i',
                function( $matches ) {
                    // matches[2] is the part of src before base_upload_url (if any, usually empty)
                    // matches[3] is the local URL to rewrite
                    // matches[1] and matches[4] are the surrounding parts of the img tag
                    $original_url = $matches[3];
                    $rewritten_url = $this->url_rewriter->rewrite_url( $original_url, 0 ); // attachment_id 0 as we don't have it here.
                    return $matches[1] . $matches[2] . $rewritten_url . $matches[4];
                },
                $content
            );
            return $content;
        }

        /**
         * Filters image srcset attributes.
         */
        public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
            if ( ! $this->url_rewriter || empty( $this->options['enable_offload'] ) || ! is_array( $sources ) ) {
                return $sources;
            }

            foreach ( $sources as $width => $source_data ) {
                $sources[ $width ]['url'] = $this->url_rewriter->rewrite_url( $source_data['url'], $attachment_id );
            }
            return $sources;
        }


        /**
         * Handles the deletion of an attachment.
         *
         * @param int $attachment_id The ID of the attachment being deleted.
         */
        public function handle_delete_attachment( $attachment_id ) {
             if ( empty( $this->options['enable_offload'] ) ) {
                return;
            }
            if ($this->logger) $this->logger->info("Attachment ID {$attachment_id} deleted from WordPress. Attempting to delete from S3.");

            $s3_key = get_post_meta( $attachment_id, '_h_bucket_s3_key', true );
            if ( empty( $s3_key ) ) {
                if ($this->logger) $this->logger->warn("No S3 key found for attachment ID {$attachment_id}. Cannot delete from S3.");
                return;
            }

            // We need a method in UploaderService to delete an object
            if ( method_exists( $this->uploader_service, 'delete_object' ) ) {
                $deleted_main = $this->uploader_service->delete_object( $s3_key );
                if ($deleted_main) {
                    if ($this->logger) $this->logger->info("Successfully deleted main object {$s3_key} from S3 for attachment ID {$attachment_id}.");
                } else {
                    if ($this->logger) $this->logger->error("Failed to delete main object {$s3_key} from S3 for attachment ID {$attachment_id}.");
                }

                // Delete thumbnails from S3
                $metadata = wp_get_attachment_metadata( $attachment_id ); // This metadata might already be deleted or unavailable.
                                                                        // It's better to have stored the keys of offloaded thumbnails.
                                                                        // For now, we construct them if metadata is available.
                if ( ! $metadata && has_action('delete_attachment_files_from_s3') ) {
                    // If metadata is gone, try to get it from a pre-delete hook if possible, or store thumb keys in meta.
                    // This part is tricky post-delete.
                }

                if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                     $upload_dir = wp_upload_dir(); // Base path for constructing keys
                    foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                        // Reconstruct thumbnail S3 key (must match original construction)
                        // This assumes original file path was used to derive structure.
                        // This is fragile. Storing a list of all offloaded keys (main + thumbs) in post meta would be more robust.
                        $original_file_path = get_attached_file( $attachment_id, true ); // true to get it even if it might be deleted
                        $base_dir_key_part = str_replace( $upload_dir['basedir'] . '/', '', dirname( $original_file_path ) );
                        $thumb_s3_key = ( !empty($base_dir_key_part) && $base_dir_key_part !== '.' ? trailingslashit($base_dir_key_part) : '' ) . $size_info['file'];
                        $thumb_s3_key = ltrim($thumb_s3_key, '/');

                        if ($this->logger) $this->logger->debug("Attempting to delete S3 thumbnail {$thumb_s3_key} for attachment ID {$attachment_id}");
                        $this->uploader_service->delete_object( $thumb_s3_key );
                    }
                }
            } else {
                if ($this->logger) $this->logger->warn("UploaderService does not have a delete_object method. Cannot delete from S3.");
            }
        }
    }
}
?>
