<?php

namespace HBucket\Tests;

use HBucket\URLRewriter;
use Yoast\WPTestUtils\BrainMonkey\TestCasePatchwork;
use Brain\Monkey;

class URLRewriterTest extends TestCasePatchwork {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('get_option')->justReturn($this->get_mock_options());
        Monkey\Functions\when('wp_get_upload_dir')->justReturn($this->get_mock_upload_dir_info());
        Monkey\Functions\when('esc_url_raw')->returnArg();
        Monkey\Functions\when('sanitize_text_field')->returnArg();
        Monkey\Functions\when('get_post_meta')->justReturn(false); // Default: not offloaded or no specific meta
        Monkey\Filters::expectApplied('h_bucket_final_s3_url')->andPassthru();
        // Ensure our singleton can be reset for option changes
        Monkey\Actions\when('update_option_h_bucket_options')->alias(function(...$args) {
            $instance = URLRewriter::get_instance();
            if (method_exists($instance, 'reload_options_on_update')) {
                $instance->reload_options_on_update($args[0] ?? null, $args[1] ?? null, $args[2] ?? null);
            } elseif (method_exists($instance, 'reload_options')) {
                $instance->reload_options();
            }
        });

    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function get_mock_options($enable_offload = true, $endpoint = 'https://s3.example.com', $bucket = 'my-bucket') {
        return [
            'enable_offload' => $enable_offload ? 1 : 0,
            'endpoint' => $endpoint,
            'bucket_name' => $bucket,
        ];
    }

    private function get_mock_upload_dir_info() {
        return [
            'baseurl' => 'http://local.test/wp-content/uploads',
            'basedir' => '/var/www/html/wp-content/uploads',
            'error' => false,
        ];
    }

    public function test_rewrite_url_offloading_disabled() {
        Monkey\Functions\when('get_option')->justReturn($this->get_mock_options(false));
        // Force re-initialization or option reload for the singleton
        do_action('update_option_h_bucket_options'); // Trigger reload via mocked action
        $rewriter = URLRewriter::get_instance();

        $original_url = 'http://local.test/wp-content/uploads/2023/10/image.jpg';
        $this->assertEquals($original_url, $rewriter->rewrite_url($original_url, 123));
    }

    public function test_rewrite_url_basic_case_no_id_or_no_meta() {
        do_action('update_option_h_bucket_options'); 
        $rewriter = URLRewriter::get_instance();
        $original_url = 'http://local.test/wp-content/uploads/2023/10/image.jpg';
        $expected_url = 'https://s3.example.com/my-bucket/2023/10/image.jpg';
        $this->assertEquals($expected_url, $rewriter->rewrite_url($original_url, 0)); // No attachment ID
    }
    
    public function test_rewrite_url_with_attachment_id_and_s3_key_meta() {
        Monkey\Functions\when('get_post_meta')->calledWith(123, '_h_bucket_s3_key', true)->justReturn('custom/path/image.jpg');
        do_action('update_option_h_bucket_options');
        $rewriter = URLRewriter::get_instance();
        $original_url = 'http://local.test/wp-content/uploads/some/other/path/image.jpg';
        $expected_url = 'https://s3.example.com/my-bucket/custom/path/image.jpg';
        $this->assertEquals($expected_url, $rewriter->rewrite_url($original_url, 123));
    }
    
    public function test_rewrite_url_attachment_id_but_no_s3_key_meta() {
        // get_post_meta will return false (default mock)
        do_action('update_option_h_bucket_options');
        $rewriter = URLRewriter::get_instance();
        $original_url = 'http://local.test/wp-content/uploads/2023/10/image.jpg';
        // Should return original URL because attachment_id is present but no S3 key meta found
        $this->assertEquals($original_url, $rewriter->rewrite_url($original_url, 123));
    }

    public function test_rewrite_url_not_a_local_upload_url() {
        do_action('update_option_h_bucket_options');
        $rewriter = URLRewriter::get_instance();
        $original_url = 'http://externalsite.com/image.jpg';
        $this->assertEquals($original_url, $rewriter->rewrite_url($original_url, 0));
    }
}
?>
