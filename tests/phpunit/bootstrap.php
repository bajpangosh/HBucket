<?php
// tests/phpunit/bootstrap.php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find {$_tests_dir}/includes/functions.php." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo "Please set the WP_TESTS_DIR environment variable or update phpunit.xml.dist." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    // Load Composer autoloader for the plugin
    if (file_exists(dirname( dirname( dirname( __FILE__ ) ) ) . '/vendor/autoload.php')) {
        require dirname( dirname( dirname( __FILE__ ) ) ) . '/vendor/autoload.php';
    }
    // Load the main plugin file
    require dirname( dirname( dirname( __FILE__ ) ) ) . '/h-bucket.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

echo "H Bucket PHPUnit Bootstrap complete." . PHP_EOL;
?>
