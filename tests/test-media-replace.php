<?php
/**
 * EveryPage_Media's link-replacement internals.
 *
 * This is the one part of the plugin that rewrites a user's post content, so
 * the counting and replacing rules are pinned here: an over-count would
 * mislead the dry-run diff, and a double replacement would corrupt content.
 * The methods are private by design; reflection keeps them that way while
 * still letting the risky logic be tested.
 */
class Test_EveryPage_Media_Replace extends WP_UnitTestCase {

	/** @var EveryPage_Media */
	private $media;

	public function set_up() {
		parent::set_up();
		$this->media = new EveryPage_Media( new EveryPage_API() );
	}

	private function call( $method, array $args ) {
		$ref = new ReflectionMethod( EveryPage_Media::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->media, $args );
	}

	private function variants() {
		return array(
			'https://example.com/wp-content/uploads/2026/08/guide.pdf',
			'http://example.com/wp-content/uploads/2026/08/guide.pdf',
			'//example.com/wp-content/uploads/2026/08/guide.pdf',
		);
	}

	/**
	 * The protocol-relative variant is a substring of both absolute forms, so
	 * a naive count would report three matches for one link.
	 */
	public function test_counting_does_not_double_count_the_protocol_relative_form() {
		$content = '<a href="https://example.com/wp-content/uploads/2026/08/guide.pdf">Guide</a>';
		$this->assertSame( 1, $this->call( 'count_matches', array( $content, $this->variants() ) ) );
	}

	public function test_counting_finds_every_distinct_occurrence() {
		$content = '<a href="https://example.com/wp-content/uploads/2026/08/guide.pdf">A</a>'
			. '<a href="http://example.com/wp-content/uploads/2026/08/guide.pdf">B</a>'
			. '<img src="//example.com/wp-content/uploads/2026/08/guide.pdf" />';
		$this->assertSame( 3, $this->call( 'count_matches', array( $content, $this->variants() ) ) );
	}

	public function test_counting_ignores_a_similar_but_different_file() {
		$content = '<a href="https://example.com/wp-content/uploads/2026/08/guide-v2.pdf">Other</a>';
		$this->assertSame( 0, $this->call( 'count_matches', array( $content, $this->variants() ) ) );
	}

	public function test_replacement_rewrites_every_variant_exactly_once() {
		$content = '<a href="https://example.com/wp-content/uploads/2026/08/guide.pdf">A</a>'
			. '<a href="//example.com/wp-content/uploads/2026/08/guide.pdf">B</a>';

		list( $out, $count ) = $this->call(
			'replace_matches',
			array( $content, $this->variants(), 'https://everypage.co/aB3xK9mZq2Lp' )
		);

		$this->assertSame( 2, $count );
		$this->assertStringNotContainsString( 'guide.pdf', $out );
		$this->assertSame( 2, substr_count( $out, 'https://everypage.co/aB3xK9mZq2Lp' ) );
	}

	public function test_replacement_leaves_unrelated_content_alone() {
		$content = 'Read the <a href="https://example.com/about">about page</a> first.';
		list( $out, $count ) = $this->call(
			'replace_matches',
			array( $content, $this->variants(), 'https://everypage.co/aB3xK9mZq2Lp' )
		);
		$this->assertSame( 0, $count );
		$this->assertSame( $content, $out );
	}

	/**
	 * Replaced links must point at the durable identifier — the short id, with
	 * the UUID as fallback — and never at a vanity slug, which the owner can
	 * change or remove out from under the post.
	 */
	public function test_replace_target_prefers_the_short_id() {
		$post_id = self::factory()->post->create();
		$uuid    = '550e8400-e29b-41d4-a716-446655440000';

		$this->assertStringEndsWith(
			'/' . $uuid,
			$this->call( 'replace_target', array( $post_id, $uuid ) )
		);

		update_post_meta( $post_id, '_everypage_short_id', 'aB3xK9mZq2Lp' );
		$this->assertStringEndsWith(
			'/aB3xK9mZq2Lp',
			$this->call( 'replace_target', array( $post_id, $uuid ) )
		);
	}
}
