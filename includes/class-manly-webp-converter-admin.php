<?php
/**
 * Admin page and AJAX handlers for Manly WebP Converter.
 *
 * @package ManlyWebpConverter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manly_WebP_Converter_Admin
 *
 * Provides the admin settings page and handles AJAX conversion requests.
 *
 * @since 1.0.0
 */
class Manly_WebP_Converter_Admin {

	/**
	 * Hook suffix for our settings page (returned by add_management_page()).
	 *
	 * @since 1.0.2
	 * @var string
	 */
	protected static $page_hook = '';

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_manly_webp_converter_get_attachments', array( __CLASS__, 'ajax_get_attachments' ) );
		add_action( 'wp_ajax_manly_webp_converter_convert_single', array( __CLASS__, 'ajax_convert_single' ) );
		add_action( 'wp_ajax_manly_webp_converter_analyze', array( __CLASS__, 'ajax_analyze' ) );
		add_filter( 'plugin_action_links_manly-webp-converter/manly-webp-converter.php', array( __CLASS__, 'add_settings_link' ) );
	}

	/**
	 * Enqueue admin JS for our settings page only.
	 *
	 * @since 1.0.2
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== self::$page_hook ) {
			return;
		}

		wp_register_script(
			'manly-webp-converter-admin',
			MANLY_WEBP_CONVERTER_URL . 'assets/js/admin.js',
			array(),
			MANLY_WEBP_CONVERTER_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'manly-webp-converter-admin',
			'manlyWebpConverter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'manly_webp_converter_nonce' ),
				'i18n'    => array(
					'reanalyze'    => __( 'Re-analyze', 'manly-webp-converter' ),
					'stopping'     => __( 'Stopping…', 'manly-webp-converter' ),
					'stop'         => __( 'Stop', 'manly-webp-converter' ),
					'start'        => __( 'Start Conversion', 'manly-webp-converter' ),
					'analyzeFirst' => __( 'Please click "Analyze last 10 pages / products" first to choose a page.', 'manly-webp-converter' ),
				),
			)
		);

		wp_enqueue_script( 'manly-webp-converter-admin' );
	}

	/**
	 * Add a Settings link in the plugin list.
	 *
	 * @since 1.0.1
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=manly-webp-converter' ) ) . '">' . esc_html__( 'Settings', 'manly-webp-converter' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register the admin menu page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function add_menu_page() {
		self::$page_hook = add_management_page(
			__( 'Manly WebP Converter', 'manly-webp-converter' ),
			__( 'Manly WebP Converter', 'manly-webp-converter' ),
			'manage_options',
			'manly-webp-converter',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * AJAX handler: get list of attachment IDs to convert.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function ajax_get_attachments() {
		check_ajax_referer( 'manly_webp_converter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'manly-webp-converter' ) );
		}

		$mode       = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'product' ) );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$post_id    = absint( $_POST['post_id'] ?? 0 );
		$converter  = new Manly_WebP_Converter();

		if ( 'product' === $mode && $product_id > 0 ) {
			$ids = $converter->get_product_attachment_ids( $product_id );
		} elseif ( 'post' === $mode && $post_id > 0 ) {
			$ids = $converter->get_post_attachment_ids( $post_id );
		} else {
			$ids = $converter->get_all_image_attachment_ids();
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No convertible images found.', 'manly-webp-converter' ) );
		}

		wp_send_json_success(
			array(
				'attachment_ids' => $ids,
				'total'          => count( $ids ),
			)
		);
	}

	/**
	 * AJAX handler: convert a single attachment to WebP.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function ajax_convert_single() {
		check_ajax_referer( 'manly_webp_converter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'manly-webp-converter' ) );
		}

		$attachment_id  = absint( $_POST['attachment_id'] ?? 0 );
		$quality        = absint( $_POST['quality'] ?? 80 );
		$keep_originals = ! empty( $_POST['keep_originals'] );
		$mode           = sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) );
		$post_id        = absint( $_POST['post_id'] ?? 0 );
		$product_id     = absint( $_POST['product_id'] ?? 0 );

		if ( $quality < 1 || $quality > 100 ) {
			$quality = 80;
		}

		// Determine which post we're operating in context of so that
		// update_content_references() can target it directly.
		$context_post_id = 0;
		if ( 'post' === $mode && $post_id > 0 ) {
			$context_post_id = $post_id;
		} elseif ( 'product' === $mode && $product_id > 0 ) {
			$context_post_id = $product_id;
		}

		$converter = new Manly_WebP_Converter( $quality, $keep_originals );
		$result    = $converter->convert_attachment( $attachment_id, $context_post_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler: analyze the media library and return stats + post list.
	 *
	 * @since 1.0.2
	 * @return void
	 */
	public static function ajax_analyze() {
		check_ajax_referer( 'manly_webp_converter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'manly-webp-converter' ) );
		}

		$converter           = new Manly_WebP_Converter();
		$posts_with_non_webp = $converter->get_recent_posts_with_non_webp( 10 );
		$non_webp_ids        = $converter->get_all_image_attachment_ids();
		$non_webp            = count( $non_webp_ids );
		$counts              = wp_count_attachments();
		$webp                = (int) ( $counts->{'image/webp'} ?? 0 );

		$ids_in_content = array();
		foreach ( $posts_with_non_webp as $item ) {
			$ids_in_content = array_merge(
				$ids_in_content,
				$converter->get_post_attachment_ids( $item['id'] )
			);
		}
		$ids_in_content = array_unique( $ids_in_content );
		// get_post_attachment_ids() returns only items needing work
		// (non-webp attachments + stale-webp references whose URL in content
		// is still .png/.jpg/.gif). So count it directly.
		$in_content     = count( $ids_in_content );
		$not_in_content = max( 0, $non_webp - count( array_intersect( $non_webp_ids, $ids_in_content ) ) );

		$posts = array();
		foreach ( $posts_with_non_webp as $item ) {
			$posts[] = array(
				'id'    => $item['id'],
				'title' => $item['title'],
				'type'  => $item['type'],
				'count' => $item['count'],
				'url'   => get_permalink( $item['id'] ),
			);
		}

		wp_send_json_success(
			array(
				'non_webp'       => $non_webp,
				'webp'           => $webp,
				'in_content'     => $in_content,
				'not_in_content' => $not_in_content,
				'posts'          => $posts,
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Manly WebP Converter', 'manly-webp-converter' ); ?></h1>

			<div id="manly-webp-main">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'manly-webp-converter' ); ?></th>
					<td>
						<div id="manly-webp-converter-mode" style="display:inline-flex; border:1px solid #8c8f94; border-radius:3px; overflow:hidden;">
							<label style="margin:0;">
								<input type="radio" name="manly_webp_mode" value="post" checked style="position:absolute;opacity:0;width:0;height:0;">
								<span class="manly-webp-mode-btn" data-value="post" style="display:block;padding:4px 12px;cursor:pointer;background:#0073aa;color:#fff;"><?php esc_html_e( 'Test on Single Page', 'manly-webp-converter' ); ?></span>
							</label>
							<label style="margin:0; border-left:1px solid #8c8f94;">
								<input type="radio" name="manly_webp_mode" value="all" style="position:absolute;opacity:0;width:0;height:0;">
								<span class="manly-webp-mode-btn" data-value="all" style="display:block;padding:4px 12px;cursor:pointer;background:#f6f7f7;"><?php esc_html_e( 'All Images', 'manly-webp-converter' ); ?></span>
							</label>
						</div>
					</td>
				</tr>
				<tr id="manly-webp-converter-post-row">
					<th scope="row">
						<label for="manly-webp-converter-post"><?php esc_html_e( 'Page / Post / Product', 'manly-webp-converter' ); ?></label>
					</th>
					<td>
						<button id="manly-webp-analyze" class="button">
							<?php esc_html_e( 'Analyze last 10 pages / products', 'manly-webp-converter' ); ?>
						</button>
						<span id="manly-webp-analyzing" style="display:none; margin-left:8px; vertical-align:middle;">
							<span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
							<?php esc_html_e( 'Analyzing…', 'manly-webp-converter' ); ?>
						</span>
						<select id="manly-webp-converter-post" style="display:none;"></select>
						<a id="manly-webp-converter-post-link" href="#" target="_blank" rel="noopener"
							style="margin-left:8px; display:none;"><?php esc_html_e( 'View', 'manly-webp-converter' ); ?> &rarr;</a>
						<span id="manly-webp-no-posts" style="display:none; margin-left:8px;">
							<em><?php esc_html_e( 'No pages, posts or products with non-WebP images found.', 'manly-webp-converter' ); ?></em>
						</span>
						<p id="manly-webp-stats" style="display:none; margin:6px 0 0; color:#646970;"></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="manly-webp-converter-quality"><?php esc_html_e( 'WebP Quality', 'manly-webp-converter' ); ?></label>
					</th>
					<td>
						<input type="range" id="manly-webp-converter-quality" min="50" max="100" value="80"
							style="vertical-align:middle">
						<span id="manly-webp-converter-quality-val">80</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep Originals', 'manly-webp-converter' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="manly-webp-converter-keep" checked>
							<?php esc_html_e( 'Keep original files after conversion (recommended for testing).', 'manly-webp-converter' ); ?>
						</label>
					</td>
				</tr>

			</table>

			<p>
				<button id="manly-webp-converter-start" class="button button-primary">
					<?php esc_html_e( 'Start Conversion', 'manly-webp-converter' ); ?>
				</button>
				<span id="manly-webp-stop-note" style="display:none; margin-left:10px; color:#646970; font-style:italic;"><?php esc_html_e( 'Already-converted images are saved &#8212; you can safely continue later.', 'manly-webp-converter' ); ?></span>
			</p>

			<div id="manly-webp-converter-progress" style="display:none; margin-top:20px;">
				<h3><?php esc_html_e( 'Progress', 'manly-webp-converter' ); ?></h3>
				<div style="background:#e0e0e0; border-radius:4px; overflow:hidden; height:24px; max-width:600px;">
					<div id="manly-webp-converter-bar"
						style="background:#0073aa; height:100%; width:0%; transition:width 0.3s;"></div>
				</div>
				<p id="manly-webp-converter-status"></p>
			</div>

			<div id="manly-webp-converter-log" style="margin-top:20px;">
				<h3><?php esc_html_e( 'Log', 'manly-webp-converter' ); ?></h3>
				<textarea id="manly-webp-converter-log-area" readonly rows="15"
					style="width:100%; max-width:800px; font-family:monospace; font-size:12px;"></textarea>
			</div>
			</div><!-- #manly-webp-main -->
		</div>
		<?php
	}
}
