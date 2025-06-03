<?php
/**
 * Plugin Name:       H Bucket
 * Plugin URI:        https://example.com/h-bucket-plugin-uri/
 * Description:       A WordPress plugin that offloads media files to Hetzner's S3-compatible Object Storage, rewrites URLs, and offers tools for migration, optimization, and control — boosting speed and reducing server load.
 * Version:           1.0.0
 * Author:            Bajpan Gosh, KLOUDBOY TECHNOLOGIES LLP
 * Author URI:        https://kloudboy.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       h-bucket
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Require Composer's autoloader
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

/**
 * Currently plugin version.
 */
define( 'H_BUCKET_VERSION', '1.0.0' );
define( 'H_BUCKET_PATH', plugin_dir_path( __FILE__ ) );
define( 'H_BUCKET_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_h_bucket() {
    // Activation code here.
}
register_activation_hook( __FILE__, 'activate_h_bucket' );

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_h_bucket() {
    // Deactivation code here.
}
register_deactivation_hook( __FILE__, 'deactivate_h_bucket' );

/**
 * Begins execution of the plugin.
 */
require_once H_BUCKET_PATH . 'admin/class-h-bucket-settings.php';

/**
 * Initialize plugin components.
 */
function h_bucket_init() {
    if ( is_admin() ) {
        H_Bucket_Settings::get_instance();
    }
    // Load other components here
    if ( class_exists('\HBucket\MediaSyncManager') ) {
         \HBucket\MediaSyncManager::get_instance(); // Ensure it's loaded and hooks (if any non-AJAX) are registered
    }
    if ( class_exists('\HBucket\CleanupManager') ) {
         \HBucket\CleanupManager::get_instance();
    }
    if ( class_exists('\HBucket\Logger') ) {
         \HBucket\Logger::get_instance();
    }
    if ( class_exists('\HBucket\HooksManager') ) {
         \HBucket\HooksManager::get_instance(); // This will register the hooks
    }
}
add_action( 'plugins_loaded', 'h_bucket_init' );

?>
