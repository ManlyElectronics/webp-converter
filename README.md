# Manly WebP Converter

A WordPress plugin that converts media library images to WebP format, reducing server storage and improving page load times.

**WordPress.org:** [https://wordpress.org/plugins/manly-webp-converter/](https://wordpress.org/plugins/manly-webp-converter/)

## Description

Manly WebP Converter converts images to the modern WebP format. For example, a shop with 1,000 products can see overall storage drop from around 15 GB to 6 GB. Page load times are reduced proportionally.

## Features

- Convert images for one WooCommerce product or all media library images
- Keep originals during testing or delete originals after conversion
- Updates attachment metadata and content references in posts, pages, and all other post types
- Shows progress, logs, and total bytes saved
- Adjustable WebP image quality
- Stop and resume at any time — already-converted images are skipped automatically

## Requirements

- WordPress 6.0+
- PHP 7.2+
- PHP **GD** extension with WebP support (`imagewebp()`)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the **Plugins** menu in WordPress
3. Go to **Tools > Manly WebP Converter**

## Conversion Modes

- **Single Product** — converts only the featured image and gallery images of a chosen WooCommerce product
- **All Images** — converts every JPEG, PNG, and GIF attachment in the entire media library

After conversion, the plugin rewrites image references in `post_content` and `post_excerpt` across all post types. It does **not** update URLs stored in post meta or custom fields (e.g. page-builder block attributes).

## Recommended Workflow

1. Run conversion with **Keep Originals** enabled
2. Check your site visually, especially pages built with a page builder
3. Only disable Keep Originals once you are confident no stale URLs remain in post meta

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

## Author

[Manly Electronics](https://manlyelectronics.com.au)
