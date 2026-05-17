<?php
/**
 * Plugin Name: Manly WebP Converter
 * Description: Converts product images to WebP format, updates DB references, optionally deletes originals.
 * Version: 1.0.3
 * Author: Manly Electronics
 * Author URI: https://manlyelectronics.com.au
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: manly-webp-converter
 * Requires PHP: 7.2
 *
 * @package  ManlyWebpConverter
 * @since    1.0.0
 * PHP Version 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MANLY_WEBP_CONVERTER_VERSION', '1.0.3' );
define( 'MANLY_WEBP_CONVERTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'MANLY_WEBP_CONVERTER_URL', plugin_dir_url( __FILE__ ) );

require_once MANLY_WEBP_CONVERTER_PATH . 'includes/class-manly-webp-converter.php';
require_once MANLY_WEBP_CONVERTER_PATH . 'includes/class-manly-webp-converter-admin.php';

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 * @return void
 */
function manly_webp_converter_init() {
	if ( ! function_exists( 'imagewebp' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Manly WebP Converter requires PHP GD extension with WebP support.', 'manly-webp-converter' );
				echo '</p></div>';
			}
		);
		return;
	}
	Manly_WebP_Converter_Admin::init();
}
add_action( 'plugins_loaded', 'manly_webp_converter_init' );
