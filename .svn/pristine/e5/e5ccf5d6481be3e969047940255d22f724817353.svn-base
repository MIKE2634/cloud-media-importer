=== Michael Cloud Image Auto Importer ===
Contributors: mike17894
Tags: woocommerce, bulk import, images compression, seo alt-text, duplicate-detection
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import images from Google Drive into the WordPress Media Library with optional Cloudflare Worker or Google API authentication.

== Description ==

Michael Cloud Image Auto Importer lets you import images from Google Drive straight into your WordPress Media Library.
It is ideal for bloggers, photographers, and content creators who store their media in Google Drive.

The plugin connects securely to your Google Drive account with TWO authentication options:

1. **New:** One-click Cloudflare Worker connection (no API credentials needed!)
2. **Traditional:** Google API credentials (for advanced users)

**Key Features:**

* 🔐 **Optional Zero Configuration** – New Cloudflare Worker option - just click "Connect"!
* ☁️ **Cloudflare Worker Integration** – Secure OAuth without exposing credentials
* 🔑 **Traditional OAuth Support** – Use your own Google API credentials if preferred
* 📁 **Bulk Image Import** – Import entire Drive folders at once
* 🔍 **Smart Duplicate Detection** – MD5 file hashing to skip existing images
* 📝 **SEO-Friendly Alt Text** – Auto-generates from filenames (cat.jpg → "Cat")
* 🗜️ **Optional Compression** – JPEG/PNG/WebP compression with quality control
* 📊 **Real-Time Progress** – Live progress bar and statistics
* 📋 **Detailed Import Logs** – Success/failure rates for every import
* 🚀 **No Limits** – Import as many images as you need
* 🎨 **Clean Interface** – Intuitive WordPress admin design
* 🌍 **Multisite Compatible** – Works on WordPress Multisite networks

== External Services ==

**Option 1: Cloudflare Worker**

**Service:** Cloudflare Worker (Google OAuth Proxy)

**Purpose:** Securely handle Google OAuth authentication without storing credentials in WordPress.

**Data Transmitted:**

* OAuth authorization codes
* Session tokens (temporary, stored in Cloudflare KV)

**Service Provider:** Cloudflare, Inc.

**Privacy Policy:** https://www.cloudflare.com/privacypolicy/

**Option 2: Direct Google API**

**Service:** Google Drive API

**Purpose:** Browse, list, and download images from your Google Drive.

**Data Transmitted:**

* OAuth 2.0 authentication tokens
* File metadata (names, IDs, sizes, MIME types)
* MD5 hashes of files (for duplicate detection)
* File content when downloading images

**Service Provider:** Google LLC

**Terms:** https://developers.google.com/drive/terms

**Privacy:** https://policies.google.com/privacy

**User Consent:** By clicking "Connect Google Drive", you consent to connecting to these services.

== Features ==

* **Two Authentication Methods** – Choose Cloudflare Worker (zero-config) or traditional API credentials
* **Zero Configuration Option** – New Cloudflare Worker integration - no API credentials needed!
* **Traditional OAuth Support** – Use your own Google API credentials for full control
* **Bulk Image Import** – Import entire folders at once
* **Smart Duplicate Detection** – MD5 file hashing prevents duplicates
* **SEO Optimization** – Auto-generates alt text from filenames
* **Image Compression** – Optional compression with adjustable quality (50-95%)
* **Real-Time Progress Tracking** – Live progress bar and statistics
* **Detailed Import Logs** – Complete history of all imports
* **One-Click Disconnect** – Easily revoke access anytime
* **No Usage Limits** – Import unlimited images
* **Privacy First** – No tracking, no analytics

== How It Works ==

**Option A: Cloudflare Worker (Recommended)**

1. Click "Connect Google Drive"
2. Authorize your Google account
3. Paste your Google Drive folder URL
4. Start the import
5. Monitor the real-time progress
6. Done!

**Option B: Traditional Google API**

1. Create a Google Cloud Project
2. Enable the Google Drive API
3. Create OAuth credentials
4. Enter your credentials in the plugin settings
5. Connect Google Drive
6. Start importing images

== Installation ==

**Quick Setup (Cloudflare Worker):**

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Go to the **Cloud Importer** page
4. Click **Connect Google Drive**
5. Authorize your Google account
6. Paste your Google Drive folder URL
7. Start importing images

**Traditional Setup (Google API Credentials):**

1. Follow the Google API Setup instructions below
2. Enter your Client ID and Client Secret in the plugin settings
3. Save the settings
4. Connect Google Drive
5. Start importing images

== Google API Setup (Traditional Method) ==

1. **Create Google Cloud Project**

   * Visit https://console.cloud.google.com/
   * Create a new project or select an existing project

2. **Enable Google Drive API**

   * Go to "APIs & Services" → "Library"
   * Search for "Google Drive API"
   * Click "Enable"

3. **Create OAuth 2.0 Credentials**

   * Go to "APIs & Services" → "Credentials"
   * Click "Create Credentials" → "OAuth client ID"
   * Application type: "Web application"
   * Name: "Cloud Auto Importer"
   * Authorized redirect URI: `[your-site]/wp-admin/admin.php?page=cloud-auto-importer`

4. **Configure Plugin**

   * Copy the "Client ID" and "Client Secret"
   * Paste them into the plugin Settings page
   * Save the settings
   * Connect Google Drive

== Frequently Asked Questions ==

= What's new in version 2.0.0? =

Version 2.0.0 introduced the Cloudflare Worker authentication option. You can connect Google Drive without entering your own Google API credentials.

The traditional Google API authentication method remains available for advanced users.

= What's new in version 2.0.2? =

Version 2.0.2 improves the Google OAuth callback flow and updates the authentication configuration to support the custom callback domain.

= Do I need Google API credentials? =

No. With the Cloudflare Worker option, you do not need to enter your own Google API credentials. Simply click "Connect Google Drive" and authorize your Google account.

The traditional Google API authentication method is still available if you prefer to use your own credentials.

= Can I still use my own Google API credentials? =

Yes. The traditional authentication method remains available for advanced users who want full control over their Google API credentials.

= How does the Cloudflare Worker work? =

The Cloudflare Worker acts as an OAuth proxy between the WordPress plugin and Google. It handles the OAuth authorization flow without requiring users to store Google API credentials directly in WordPress.

= Is my data secure with the worker? =

The worker is designed to handle OAuth authentication securely. OAuth authorization codes and temporary session information may be transmitted to the Cloudflare Worker as required for authentication.

Images are downloaded to your WordPress server through the Google Drive API.

= Is there a limit to how many images I can import? =

The plugin does not impose an artificial image import limit. Actual limits may depend on your WordPress hosting resources, Google Drive API quotas, PHP limits, and available server storage.

= Can I import images from shared folders? =

Yes. You can import images from Google Drive folders that your connected Google account has permission to access.

= What image formats are supported? =

JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, TIF, SVG, and ICO.

= Are images compressed during import? =

Optional image compression is available for supported JPG, PNG, and WebP images. Compression quality can be adjusted from 50-95%.

= How are duplicates detected? =

The plugin uses MD5 file hashing to identify duplicate files. If an imported image matches an existing image hash, the duplicate can be skipped.

= What happens when I uninstall? =

The plugin provides data management options that allow you to choose whether plugin settings, logs, and import metadata are retained or deleted.

= Does this work on multisite? =

Yes. The plugin is designed to support WordPress Multisite installations.

= What if I want to use my own Cloudflare Worker? =

The plugin can support a custom Cloudflare Worker configuration where supported by the plugin settings. You can deploy and configure your own Worker if you prefer to manage your own OAuth infrastructure.

== Screenshots ==

1. **Main Interface** – Connect Google Drive and start imports
2. **Import Progress** – Real-time progress bar with live statistics
3. **Settings Page** – Simple data management options
4. **Import Logs** – Detailed history with success rates
5. **Setup Guide** – Quick start instructions

== Changelog ==

= 2.0.2 =

* **FIXED:** Updated the Google OAuth callback flow to use the custom callback domain.
* **FIXED:** Updated OAuth authorization handling for the `michaelcloudimage.co.ke` callback endpoint.
* **IMPROVED:** Updated Cloudflare Worker OAuth integration.
* **IMPROVED:** Updated authentication and callback documentation.
* **IMPROVED:** Updated release documentation and stable version information.

= 2.0.0 =

* **NEW:** Cloudflare Worker integration for optional zero-configuration authentication
* **NEW:** One-click Google Drive connection without requiring users to enter API credentials
* **NEW:** Added additional plugin tags for improved discoverability
* **IMPROVED:** Existing credential-based authentication remains supported
* **IMPROVED:** Better error messages for connection issues
* **IMPROVED:** Updated documentation for the new authentication workflow
* **SECURITY:** Optional external credential storage through Cloudflare
* **UX:** Simplified setup process for new users
* **COMPATIBILITY:** Fully backward compatible with version 1.0.x

= 1.0.7 =

* Increased reliability and efficiency
* Maintained WordPress.org compliance

= 1.0.6 =

* Fixed text domain to match plugin slug
* Updated all translation strings
* Improved WordPress.org compliance

= 1.0.5 =

* Added comprehensive external services documentation
* Fixed variable prefix consistency
* Improved WordPress.org compliance

= 1.0.4 =

* Added external services documentation
* Fixed prefix compliance: `cai_` to `mcai_`
* Fixed sanitization using `wp_validate_boolean()`
* Removed direct file inclusion
* Added WordPress.org username to Contributors
* Updated all AJAX actions to use the `mcai_` prefix
* Updated CSS and JavaScript class names
* Fixed database table name prefix

= 1.0.3 =

* Renamed plugin to Michael Cloud Image Auto Importer

= 1.0.2 =

* Fixed PHPCS warnings and coding standards
* Added proper escaping for all output
* Improved nonce verification
* Enhanced error handling

= 1.0.1 =

* Fixed translation compatibility
* Improved alt text generation
* Enhanced compression controls
* Fixed duplicate detection

= 1.0.0 =

* Initial release

== Upgrade Notice ==

= 2.0.2 =

* **OAuth callback improvements.** Updated the Google authentication flow and callback configuration for the custom domain. Recommended update for all users.

= 2.0.0 =

* **New feature!** Cloudflare Worker integration added. Connect Google Drive without entering your own API credentials. The traditional authentication method remains available.

= 1.0.7 =

* Reliability improvements. Recommended update.

= 1.0.6 =

* Fixed domain and compatibility issues. Recommended update.

= 1.0.5 =

* Added external services documentation. Recommended update.

== Languages ==

* English (default)
* Translations welcome via WordPress.org
* Text Domain: michael-cloud-image-auto-importer

== Credits ==

**Developed by:** Michael Otieno (WordPress.org: mike17894)

**Cloudflare Integration:** Cloudflare Workers for secure OAuth

**Google API:** Official Google Drive API

**Coding Standards:** WordPress PHP, JavaScript, and CSS standards

**License:** 100% GPLv2+

== Support ==

WordPress.org support forum:
https://wordpress.org/support/plugin/michael-cloud-image-auto-importer/
