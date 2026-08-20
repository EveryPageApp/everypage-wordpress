<?php
/**
 * EveryPage_API — status mapping, caching, and cache invalidation.
 */
class Test_EveryPage_API extends WP_UnitTestCase {

	/** @var EveryPage_API */
	private $api;

	public function set_up() {
		parent::set_up();
		EveryPage_HTTP_Stub::reset();
		EveryPage_HTTP_Stub::hook();
		update_option( 'everypage_settings', array( 'api_key' => 'ep_live_test' ) );
		$this->api = new EveryPage_API();
	}

	public function tear_down() {
		EveryPage_HTTP_Stub::unhook();
		delete_option( 'everypage_settings' );
		delete_option( 'everypage_cache_v' );
		parent::tear_down();
	}

	public function test_no_key_short_circuits_before_any_request() {
		delete_option( 'everypage_settings' );
		$api = new EveryPage_API();
		$res = $api->get_user();
		$this->assertWPError( $res );
		$this->assertSame( 'everypage_no_key', $res->get_error_code() );
		$this->assertEmpty( EveryPage_HTTP_Stub::$requests );
	}

	/** A revoked key must read as "reconnect", not as a generic HTTP error. */
	public function test_401_maps_to_an_unauthorized_error() {
		EveryPage_HTTP_Stub::on( '/api/v1/user', 'nope', 401 );
		$res = $this->api->get_user();
		$this->assertWPError( $res );
		$this->assertSame( 'everypage_unauthorized', $res->get_error_code() );
	}

	/** 403 carries the upstream text, which is what separates tier from scope. */
	public function test_403_keeps_the_upstream_message() {
		EveryPage_HTTP_Stub::on( '/api/v1/gate-responses', 'insufficient_scope: readership:read', 403 );
		$res = $this->api->list_gate_responses( 0, 10 );
		$this->assertWPError( $res );
		$this->assertSame( 'everypage_http_403', $res->get_error_code() );
		$this->assertStringContainsString( 'insufficient_scope', $res->get_error_message() );
	}

	/** A 200 with no body (the settings PUT) is success, not a parse failure. */
	public function test_empty_success_body_is_an_empty_array() {
		EveryPage_HTTP_Stub::on( '/settings', '', 200 );
		$res = $this->api->update_settings( 'aB3xK9mZq2Lp', array( 'allowDownload' => true ) );
		$this->assertNotWPError( $res );
		$this->assertSame( array(), $res );
	}

	public function test_malformed_json_is_an_error_not_a_null_payload() {
		EveryPage_HTTP_Stub::on( '/api/v1/user', '{not json', 200 );
		$res = $this->api->get_user();
		$this->assertWPError( $res );
		$this->assertSame( 'everypage_bad_response', $res->get_error_code() );
	}

	public function test_gets_are_cached_and_mutations_bust_the_cache() {
		EveryPage_HTTP_Stub::on( '/api/v1/user', array( 'email' => 'a@example.com' ) );
		$this->api->get_user();
		$this->api->get_user(); // Served from the transient.
		$this->assertCount( 1, EveryPage_HTTP_Stub::$requests );

		// A mutation bumps the cache version, so the next read re-fetches.
		EveryPage_HTTP_Stub::on( '/settings', '', 200 );
		$this->api->update_settings( 'aB3xK9mZq2Lp', array( 'allowDownload' => false ) );

		EveryPage_HTTP_Stub::on( '/api/v1/user', array( 'email' => 'b@example.com' ) );
		$user = $this->api->get_user();
		$this->assertSame( 'b@example.com', $user['email'] );
	}

	/** Errors must never be cached, or one blip would stick for the whole TTL. */
	public function test_errors_are_not_cached() {
		EveryPage_HTTP_Stub::on( '/api/v1/user', 'boom', 500 );
		$this->assertWPError( $this->api->get_user() );

		EveryPage_HTTP_Stub::on( '/api/v1/user', array( 'email' => 'a@example.com' ) );
		$this->assertNotWPError( $this->api->get_user() );
	}

	/** The cursor read must be fresh, and must not send since=0. */
	public function test_gate_responses_are_uncached_and_omit_a_zero_cursor() {
		EveryPage_HTTP_Stub::on( '/api/v1/gate-responses', array() );
		$this->api->list_gate_responses( 0, 100 );
		$this->assertStringContainsString( 'limit=100', EveryPage_HTTP_Stub::$requests[0] );
		$this->assertStringNotContainsString( 'since=', EveryPage_HTTP_Stub::$requests[0] );

		EveryPage_HTTP_Stub::on( '/api/v1/gate-responses', array() );
		$this->api->list_gate_responses( 42, 100 );
		$this->assertCount( 2, EveryPage_HTTP_Stub::$requests, 'cursor reads must never be served from cache' );
		$this->assertStringContainsString( 'since=42', EveryPage_HTTP_Stub::$requests[1] );
	}

	public function test_requests_carry_the_bearer_key() {
		$captured = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured ) {
				$captured = $args['headers']['Authorization'];
				return $preempt;
			},
			5,
			3
		);
		EveryPage_HTTP_Stub::on( '/api/v1/user', array() );
		$this->api->get_user();
		$this->assertSame( 'Bearer ep_live_test', $captured );
	}
}
