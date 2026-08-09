<?php
/**
 * [everypage] shortcode - embed a tracked EveryPage link in posts/pages.
 *
 * Usage: [everypage uuid="550e8400-..." text="View the proposal" button="yes"]
 *
 * Output comes from EveryPage_Renderer, shared with the everypage/document
 * block, so shortcode and block button mode always render identically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Shortcode {

	public function hooks() {
		add_shortcode( 'everypage', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/** Register (don't enqueue) the frontend stylesheet; the renderer enqueues it on demand. */
	public function register_assets() {
		wp_register_style( 'everypage-shortcode', EVERYPAGE_PLUGIN_URL . 'assets/shortcode.css', array(), EVERYPAGE_VERSION );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'uuid'   => '',
				'text'   => __( 'View document', 'everypage' ),
				'button' => 'no',
			),
			$atts,
			'everypage'
		);

		// Three-identifier validation (UUID / short id / vanity slug) lives in
		// EveryPage_Renderer::validate_id(): reject on any stripped character,
		// never truncate. See the comment there for the history.
		$id = EveryPage_Renderer::validate_id( $atts['uuid'] );
		if ( '' === $id ) {
			return '';
		}

		$is_btn = in_array( strtolower( (string) $atts['button'] ), array( 'yes', 'true', '1' ), true );

		return EveryPage_Renderer::link( $id, $atts['text'], $is_btn );
	}
}
