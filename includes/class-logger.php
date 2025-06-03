<?php
/**
 * Logger for H Bucket
 *
 * @package H_Bucket
 */

namespace HBucket;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'Logger' ) ) {
    /**
     * Class Logger.
     * Handles logging of events, warnings, and errors.
     */
    class Logger {

        private static $instance = null;
        private $log_file_path;
        private $max_log_size = 5 * 1024 * 1024; // 5 MB
        private $log_level = 'DEBUG'; // TODO: Make this configurable (DEBUG, INFO, WARN, ERROR)

        const DEBUG = 'DEBUG';
        const INFO  = 'INFO';
        const WARN  = 'WARN';
        const ERROR = 'ERROR';

        /**
         * Constructor.
         * Sets up the log file path.
         */
        private function __construct() {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/h-bucket-logs/';
            if ( ! file_exists( $log_dir ) ) {
                wp_mkdir_p( $log_dir );
            }
            $this->log_file_path = $log_dir . 'h-bucket-debug.log';

            // Secure the log directory with an index.html and .htaccess file if possible
            if ( ! file_exists( $log_dir . 'index.html' ) ) {
                @file_put_contents( $log_dir . 'index.html', '<!-- Silence is golden. -->' );
            }
            // .htaccess to prevent direct access if server allows it (Apache)
            if ( ! file_exists( $log_dir . '.htaccess' ) && strpos( $_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache' ) !== false ) {
                 @file_put_contents( $log_dir . '.htaccess', "Options -Indexes
deny from all" );
            }

            // TODO: Add option to set log level from plugin settings.
        }

        /**
         * Get class instance (Singleton pattern).
         * @return Logger
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Logs a message.
         *
         * @param string $level The log level (e.g., INFO, ERROR).
         * @param string $message The message to log.
         */
        private function log( $level, $message ) {
            // TODO: Implement log level check here once configurable
            // For example: if ($this->get_level_priority($level) < $this->get_level_priority($this->log_level)) return;

            $timestamp = current_time( 'mysql' ); // WordPress way to get current time
            $formatted_message = sprintf( "[%s] [%s]: %s
", $timestamp, $level, $message );

            // Handle log rotation if file exceeds max size
            if ( file_exists( $this->log_file_path ) && filesize( $this->log_file_path ) > $this->max_log_size ) {
                $this->rotate_log();
            }

            if ( is_writable( dirname( $this->log_file_path ) ) ) {
                file_put_contents( $this->log_file_path, $formatted_message, FILE_APPEND | LOCK_EX );
            } else {
                // Fallback to PHP error_log if file is not writable
                error_log("H Bucket Logger: Log file directory not writable. Message: {$level} - {$message}");
            }
        }

        /**
         * Rotates the log file. Renames current log and starts a new one.
         */
        private function rotate_log() {
            $rotated_log_path = $this->log_file_path . '.' . time() . '.old';
            @rename( $this->log_file_path, $rotated_log_path );
            // Optionally, delete very old logs or compress them.
        }

        public function debug( $message ) {
            $this->log( self::DEBUG, $message );
        }

        public function info( $message ) {
            $this->log( self::INFO, $message );
        }

        public function warn( $message ) {
            $this->log( self::WARN, $message );
        }

        public function error( $message ) {
            $this->log( self::ERROR, $message );
        }

        /**
         * Retrieves the content of the log file.
         * @param int $lines Number of lines to retrieve from the end of the file. Default 100.
         * @return string|false Log content or false on failure.
         */
        public function get_log_content( $lines = 100 ) {
            if ( ! file_exists( $this->log_file_path ) || ! is_readable( $this->log_file_path ) ) {
                return 'Log file not found or not readable.';
            }
            
            // Read the last N lines from the file (can be memory intensive for large N or many calls)
            // A more robust solution for very large files might involve `tac` command or reverse reading in chunks.
            $file_content_array = file( $this->log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if (empty($file_content_array)) {
                return 'Log file is empty.';
            }
            $log_entries = array_slice( $file_content_array, -$lines );
            return implode( "
", array_reverse($log_entries) ); // Show newest first
        }

        /**
         * Clears the log file.
         * @return bool True on success, false on failure.
         */
        public function clear_log() {
            if ( file_exists( $this->log_file_path ) && is_writable( $this->log_file_path ) ) {
                return @file_put_contents( $this->log_file_path, '' ); // Empty the file
            }
            return false;
        }
    }
}
?>
