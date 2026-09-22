=== Michael Cloud Image Auto Importer ===
Contributors: mike17894
Tags: woocommerce, bulk import, image compression, seo alt-text, duplicate detection
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import images from Google Drive into the WordPress Media Library with optional Cloudflare Worker or Google API authentication.

== Description ==

Michael Cloud Image Auto Importer lets you import images from Google Drive straight into your WordPress Media Library. It is ideal for bloggers, photographers, WooCommerce stores, and content creators who store their media in Google Drive.

The plugin supports two authentication options:

1. **Cloudflare Worker connection** – Connect Google Drive without entering API credentials in WordPress.
2. **Traditional Google API credentials** – Use your own Google OAuth credentials for advanced users.

**Key Features:**
* 🔐 **One-Click Google Drive Connection** – Connect without entering API credentials
* ☁️ **Cloudflare Worker Integration** – Secure OAuth authentication
* 🔑 **Traditional OAuth Support** – Use your own Google API credentials
* 📁 **Bulk Image Import** – Import entire Drive folders
* 🔍 **Smart Duplicate Detection** – MD5 file hashing to skip duplicates
* 📝 **SEO-Friendly Alt Text** – Generates alt text from filenames
* 🗜️ **Optional Image Compression** – JPEG, PNG, and WebP
* 📊 **Real-Time Progress** – Live progress and statistics
* 📋 **Detailed Import Logs** – Track successful and failed imports
* 🚀 **No Plugin Import Limits**
* 🎨 **Clean WordPress Admin Interface**
* 🌍 **Multisite Compatible**

== External Services ==

**Cloudflare Worker**

The plugin can use a Cloudflare Worker to securely handle Google OAuth authentication.

**Purpose:**
- Authenticate users with Google
- Securely exchange OAuth authorization codes
- Maintain temporary OAuth sessions

**Data Transmitted:**
- OAuth authorization codes
- Temporary session information
- Google Drive authentication tokens required for the connection

**Service Provider:** Cloudflare, Inc.

**Privacy Policy:** https://www.cloudflare.com/privacypolicy/

**Google Drive API**

The plugin uses the Google Drive API to access files that the user has authorized the plugin to access.

**Purpose:**
- Authenticate the user's Google account
- List Google Drive files
- Retrieve file metadata
- Download selected images

**Data Transmitted:**
- OAuth authentication tokens
- File metadata such as names, IDs, sizes, and MIME types
- File content when an image is downloaded

**Service Provider:** Google LLC

**Terms:** https://developers.google.com/drive/terms

**Privacy:** https://policies.google.com/privacy

**User Consent:** By clicking "Connect Google Drive", the user authorizes the plugin to access the requested Google Drive data.

== Changelog ==

= 2.0.2 =

* **FIXED:** Updated the Cloudflare Worker connection endpoint.
* **FIXED:** Updated Google OAuth callback routing for the custom Worker domain.
* **IMPROVED:** Improved compatibility with the production OAuth configuration.
* **IMPROVED:** Updated documentation for the Cloudflare Worker authentication workflow.

= 2.0.0 =

* **NEW:** Cloudflare Worker integration for optional zero-config authentication.
* **NEW:** One-click Google Drive connection without entering API credentials.
* **NEW:** Added additional plugin tags for better discoverability.
* **IMPROVED:** Existing credential-based authentication remains supported.
* **IMPROVED:** Better connection error handling.
* **IMPROVED:** Updated documentation for the new workflow.
* **SECURITY:** Optional external credential storage through Cloudflare Workers.
* **UX:** Simplified Google Drive connection setup.
* **COMPATIBILITY:** Fully backward compatible with version 1.0.x.

= 1.0.7 =

* Increased reliability and efficiency.
* Maintained WordPress.org compliance.

= 1.0.6 =

* Fixed text domain to match plugin slug.
* Updated translation strings.
* Improved WordPress.org compliance.

== Upgrade Notice ==

= 2.0.2 =
* Updated the Cloudflare Worker OAuth connection and callback configuration. Recommended update for users using the Cloudflare Worker authentication method.

= 2.0.0 =
* New Cloudflare Worker integration. Connect Google Drive without entering API credentials.

== Support ==

WordPress.org support forum:
https://wordpress.org/support/plugin/michael-cloud-image-auto-importer/