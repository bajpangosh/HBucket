<?php
/**
 * Uploader Service for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Credentials\Credentials;

if ( ! class_exists( 'UploaderService' ) ) {
    /**
     * Class UploaderService.
     * Handles file uploads to Hetzner Object Storage.
     */
    class UploaderService {

        private $s3_client;
        private $options;

        /**
         * Constructor.
         * Initializes the S3 client.
         */
        public function __construct() {
            $this->options = get_option( 'h_bucket_options' );

            $access_key_encrypted = $this->options['access_key'] ?? '';
            $secret_key_encrypted = $this->options['secret_key'] ?? '';
            $decrypted_access_key = '';
            $decrypted_secret_key = '';

            if ( !empty($access_key_encrypted) ) {
                $decrypted_access_key = wp_decrypt_data( $access_key_encrypted );
                if ( false === $decrypted_access_key ) {
                    $this->log_error("Failed to decrypt Access Key. WordPress decryption function failed. The key might be corrupted or encryption settings changed.");
                    $decrypted_access_key = ''; // Treat as empty
                } elseif ( !is_string($decrypted_access_key) ) {
                     $this->log_error("Decrypted Access Key is not a string. Treating as empty.");
                     $decrypted_access_key = ''; 
                }
            }

            if ( !empty($secret_key_encrypted) ) {
                $decrypted_secret_key = wp_decrypt_data( $secret_key_encrypted );
                if ( false === $decrypted_secret_key ) {
                    $this->log_error("Failed to decrypt Secret Key. WordPress decryption function failed. The key might be corrupted or encryption settings changed.");
                    $decrypted_secret_key = ''; // Treat as empty
                } elseif ( !is_string($decrypted_secret_key) ) {
                    $this->log_error("Decrypted Secret Key is not a string. Treating as empty.");
                    $decrypted_secret_key = '';
                }
            }

            if ( empty( $this->options['endpoint'] ) || empty($decrypted_access_key) || empty($decrypted_secret_key) || empty( $this->options['bucket_name'] ) ) {
                $this->log_error("S3 client not configured: missing endpoint, access key, secret key, or bucket name. Ensure credentials are correctly saved and decrypted.");
                $this->s3_client = null; // Ensure it's null
                return;
            }
            
            $credentials = null;
            try {
                $credentials = new Credentials( $decrypted_access_key, $decrypted_secret_key );
            } catch ( \Exception $e ) {
                $this->log_error("Error creating AWS Credentials object: " . $e->getMessage());
                $this->s3_client = null;
                return;
            }
            
            $client_args = [
                'version'     => 'latest',
                'endpoint'    => esc_url_raw( $this->options['endpoint'] ),
                'credentials' => $credentials, // Assign the new Credentials object here
                'use_path_style_endpoint' => true,
            ];

            if ( ! empty( $this->options['region'] ) ) {
                $client_args['region'] = sanitize_text_field( $this->options['region'] );
            } else {
                if (preg_match('/s3\.([a-z0-9-]+)\.(projekt\.hetznercloud\.com|amazonaws\.com)/', $this->options['endpoint'], $matches)) {
                    $client_args['region'] = $matches[1];
                }
            }

            try {
                $this->s3_client = new S3Client( $client_args );
                // $this->log_error("S3 Client Initialized. Endpoint: {$client_args['endpoint']}"); // Debug log
            } catch ( \Aws\Exception\CredentialsException $e ) {
                $this->log_error("AWS Credentials Exception during S3 Client creation: " . $e->getMessage() . ". Check if keys are valid.");
                $this->s3_client = null;
            } catch ( \Aws\Exception\AwsException $e ) {
                $this->log_error("AWS SDK Exception during S3 Client creation: " . $e->getMessage() . ". AWS Request ID: " . ($e->getAwsRequestId() ?: 'N/A'));
                $this->s3_client = null;
            } catch ( \Exception $e ) {
                $this->log_error("General Exception during S3 Client creation: " . $e->getMessage());
                $this->s3_client = null;
            }
        }

        /**
         * Uploads a file to Hetzner Object Storage.
         *
         * @param string $file_path Absolute path to the file.
         * @param string $s3_key The key (path/filename) to store the file under in S3.
         * @param string $mime_type The MIME type of the file.
         * @return bool|string URL of the uploaded file on success, false on failure.
         */
        public function upload_file( $file_path, $s3_key, $mime_type ) {
            if ( ! $this->s3_client || ! file_exists( $file_path ) ) {
                 error_log("H Bucket - Upload Error: S3 client not initialized or file not found at " . $file_path);
                return false;
            }

            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            try {
                $result = $this->s3_client->putObject([
                    'Bucket'      => $bucket_name,
                    'Key'         => $s3_key,
                    'SourceFile'  => $file_path,
                    'ContentType' => $mime_type,
                    'ACL'         => 'public-read',
                ]);

                if ( isset( $result['@metadata']['statusCode'] ) && $result['@metadata']['statusCode'] == 200 ) {
                    return $result['ObjectURL'] ?? $this->s3_client->getObjectUrl( $bucket_name, $s3_key );
                } else {
                     error_log("H Bucket - Upload Error: S3 putObject failed. Result: " . print_r($result, true));
                    return false;
                }
            } catch ( S3Exception $e ) {
                 error_log("H Bucket - S3 Upload Exception: " . $e->getMessage());
                return false;
            } catch ( \Exception $e ) {
                 error_log("H Bucket - General Upload Exception: " . $e->getMessage());
                return false;
            }
        }
        
        /**
         * Test connection to S3.
         * @return bool True if connection is successful, false otherwise.
         */
        public function test_connection() {
            if ( ! $this->s3_client ) {
                error_log("H Bucket - Test Connection Error: S3 client not initialized.");
                return false;
            }

            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            try {
                $this->s3_client->headBucket([
                    'Bucket' => $bucket_name,
                ]);
                return true; 
            } catch (S3Exception $e) {
                error_log("H Bucket - Test Connection S3Exception: " . $e->getMessage());
                return false;
            } catch (\Exception $e) {
                error_log("H Bucket - Test Connection Exception: " . $e->getMessage());
                return false;
            }
        }

        /**
         * Deletes an object from Hetzner Object Storage.
         *
         * @param string $s3_key The key of the object to delete.
         * @return bool True on success, false on failure.
         */
        public function delete_object( $s3_key ) {
            if ( ! $this->s3_client ) {
                error_log("H Bucket - Delete Error: S3 client not initialized."); // Or use HBucket\Logger
                return false;
            }
            if ( empty( $s3_key ) ) {
                error_log("H Bucket - Delete Error: S3 key is empty.");
                return false;
            }

            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            try {
                $this->s3_client->deleteObject([
                    'Bucket' => $bucket_name,
                    'Key'    => $s3_key,
                ]);
                // deleteObject does not throw an error if the key doesn't exist, it's considered a success.
                return true;
            } catch ( S3Exception $e ) {
                $logger = \HBucket\Logger::get_instance();
                if ($logger) $logger->error("S3 Delete Exception for key {$s3_key}: " . $e->getMessage());
                else error_log("H Bucket - S3 Delete Exception for key {$s3_key}: " . $e->getMessage());
                return false;
            } catch ( \Exception $e ) {
                 $logger = \HBucket\Logger::get_instance();
                if ($logger) $logger->error("General Delete Exception for key {$s3_key}: " . $e->getMessage());
                else error_log("H Bucket - General Delete Exception for key {$s3_key}: " . $e->getMessage());
                return false;
            }
        }

        /**
         * Downloads an object from Hetzner Object Storage to a local file.
         *
         * @param string $s3_key The key of the object to download.
         * @param string $destination_path The full path to save the downloaded file locally.
         * @return bool True on success, false on failure.
         */
        public function download_object( $s3_key, $destination_path ) {
            if ( ! $this->s3_client ) {
                $this->log_error("Download Error: S3 client not initialized.");
                return false;
            }
            if ( empty( $s3_key ) ) {
                $this->log_error("Download Error: S3 key is empty.");
                return false;
            }
            if ( empty( $destination_path ) ) {
                $this->log_error("Download Error: Destination path is empty for S3 key {$s3_key}.");
                return false;
            }

            // Ensure destination directory exists
            $destination_dir = dirname( $destination_path );
            if ( ! is_dir( $destination_dir ) ) {
                if ( ! wp_mkdir_p( $destination_dir ) ) {
                    $this->log_error("Download Error: Could not create destination directory {$destination_dir} for S3 key {$s3_key}.");
                    return false;
                }
            }

            $bucket_name = sanitize_text_field( $this->options['bucket_name'] );

            try {
                $result = $this->s3_client->getObject([
                    'Bucket' => $bucket_name,
                    'Key'    => $s3_key,
                    'SaveAs' => $destination_path,
                ]);
                
                // Check if getObject was successful (status code 200)
                return ( isset( $result['@metadata']['statusCode'] ) && $result['@metadata']['statusCode'] == 200 );

            } catch ( S3Exception $e ) {
                $this->log_error("S3 Download Exception for key {$s3_key} to {$destination_path}: " . $e->getMessage());
                // If file was partially saved by SaveAs on error, it might need cleanup, but S3 SDK usually handles this.
                if (file_exists($destination_path) && filesize($destination_path) === 0) {
                     @unlink($destination_path); // Clean up empty file on common error cases
                }
                return false;
            } catch ( \Exception $e ) {
                $this->log_error("General Download Exception for key {$s3_key} to {$destination_path}: " . $e->getMessage());
                if (file_exists($destination_path) && filesize($destination_path) === 0) {
                     @unlink($destination_path);
                }
                return false;
            }
        }

        /**
         * Helper to log errors consistently, using HBucket\Logger if available.
         */
        private function log_error($message) {
            if (class_exists('\HBucket\Logger')) {
                $logger = \HBucket\Logger::get_instance();
                $logger->error($message);
            } else {
                error_log("H Bucket UploaderService: " . $message);
            }
        }
    }
}
?>
