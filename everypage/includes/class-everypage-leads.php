<?php
/**
 * Lead sync: pulls captured leads from EveryPage into WordPress and fires the
 * `everypage_lead_captured` action so mailing-list and CRM plugins (MailPoet,
 * FluentCRM, Newsletter, WP Fusion, or a two-line add_action) can consume them.
 *
 * Two upstream sources, because one endpoint does not cover the job:
 *
 *  1. Lead-capture FORMS — `GET /api/v1/gate-responses`, an exact, gapless,
 *     cursor-paged stream. This is the reliable path.
 *  2. Plain EMAIL GATES ("Require email to view") — those captures are stored
 *     on the reading session, not in gate responses, and are excluded from the
 *     cursor upstream by design. They can only be read as `sessions[].email`
 *     inside per-file readership, which has no cursor, so that path is a
 *     best-effort sweep with local de-duplication. It is the classic
 *     lead-magnet setup, which is why it is worth sweeping at all.
 *
 * Both are Pro-only upstream and need an API key carrying `readership:read`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Leads {

	const CRON_HOOK  = 'everypage_leads_sync';
	const OPT_CURSOR = 'everypage_leads_cursor';
	const OPT_SEEN   = 'everypage_leads_seen';
	const OPT_ERROR  = 'everypage_leads_last_error';
	const LOCK       = 'everypage_leads_lock';

	/** Sweep de-duplication entries kept, newest first, so the option cannot grow without bound. */
	const SEEN_MAX = 5000;

	/** Cursor pages consumed per run: bounds the work a single cron tick can do. */
	const MAX_PAGES = 10;

	/** Files inspected per sweep, newest first — the sweep is best-effort, not exhaustive. */
	const SWEEP_MAX_FILES = 25;

	private $api;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	public function hooks() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/* ---------------------------------------------------------------- state */

	private function settings() {
		$opts = get_option( EveryPage_API::OPTION, array() );
		return is_array( $opts ) ? $opts : array();
	}

	private function flag( $name ) {
		$opts = $this->settings();
		return ! empty( $opts[ $name ] );
	}

	public function is_enabled() {
		return $this->flag( 'leads_sync' );
	}

	public function sweep_enabled() {
		return $this->flag( 'leads_sweep' );
	}

	public function creates_users() {
		return $this->flag( 'leads_create_users' );
	}

	public function last_error() {
		return (string) get_option( self::OPT_ERROR, '' );
	}

	public function cursor() {
		return (int) get_option( self::OPT_CURSOR, 0 );
	}

	/** Next scheduled run as a UTC timestamp, or 0 when unscheduled. */
	public function next_run() {
		return (int) wp_next_scheduled( self::CRON_HOOK );
	}

	/* ------------------------------------------------------------ scheduling */

	/**
	 * Keep the cron event in step with the toggle. Safe to call repeatedly —
	 * on every settings save, on activation, and on upgrade.
	 */
	public function sync_schedule() {
		if ( $this->is_enabled() && $this->api->has_key() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
			}
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* --------------------------------------------------------------- actions */

	/**
	 * Settings-form POSTs. Mirrors EveryPage_Admin's pattern: capability check,
	 * nonce per action, then redirect so a refresh cannot replay the request.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) || empty( $_POST['everypage_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['everypage_action'] ) );

		if ( 'save_leads' === $action && check_admin_referer( 'everypage_save_leads' ) ) {
			$was_enabled = $this->is_enabled();
			$opts        = $this->settings();

			$opts['leads_sync']         = ! empty( $_POST['everypage_leads_sync'] );
			$opts['leads_sweep']        = ! empty( $_POST['everypage_leads_sweep'] );
			$opts['leads_create_users'] = ! empty( $_POST['everypage_leads_create_users'] );
			update_option( EveryPage_API::OPTION, $opts );

			// Turning sync on primes the cursor at the newest lead instead of
			// replaying history: nobody wants their CRM to receive every lead
			// they ever captured the moment they tick a box.
			$notice = 'leadssaved';
			if ( ! $was_enabled && ! empty( $opts['leads_sync'] ) ) {
				$this->prime_cursor();
				$notice = 'leadsprimed';
			}
			$this->sync_schedule();
			$this->redirect( $notice );
		}

		if ( 'sync_leads' === $action && check_admin_referer( 'everypage_sync_leads' ) ) {
			$fired = $this->run();
			$this->redirect( is_wp_error( $fired ) ? 'leadsfailed' : 'leadssynced', is_wp_error( $fired ) ? 0 : (int) $fired );
		}
	}

	private function redirect( $notice, $count = 0 ) {
		$args = array(
			'page'             => 'everypage-settings',
			'everypage_notice' => $notice,
		);
		if ( $count ) {
			$args['everypage_count'] = $count;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Move the cursor to the newest lead without firing anything, so enabling
	 * sync starts the stream from "now".
	 */
	public function prime_cursor() {
		$rows = $this->api->list_gate_responses( 0, 1 );
		if ( is_wp_error( $rows ) ) {
			// A key without Pro or without the scope cannot prime; leave the
			// cursor alone and let the first real run report the error.
			$this->record_error( $rows );
			return;
		}
		if ( ! empty( $rows[0]['id'] ) ) {
			update_option( self::OPT_CURSOR, (int) $rows[0]['id'] );
		}
		delete_option( self::OPT_ERROR );
	}

	/* ------------------------------------------------------------------- run */

	/**
	 * One sync pass. Returns the number of leads dispatched, or a WP_Error if
	 * the upstream call failed (the cursor stays put in that case, so nothing
	 * is skipped — a later run picks the same rows up again).
	 *
	 * @return int|WP_Error
	 */
	public function run() {
		if ( ! $this->is_enabled() || ! $this->api->has_key() ) {
			return 0;
		}
		// Overlapping runs (a slow cron tick plus a "Sync now" click) would
		// fire the action twice for the same rows.
		if ( get_transient( self::LOCK ) ) {
			return 0;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$fired = $this->sync_forms();
			if ( is_wp_error( $fired ) ) {
				return $fired;
			}
			if ( $this->sweep_enabled() ) {
				$swept = $this->sweep_email_gates();
				if ( ! is_wp_error( $swept ) ) {
					$fired += $swept;
				}
			}
			delete_option( self::OPT_ERROR );
			return $fired;
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * The reliable path: walk the gate-response cursor forward.
	 *
	 * The cursor is persisted after each page, never at the end, so a failure
	 * or a timeout half way through a long backlog keeps the progress already
	 * made instead of replaying it.
	 *
	 * @return int|WP_Error
	 */
	private function sync_forms() {
		$cursor = $this->cursor();
		$fired  = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$rows = $this->api->list_gate_responses( $cursor, 100 );
			if ( is_wp_error( $rows ) ) {
				$this->record_error( $rows );
				return $rows;
			}
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			$max = $cursor;
			foreach ( $rows as $row ) {
				$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( $id > $max ) {
					$max = $id;
				}
				// A cursor read (since > 0) only ever returns rows above the
				// cursor; the priming path never dispatches. Guard anyway so a
				// cursor reset cannot double-fire the newest page.
				if ( $id > $cursor ) {
					$this->dispatch( $this->lead_from_gate_row( $row ) );
					++$fired;
				}
			}

			if ( $max <= $cursor ) {
				break; // No forward progress: stop rather than spin.
			}
			$cursor = $max;
			update_option( self::OPT_CURSOR, $cursor );

			if ( count( $rows ) < 100 ) {
				break; // Short page: caught up.
			}
		}

		return $fired;
	}

	/**
	 * The best-effort path: documents whose gate is a plain email gate (no
	 * lead-capture form) never appear in gate responses, so read their recent
	 * sessions and treat each captured, non-invite email as a lead once.
	 *
	 * @return int|WP_Error
	 */
	private function sweep_email_gates() {
		$files = $this->api->list_files();
		if ( is_wp_error( $files ) ) {
			$this->record_error( $files );
			return $files;
		}
		if ( ! is_array( $files ) ) {
			return 0;
		}

		$seen    = $this->seen();
		$fired   = 0;
		$checked = 0;

		foreach ( $files as $file ) {
			if ( $checked >= self::SWEEP_MAX_FILES ) {
				break;
			}
			// Only plain email gates: a file with a lead form is already
			// covered exactly by the cursor, and sweeping it would duplicate.
			if ( empty( $file['requireEmail'] ) || ! empty( $file['gateFields'] ) ) {
				continue;
			}
			$uuid = isset( $file['uuid'] ) ? (string) $file['uuid'] : '';
			if ( '' === $uuid ) {
				continue;
			}
			++$checked;

			$data = $this->api->get_analytics( $uuid );
			if ( is_wp_error( $data ) || empty( $data['sessions'] ) || ! is_array( $data['sessions'] ) ) {
				continue;
			}

			foreach ( $data['sessions'] as $session ) {
				$email = isset( $session['email'] ) ? trim( (string) $session['email'] ) : '';
				// fromInvite means the address came from an email invite the
				// owner already has — that is not a newly captured lead.
				if ( '' === $email || ! empty( $session['fromInvite'] ) || ! is_email( $email ) ) {
					continue;
				}
				$hash = sha1( strtolower( $email ) . '|' . $uuid );
				if ( isset( $seen[ $hash ] ) ) {
					continue;
				}
				$seen[ $hash ] = time();

				$this->dispatch(
					array(
						'id'          => 0,
						'source'      => 'email_gate',
						'email'       => $email,
						'fields'      => array( 'email' => $email ),
						'file_uuid'   => $uuid,
						'file_name'   => isset( $file['originalName'] ) ? (string) $file['originalName'] : '',
						'captured_at' => isset( $session['startedAt'] ) ? (string) $session['startedAt'] : '',
					)
				);
				++$fired;
			}
		}

		$this->save_seen( $seen );
		return $fired;
	}

	/* ---------------------------------------------------------------- leads */

	/** Normalise a gate-response row into the documented lead shape. */
	private function lead_from_gate_row( $row ) {
		$fields = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
		$email  = '';
		if ( isset( $fields['email'] ) ) {
			$email = trim( (string) $fields['email'] );
		}
		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'source'      => 'form',
			'email'       => $email,
			'fields'      => array_map( 'strval', $fields ),
			'file_uuid'   => isset( $row['fileUuid'] ) ? (string) $row['fileUuid'] : '',
			'file_name'   => isset( $row['fileName'] ) ? (string) $row['fileName'] : '',
			'captured_at' => isset( $row['readAt'] ) ? (string) $row['readAt'] : '',
		);
	}

	/**
	 * Hand one lead to WordPress.
	 *
	 * `everypage_lead_captured` is this plugin's public contract — the shape
	 * passed here is documented in readme.txt and must stay stable.
	 */
	private function dispatch( $lead ) {
		if ( $this->creates_users() && ! empty( $lead['email'] ) && is_email( $lead['email'] ) ) {
			$this->maybe_create_user( $lead );
		}

		/**
		 * Fires once for every lead EveryPage captured for this account.
		 *
		 * @param array $lead {
		 *     @type int    $id          Gate-response id; 0 for a swept email-gate capture.
		 *     @type string $source      'form' or 'email_gate'.
		 *     @type string $email       Captured email, '' if the form had none.
		 *     @type array  $fields      Captured key => value pairs.
		 *     @type string $file_uuid   Document the lead was captured on.
		 *     @type string $file_name   Document's file name.
		 *     @type string $captured_at ISO-8601 timestamp.
		 * }
		 */
		do_action( 'everypage_lead_captured', $lead );
	}

	/** Optional: mirror the lead as a WP subscriber. Off by default. */
	private function maybe_create_user( $lead ) {
		if ( email_exists( $lead['email'] ) ) {
			return;
		}
		$username = sanitize_user( current( explode( '@', $lead['email'] ) ), true );
		if ( '' === $username || username_exists( $username ) ) {
			$username = 'ep_' . wp_generate_password( 8, false, false );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $lead['email'],
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => ! empty( $lead['fields']['name'] ) ? (string) $lead['fields']['name'] : $username,
				'role'         => 'subscriber',
			)
		);
		if ( ! is_wp_error( $user_id ) ) {
			add_user_meta( $user_id, 'everypage_lead_source', $lead['file_uuid'], true );
		}
	}

	/* ----------------------------------------------------------------- state */

	private function seen() {
		$seen = get_option( self::OPT_SEEN, array() );
		return is_array( $seen ) ? $seen : array();
	}

	/** Persist the sweep's de-dup set, keeping only the newest SEEN_MAX entries. */
	private function save_seen( $seen ) {
		if ( count( $seen ) > self::SEEN_MAX ) {
			arsort( $seen );
			$seen = array_slice( $seen, 0, self::SEEN_MAX, true );
		}
		update_option( self::OPT_SEEN, $seen, false );
	}

	/**
	 * Store a human-readable reason for the settings page. The upstream tier
	 * gate (403 for a non-Pro account) and the scope gate (403 for a key
	 * without readership:read) both arrive as HTTP 403 but need different
	 * advice, so keep the upstream text — it names which one it is.
	 */
	private function record_error( WP_Error $error ) {
		update_option( self::OPT_ERROR, $error->get_error_message(), false );
	}
}
