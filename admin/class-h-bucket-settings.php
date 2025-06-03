<?php
/**
 * H Bucket Settings Page
 *
 * @package H_Bucket
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'H_Bucket_Settings' ) ) {
    /**
     * Class H_Bucket_Settings.
     */
    class H_Bucket_Settings {

        private $options;
        private static $instance = null;
            private $settings_page_hook_suffix;

        /**
         * Constructor.
         */
        private function __construct() {
            add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
            add_action( 'admin_init', array( $this, 'page_init' ) );
                add_action( 'wp_ajax_h_bucket_test_connection', array( $this, 'handle_test_connection_ajax' ) );

                if ( isset( $_POST['h_bucket_action'] ) && $_POST['h_bucket_action'] === 'clear_logs' ) {
                    // Check nonce and capability before clearing
                    if ( isset( $_POST['h_bucket_clear_logs_nonce'] ) && 
                         wp_verify_nonce( sanitize_key($_POST['h_bucket_clear_logs_nonce']), 'h_bucket_clear_logs_action' ) &&
                         current_user_can('manage_options') ) {
                        
                        if ( class_exists('\HBucket\Logger') ) {
                            $logger = \HBucket\Logger::get_instance();
                            if ( $logger->clear_log() ) {
                                add_action( 'admin_notices', function() {
                                    echo '<div class="notice notice-success is-dismissible"><p>H Bucket log file cleared successfully.</p></div>';
                                });
                            } else {
                                add_action( 'admin_notices', function() {
                                    echo '<div class="notice notice-error is-dismissible"><p>H Bucket: Could not clear log file. Check file permissions.</p></div>';
                                });
                            }
                        }
                    } else {
                         add_action( 'admin_notices', function() {
                            echo '<div class="notice notice-error is-dismissible"><p>H Bucket: Security check failed or insufficient permissions to clear logs.</p></div>';
                        });
                    }
                }
        }

        /**
         * Get class instance.
         *
         * @return H_Bucket_Settings
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Add options page.
         */
        public function add_plugin_page() {
                $this->settings_page_hook_suffix = add_options_page(
                    'Hetzner Offload Settings', 
                    'Hetzner Offload',          
                    'manage_options',           
                    'h-bucket-settings',        
                    array( $this, 'create_admin_page' ) 
                );
                // Ensure this action is added only once, maybe move to constructor or a dedicated hook registration method if class is instantiated multiple times.
                // However, for add_options_page, the instance is typically a singleton managed by a get_instance() method which is good.
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
            }

            public function enqueue_admin_scripts( $hook_suffix ) {
                if ( $hook_suffix !== $this->settings_page_hook_suffix ) {
                    return;
                }
                wp_enqueue_script(
                    'h-bucket-admin-js',
                    H_BUCKET_URL . 'assets/js/h-bucket-admin.js', // H_BUCKET_URL should be defined in h-bucket.php
                    array( 'jquery' ),
                    H_BUCKET_VERSION, // H_BUCKET_VERSION should be defined in h-bucket.php
                    true
            );
        }

        /**
         * Options page callback.
         */
        public function create_admin_page() {
            $this->options = get_option( 'h_bucket_options' );
            $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
            ?>
            <div class="wrap">
                <h1>H Bucket | Hetzner Media Offloader</h1>
                <p>Developed by Bajpan Gosh, KLOUDBOY TECHNOLOGIES LLP</p>
                <h2 class="nav-tab-wrapper">
                    <a href="?page=h-bucket-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                    <a href="?page=h-bucket-settings&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">Advanced</a>
                    <a href="?page=h-bucket-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
                    <a href="?page=h-bucket-settings&tab=migrate" class="nav-tab <?php echo $active_tab == 'migrate' ? 'nav-tab-active' : ''; ?>">Migrate</a>
                </h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'h_bucket_option_group' );
                    
                    if ( $active_tab == 'general' ) {
                        do_settings_sections( 'h-bucket-settings-general' );
                    } elseif ( $active_tab == 'advanced' ) {
                        do_settings_sections( 'h-bucket-settings-advanced' );
                    } elseif ( $active_tab == 'logs' ) {
                            if ( class_exists('\HBucket\Logger') ) {
                                $logger = \HBucket\Logger::get_instance();
                                echo '<h3>Plugin Activity Logs</h3>';
                                echo '<p>Displaying the last 100 lines. Newest entries first.</p>';
                                
                                // Add a button to clear logs
                                echo '<form method="post" action="">';
                                // Add a nonce for the clear logs action
                                wp_nonce_field( 'h_bucket_clear_logs_action', 'h_bucket_clear_logs_nonce' );
                                echo '<input type="hidden" name="h_bucket_action" value="clear_logs">';
                                submit_button('Clear Log File', 'delete', 'h_bucket_clear_logs_submit', false);
                                echo '</form>';
                                echo '<hr>';

                                echo '<pre style="white-space: pre-wrap; background-color: #f1f1f1; border: 1px solid #ccc; padding: 10px; max-height: 500px; overflow-y: auto;">';
                                echo esc_textarea( $logger->get_log_content(100) );
                                echo '</pre>';
                            } else {
                                echo '<p>Logger class not available.</p>';
                            }
                    } elseif ( $active_tab == 'migrate' ) {
                            // Ensure MediaSyncManager is loaded (via autoloader or direct require if needed)
                            // $media_sync_manager = new \HBucket\MediaSyncManager(); // If H_Bucket_Settings is not namespaced
                            // If H_Bucket_Settings becomes namespaced (e.g. namespace HBucket\Admin;) then:
                            // use HBucket\MediaSyncManager;
                            // $media_sync_manager = new MediaSyncManager();
                            
                            // For now, assuming H_Bucket_Settings is not namespaced:
                            if (class_exists('\HBucket\MediaSyncManager')) {
                                $media_sync_manager = \HBucket\MediaSyncManager::get_instance();
                                $total_local_files = $media_sync_manager->count_local_media_attachments(false);
                                $total_not_offloaded = $media_sync_manager->count_local_media_attachments(true);
                                $total_offloaded = $media_sync_manager->count_offloaded_media_attachments();

                                echo '<h3>Media Library Status</h3>';
                                echo "<p>Total media items in library: <strong>{$total_local_files}</strong></p>";
                                echo "<p>Media items already offloaded to Hetzner: <strong>{$total_offloaded}</strong></p>";
                                echo "<p>Media items not yet offloaded: <strong>{$total_not_offloaded}</strong></p>";

                                if ($total_not_offloaded > 0) {
                                    echo '<h3>Start Migration</h3>';
                                    echo '<p>Offload your existing media library to Hetzner Object Storage.</p>';
                                    echo '<button type="button" id="h-bucket-start-migration-button" class="button button-primary">Start Bulk Migration</button>';
                                    echo '<div id="h-bucket-migration-progress-bar" style="width:100%; background-color:#ddd; margin-top:10px; display:none;"><div style="width:0%; height:20px; background-color:green; text-align:center; color:white;">0%</div></div>';
                                    echo '<div id="h-bucket-migration-status-log" style="margin-top:10px; max-height:200px; overflow-y:auto; border:1px solid #ccc; padding:5px; display:none;"></div>';
                                } else {
                                    echo '<p>All your media items seem to be offloaded already or your library is empty!</p>';
                                }
                            } else {
                                echo '<p>Media Sync Manager is not available.</p>';
                            }
                    }
                    
                    if ( $active_tab !== 'logs' && $active_tab !== 'migrate') {
                        submit_button();
                    }
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Register and add settings.
         */
        public function page_init() {
            register_setting(
                'h_bucket_option_group', // Option group
                'h_bucket_options', // Option name
                array( $this, 'sanitize' ) // Sanitize
            );

            // General Tab Settings
            add_settings_section(
                'setting_section_general', // ID
                'General Settings', // Title
                array( $this, 'print_section_info_general' ), // Callback
                'h-bucket-settings-general' // Page
            );

            add_settings_field(
                'enable_offload',
                'Enable Media Offload',
                array( $this, 'enable_offload_callback' ),
                'h-bucket-settings-general',
                'setting_section_general'
            );

            add_settings_field(
                'endpoint', // ID
                'Hetzner Endpoint', // Title
                array( $this, 'endpoint_callback' ), // Callback
                'h-bucket-settings-general', // Page
                'setting_section_general' // Section
            );

            add_settings_field(
                'bucket_name',
                'Bucket Name',
                array( $this, 'bucket_name_callback' ),
                'h-bucket-settings-general',
                'setting_section_general'
            );
            
            add_settings_field(
                'access_key',
                'Access Key ID',
                array( $this, 'access_key_callback' ),
                'h-bucket-settings-general',
                'setting_section_general'
            );

            add_settings_field(
                'secret_key',
                'Secret Access Key',
                array( $this, 'secret_key_callback' ),
                'h-bucket-settings-general',
                'setting_section_general'
            );
             add_settings_field(
                'region',
                'Region (Optional)',
                array( $this, 'region_callback' ),
                'h-bucket-settings-general',
                'setting_section_general'
            );
                
                add_settings_field(
                    'test_connection_button',
                    'Test Connection',
                    array( $this, 'test_connection_button_callback' ),
                    'h-bucket-settings-general',
                    'setting_section_general'
                );


            // Advanced Tab Settings
             add_settings_section(
                'setting_section_advanced', // ID
                'Advanced Settings', // Title
                array( $this, 'print_section_info_advanced' ), // Callback
                'h-bucket-settings-advanced' // Page
            );
            add_settings_field(
                'delete_local_copy',
                'Delete Local Copy After Offload',
                array( $this, 'delete_local_copy_callback' ),
                'h-bucket-settings-advanced',
                'setting_section_advanced'
            );
        }

        /**
         * Sanitize each setting field as needed.
         *
         * @param array $input Contains all settings fields as array keys.
         * @return array
         */
        public function sanitize( $input ) {
            $new_input = array();
            if ( isset( $input['enable_offload'] ) ) {
                $new_input['enable_offload'] = absint( $input['enable_offload'] );
            }
            if ( isset( $input['endpoint'] ) ) {
                $new_input['endpoint'] = esc_url_raw( trim($input['endpoint']) );
            }
            if ( isset( $input['bucket_name'] ) ) {
                $new_input['bucket_name'] = sanitize_text_field( $input['bucket_name'] );
            }
            if ( isset( $input['access_key'] ) ) {
                $new_input['access_key'] = sanitize_text_field( $input['access_key'] );
            }
            if ( isset( $input['secret_key'] ) ) {
                // This will be encrypted later. For now, just sanitize.
                $new_input['secret_key'] = sanitize_text_field( $input['secret_key'] );
            }
             if ( isset( $input['region'] ) ) {
                $new_input['region'] = sanitize_text_field( $input['region'] );
            }
            if ( isset( $input['delete_local_copy'] ) ) {
                $new_input['delete_local_copy'] = absint( $input['delete_local_copy'] );
            }

            return $new_input;
        }

        /**
         * Print the Section text.
         */
        public function print_section_info_general() {
            print 'Enter your Hetzner Object Storage credentials and settings below:';
        }
        public function print_section_info_advanced() {
            print 'Configure advanced plugin behaviors:';
        }

        /**
         * Get the settings option array and print one of its values.
         */
        public function enable_offload_callback() {
            printf(
                '<input type="checkbox" id="enable_offload" name="h_bucket_options[enable_offload]" value="1" %s /> <label for="enable_offload">Enable</label>',
                isset( $this->options['enable_offload'] ) && $this->options['enable_offload'] == 1 ? 'checked' : ''
            );
        }
        public function endpoint_callback() {
            printf(
                '<input type="text" id="endpoint" name="h_bucket_options[endpoint]" value="%s" class="regular-text" placeholder="e.g., your-storagebox.your-server.de"/>',
                isset( $this->options['endpoint'] ) ? esc_attr( $this->options['endpoint'] ) : ''
            );
             echo '<p class="description">Enter the full endpoint for your Hetzner Object Storage. For example <code>https://your-storagebox.your-server.de</code> or <code>https://s3.your-region.cloud.hetzner.com</code>.</p>';
        }
        public function bucket_name_callback() {
            printf(
                '<input type="text" id="bucket_name" name="h_bucket_options[bucket_name]" value="%s" class="regular-text" placeholder="e.g., my-media-bucket"/>',
                isset( $this->options['bucket_name'] ) ? esc_attr( $this->options['bucket_name'] ) : ''
            );
        }
         public function access_key_callback() {
            printf(
                '<input type="text" id="access_key" name="h_bucket_options[access_key]" value="%s" class="regular-text"/>',
                isset( $this->options['access_key'] ) ? esc_attr( $this->options['access_key'] ) : ''
            );
        }
        public function secret_key_callback() {
            printf(
                '<input type="password" id="secret_key" name="h_bucket_options[secret_key]" value="%s" class="regular-text"/>',
                isset( $this->options['secret_key'] ) ? esc_attr( $this->options['secret_key'] ) : '' // Later this will be a placeholder like '********' if set
            );
        }
         public function region_callback() {
            printf(
                '<input type="text" id="region" name="h_bucket_options[region]" value="%s" class="regular-text" placeholder="e.g., eu-central-1"/>',
                isset( $this->options['region'] ) ? esc_attr( $this->options['region'] ) : ''
            );
             echo '<p class="description">Optional. Only needed if your endpoint does not include the region, e.g. if using a custom domain or proxy for your bucket.</p>';
        }
         public function delete_local_copy_callback() {
            printf(
                '<input type="checkbox" id="delete_local_copy" name="h_bucket_options[delete_local_copy]" value="1" %s /> <label for="delete_local_copy">Yes</label>',
                isset( $this->options['delete_local_copy'] ) && $this->options['delete_local_copy'] == 1 ? 'checked' : ''
            );
            echo '<p class="description">If checked, media files will be deleted from your local server after being successfully offloaded to Hetzner. Use with caution.</p>';
        }

            public function test_connection_button_callback() {
                echo '<button type="button" name="h_bucket_test_connection" id="h_bucket_test_connection" class="button">Test Hetzner Connection</button>';
                echo '<span id="h-bucket-test-connection-status" style="margin-left:10px;"></span>';
                wp_nonce_field( 'h_bucket_test_connection_action', 'h_bucket_test_connection_nonce' );
            }

            public function handle_test_connection_ajax() {
                check_ajax_referer( 'h_bucket_test_connection_action', 'nonce' );

                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( 'Permission denied.' );
                }

                $uploader_service = new \HBucket\UploaderService(); // Note the namespace
                $result = $uploader_service->test_connection();

                if ( $result ) {
                    wp_send_json_success( 'Successfully connected to Hetzner Object Storage and bucket is accessible.' );
                } else {
                    wp_send_json_error( 'Failed to connect. Check credentials, bucket name, endpoint, and region. See error log for details.' );
                }
            }
    }
}

// Initialize the settings page
// H_Bucket_Settings::get_instance();

?>
