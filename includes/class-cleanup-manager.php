<?php
/**
 * Cleanup Manager for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'CleanupManager' ) ) {
    /**
     * Class CleanupManager.
     * Handles deletion of local media files after successful offload.
     */
    class CleanupManager {

        private static $instance = null;
        private $options;

        /**
         * Constructor.
         */
        private function __construct() {
            $this->options = get_option( 'h_bucket_options' );
            // Hook to reload options if they change
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
         * @return CleanupManager
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Deletes local copies of an attachment's files if conditions are met.
         *
         * @param int $attachment_id The ID of the attachment to clean up.
         * @return bool True if deletion was attempted and successful or not required, false on error or if conditions not met for deletion.
         */
        public function delete_local_attachment_files( $attachment_id ) {
            // Check if 'Delete Local Copy' option is enabled
            if ( empty( $this->options['delete_local_copy'] ) || $this->options['delete_local_copy'] != 1 ) {
                // Optional: Log that deletion is disabled.
                // error_log("H Bucket Cleanup: Deletion of local files is disabled. Attachment ID: {$attachment_id}");
                return true; // Not an error, just not enabled.
            }

            // Verify the attachment ID is valid
            if ( !is_numeric( $attachment_id ) || $attachment_id <= 0 ) {
                error_log("H Bucket Cleanup: Invalid Attachment ID provided: {$attachment_id}");
                return false;
            }
            
            // Verify the post is an attachment
            if ( get_post_type( $attachment_id ) !== 'attachment' ) {
                error_log("H Bucket Cleanup: Post ID {$attachment_id} is not an attachment.");
                return false;
            }

            // Check if the file has been successfully offloaded by looking for the S3 key meta
            $s3_key = get_post_meta( $attachment_id, '_h_bucket_s3_key', true );
            if ( empty( $s3_key ) ) {
                error_log("H Bucket Cleanup: Attachment ID {$attachment_id} has no '_h_bucket_s3_key' meta. Skipping deletion as it may not be offloaded.");
                return false; // Not offloaded or meta key missing, so don't delete.
            }

            // At this point, deletion is enabled and the file seems to be offloaded.
            // WordPress manages various file sizes (thumbnails).
            // `wp_delete_attachment_files` is an internal WordPress function that is not directly available.
            // The typical way to delete attachment files is `wp_delete_attachment( $attachment_id, true )`,
            // but this also deletes the WordPress post entry and metadata, which we do NOT want.
            // We only want to delete the FILES, not the attachment post itself.

            // Get all file paths associated with the attachment
            $attached_file = get_attached_file( $attachment_id ); // Full path to the original file
            
            if ( ! $attached_file || ! file_exists( $attached_file ) ) {
                 error_log("H Bucket Cleanup: Original file for attachment ID {$attachment_id} not found at path: " . ($attached_file ? $attached_file : 'N/A'));
                 // It might be already deleted, consider this a success or a specific state.
                 // Let's mark it as true because the goal is for the file to not be there.
                 update_post_meta( $attachment_id, '_h_bucket_local_file_deleted', '1' );
                 return true;
            }

            // The `wp_delete_attachment_files` action hook is called by `wp_delete_attachment()`.
            // We need to replicate its core logic for deleting files without deleting the post.
            // This includes the main file and all its intermediate sizes (thumbnails).
            
            $backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ); // For scaled images
            $metadata = wp_get_attachment_metadata( $attachment_id );

            // Start with the main attached file
            // Ensure we don't delete files outside of the uploads directory as a safety measure.
            $upload_dir = wp_get_upload_dir();
            if ( strpos( realpath($attached_file), realpath($upload_dir['basedir']) ) !== 0 ) {
                error_log("H Bucket Cleanup: File path {$attached_file} is outside of uploads directory. Deletion aborted for safety.");
                return false;
            }
            
            // Try to delete the main file
            if ( ! @unlink( $attached_file ) ) {
                 error_log("H Bucket Cleanup: Could not delete main file for attachment ID {$attachment_id} at path: {$attached_file}");
                 // If the main file can't be deleted, we probably shouldn't proceed with thumbnails.
                 return false; 
            } else {
                // Log success for main file
                // error_log("H Bucket Cleanup: Successfully deleted main file {$attached_file}");
            }

            // Delete intermediate image sizes (thumbnails)
            if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                $base_dir = dirname( $attached_file );
                foreach ( $metadata['sizes'] as $size_info ) {
                    $thumb_file = $base_dir . '/' . $size_info['file'];
                     if ( strpos( realpath($thumb_file), realpath($upload_dir['basedir']) ) !== 0 ) {
                        error_log("H Bucket Cleanup: Thumbnail path {$thumb_file} is outside of uploads directory. Skipping.");
                        continue;
                    }
                    if ( file_exists( $thumb_file ) ) {
                        if ( ! @unlink( $thumb_file ) ) {
                            error_log("H Bucket Cleanup: Could not delete thumbnail for attachment ID {$attachment_id} at path: {$thumb_file}");
                            // Continue trying to delete other thumbnails even if one fails.
                        } else {
                            // error_log("H Bucket Cleanup: Successfully deleted thumbnail {$thumb_file}");
                        }
                    }
                }
            }
            
            // Delete backup sizes if they exist (for scaled images)
            if ( is_array( $backup_sizes ) ) {
                $base_dir = dirname( $attached_file );
                foreach ( $backup_sizes as $size_info ) {
                    $backup_file = $base_dir . '/' . $size_info['file'];
                    if ( strpos( realpath($backup_file), realpath($upload_dir['basedir']) ) !== 0 ) {
                        error_log("H Bucket Cleanup: Backup image path {$backup_file} is outside of uploads directory. Skipping.");
                        continue;
                    }
                    if ( file_exists( $backup_file ) ) {
                       if ( ! @unlink( $backup_file ) ) {
                            error_log("H Bucket Cleanup: Could not delete backup image for attachment ID {$attachment_id} at path: {$backup_file}");
                       } else {
                            // error_log("H Bucket Cleanup: Successfully deleted backup image {$backup_file}");
                       }
                    }
                }
            }

            // After successful deletion of all files, mark it so we don't try again
            // And to potentially hide the "Delete local copy" option for this file in UI later
            update_post_meta( $attachment_id, '_h_bucket_local_file_deleted', '1' );
            
            // Also, remove the _wp_attachment_metadata if all files are gone,
            // as WordPress might try to regenerate thumbnails if it exists but files are missing.
            // However, this might have side effects. A safer approach is to update it to reflect no files.
            // For now, let's leave metadata and see. If issues arise, clearing/updating metadata might be needed.
            // Alternative: update_attached_file($attachment_id, ''); // This might be too much.

            // It's also good practice to clear statcache
            clearstatcache();

            // error_log("H Bucket Cleanup: Successfully deleted all local files for attachment ID {$attachment_id}.");
            return true;
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
