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

            if ( empty( $this->options['endpoint'] ) || empty( $this->options['access_key'] ) || empty( $this->options['secret_key'] ) || empty( $this->options['bucket_name'] ) ) {
                return;
            }
            
            $credentials = new Credentials( $this->options['access_key'], $this->options['secret_key'] );
            
            $client_args = [
                'version'     => 'latest',
                'endpoint'    => esc_url_raw( $this->options['endpoint'] ),
                'credentials' => $credentials,
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
            } catch ( \Exception $e ) {
                error_log("H Bucket - S3 Client Init Error: " . $e->getMessage());
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
    }
}
?>
