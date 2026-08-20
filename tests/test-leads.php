<?php
/**
 * EveryPage_Leads — cursor discipline, de-duplication, and the public
 * everypage_lead_captured contract.
 */
class Test_EveryPage_Leads extends WP_UnitTestCase {

	/** @var EveryPage_Leads */
	private $leads;

	/** @var array Leads captured by the test listener. */
	private $captured = array();

	public function set_up() {
		parent::set_up();
		EveryPage_HTTP_Stub::reset();
		EveryPage_HTTP_Stub::hook();
		update_option(
			'everypage_settings',
			array(
				'api_key'    => 'ep_live_test',
				'leads_sync' => true,
			)
		);
		$this->captured = array();
		add_action(
			'everypage_lead_captured',
			function ( $lead ) {
				$this->captured[] = $lead;
			}
		);
		$this->leads = new EveryPage_Leads( new EveryPage_API() );
	}

	public function tear_down() {
		EveryPage_HTTP_Stub::unhook();
		delete_option( 'everypage_settings' );
		delete_option( 'everypage_leads_cursor' );
		delete_option( 'everypage_leads_seen' );
		delete_option( 'everypage_leads_last_error' );
		delete_transient( 'everypage_leads_lock' );
		parent::tear_down();
	}

	private function gate_row( $id, $email ) {
		return array(
			'id'       => $id,
			'fileUuid' => '550e8400-e29b-41d4-a716-446655440000',
			'fileName' => 'Lead magnet.pdf',
			'readAt'   => '2026-08-20T10:00:00Z',
			'type'     => 'gate',
			'fields'   => array( 'email' => $email, 'name' => 'Ada' ),
		);
	}

	public function test_a_run_fires_the_hook_and_advances_the_cursor() {
		EveryPage_HTTP_Stub::on( '/gate-responses', array( $this->gate_row( 7, 'ada@example.com' ) ) );
		$fired = $this->leads->run();

		$this->assertSame( 1, $fired );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( 7, $this->leads->cursor() );

		$lead = $this->captured[0];
		$this->assertSame( 'form', $lead['source'] );
		$this->assertSame( 'ada@example.com', $lead['email'] );
		$this->assertSame( 'Ada', $lead['fields']['name'] );
		$this->assertSame( 'Lead magnet.pdf', $lead['file_name'] );
		$this->assertSame( '2026-08-20T10:00:00Z', $lead['captured_at'] );
	}

	/**
	 * The whole point of a cursor: a failed page must leave it where it was,
	 * so the rows are re-read next time rather than skipped for ever.
	 */
	public function test_an_upstream_error_holds_the_cursor() {
		update_option( 'everypage_leads_cursor', 5 );
		EveryPage_HTTP_Stub::on( '/gate-responses', 'server exploded', 500 );

		$res = $this->leads->run();

		$this->assertWPError( $res );
		$this->assertSame( 5, $this->leads->cursor(), 'cursor must not move past unread rows' );
		$this->assertEmpty( $this->captured );
		$this->assertNotEmpty( $this->leads->last_error() );
	}

	/** Rows at or below the cursor are never re-dispatched. */
	public function test_rows_at_the_cursor_do_not_refire() {
		update_option( 'everypage_leads_cursor', 7 );
		EveryPage_HTTP_Stub::on( '/gate-responses', array( $this->gate_row( 7, 'ada@example.com' ) ) );

		$this->assertSame( 0, $this->leads->run() );
		$this->assertEmpty( $this->captured );
	}

	public function test_disabled_sync_does_nothing() {
		update_option( 'everypage_settings', array( 'api_key' => 'ep_live_test', 'leads_sync' => false ) );
		$leads = new EveryPage_Leads( new EveryPage_API() );
		$this->assertSame( 0, $leads->run() );
		$this->assertEmpty( EveryPage_HTTP_Stub::$requests );
	}

	/** A concurrent run (cron tick plus a "Sync now" click) must not double-fire. */
	public function test_the_lock_blocks_a_concurrent_run() {
		set_transient( 'everypage_leads_lock', 1, 300 );
		$this->assertSame( 0, $this->leads->run() );
		$this->assertEmpty( EveryPage_HTTP_Stub::$requests );
	}

	/** Priming moves the cursor to the newest lead WITHOUT dispatching it. */
	public function test_priming_skips_history() {
		EveryPage_HTTP_Stub::on( '/gate-responses', array( $this->gate_row( 99, 'ada@example.com' ) ) );
		$this->leads->prime_cursor();

		$this->assertSame( 99, $this->leads->cursor() );
		$this->assertEmpty( $this->captured, 'enabling sync must not replay every historical lead' );
	}

	/* ------------------------------------------------------------- the sweep */

	private function enable_sweep() {
		update_option(
			'everypage_settings',
			array(
				'api_key'     => 'ep_live_test',
				'leads_sync'  => true,
				'leads_sweep' => true,
			)
		);
		return new EveryPage_Leads( new EveryPage_API() );
	}

	private function stub_sweep( $sessions ) {
		EveryPage_HTTP_Stub::on( '/gate-responses', array() );
		EveryPage_HTTP_Stub::on(
			'/api/v1/files',
			array(
				array(
					'uuid'         => '550e8400-e29b-41d4-a716-446655440000',
					'originalName' => 'Guide.pdf',
					'requireEmail' => true,
				),
			)
		);
		EveryPage_HTTP_Stub::on( '/readership', array( 'sessions' => $sessions ) );
	}

	public function test_the_sweep_captures_email_gate_readers_once() {
		$leads = $this->enable_sweep();
		$this->stub_sweep(
			array(
				array( 'email' => 'reader@example.com', 'startedAt' => '2026-08-20T09:00:00Z' ),
				array( 'email' => '' ),
			)
		);

		$this->assertSame( 1, $leads->run() );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'email_gate', $this->captured[0]['source'] );
		$this->assertSame( 'reader@example.com', $this->captured[0]['email'] );
		$this->assertSame( 0, $this->captured[0]['id'] );

		// Same session again on the next pass: already seen, no second fire.
		delete_transient( 'everypage_leads_lock' );
		$this->stub_sweep( array( array( 'email' => 'reader@example.com', 'startedAt' => '2026-08-20T09:00:00Z' ) ) );
		$this->assertSame( 0, $leads->run() );
		$this->assertCount( 1, $this->captured );
	}

	/** An invited reader is an address the owner already had, not a new lead. */
	public function test_the_sweep_ignores_invited_readers() {
		$leads = $this->enable_sweep();
		$this->stub_sweep( array( array( 'email' => 'client@example.com', 'fromInvite' => true ) ) );

		$this->assertSame( 0, $leads->run() );
		$this->assertEmpty( $this->captured );
	}

	/** Files with a lead form are covered exactly by the cursor; sweeping them would duplicate. */
	public function test_the_sweep_skips_files_that_have_a_lead_form() {
		$leads = $this->enable_sweep();
		EveryPage_HTTP_Stub::on( '/gate-responses', array() );
		EveryPage_HTTP_Stub::on(
			'/api/v1/files',
			array(
				array(
					'uuid'         => '550e8400-e29b-41d4-a716-446655440000',
					'originalName' => 'Guide.pdf',
					'requireEmail' => true,
					'gateFields'   => array( array( 'key' => 'email', 'label' => 'Email' ) ),
				),
			)
		);

		$this->assertSame( 0, $leads->run() );
		$this->assertEmpty( $this->captured );
	}

	public function test_sweep_is_off_unless_enabled() {
		EveryPage_HTTP_Stub::on( '/gate-responses', array() );
		$this->assertSame( 0, $this->leads->run() );
		// Only the cursor read happened — no file listing, no readership calls.
		$this->assertCount( 1, EveryPage_HTTP_Stub::$requests );
	}

	/* ---------------------------------------------------------- scheduling */

	public function test_schedule_follows_the_toggle() {
		$this->leads->sync_schedule();
		$this->assertNotEmpty( wp_next_scheduled( EveryPage_Leads::CRON_HOOK ) );

		update_option( 'everypage_settings', array( 'api_key' => 'ep_live_test', 'leads_sync' => false ) );
		$off = new EveryPage_Leads( new EveryPage_API() );
		$off->sync_schedule();
		$this->assertFalse( wp_next_scheduled( EveryPage_Leads::CRON_HOOK ) );
	}
}
