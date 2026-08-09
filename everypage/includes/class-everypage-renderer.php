<?php
/**
 * Shared front-end renderer for EveryPage output. The [everypage] shortcode
 * and the everypage/document block both produce their markup here, so the two
 * can never drift apart (ServerSideRender-style parity, one source of truth).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Renderer {

	/**
	 * Validate a share identifier.
	 *
	 * Share identifiers come in three shapes: a canonical UUID, a 12-char
	 * base62 short id, or a lowercase vanity slug. An earlier hex-only filter
	 * silently DELETED every character outside [a-f0-9-], so the link a user
	 * copies from the EveryPage UI — which prefers the short id — was mangled
	 * rather than rejected: aB3xK9mZq2Lp became aB392, a dead link rendered
	 * with no error.
	 *
	 * Reject on any difference instead of truncating: a partially-stripped
	 * identifier can never be the right one, and a visible failure beats a
	 * broken page.
	 *
	 * @param string $raw The identifier as supplied.
	 * @return string The identifier, or '' if it contains any disallowed character.
	 */
	public static function validate_id( $raw ) {
		$raw = trim( (string) $raw );
		$id  = preg_replace( '/[^A-Za-z0-9_-]/', '', $raw );
		if ( '' === $id || $id !== $raw ) {
			return '';
		}
		return $id;
	}

	/**
	 * A share link (plain anchor or styled button) — the shortcode's output.
	 *
	 * @param string $id        Validated share identifier (UUID or short id).
	 * @param string $text      Link text.
	 * @param bool   $is_button Render as a styled button instead of a plain link.
	 * @return string HTML, or '' for an empty identifier.
	 */
	public static function link( $id, $text, $is_button ) {
		if ( '' === $id ) {
			return '';
		}
		$url = everypage_base_url() . '/' . rawurlencode( $id );
		// Frontend pages don't load the admin stylesheet, so a button needs its
		// own CSS. Enqueue it only when a button is actually rendered.
		if ( $is_button ) {
			wp_enqueue_style( 'everypage-shortcode' );
		}
		$classes = $is_button ? 'everypage-share-button' : 'everypage-share-link';

		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener">%3$s</a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $text )
		);
	}

	/**
	 * An inline document embed (iframe) — the block's embed mode.
	 *
	 * The iframe src MUST be the /embed/ path: the bare share page is served
	 * with CSP `frame-ancestors 'none'` and will not render inside a frame,
	 * while /embed/{id} is served with `frame-ancestors *`. Embeds are durable
	 * artifacts, so the id must be the short id (or UUID fallback) — never the
	 * vanity slug, which the owner can change or remove.
	 *
	 * @param string $id     Validated share identifier (short id preferred, UUID fallback).
	 * @param int    $height Iframe height in pixels.
	 * @param string $title  Accessible iframe title (the file name).
	 * @return string HTML, or '' for an empty identifier.
	 */
	public static function embed( $id, $height, $title ) {
		if ( '' === $id ) {
			return '';
		}
		$src    = everypage_base_url() . '/embed/' . rawurlencode( $id );
		$height = max( 200, min( 2000, absint( $height ) ) );
		if ( '' === trim( (string) $title ) ) {
			$title = __( 'EveryPage document', 'everypage' );
		}

		return sprintf(
			'<div class="everypage-embed"><iframe src="%1$s" width="100%%" height="%2$d" frameborder="0" allowfullscreen title="%3$s" loading="lazy"></iframe></div>',
			esc_url( $src ),
			$height,
			esc_attr( $title )
		);
	}
}
