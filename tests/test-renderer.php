<?php
/**
 * EveryPage_Renderer — the shared output path for the shortcode, the block,
 * and the Elementor widget.
 */
class Test_EveryPage_Renderer extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		EveryPage_HTTP_Stub::reset();
		EveryPage_HTTP_Stub::hook();
		update_option( 'everypage_settings', array( 'api_key' => 'ep_live_test' ) );
	}

	public function tear_down() {
		EveryPage_HTTP_Stub::unhook();
		delete_option( 'everypage_settings' );
		parent::tear_down();
	}

	/**
	 * The v1 bug: a hex-only filter silently DELETED every character outside
	 * [a-f0-9-], so the short id users copy (aB3xK9mZq2Lp) became aB392 — a
	 * dead link rendered with no error. Reject, never truncate.
	 */
	public function test_validate_id_rejects_rather_than_truncating() {
		$this->assertSame( '', EveryPage_Renderer::validate_id( 'aB3xK9mZq2Lp!' ) );
		$this->assertSame( '', EveryPage_Renderer::validate_id( 'abc def' ) );
		$this->assertSame( '', EveryPage_Renderer::validate_id( '<script>' ) );
		$this->assertSame( '', EveryPage_Renderer::validate_id( '' ) );
	}

	public function test_validate_id_accepts_all_three_identifier_shapes() {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$this->assertSame( $uuid, EveryPage_Renderer::validate_id( $uuid ) );
		$this->assertSame( 'aB3xK9mZq2Lp', EveryPage_Renderer::validate_id( 'aB3xK9mZq2Lp' ) );
		$this->assertSame( 'my-proposal', EveryPage_Renderer::validate_id( 'my-proposal' ) );
		$this->assertSame( 'abc', EveryPage_Renderer::validate_id( '  abc  ' ) );
	}

	/**
	 * The bare share page is served with `frame-ancestors 'none'`; only
	 * /embed/{id} can render in a frame. Getting this wrong produces a blank
	 * box on every site using the block.
	 */
	public function test_embed_uses_the_embed_path() {
		$html = EveryPage_Renderer::embed( 'aB3xK9mZq2Lp', 600, 'Report.pdf' );
		$this->assertStringContainsString( '/embed/aB3xK9mZq2Lp', $html );
		$this->assertStringContainsString( 'title="Report.pdf"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	public function test_embed_clamps_height_and_handles_empty_id() {
		$this->assertStringContainsString( 'height="200"', EveryPage_Renderer::embed( 'abc', 10, '' ) );
		$this->assertStringContainsString( 'height="2000"', EveryPage_Renderer::embed( 'abc', 99999, '' ) );
		$this->assertSame( '', EveryPage_Renderer::embed( '', 600, '' ) );
	}

	public function test_link_escapes_text_and_marks_external() {
		$html = EveryPage_Renderer::link( 'abc', '<b>Read</b> "now"', false );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
		$this->assertStringContainsString( 'everypage-share-link', $html );

		$button = EveryPage_Renderer::link( 'abc', 'Read', true );
		$this->assertStringContainsString( 'everypage-share-button', $button );
	}

	/** document() prefers the freshly-resolved short id over a stored UUID. */
	public function test_document_prefers_short_id_from_the_api() {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		EveryPage_HTTP_Stub::on( '/api/v1/files/', array( 'shortId' => 'aB3xK9mZq2Lp', 'originalName' => 'Deck.pdf' ) );

		$html = EveryPage_Renderer::document( array( 'uuid' => $uuid ), new EveryPage_API() );

		$this->assertStringContainsString( '/embed/aB3xK9mZq2Lp', $html );
		$this->assertStringNotContainsString( $uuid, $html );
		$this->assertStringContainsString( 'title="Deck.pdf"', $html );
	}

	/** A file that is verifiably gone renders nothing, not a broken frame. */
	public function test_document_renders_nothing_when_the_file_is_gone() {
		EveryPage_HTTP_Stub::on( '/api/v1/files/', 'not found', 404 );
		$html = EveryPage_Renderer::document( array( 'uuid' => 'aB3xK9mZq2Lp' ), new EveryPage_API() );
		$this->assertSame( '', $html );
	}

	/** A transient failure still renders, from the stored attributes. */
	public function test_document_renders_optimistically_on_a_transient_failure() {
		EveryPage_HTTP_Stub::on( '/api/v1/files/', 'rate limited', 429 );
		$html = EveryPage_Renderer::document( array( 'shortId' => 'aB3xK9mZq2Lp' ), new EveryPage_API() );
		$this->assertStringContainsString( '/embed/aB3xK9mZq2Lp', $html );
	}

	public function test_document_renders_nothing_without_a_key() {
		delete_option( 'everypage_settings' );
		$this->assertSame( '', EveryPage_Renderer::document( array( 'uuid' => 'abc' ), new EveryPage_API() ) );
	}

	public function test_document_button_mode_uses_the_link_renderer() {
		EveryPage_HTTP_Stub::on( '/api/v1/files/', array( 'shortId' => 'aB3xK9mZq2Lp' ) );
		$html = EveryPage_Renderer::document(
			array( 'uuid' => 'aB3xK9mZq2Lp', 'mode' => 'button', 'text' => 'Get the guide' ),
			new EveryPage_API()
		);
		$this->assertStringContainsString( 'Get the guide', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}
}
