<?php
/**
 * Elementor widget for an EveryPage document.
 *
 * Elementor has its own editor and its users mostly never touch Gutenberg, so
 * the block alone leaves them with the shortcode. The widget renders through
 * EveryPage_Renderer::document(), exactly like the block, so embed and button
 * output can never drift between the two.
 *
 * Viewer APPEARANCE (flipbook effects, branding, protection) is a property of
 * the document on EveryPage, not of the placement — it is edited once in the
 * Files-page settings drawer and applies everywhere the document appears. The
 * widget therefore carries placement controls only and links to the drawer,
 * rather than keeping a second copy of the tier-gated appearance UI in sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Elementor {

	/** @var EveryPage_API|null Shared client for the widget instances Elementor constructs. */
	private static $api = null;

	/** Register with Elementor if — and only if — Elementor is present. */
	public static function hooks( EveryPage_API $api ) {
		self::$api = $api;
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
	}

	public static function api() {
		return self::$api;
	}

	public static function register( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			return;
		}
		require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-elementor-widget.php';
		$widgets_manager->register( new EveryPage_Elementor_Widget() );
	}
}
