<?php
/**
 * The everypage/document block: registers block.json from build/ and renders
 * dynamically in PHP (no saved markup beyond attributes), so front-end output
 * always reflects the file's current state and matches the shortcode renderer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Block {

	private $api;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		$dir = EVERYPAGE_PLUGIN_DIR . 'build/document';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			return; // Source tree without a build; nothing to register.
		}
		register_block_type(
			$dir,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Dynamic render. The resolve-and-render rules live in the shared
	 * renderer, so the block, the Elementor widget, and the shortcode can
	 * never drift apart.
	 */
	public function render( $attributes ) {
		return EveryPage_Renderer::document( is_array( $attributes ) ? $attributes : array(), $this->api );
	}
}
