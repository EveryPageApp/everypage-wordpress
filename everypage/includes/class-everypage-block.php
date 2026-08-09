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
	 * Dynamic render. Embed mode degrades gracefully: a file that is verifiably
	 * gone (deleted/expired) renders nothing rather than a broken frame, while
	 * a transient API failure renders optimistically from the stored short id —
	 * the /embed/ page itself needs no API key.
	 */
	public function render( $attributes ) {
		$uuid     = EveryPage_Renderer::validate_id( isset( $attributes['uuid'] ) ? $attributes['uuid'] : '' );
		$short_id = EveryPage_Renderer::validate_id( isset( $attributes['shortId'] ) ? $attributes['shortId'] : '' );
		if ( '' === $uuid && '' === $short_id ) {
			return '';
		}
		if ( ! $this->api->has_key() ) {
			return ''; // Unconfigured plugin renders nothing on the front end.
		}

		$mode   = isset( $attributes['mode'] ) && 'button' === $attributes['mode'] ? 'button' : 'embed';
		$height = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 600;
		$text   = isset( $attributes['text'] ) && '' !== trim( (string) $attributes['text'] )
			? (string) $attributes['text']
			: __( 'View document', 'everypage' );
		$is_btn = ! isset( $attributes['buttonStyle'] ) || 'link' !== $attributes['buttonStyle'];
		$title  = isset( $attributes['fileName'] ) ? (string) $attributes['fileName'] : '';

		// Resolve the stored file so output reflects its current state. Cached
		// (30s transient) so front-end page loads don't block on the API.
		$file = $this->api->get_file( '' !== $uuid ? $uuid : $short_id );
		if ( is_wp_error( $file ) ) {
			$code = (string) $file->get_error_code();
			if ( in_array( $code, array( 'everypage_http_404', 'everypage_http_410' ), true ) ) {
				return ''; // Deleted or expired: no broken frame, no dead link.
			}
			// Transient failure (network, rate limit): render from stored attributes.
		} else {
			if ( ! empty( $file['shortId'] ) ) {
				$short_id = EveryPage_Renderer::validate_id( $file['shortId'] );
			}
			if ( ! empty( $file['originalName'] ) ) {
				$title = (string) $file['originalName'];
			}
		}

		// Embeds are durable artifacts: short id preferred, UUID fallback,
		// never the vanity slug. Same identifier for the button link.
		$id = '' !== $short_id ? $short_id : $uuid;

		if ( 'button' === $mode ) {
			return EveryPage_Renderer::link( $id, $text, $is_btn );
		}

		return EveryPage_Renderer::embed( $id, $height, $title );
	}
}
