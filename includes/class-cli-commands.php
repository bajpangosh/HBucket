<?php
/**
 * WP-CLI Commands for H Bucket
 *
 * @package H_Bucket
 */

// Ensure WP-CLI is running
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

namespace HBucket;

// Make sure autoloader has loaded other classes if needed, or require them.
// For UploaderService, MediaSyncManager, Logger.

/**
 * Manages H Bucket media offloading via WP-CLI.
 */
class CLI_Commands extends \WP_CLI_Command {

    private $logger;
    private $uploader_service;
    private $media_sync_manager;
    private $options;

    public function __construct() {
        // Ensure dependent classes are loaded (handled by autoloader if called correctly)
        if ( class_exists('\HBucket\Logger') ) {
            $this->logger = Logger::get_instance();
        }
        if ( class_exists('\HBucket\UploaderService') ) {
            $this->uploader_service = new UploaderService(); // UploaderService loads its own options
        }
        if ( class_exists('\HBucket\MediaSyncManager') ) {
            $this->media_sync_manager = MediaSyncManager::get_instance();
        }
        $this->options = get_option( 'h_bucket_options' );
    }

    /**
     * Offloads all media attachments that have not yet been offloaded.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Perform a dry run; count files and report but do not actually offload.
     * 
     * [--batch-size=<number>]
     * : Process attachments in batches. Default is 50.
     * 
     * [--sleep=<seconds>]
     * : Sleep for a specified number of seconds between batches. Default is 1.
     *
     * ## EXAMPLES
     *
     *     wp hetzner offload all
     *     wp hetzner offload all --dry-run
     *     wp hetzner offload all --batch-size=100 --sleep=2
     *
     * @when after_wp_load
     */
    public function offload( $args, $assoc_args ) {
        if ( empty( $this->options['enable_offload'] ) ) {
            \WP_CLI::warning( "Media offloading is currently disabled in plugin settings. Enable it to offload media." );
            return;
        }
        if ( ! $this->uploader_service ) {
             \WP_CLI::error("UploaderService is not available. Cannot proceed.");
             return;
        }
         if ( ! $this->media_sync_manager ) {
             \WP_CLI::error("MediaSyncManager is not available. Cannot proceed.");
             return;
        }

        $dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
        $batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 50;
        $sleep_duration = isset( $assoc_args['sleep'] ) ? intval( $assoc_args['sleep'] ) : 1;

        if ($batch_size <=0) $batch_size = 50;
        if ($sleep_duration <0) $sleep_duration = 0;


        if ( $dry_run ) {
            \WP_CLI::line( "Performing a dry run. No files will be offloaded." );
        }

        \WP_CLI::line( "Fetching attachments that need offloading..." );
        
        $total_to_offload = $this->media_sync_manager->count_local_media_attachments( true /* only_not_offloaded */ );

        if ( $total_to_offload == 0 ) {
            \WP_CLI::success( "No media attachments found that require offloading." );
            return;
        }

        \WP_CLI::line( "Found {$total_to_offload} attachments to process." );
        
        $progress = \WP_CLI\Utils\make_progress_bar( 'Offloading Media', $total_to_offload );
        $offset = 0;
        $processed_count = 0;
        $success_count = 0;
        $error_count = 0;

        while ( $processed_count < $total_to_offload ) {
            $attachments = $this->media_sync_manager->get_local_media_attachments( $batch_size, $offset, true /* only_not_offloaded */);
            
            if ( empty( $attachments ) ) {
                // Should not happen if count was > 0 and we are iterating correctly,
                // but good as a safe break. Could mean items were offloaded by another process.
                break; 
            }

            foreach ( $attachments as $attachment ) {
                \WP_CLI::debug( "Processing attachment ID: {$attachment->ID} ('{$attachment->post_title}')" );

                if ( $dry_run ) {
                    \WP_CLI::line( "[Dry Run] Would offload attachment ID: {$attachment->ID} - " . get_attached_file( $attachment->ID ) );
                    $success_count++;
                } else {
                    // Re-use logic from HooksManager::handle_new_attachment_upload if possible, or replicate carefully
                    $file_path = get_attached_file( $attachment->ID );
                    if ( ! $file_path || ! file_exists( $file_path ) ) {
                        \WP_CLI::warning( "File for attachment ID {$attachment->ID} not found. Path: " . ($file_path ?: 'N/A') );
                        $error_count++;
                        $progress->tick();
                        continue;
                    }

                    $file_name = basename( $file_path );
                    $mime_type = get_post_mime_type( $attachment->ID );
                    $upload_dir = wp_upload_dir();
                    $s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $file_path ) );
                    $s3_key = ( !empty($s3_key_path) && $s3_key_path !== '.' ? trailingslashit($s3_key_path) : '' ) . $file_name;
                    $s3_key = ltrim($s3_key, '/');

                    $s3_url = $this->uploader_service->upload_file( $file_path, $s3_key, $mime_type );

                    if ( $s3_url ) {
                        \WP_CLI::debug( "Successfully offloaded attachment ID {$attachment->ID} to S3. URL: {$s3_url}" );
                        update_post_meta( $attachment->ID, '_h_bucket_s3_key', $s3_key );
                        update_post_meta( $attachment->ID, '_h_bucket_s3_url', $s3_url );
                        update_post_meta( $attachment->ID, '_h_bucket_offloaded', '1' );
                        $success_count++;

                        // Offload thumbnails
                        $metadata = wp_get_attachment_metadata( $attachment->ID );
                        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                            $base_dir = dirname( $file_path );
                            foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                                $thumb_file_path = $base_dir . '/' . $size_info['file'];
                                 $thumb_s3_key_path = str_replace( $upload_dir['basedir'] . '/', '', dirname( $thumb_file_path ) );
                                $thumb_s3_key = ( !empty($thumb_s3_key_path) && $thumb_s3_key_path !== '.' ? trailingslashit($thumb_s3_key_path) : '' ) . $size_info['file'];
                                $thumb_s3_key = ltrim($thumb_s3_key, '/');
                                if ( file_exists( $thumb_file_path ) ) {
                                    $this->uploader_service->upload_file( $thumb_file_path, $thumb_s3_key, $size_info['mime-type'] );
                                }
                            }
                        }
                        
                        // Cleanup local files if enabled
                        if ( !empty($this->options['delete_local_copy']) && class_exists('\HBucket\CleanupManager') ) {
                            $cleanup_manager = CleanupManager::get_instance();
                            if ( $cleanup_manager->delete_local_attachment_files( $attachment->ID ) ){
                                 \WP_CLI::debug("Local files deleted for {$attachment->ID}");
                            } else {
                                 \WP_CLI::warning("Failed to delete local files for {$attachment->ID}");
                            }
                        }

                    } else {
                        \WP_CLI::warning( "Failed to offload attachment ID {$attachment->ID}. File: {$file_path}" );
                        $error_count++;
                    }
                }
                $progress->tick();
            } // end foreach attachment
            
            $processed_count += count($attachments);
            // $offset += $batch_size; // No, offset is not needed as we always query for NOT EXISTS _h_bucket_s3_key
            
            if ( $processed_count < $total_to_offload && $sleep_duration > 0 && !$dry_run) {
                \WP_CLI::debug( "Sleeping for {$sleep_duration} seconds..." );
                sleep( $sleep_duration );
            }
        } // end while

        $progress->finish();

        if ( $dry_run ) {
            \WP_CLI::success( "Dry run complete. {$success_count} attachments would have been processed." );
        } else {
            \WP_CLI::line( "--------------------------------------------------" );
            \WP_CLI::line( "Bulk offload process complete." );
            \WP_CLI::success( "Successfully offloaded attachments: {$success_count}" );
            if ( $error_count > 0 ) {
                \WP_CLI::warning( "Failed to offload attachments: {$error_count}" );
            } else {
                \WP_CLI::line( "All pending attachments processed without errors." );
            }
            \WP_CLI::line( "--------------------------------------------------" );
        }
    }

    /**
     * Displays the current status and configuration of the H Bucket plugin.
     *
     * ## EXAMPLES
     *
     *     wp hetzner status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        \WP_CLI::line( "=== H Bucket Status ===" );

        $is_enabled = !empty( $this->options['enable_offload'] ) ? "Enabled" : "Disabled";
        \WP_CLI::line( "Media Offloading: " . $is_enabled );

        if ( $this->media_sync_manager ) {
            $total_local = $this->media_sync_manager->count_local_media_attachments(false);
            $total_offloaded = $this->media_sync_manager->count_offloaded_media_attachments();
            $needs_offload = $this->media_sync_manager->count_local_media_attachments(true);

            \WP_CLI::line( "Total media items in library: {$total_local}" );
            \WP_CLI::line( "Media items offloaded to Hetzner: {$total_offloaded}" );
            \WP_CLI::line( "Media items needing offload: {$needs_offload}" );
        } else {
            \WP_CLI::warning("MediaSyncManager not available, cannot fetch detailed counts.");
        }

        \WP_CLI::line( "
--- Configuration ---" );
        \WP_CLI::line( "Endpoint: " . ($this->options['endpoint'] ?? 'Not set') );
        \WP_CLI::line( "Bucket Name: " . ($this->options['bucket_name'] ?? 'Not set') );
        $access_key_status = (!empty($this->options['access_key'])) ? "Set (Encrypted)" : "Not set";
        \WP_CLI::line( "Access Key: " . $access_key_status );
        $delete_local = !empty( $this->options['delete_local_copy'] ) ? "Yes" : "No";
        \WP_CLI::line( "Delete Local Copy After Offload: " . $delete_local );
        \WP_CLI::line( "Region: " . ($this->options['region'] ?? 'Not set or auto-detected') );
        
        \WP_CLI::line( "
--- Test Connection ---");
        if ($this->uploader_service) {
            if (empty($this->options['enable_offload'])) {
                 \WP_CLI::line( "Connection test skipped as offloading is disabled." );
            } else {
                \WP_CLI::line( "Attempting to connect to Hetzner Object Storage..." );
                if ($this->uploader_service->test_connection()) {
                    \WP_CLI::success( "Successfully connected to Hetzner Object Storage and bucket is accessible." );
                } else {
                    \WP_CLI::error( "Failed to connect. Check credentials, bucket name, endpoint, and region. See plugin error log for details." );
                }
            }
        } else {
             \WP_CLI::warning("UploaderService not available, cannot perform connection test.");
        }

        \WP_CLI::line( "=======================" );
    }

    /**
     * Restores a specific media attachment from Hetzner Object Storage to the local server.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The ID of the attachment to restore.
     *
     * [--force]
     * : Force download even if a local file already exists. Default is to skip if local file exists.
     *
     * ## EXAMPLES
     *
     *     wp hetzner restore 123
     *     wp hetzner restore 123 --force
     *
     * @when after_wp_load
     */
    public function restore( $args, $assoc_args ) {
        $attachment_id = $args[0] ?? null;

        if ( ! is_numeric( $attachment_id ) || $attachment_id <= 0 ) {
            \WP_CLI::error( "Invalid attachment ID provided." );
            return;
        }
        $attachment_id = intval($attachment_id);

        $post = get_post( $attachment_id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            \WP_CLI::error( "Attachment with ID {$attachment_id} not found." );
            return;
        }

        if ( ! $this->uploader_service ) {
             \WP_CLI::error("UploaderService is not available. Cannot proceed.");
             return;
        }

        $s3_key = get_post_meta( $attachment_id, '_h_bucket_s3_key', true );
        if ( empty( $s3_key ) ) {
            \WP_CLI::warning( "Attachment ID {$attachment_id} does not have an S3 key (_h_bucket_s3_key) meta value. It might not be offloaded or is already restored." );
            // Check if local file exists
            $original_file_path = get_attached_file($attachment_id, true); // true = Unfiltered path
             if ( $original_file_path && file_exists( $original_file_path ) ) {
                 \WP_CLI::line( "Local file already exists at: {$original_file_path}. No restore needed unless --force is used." );
             } else {
                 \WP_CLI::line( "No S3 key and no local file found. Cannot restore." );
             }
            return;
        }

        $force_download = isset( $assoc_args['force'] ) ? true : false;
        
        // Determine the original local path for the main file
        // wp_upload_dir() gives base paths. We need to reconstruct the year/month structure if not in $s3_key.
        // However, get_attached_file($attachment_id, true) should give the correct original path regardless of whether the file exists.
        $local_file_path = get_attached_file( $attachment_id, true ); // true for unfiltered path
        if ( empty( $local_file_path ) ) {
            \WP_CLI::error( "Could not determine the original local file path for attachment ID {$attachment_id}." );
            return;
        }

        \WP_CLI::line( "Attempting to restore main file for attachment ID {$attachment_id} from S3 key '{$s3_key}' to '{$local_file_path}'." );

        if ( file_exists( $local_file_path ) && ! $force_download ) {
            \WP_CLI::line( "Local file already exists at '{$local_file_path}'. Use --force to overwrite." );
        } else {
            if ( $this->uploader_service->download_object( $s3_key, $local_file_path ) ) {
                \WP_CLI::success( "Successfully restored main file: {$local_file_path}" );
                // Remove the meta key indicating local file was deleted
                delete_post_meta( $attachment_id, '_h_bucket_local_file_deleted' );
                // Potentially update _wp_attachment_metadata if it was altered during deletion
                // wp_update_attachment_metadata might be needed if file sizes changed or were lost
                // For now, just restoring the file.
            } else {
                \WP_CLI::error( "Failed to restore main file for attachment ID {$attachment_id} from S3 key '{$s3_key}'." );
                // If main file fails, perhaps stop for this attachment?
                return; 
            }
        }

        // Restore thumbnails
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            \WP_CLI::line( "Attempting to restore thumbnails..." );
            $upload_dir_info = wp_get_upload_dir();
            $base_dir = dirname( $local_file_path ); // Base directory of the main attachment

            foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                $thumb_local_path = $base_dir . '/' . $size_info['file'];
                
                // Reconstruct thumbnail S3 key (must match original construction during upload)
                $original_file_path_for_key_calc = get_attached_file( $attachment_id, true ); // Unfiltered path of main file
                $base_dir_key_part = str_replace( $upload_dir_info['basedir'] . '/', '', dirname( $original_file_path_for_key_calc ) );
                $thumb_s3_key = ( !empty($base_dir_key_part) && $base_dir_key_part !== '.' ? trailingslashit($base_dir_key_part) : '' ) . $size_info['file'];
                $thumb_s3_key = ltrim($thumb_s3_key, '/');

                if ( empty($thumb_s3_key) ) {
                    \WP_CLI::warning("Could not determine S3 key for thumbnail size '{$size_name}'. Skipping.");
                    continue;
                }

                \WP_CLI::line( "Restoring thumbnail '{$size_name}' from S3 key '{$thumb_s3_key}' to '{$thumb_local_path}'." );

                if ( file_exists( $thumb_local_path ) && ! $force_download ) {
                    \WP_CLI::line( "Local thumbnail '{$size_name}' already exists. Use --force to overwrite." );
                } else {
                    if ( $this->uploader_service->download_object( $thumb_s3_key, $thumb_local_path ) ) {
                        \WP_CLI::success( "Restored thumbnail '{$size_name}': {$thumb_local_path}" );
                    } else {
                        \WP_CLI::warning( "Failed to restore thumbnail '{$size_name}' for S3 key '{$thumb_s3_key}'." );
                    }
                }
            }
        }
        \WP_CLI::success( "Restore process finished for attachment ID {$attachment_id}." );
    }
}

// Register the command
if ( class_exists( '\HBucket\CLI_Commands' ) ) {
    \WP_CLI::add_command( 'hetzner', '\HBucket\CLI_Commands' );
}
?>
