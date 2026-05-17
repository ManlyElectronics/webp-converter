=== Manly WebP Converter ===
Contributors: DimitriAus
Tags: webp, media, image optimization, woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reduces server space and page load times by converting WordPress and WooCommerce images to WebP format.

== Description ==

Manly WebP Converter converting images to the modern WebP format. For example, a shop with 1,000 products can see overall storage drop from around 15 GB to 6 GB. Page load times are reduced proportionally.

Features:
- Convert images for one WooCommerce product or all media images.
- Keep originals during testing or delete originals after conversion.
- Updates attachment metadata and content references in posts, pages, and all other post types.
- Shows progress, logs, and total bytes saved.
- Adjustable WebP image quality — test on a single page or product first and verify picture quality visually before converting your entire library.
- Conversion of thousands of pictures may take hours; you can stop at any time by clicking the Stop button and resume later by clicking Start again — already-converted images are skipped automatically. Refreshing or closing the page is also safe: each image is saved to disk as it is processed, so starting again after a page refresh will continue from where the conversion left off.

= Requirements =

This plugin requires the PHP **GD** extension, which is usually included with standard PHP installations. If GD is not already active on your server, it can be enabled in **cPanel → PHP Configuration** with a single tick.

= Conversion Modes =

Both modes convert attachments stored in the media library. The difference is scope:

* **Single Product** — reads the featured image and gallery post meta of the chosen WooCommerce product and converts only those linked attachments.
* **All Images** — queries every JPEG, PNG, and GIF attachment in the entire media library, regardless of whether it is attached to a product.

After each conversion, the plugin searches `post_content` and `post_excerpt` across every post type (posts, pages, products, custom post types) and rewrites both full URLs and path-only references. It does **not** update image URLs stored in post meta or custom fields (e.g. page-builder block attributes saved outside the content column).

**WooCommerce product images are safe** because featured images and gallery images are stored as attachment IDs, not URLs — WordPress resolves them dynamically from the updated attachment record.

**Warning: page builders and URL-based custom fields.** Tools such as Elementor, Divi, Beaver Builder, and ACF (when set to return a URL) store image URLs directly in post meta. These are not rewritten by this plugin. If you then delete the original files, any such stale URL will point to a missing file and cause broken images on the front end.

**Recommended workflow:**
1. Run the conversion with **Keep Originals enabled**.
2. Check your site visually — especially pages built with a page builder.
3. Only disable Keep Originals (to delete originals) once you are confident no post meta or theme option holds a raw image URL that has not been updated.

== Installation ==

1. Upload the plugin folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Tools > Manly WebP Converter.

== Frequently Asked Questions ==

= Does this require GD WebP support? =
Yes. The server must support imagewebp() via the PHP GD extension.

= Can I keep original files? =
Yes. Enable Keep Originals in the tool before conversion.

== Additional Information ==

This plugin is primarily designed for WooCommerce stores. While the All Images mode covers the entire media library, the core workflow and Single Product mode are built around WooCommerce product images. More detailed guidance will be added in a future update.

== Changelog ==

= 1.0.3 =
- Fixed: Coding standards compliance (WordPress PHPCS).
- Fixed: Minimum PHP requirement corrected to 7.2.

= 1.0.1 =
- Updated readme with conversion mode details, content reference scope, and page-builder warning.

= 1.0.0 =
- Initial release.

== Upgrade Notice ==

= 1.0.1 =
Readme improvements only. No code changes.

= 1.0.0 =
Initial release.
