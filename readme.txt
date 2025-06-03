=== H Bucket ===
Contributors: bajpangosh, kloudboy
Donate link: https://example.com/donate
Tags: media, offload, hetzner, s3, cdn, performance, storage, upload, media library, cloud, backup
Requires at least: 5.5
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that offloads media files to Hetzner's S3-compatible Object Storage, rewrites URLs, and offers tools for migration, optimization, and control — boosting speed and reducing server load. Developed by Bajpan Gosh, KLOUDBOY TECHNOLOGIES LLP.

== Description ==

H Bucket seamlessly integrates your WordPress media library with Hetzner's S3-compatible Object Storage. It automatically offloads newly uploaded media files, rewrites their URLs to serve directly from Hetzner, and provides robust tools for managing your media cloud. This boosts your website's speed, reduces load on your server, and allows for scalable, cost-effective storage.

Whether you're starting a new site or managing an existing one with a large library, H Bucket offers both automatic offloading for new files and powerful bulk migration tools (via Admin UI and WP-CLI) for your existing media. Local file cleanup, status reporting, logging, and a restore functionality provide complete control over your offloaded media.

**Key Features:**
*   **Automatic Offloading:** Media files are automatically offloaded to Hetzner Object Storage upon upload to the WordPress media library.
*   **URL Rewriting:** Serves media directly from Hetzner by rewriting file URLs, including support for `srcset` attributes in responsive images and URLs in post content.
*   **Secure Credential Storage:** Hetzner API keys are encrypted before being stored in the database.
*   **Admin Settings Page:** Intuitive interface under "Settings > Hetzner Offload" with tabs for:
    *   **General:** Configure Hetzner endpoint, bucket name, access key, secret key, and region. Includes a "Test Connection" button.
    *   **Advanced:** Option to automatically delete local copies of files after successful offload.
    *   **Migrate:** View media library statistics and perform bulk migration of existing media to Hetzner with an interactive progress bar.
    *   **Logs:** View detailed plugin activity logs, with an option to clear the log file.
*   **Bulk Migration (UI & WP-CLI):** Offload your entire existing media library or just files not yet offloaded.
*   **WP-CLI Commands:**
    *   `wp hetzner status`: Check plugin status, configuration, and media counts.
    *   `wp hetzner offload all`: Bulk offload media with options for dry-run, batch size, and sleep duration.
    *   `wp hetzner restore <ID>`: Restore a specific offloaded attachment (including thumbnails) back to the local server.
*   **Local File Cleanup:** Option to remove files from your local server once they are safely stored on Hetzner.
*   **Logging System:** Detailed logs for troubleshooting and monitoring plugin activity.
*   **Developer Friendly:** Includes filters like `h_bucket_final_s3_url` for customizing the S3 URL.

== Installation ==

1.  Upload the `h-bucket` folder to the `/wp-content/plugins/` directory, or install directly from the WordPress plugin directory if available.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to **Settings > Hetzner Offload** in your WordPress admin dashboard to configure the plugin with your Hetzner Object Storage details.
4.  If installing from a Git repository, you may need to run `composer install` in the plugin's root directory to install required dependencies (like the AWS SDK). For installations from WordPress.org, these will be pre-packaged.

== Frequently Asked Questions ==

= How do I get Hetzner API credentials? =
You can generate API credentials (Access Key and Secret Key) from your Hetzner Cloud Console.
1.  Log in to your Hetzner Cloud Console.
2.  Navigate to your Project, then select "Storage Boxes" (or "Object Storage" depending on the product name).
3.  Either select an existing Storage Box/Bucket or create a new one.
4.  Within your Storage Box/Bucket settings, look for a section related to "Access Keys" or "API Keys" to generate a new key pair. Keep your Secret Key secure, as it won't be shown again.

= What happens if I disable "Enable Media Offload" after offloading files? =
If you disable the "Enable Media Offload" option:
*   URLs for already offloaded media will revert to pointing to your local server. The files themselves will remain on your Hetzner Object Storage bucket unless you manually delete them there.
*   New media uploads will not be offloaded to Hetzner.
*   You can re-enable the option at any time to resume offloading and URL rewriting.

= How does the "Delete Local Copy" option work? =
When the "Delete Local Copy After Offload" option is enabled in the Advanced Settings:
*   After a media file (and its thumbnails) is successfully uploaded to Hetzner Object Storage, the plugin will delete these files from your local server's `wp-content/uploads` directory.
*   This helps save server disk space.
*   This option only affects new uploads or items processed by the bulk migration tool *after* the option is enabled. It is not retroactive for files that were previously offloaded while this option was disabled.
*   It is recommended to ensure your offloading is working correctly before enabling this option.

= Can I use this plugin with an existing Hetzner bucket that already has files? =
This plugin is primarily designed to manage media files that are uploaded *through* the WordPress media library.
*   For existing media *within your WordPress library*, you can use the "Migrate" tab (or the `wp hetzner offload all` WP-CLI command) to offload these files to your Hetzner bucket. The plugin will then manage their URLs.
*   If your Hetzner bucket contains files that were *not* uploaded via WordPress (e.g., manually uploaded backups, other application data), this plugin will not interact with or manage those files. It focuses on linking WordPress attachment posts to their corresponding S3 objects.

= What happens if an upload to Hetzner fails? =
If an attempt to offload a media file to Hetzner Object Storage fails:
*   The original media file will remain on your local WordPress server.
*   The URL for that media file will continue to point to the local file, ensuring it remains accessible on your site.
*   An error message detailing the failure should be logged in the "Logs" tab of the plugin settings, which can help in troubleshooting the issue.

= How do I use the WP-CLI commands? =
H Bucket provides several WP-CLI commands for server administrators and developers:
*   `wp hetzner status`: Displays the current plugin status, configuration settings, and statistics about offloaded media.
*   `wp hetzner offload all`: Initiates a bulk offload process for all media files in the library that have not yet been offloaded.
    *   `--dry-run`: Use this flag to simulate the offload process without actually transferring any files or making database changes. It will report what actions would be taken.
    *   `--batch-size=<number>`: Specify how many attachments to process in each batch (e.g., `--batch-size=50`).
    *   `--sleep=<seconds>`: Specify a number of seconds to pause between batches (e.g., `--sleep=2`).
*   `wp hetzner restore <ID> [--force]`: Restores a specific offloaded attachment (specified by its WordPress Attachment ID) from Hetzner back to your local server.
    *   `--force`: Use this flag to overwrite the local file if it already exists. By default, restore skips if a local file is present.

= Where can I find logs for troubleshooting? =
You can find detailed activity logs for the H Bucket plugin by navigating to **Settings > Hetzner Offload** in your WordPress admin area and clicking on the **"Logs"** tab. These logs provide information about successful offloads, errors, and other plugin operations. You can also clear the log file from this tab.

== Screenshots ==

1.  The General Settings tab showing configuration options for Hetzner endpoint, bucket, and credentials.
2.  The Advanced Settings tab displaying the "Delete Local Copy" option.
3.  The Migrate tab showing media library statistics and the bulk migration button with progress bar.
4.  The Logs tab displaying plugin activity logs and the clear log button.
5.  Example of `wp hetzner status` command output in a terminal (optional).

== Changelog ==

= 1.0.0 =
* Initial stable release.
* Feature: Automatic offloading of new media uploads to Hetzner Object Storage.
* Feature: Rewriting media URLs to serve files directly from Hetzner, including `srcset` and content URLs.
* Feature: Admin settings page (Settings > Hetzner Offload) with tabs for General, Advanced, Logs, and Migrate.
* Feature: Configuration for Hetzner endpoint, bucket, access key (encrypted), secret key (encrypted), and region.
* Feature: Option to delete local copies of media files after successful offload.
* Feature: "Test Connection" button to verify Hetzner S3 settings.
* Feature: Bulk migration tool in the admin UI with AJAX progress for existing media.
* Feature: Logging system with log viewer and clearer in admin settings.
* Feature: WP-CLI commands:
    * `wp hetzner status` - Display plugin status and configuration.
    * `wp hetzner offload all` - Bulk offload media with dry-run and batch options.
    * `wp hetzner restore <ID>` - Restore an offloaded attachment back to local server.
* Feature: Secure credential storage using WordPress encryption functions (`wp_encrypt_data` / `wp_decrypt_data`).
* Feature: Basic PHPUnit test structure implemented for core components like URLRewriter.
* Enhancement: Improved handling of thumbnail offloading and deletion.
* Enhancement: Role/capability checks for admin actions and AJAX handlers.

== Upgrade Notice ==

= 1.0.0 =
This is the first stable release of H Bucket. Please configure your Hetzner credentials carefully and test the offloading process. It's recommended to backup your site (database and files) before performing bulk migration operations or enabling the "Delete Local Copy" feature.
