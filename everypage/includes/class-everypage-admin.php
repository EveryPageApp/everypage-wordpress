<?php
/**
 * Admin UI: a "EveryPage" menu with a Files page (upload + list + analytics
 * links) and a Settings page (API key + connection test).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Admin {

	private $api;

	/** Cached /api/v1/user result so the masthead + status share one request. */
	private $user_cache  = null;
	private $user_loaded = false;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	/** The connected account (WP_Error or null), fetched at most once per request. */
	private function current_user() {
		if ( ! $this->user_loaded ) {
			$this->user_cache  = $this->api->has_key() ? $this->api->get_user() : null;
			$this->user_loaded = true;
		}
		return $this->user_cache;
	}

	/** Brand masthead + connection pill, shared across all EveryPage screens. */
	private function masthead() {
		$user = $this->current_user();
		echo '<div class="everypage-masthead"><div class="everypage-brand">';
		echo '<span class="everypage-seal" aria-hidden="true"><span class="dashicons dashicons-media-document"></span></span>';
		echo '<div><div class="everypage-wordmark">EveryPage</div><p class="everypage-tagline">' .
			esc_html__( 'Share PDFs and see who actually reads them.', 'everypage' ) . '</p></div></div>';

		if ( ! is_wp_error( $user ) && ! empty( $user ) ) {
			echo '<span class="everypage-conn"><span class="dot"></span>' . esc_html( isset( $user['email'] ) ? $user['email'] : '' );
			if ( ! empty( $user['subscription'] ) ) {
				echo ' <span class="plan">' . esc_html( $user['subscription'] ) . '</span>';
			}
			echo '</span>';
		} else {
			echo '<span class="everypage-conn is-off"><span class="dot"></span>' . esc_html__( 'Not connected', 'everypage' ) . '</span>';
		}
		echo '</div>';
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_everypage_qr', array( $this, 'ajax_qr' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'EveryPage', 'everypage' ),
			__( 'EveryPage', 'everypage' ),
			'manage_options',
			'everypage',
			array( $this, 'render_files' ),
			'dashicons-media-document',
			81
		);
		add_submenu_page( 'everypage', __( 'Files', 'everypage' ), __( 'Files', 'everypage' ), 'manage_options', 'everypage', array( $this, 'render_files' ) );
		add_submenu_page( 'everypage', __( 'Settings', 'everypage' ), __( 'Settings', 'everypage' ), 'manage_options', 'everypage-settings', array( $this, 'render_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'everypage' ) ) {
			return;
		}
		wp_enqueue_style( 'everypage-admin', EVERYPAGE_PLUGIN_URL . 'assets/admin.css', array(), EVERYPAGE_VERSION );
		wp_enqueue_script( 'everypage-admin', EVERYPAGE_PLUGIN_URL . 'assets/admin.js', array(), EVERYPAGE_VERSION, true );
		wp_localize_script(
			'everypage-admin',
			'EveryPageQR',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'everypage_qr' ),
			)
		);
		$user = $this->current_user();
		$plan = ( ! is_wp_error( $user ) && ! empty( $user['subscription'] ) ) ? (string) $user['subscription'] : 'free';
		wp_localize_script(
			'everypage-admin',
			'EveryPageAdmin',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'everypage/v1/' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'plan'       => $plan,
				'baseUrl'    => everypage_base_url(),
				'pricingUrl' => 'https://everypage.co/pricing',
				'i18n'       => array(
					'copied'          => __( 'Copied!', 'everypage' ),
					'copyFailed'      => __( 'Copy failed — select the text and copy it manually.', 'everypage' ),
					'saving'          => __( 'Saving…', 'everypage' ),
					'saved'           => __( 'Saved.', 'everypage' ),
					'save'            => __( 'Save changes', 'everypage' ),
					'unsaved'         => __( 'Unsaved changes', 'everypage' ),
					'genericError'    => __( 'Something went wrong. Please try again.', 'everypage' ),
					'loadFailed'      => __( 'Could not load this file. Please try again.', 'everypage' ),
					'deadLink'        => __( 'This link has expired or reached its view limit; its settings can be viewed but not changed.', 'everypage' ),
					/* translators: %s: plan name (free, basic or pro) */
					'planNow'         => __( 'Your plan is now %s — the controls below have been updated.', 'everypage' ),
					/* translators: %s: a number of days */
					'expiryCap'       => __( 'Your plan allows expiry up to %s days from now.', 'everypage' ),
					/* translators: %s: a number of days */
					'expiryOverCap'   => __( 'That date is beyond your plan\'s expiry limit (%s days from now).', 'everypage' ),
					'expiryNoCap'     => __( 'Leave empty to keep the current expiry.', 'everypage' ),
					'watermarkRule'   => __( 'Watermarking with downloads enabled is not available for files over 100 MB. Turn one of the two off.', 'everypage' ),
					'passwordSet'     => __( 'A password is currently set.', 'everypage' ),
					'setPassword'     => __( 'Set a password', 'everypage' ),
					'replacePassword' => __( 'Enter a new password to replace it', 'everypage' ),
					/* translators: 1: views consumed, 2: the view limit */
					'viewsUsed'       => __( '%1$s of %2$s views used.', 'everypage' ),
					'burned'          => __( 'View limit reached — this link is burned.', 'everypage' ),
					/* translators: %s: the resulting share URL */
					'slugUrl'         => __( 'Share URL: %s', 'everypage' ),
					'slugHint'        => __( 'Vanity slugs apply on custom domains. Connect a custom domain on EveryPage to use this URL.', 'everypage' ),
					'slugTaken'       => __( 'That slug is already taken — try another.', 'everypage' ),
					/* translators: %s: the rejected domain entry */
					'badDomain'       => __( '"%s" is not a valid domain — use bare hostnames like acme.com.', 'everypage' ),
					'labelRequired'   => __( 'Every form field needs a label.', 'everypage' ),
					'emailLabel'      => __( 'Email', 'everypage' ),
					'textLabel'       => __( 'Name', 'everypage' ),
					'phoneLabel'      => __( 'Phone number', 'everypage' ),
					'companyLabel'    => __( 'Company', 'everypage' ),
					'removeField'     => __( 'Remove', 'everypage' ),
					'requiredLabel'   => __( 'Required', 'everypage' ),
					'never'           => __( 'Never', 'everypage' ),
				),
			)
		);
	}

	/**
	 * admin-ajax proxy: stream a file's QR PNG (inline, or as a download).
	 * Gated on upload_files (a read of a public share artifact), matching the
	 * REST proxy's read capability, so the Media Library share modal can show
	 * QR codes to the same users who may share files.
	 */
	public function ajax_qr() {
		if ( ! current_user_can( 'upload_files' ) ) {
			status_header( 403 );
			exit;
		}
		check_ajax_referer( 'everypage_qr' );
		$uuid = isset( $_GET['uuid'] ) ? preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) ) : '';
		if ( '' === $uuid ) {
			status_header( 400 );
			exit;
		}
		$png = $this->api->get_qr_png( $uuid );
		if ( is_wp_error( $png ) ) {
			status_header( 502 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $png ) );
		if ( ! empty( $_GET['download'] ) ) {
			header( 'Content-Disposition: attachment; filename="everypage-' . $uuid . '-qr.png"' );
		}
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PNG image stream
		exit;
	}

	/** Handle form posts (save key, upload, delete) with nonce + capability checks. */
	public function handle_actions() {
		// A POST that PHP silently discarded for exceeding post_max_size arrives
		// with empty $_POST/$_FILES (the nonce can't even be checked), so the
		// upload would otherwise no-op with no feedback. Detect it on our page
		// and surface a clear size error.
		if ( current_user_can( 'manage_options' )
			&& 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' )
			&& isset( $_GET['page'] ) && 'everypage' === sanitize_key( wp_unslash( $_GET['page'] ) )
			&& empty( $_POST )
			&& ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			$this->redirect( 'everypage', 'toolarge' );
		}

		if ( ! current_user_can( 'manage_options' ) || empty( $_POST['everypage_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['everypage_action'] ) );

		if ( 'save_key' === $action && check_admin_referer( 'everypage_save_key' ) ) {
			$key = isset( $_POST['everypage_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['everypage_api_key'] ) ) : '';
			$this->api->set_key( $key );
			$this->redirect( 'everypage-settings', 'saved' );
		}

		if ( 'upload' === $action && check_admin_referer( 'everypage_upload' ) ) {
			$this->do_upload();
		}

		if ( 'delete' === $action && check_admin_referer( 'everypage_delete' ) ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			if ( $uuid ) {
				$this->api->delete_file( $uuid );
			}
			$this->redirect( 'everypage', 'deleted' );
		}
	}

	private function do_upload() {
		// Nonce is verified by the caller, handle_actions(), via check_admin_referer( 'everypage_upload' ).
		if ( empty( $_FILES['everypage_pdf']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_actions()
			$this->redirect( 'everypage', 'nofile' );
		}
		$file = $_FILES['everypage_pdf']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- sanitized below; nonce verified in handle_actions()
		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'document.pdf';
		// Validate by extension, not the browser-supplied MIME (which is often
		// application/octet-stream for a genuine PDF); the backend re-validates.
		if ( 'pdf' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$this->redirect( 'everypage', 'notpdf' );
		}
		$bytes = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading the just-uploaded temp file
		if ( false === $bytes ) {
			$this->redirect( 'everypage', 'readfail' );
		}
		$res = $this->api->upload_pdf( $bytes, $name );
		$this->redirect( 'everypage', is_wp_error( $res ) ? 'uploadfail' : 'uploaded' );
	}

	private function redirect( $page, $notice ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&everypage_notice=' . $notice ) );
		exit;
	}

	private function notice() {
		// Read-only: shows a one-time notice keyed to a fixed allowlist below. No
		// state change, so no nonce applies (the value is sanitized before use).
		if ( empty( $_GET['everypage_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display
			return;
		}
		$map = array(
			'saved'      => array( 'success', __( 'Settings saved.', 'everypage' ) ),
			'uploaded'   => array( 'success', __( 'PDF uploaded and shared.', 'everypage' ) ),
			'deleted'    => array( 'success', __( 'File deleted.', 'everypage' ) ),
			'notpdf'     => array( 'error', __( 'Only PDF files can be shared.', 'everypage' ) ),
			'nofile'     => array( 'error', __( 'Please choose a PDF to upload.', 'everypage' ) ),
			'uploadfail' => array( 'error', __( 'Upload failed. Check your API key and plan limits.', 'everypage' ) ),
			'readfail'   => array( 'error', __( 'Could not read the uploaded file.', 'everypage' ) ),
			'toolarge'   => array(
				'error',
				sprintf(
					/* translators: %s: maximum upload size, e.g. "8 MB" */
					__( 'That file is larger than this server allows (max %s). Try a smaller PDF.', 'everypage' ),
					size_format( wp_max_upload_size() )
				),
			),
		);
		$key = sanitize_key( wp_unslash( $_GET['everypage_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $key ][0] ), esc_html( $map[ $key ][1] ) );
		}
	}

	public function render_settings() {
		$this->notice();
		?>
		<div class="wrap everypage-screen">
			<?php $this->masthead(); ?>
			<div class="everypage-panel" style="max-width:680px">
				<div class="everypage-panel-head">
					<h2><?php esc_html_e( 'API connection', 'everypage' ); ?></h2>
					<p class="everypage-sub">
						<?php
						printf(
							/* translators: %s: link to the account API keys page */
							esc_html__( 'Create an API key in your %s and paste it below.', 'everypage' ),
							'<a href="' . esc_url( everypage_base_url() . '/account' ) . '" target="_blank" rel="noopener">' . esc_html__( 'EveryPage account', 'everypage' ) . '</a>'
						);
						?>
					</p>
				</div>
				<form method="post">
					<?php wp_nonce_field( 'everypage_save_key' ); ?>
					<input type="hidden" name="everypage_action" value="save_key" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="everypage_api_key"><?php esc_html_e( 'API key', 'everypage' ); ?></label></th>
							<td>
								<input name="everypage_api_key" id="everypage_api_key" type="password" class="regular-text" autocomplete="off"
									value="<?php echo esc_attr( $this->api->get_key() ); ?>" placeholder="ep_live_..." />
								<?php $this->connection_status(); ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save key', 'everypage' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private function connection_status() {
		if ( ! $this->api->has_key() ) {
			return;
		}
		$user = $this->current_user();
		if ( is_wp_error( $user ) ) {
			echo '<p class="everypage-status everypage-bad">' . esc_html( $user->get_error_message() ) . '</p>';
			return;
		}
		echo '<p class="everypage-status everypage-ok">' . sprintf(
			/* translators: 1: email, 2: plan */
			esc_html__( 'Connected as %1$s (%2$s plan).', 'everypage' ),
			esc_html( isset( $user['email'] ) ? $user['email'] : '' ),
			esc_html( isset( $user['subscription'] ) ? $user['subscription'] : '' )
		) . '</p>';
	}

	public function render_files() {
		// Read-only view routing on a manage_options-gated page; the uuid is
		// sanitized to a hex/hyphen allowlist below. No state change, no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['everypage_view'] ) && 'analytics' === $_GET['everypage_view'] && ! empty( $_GET['uuid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$uuid = preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_analytics( $uuid );
			return;
		}
		$this->notice();
		?>
		<div class="wrap everypage-screen">
			<?php $this->masthead(); ?>
			<?php
			if ( ! $this->api->has_key() ) {
				echo '<div class="everypage-panel"><p>';
				printf(
					/* translators: %s: settings page link */
					esc_html__( 'Add your API key in %s to get started.', 'everypage' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=everypage-settings' ) ) . '">' . esc_html__( 'EveryPage settings', 'everypage' ) . '</a>'
				);
				echo '</p></div></div>';
				return;
			}
			?>

			<div class="everypage-panel everypage-share">
				<div class="everypage-panel-head">
					<h2><?php esc_html_e( 'Share a PDF', 'everypage' ); ?></h2>
					<p class="everypage-sub"><?php esc_html_e( 'Upload a PDF and get a tracked link in seconds.', 'everypage' ); ?></p>
				</div>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'everypage_upload' ); ?>
					<input type="hidden" name="everypage_action" value="upload" />
					<input type="file" name="everypage_pdf" accept="application/pdf" required />
					<?php submit_button( __( 'Upload & get tracked link', 'everypage' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="everypage-panel">
				<div class="everypage-panel-head">
					<h2><?php esc_html_e( 'Your files', 'everypage' ); ?></h2>
				</div>
				<?php $this->render_files_table(); ?>
			</div>

			<div class="everypage-modal" id="everypage-qr-modal" hidden>
				<div class="everypage-modal-backdrop" data-close></div>
				<div class="everypage-modal-card" role="dialog" aria-modal="true" aria-labelledby="ep-qr-title">
					<button type="button" class="everypage-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'everypage' ); ?>">&times;</button>
					<h2 class="everypage-modal-title" id="ep-qr-title"><?php esc_html_e( 'QR code', 'everypage' ); ?></h2>
					<p class="everypage-modal-sub" id="ep-qr-name"></p>
					<div class="everypage-qr-frame">
						<img id="ep-qr-img" alt="<?php esc_attr_e( 'QR code for the share link', 'everypage' ); ?>" />
					</div>
					<a class="everypage-button" id="ep-qr-download" href="#" download><?php esc_html_e( 'Download PNG', 'everypage' ); ?></a>
				</div>
			</div>

			<?php $this->render_settings_drawer(); ?>
		</div>
		<?php
	}

	/**
	 * An inline "available on a higher plan" upgrade link for one drawer
	 * control. Rendered hidden; the JS unhides it (and disables the control)
	 * when the connected account's plan is below $plan. Controls above the
	 * tier are always visible-but-disabled, never hidden.
	 */
	private function drawer_upgrade( $plan ) {
		$label = 'pro' === $plan ? __( 'Pro', 'everypage' ) : __( 'Basic', 'everypage' );
		printf(
			'<a class="ep-upgrade" href="%1$s" target="_blank" rel="noopener" hidden>%2$s</a>',
			esc_url( 'https://everypage.co/pricing' ),
			sprintf(
				/* translators: %s: plan name ("Basic" or "Pro") */
				esc_html__( 'Available on the %s plan — upgrade', 'everypage' ),
				esc_html( $label )
			)
		);
	}

	/**
	 * The per-file settings drawer. One instance on the Files page; the JS
	 * populates it from GET everypage/v1/files/{id} when a row's "Settings"
	 * action opens it, and PUTs only the changed keys back. Every control is
	 * annotated with the plan tier that may change it (data-tier); controls
	 * above the account's tier are disabled with an inline upgrade link.
	 */
	private function render_settings_drawer() {
		?>
		<div class="everypage-modal everypage-drawer" id="everypage-settings-modal" hidden>
			<div class="everypage-modal-backdrop" data-close></div>
			<div class="everypage-modal-card everypage-drawer-card" role="dialog" aria-modal="true" aria-labelledby="ep-settings-title">
				<button type="button" class="everypage-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'everypage' ); ?>">&times;</button>
				<h2 class="everypage-modal-title" id="ep-settings-title"><?php esc_html_e( 'File settings', 'everypage' ); ?></h2>
				<p class="everypage-modal-sub" id="ep-settings-name"></p>
				<p class="ep-drawer-loading" id="ep-settings-loading"><?php esc_html_e( 'Loading…', 'everypage' ); ?></p>
				<div class="ep-drawer-banner" id="ep-settings-banner" hidden></div>

				<form id="ep-settings-form" hidden>

					<details class="ep-section" data-section="viewer" open>
						<summary><?php esc_html_e( 'Viewer', 'everypage' ); ?> <span class="ep-count" hidden></span></summary>
						<div class="ep-section-body">
							<div class="ep-field" data-tier="free">
								<label class="ep-label" for="ep-s-mode"><?php esc_html_e( 'Viewer mode', 'everypage' ); ?></label>
								<select id="ep-s-mode" data-field="viewerMode" data-default="standard">
									<option value="standard"><?php esc_html_e( 'Standard', 'everypage' ); ?></option>
									<option value="flipbook"><?php esc_html_e( 'Flipbook', 'everypage' ); ?></option>
									<option value="swipe"><?php esc_html_e( 'Swipe', 'everypage' ); ?></option>
									<option value="magazine"><?php esc_html_e( 'Magazine', 'everypage' ); ?></option>
								</select>
							</div>

							<div class="ep-field" data-tier="basic">
								<label class="ep-label" for="ep-s-bgcolor"><?php esc_html_e( 'Background colour', 'everypage' ); ?></label>
								<input type="color" id="ep-s-bgcolor" data-vs="background.color" data-default="#ffffff" />
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic" id="ep-s-bgimage-extras" hidden>
								<span class="ep-label"><?php esc_html_e( 'Background image effects', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'Blur', 'everypage' ); ?>
										<input type="number" min="0" max="20" step="1" id="ep-s-bgblur" data-vs="background.blur" /></label>
									<label><?php esc_html_e( 'Dim', 'everypage' ); ?>
										<input type="number" min="0" max="80" step="5" id="ep-s-bgdim" data-vs="background.dim" /></label>
								</span>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic">
								<span class="ep-label"><?php esc_html_e( 'Page style', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'Shadow', 'everypage' ); ?>
										<input type="number" min="0" max="3" step="1" id="ep-s-shadow" data-vs="page.shadow" /></label>
									<label><?php esc_html_e( 'Rounding', 'everypage' ); ?>
										<input type="number" min="0" max="3" step="1" id="ep-s-rounded" data-vs="page.rounded" /></label>
								</span>
								<label class="ep-check"><input type="checkbox" id="ep-s-edges" data-vs="page.edges" /> <?php esc_html_e( 'Stacked paper edges', 'everypage' ); ?></label>
								<label class="ep-check"><input type="checkbox" id="ep-s-coveralone" data-vs="page.coverAlone" data-default-checked="1" /> <?php esc_html_e( 'Show the cover page alone', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic" data-show-mode="flipbook">
								<span class="ep-label"><?php esc_html_e( 'Flipbook', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'Flip speed (ms)', 'everypage' ); ?>
										<input type="number" min="200" max="1200" step="50" id="ep-s-flipspeed" data-vs="flip.speedMs" data-default="450" /></label>
									<label><?php esc_html_e( 'Layout', 'everypage' ); ?>
										<select id="ep-s-fliplayout" data-vs="flip.layout">
											<option value=""><?php esc_html_e( 'Adaptive', 'everypage' ); ?></option>
											<option value="single"><?php esc_html_e( 'Single page', 'everypage' ); ?></option>
											<option value="double"><?php esc_html_e( 'Double spread', 'everypage' ); ?></option>
										</select></label>
								</span>
								<label class="ep-check"><input type="checkbox" id="ep-s-flipsound" data-vs="flip.sound" /> <?php esc_html_e( 'Page-turn sound', 'everypage' ); ?></label>
								<label class="ep-check"><input type="checkbox" id="ep-s-fliprtl" data-vs="flip.rtl" /> <?php esc_html_e( 'Right-to-left reading', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic" data-show-mode="swipe">
								<span class="ep-label"><?php esc_html_e( 'Swipe', 'everypage' ); ?></span>
								<label class="ep-check"><input type="checkbox" id="ep-s-autoadvance" data-vs="swipe.autoAdvance" /> <?php esc_html_e( 'Auto-advance pages', 'everypage' ); ?></label>
								<label><?php esc_html_e( 'Interval (ms)', 'everypage' ); ?>
									<input type="number" min="3000" max="30000" step="500" id="ep-s-swipeinterval" data-vs="swipe.intervalMs" data-default="6000" /></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic" id="ep-s-logo-group" hidden>
								<span class="ep-label"><?php esc_html_e( 'Logo', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'Position', 'everypage' ); ?>
										<select id="ep-s-logopos" data-vs="logo.position">
											<option value=""><?php esc_html_e( 'Bottom right', 'everypage' ); ?></option>
											<option value="tl"><?php esc_html_e( 'Top left', 'everypage' ); ?></option>
											<option value="tr"><?php esc_html_e( 'Top right', 'everypage' ); ?></option>
											<option value="bl"><?php esc_html_e( 'Bottom left', 'everypage' ); ?></option>
											<option value="br"><?php esc_html_e( 'Bottom right', 'everypage' ); ?></option>
										</select></label>
									<label><?php esc_html_e( 'Size', 'everypage' ); ?>
										<select id="ep-s-logosize" data-vs="logo.size">
											<option value=""><?php esc_html_e( 'Medium', 'everypage' ); ?></option>
											<option value="s"><?php esc_html_e( 'Small', 'everypage' ); ?></option>
											<option value="m"><?php esc_html_e( 'Medium', 'everypage' ); ?></option>
											<option value="l"><?php esc_html_e( 'Large', 'everypage' ); ?></option>
											<option value="xl"><?php esc_html_e( 'Extra large', 'everypage' ); ?></option>
										</select></label>
								</span>
								<label class="ep-check"><input type="checkbox" id="ep-s-hidebadge" data-vs="logo.hideBadge" /> <?php esc_html_e( 'Hide the EveryPage badge', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<span class="ep-label"><?php esc_html_e( 'Branding', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'Accent colour', 'everypage' ); ?>
										<input type="color" id="ep-s-accent" data-vs="brand.accentColor" data-default="#000000" /></label>
									<label><?php esc_html_e( 'Toolbar theme', 'everypage' ); ?>
										<select id="ep-s-toolbar" data-vs="brand.toolbarTheme">
											<option value=""><?php esc_html_e( 'Auto', 'everypage' ); ?></option>
											<option value="light"><?php esc_html_e( 'Light', 'everypage' ); ?></option>
											<option value="dark"><?php esc_html_e( 'Dark', 'everypage' ); ?></option>
										</select></label>
								</span>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>
						</div>
					</details>

					<details class="ep-section" data-section="protection">
						<summary><?php esc_html_e( 'Protection', 'everypage' ); ?> <span class="ep-count" hidden></span></summary>
						<div class="ep-section-body">
							<div class="ep-field" data-tier="free">
								<label class="ep-check"><input type="checkbox" id="ep-s-download" data-field="allowDownload" /> <?php esc_html_e( 'Allow readers to download the PDF', 'everypage' ); ?></label>
							</div>

							<div class="ep-field" data-tier="basic">
								<span class="ep-label"><?php esc_html_e( 'Content protection', 'everypage' ); ?></span>
								<label class="ep-check"><input type="checkbox" id="ep-s-contextmenu" data-vs="protect.contextMenu" /> <?php esc_html_e( 'Disable the right-click menu', 'everypage' ); ?></label>
								<label class="ep-check"><input type="checkbox" id="ep-s-print" data-vs="protect.print" /> <?php esc_html_e( 'Suppress printing', 'everypage' ); ?></label>
								<label class="ep-check"><input type="checkbox" id="ep-s-select" data-vs="protect.select" /> <?php esc_html_e( 'Disable text selection', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-check"><input type="checkbox" id="ep-s-bluronleave" data-vs="protect.blurOnLeave" /> <?php esc_html_e( 'Blur pages when the tab loses focus', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="basic">
								<label class="ep-label" for="ep-s-password"><?php esc_html_e( 'Password', 'everypage' ); ?></label>
								<p class="ep-hint" id="ep-s-password-state" hidden></p>
								<input type="password" id="ep-s-password" autocomplete="new-password" />
								<label class="ep-check" id="ep-s-password-remove-wrap" hidden><input type="checkbox" id="ep-s-password-remove" /> <?php esc_html_e( 'Remove the password', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic">
								<label class="ep-label" for="ep-s-viewlimit"><?php esc_html_e( 'View limit', 'everypage' ); ?></label>
								<input type="number" min="0" step="1" id="ep-s-viewlimit" data-field="viewLimit" />
								<p class="ep-hint"><?php esc_html_e( 'The link burns after this many views. 0 removes the limit.', 'everypage' ); ?></p>
								<p class="ep-hint" id="ep-s-viewlimit-meta" hidden></p>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-check"><input type="checkbox" id="ep-s-watermark" data-field="watermark" /> <?php esc_html_e( 'Watermark pages with the reader\'s email', 'everypage' ); ?></label>
								<p class="ep-hint"><?php esc_html_e( 'Not available together with downloads on files over 100 MB.', 'everypage' ); ?></p>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>
						</div>
					</details>

					<details class="ep-section" data-section="capture">
						<summary><?php esc_html_e( 'Capture', 'everypage' ); ?> <span class="ep-count" hidden></span></summary>
						<div class="ep-section-body">
							<div class="ep-field" data-tier="pro">
								<label class="ep-check"><input type="checkbox" id="ep-s-requireemail" data-field="requireEmail" /> <?php esc_html_e( 'Require an email address to read', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-label" for="ep-s-gatedomains"><?php esc_html_e( 'Allowed email domains', 'everypage' ); ?></label>
								<input type="text" id="ep-s-gatedomains" placeholder="acme.com, example.org" />
								<p class="ep-hint"><?php esc_html_e( 'Only these email domains may pass the gate. Leave empty to allow all.', 'everypage' ); ?></p>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-check"><input type="checkbox" id="ep-s-gateform" /> <?php esc_html_e( 'Lead capture form', 'everypage' ); ?></label>
								<p class="ep-hint"><?php esc_html_e( 'Ask for more than an email before opening the document. Saving a form turns the email gate on.', 'everypage' ); ?></p>
								<div id="ep-s-gatefields" hidden>
									<div class="ep-gf-rows" id="ep-s-gf-rows"></div>
									<div class="ep-gf-add">
										<button type="button" class="button" data-gf-add="text"><?php esc_html_e( '+ Text field', 'everypage' ); ?></button>
										<button type="button" class="button" data-gf-add="phone"><?php esc_html_e( '+ Phone', 'everypage' ); ?></button>
										<button type="button" class="button" data-gf-add="company"><?php esc_html_e( '+ Company', 'everypage' ); ?></button>
									</div>
								</div>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="basic">
								<label class="ep-check"><input type="checkbox" id="ep-s-notify" data-field="notifyOnView" /> <?php esc_html_e( 'Email me when someone reads this file', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>

							<div class="ep-field" data-tier="basic">
								<label class="ep-check"><input type="checkbox" id="ep-s-receipt" data-field="askReceipt" /> <?php esc_html_e( 'Ask readers to confirm receipt', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'basic' ); ?>
							</div>
						</div>
					</details>

					<details class="ep-section" data-section="link">
						<summary><?php esc_html_e( 'Link', 'everypage' ); ?> <span class="ep-count" hidden></span></summary>
						<div class="ep-section-body">
							<div class="ep-field" data-tier="free">
								<label class="ep-label" for="ep-s-deleteat"><?php esc_html_e( 'Expires', 'everypage' ); ?></label>
								<input type="datetime-local" id="ep-s-deleteat" data-field="deleteAt" />
								<p class="ep-hint" id="ep-s-expiry-cap"></p>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-check"><input type="checkbox" id="ep-s-neverexpire" data-field="neverExpire" /> <?php esc_html_e( 'Never expire', 'everypage' ); ?></label>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<label class="ep-label" for="ep-s-slug"><?php esc_html_e( 'Vanity slug', 'everypage' ); ?></label>
								<input type="text" id="ep-s-slug" data-field="slug" maxlength="64" pattern="[a-z0-9-]*" autocomplete="off" />
								<p class="ep-hint" id="ep-s-slug-hint"></p>
								<p class="ep-error" id="ep-s-slug-error" hidden></p>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>

							<div class="ep-field" data-tier="pro">
								<span class="ep-label"><?php esc_html_e( 'Page range', 'everypage' ); ?></span>
								<span class="ep-inline">
									<label><?php esc_html_e( 'From', 'everypage' ); ?>
										<input type="number" min="0" step="1" id="ep-s-pr-from" /></label>
									<label><?php esc_html_e( 'To', 'everypage' ); ?>
										<input type="number" min="0" step="1" id="ep-s-pr-to" /></label>
								</span>
								<p class="ep-hint"><?php esc_html_e( 'Only these pages are served to readers. 0 to 0 shows the whole document.', 'everypage' ); ?></p>
								<?php $this->drawer_upgrade( 'pro' ); ?>
							</div>
						</div>
					</details>

					<p class="ep-error" id="ep-settings-error" hidden></p>
					<div class="ep-drawer-foot">
						<span class="ep-drawer-status" id="ep-settings-status" aria-live="polite"></span>
						<button type="submit" class="everypage-button" id="ep-settings-save" disabled><?php esc_html_e( 'Save changes', 'everypage' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_files_table() {
		$files = $this->api->list_files();
		if ( is_wp_error( $files ) ) {
			echo '<p class="everypage-bad">' . esc_html( $files->get_error_message() ) . '</p>';
			return;
		}
		if ( empty( $files ) ) {
			echo '<p>' . esc_html__( 'No files yet.', 'everypage' ) . '</p>';
			return;
		}
		$base = everypage_base_url();
		?>
		<table class="everypage-files">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'everypage' ); ?></th>
				<th><?php esc_html_e( 'Views', 'everypage' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'everypage' ); ?></th>
				<th><?php esc_html_e( 'Link', 'everypage' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $files as $f ) :
				$uuid   = isset( $f['uuid'] ) ? $f['uuid'] : '';
				$short  = isset( $f['shortId'] ) ? (string) $f['shortId'] : '';
				$slug   = isset( $f['slug'] ) ? (string) $f['slug'] : '';
				$domain = isset( $f['shareDomain'] ) ? (string) $f['shareDomain'] : '';
				$views  = isset( $f['viewCount'] ) ? (int) $f['viewCount'] : 0;
				$name   = isset( $f['originalName'] ) ? $f['originalName'] : $uuid;
				// Human share URL preference: custom-domain slug, else the
				// short id, else the UUID. Slugs only resolve on custom
				// domains, so the slug URL is shown only with a shareDomain.
				$durable = '' !== $short ? $short : $uuid;
				$link    = ( '' !== $slug && '' !== $domain ) ? 'https://' . $domain . '/' . $slug : $base . '/' . $durable;
				// The embed snippet is a durable artifact: ALWAYS the short
				// id / UUID via /embed/ (framable lane), never the slug.
				$embed = sprintf(
					'<iframe src="%1$s" width="800" height="600" frameborder="0" allowfullscreen title="%2$s"></iframe>',
					esc_url( $base . '/embed/' . rawurlencode( $durable ) ),
					esc_attr( $name )
				);
				?>
				<tr data-uuid="<?php echo esc_attr( $uuid ); ?>">
					<td class="ep-name"><?php echo esc_html( $name ); ?></td>
					<td class="ep-views"><?php echo esc_html( number_format_i18n( $views ) ); ?></td>
					<td class="ep-expires"><?php echo esc_html( empty( $f['deleteAt'] ) ? __( 'Never', 'everypage' ) : date_i18n( get_option( 'date_format' ), strtotime( $f['deleteAt'] ) ) ); ?></td>
					<td>
						<span class="everypage-share-cluster">
							<button type="button" class="everypage-icon-btn everypage-copy-btn" data-copy="<?php echo esc_attr( $link ); ?>" title="<?php esc_attr_e( 'Copy link', 'everypage' ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'everypage' ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10.6 13.4a1 1 0 0 0 1.4 1.4l4.6-4.6a3 3 0 0 0-4.2-4.2l-2.4 2.4a1 1 0 0 0 1.4 1.4l2.4-2.4a1 1 0 0 1 1.4 1.4l-4.6 4.6Zm2.8-2.8a1 1 0 0 0-1.4-1.4l-4.6 4.6a3 3 0 0 0 4.2 4.2l2.4-2.4a1 1 0 0 0-1.4-1.4l-2.4 2.4a1 1 0 0 1-1.4-1.4l4.6-4.6Z"/></svg>
							</button>
							<button type="button" class="everypage-icon-btn everypage-qr-btn" data-uuid="<?php echo esc_attr( $uuid ); ?>" data-name="<?php echo esc_attr( $name ); ?>" title="<?php esc_attr_e( 'Show QR code', 'everypage' ); ?>" aria-label="<?php esc_attr_e( 'Show QR code', 'everypage' ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 3h8v8H3V3Zm2 2v4h4V5H5Zm8-2h8v8h-8V3Zm2 2v4h4V5h-4ZM3 13h8v8H3v-8Zm2 2v4h4v-4H5Zm8-2h2v2h-2v-2Zm2 2h2v2h-2v-2Zm2-2h2v2h-2v-2Zm0 4h2v2h-2v-2Zm-4 0h2v2h-2v-2Zm0 2h2v2h-2v-2Zm2 0h2v2h-2v-2Zm2 0h2v2h-2v-2Z"/></svg>
							</button>
							<button type="button" class="everypage-icon-btn everypage-copy-btn" data-copy="<?php echo esc_attr( $embed ); ?>" title="<?php esc_attr_e( 'Copy embed code', 'everypage' ); ?>" aria-label="<?php esc_attr_e( 'Copy embed code', 'everypage' ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.7 7.7a1 1 0 0 0-1.4-1.4l-5 5a1 1 0 0 0 0 1.4l5 5a1 1 0 0 0 1.4-1.4L4.4 12l4.3-4.3Zm6.6-1.4a1 1 0 0 0 0 1.4l4.3 4.3-4.3 4.3a1 1 0 0 0 1.4 1.4l5-5a1 1 0 0 0 0-1.4l-5-5a1 1 0 0 0-1.4 0Z"/></svg>
							</button>
							<input type="text" class="everypage-link" readonly value="<?php echo esc_attr( $link ); ?>" onfocus="this.select();" />
							<a class="everypage-open-link" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'everypage' ); ?></a>
						</span>
					</td>
					<td class="everypage-actions">
						<button type="button" class="button-link everypage-settings-btn" data-uuid="<?php echo esc_attr( $uuid ); ?>" data-name="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Settings', 'everypage' ); ?></button>
						<span class="sep">&middot;</span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=everypage&everypage_view=analytics&uuid=' . rawurlencode( $uuid ) ) ); ?>"><?php esc_html_e( 'Analytics', 'everypage' ); ?></a>
						<span class="sep">&middot;</span>
						<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this file?', 'everypage' ) ); ?>');">
							<?php wp_nonce_field( 'everypage_delete' ); ?>
							<input type="hidden" name="everypage_action" value="delete" />
							<input type="hidden" name="uuid" value="<?php echo esc_attr( $uuid ); ?>" />
							<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete', 'everypage' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Human-readable duration from milliseconds. */
	private function fmt_ms( $ms ) {
		$s = (int) round( $ms / 1000 );
		if ( $s < 60 ) {
			/* translators: %d: a number of seconds */
			return sprintf( __( '%ds', 'everypage' ), $s );
		}
		return sprintf( '%dm %ds', intdiv( $s, 60 ), $s % 60 );
	}

	private function stat_card( $label, $value, $variant = '' ) {
		printf(
			'<div class="everypage-card%3$s"><div class="everypage-card-value">%1$s</div><div class="everypage-card-label">%2$s</div></div>',
			esc_html( $value ),
			esc_html( $label ),
			'hero' === $variant ? ' is-hero' : ''
		);
	}

	private function breakdown_list( $title, $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		$max = 1;
		foreach ( $rows as $row ) {
			$max = max( $max, isset( $row['count'] ) ? (int) $row['count'] : 0 );
		}
		echo '<div class="everypage-breakdown"><h3>' . esc_html( $title ) . '</h3><ul>';
		foreach ( $rows as $row ) {
			$count = isset( $row['count'] ) ? (int) $row['count'] : 0;
			$label = isset( $row['label'] ) && '' !== (string) $row['label'] ? $row['label'] : __( 'Unknown', 'everypage' );
			printf(
				'<li><span class="ep-bd-bar" style="width:%3$d%%"></span><span class="ep-bd-label">%1$s</span><span class="ep-bd-count">%2$s</span></li>',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) ),
				(int) round( $count / $max * 100 )
			);
		}
		echo '</ul></div>';
	}

	private function render_analytics( $uuid ) {
		$base = everypage_base_url();
		$back = admin_url( 'admin.php?page=everypage' );
		$data = $this->api->get_analytics( $uuid );
		?>
		<div class="wrap everypage-screen">
			<?php $this->masthead(); ?>
			<?php
			if ( is_wp_error( $data ) ) {
				echo '<div class="everypage-panel"><p class="everypage-bad">' . esc_html( $data->get_error_message() ) . '</p>';
				echo '<p><a href="' . esc_url( $back ) . '" class="page-title-action">' . esc_html__( 'Back to files', 'everypage' ) . '</a></p></div></div>';
				return;
			}
			$summary = isset( $data['summary'] ) ? $data['summary'] : array();
			$file    = isset( $data['file'] ) ? $data['file'] : array();
			?>
			<div class="everypage-fileline">
				<a href="<?php echo esc_url( $back ); ?>" class="page-title-action"><?php esc_html_e( 'Back to files', 'everypage' ); ?></a>
				<span class="ep-filename"><?php echo esc_html( ! empty( $file['originalName'] ) ? $file['originalName'] : __( 'Readership', 'everypage' ) ); ?></span>
				<a href="<?php echo esc_url( $base . '/' . rawurlencode( $uuid ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open link', 'everypage' ); ?></a>
			</div>

			<div class="everypage-stats">
				<?php
				$this->stat_card( __( 'Views', 'everypage' ), number_format_i18n( isset( $summary['totalViews'] ) ? (int) $summary['totalViews'] : 0 ), 'hero' );
				$this->stat_card( __( 'Sessions', 'everypage' ), number_format_i18n( isset( $summary['totalSessions'] ) ? (int) $summary['totalSessions'] : 0 ) );
				$this->stat_card( __( 'Unique readers', 'everypage' ), number_format_i18n( isset( $summary['uniqueVisitors'] ) ? (int) $summary['uniqueVisitors'] : 0 ) );
				$this->stat_card( __( 'Avg. time', 'everypage' ), $this->fmt_ms( isset( $summary['avgTimeMs'] ) ? (int) $summary['avgTimeMs'] : 0 ) );
				$this->stat_card( __( 'Countries', 'everypage' ), number_format_i18n( isset( $summary['uniqueCountries'] ) ? (int) $summary['uniqueCountries'] : 0 ) );
				$this->stat_card( __( 'Downloads', 'everypage' ), number_format_i18n( isset( $summary['downloads'] ) ? (int) $summary['downloads'] : 0 ) );
				if ( isset( $summary['completionRate'] ) ) {
					$this->stat_card( __( 'Completion', 'everypage' ), round( (float) $summary['completionRate'] ) . '%' );
				}
				?>
			</div>

			<div class="everypage-breakdowns">
				<?php
				$this->breakdown_list( __( 'Countries', 'everypage' ), isset( $data['countries'] ) ? $data['countries'] : array() );
				$this->breakdown_list( __( 'Devices', 'everypage' ), isset( $data['devices'] ) ? $data['devices'] : array() );
				$this->breakdown_list( __( 'Sources', 'everypage' ), isset( $data['sources'] ) ? $data['sources'] : array() );
				?>
			</div>

			<?php if ( ! empty( $data['sessions'] ) ) : ?>
				<h2 class="everypage-section"><?php esc_html_e( 'Recent sessions', 'everypage' ); ?></h2>
				<table class="everypage-sessions">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'everypage' ); ?></th>
						<th><?php esc_html_e( 'Country', 'everypage' ); ?></th>
						<th><?php esc_html_e( 'Pages', 'everypage' ); ?></th>
						<th><?php esc_html_e( 'Time', 'everypage' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $data['sessions'] as $s ) : ?>
						<tr>
							<td><?php echo esc_html( empty( $s['startedAt'] ) ? '-' : date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $s['startedAt'] ) ) ); ?></td>
							<td><?php echo esc_html( ! empty( $s['country'] ) ? $s['country'] : '-' ); ?></td>
							<td><?php echo esc_html( isset( $s['pagesViewed'] ) ? (int) $s['pagesViewed'] : 0 ); ?></td>
							<td><?php echo esc_html( $this->fmt_ms( isset( $s['totalTimeMs'] ) ? (int) $s['totalTimeMs'] : 0 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p>
				<a class="everypage-fulllink" href="<?php echo esc_url( $base . '/readership/' . rawurlencode( $uuid ) ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Full analytics & charts on EveryPage →', 'everypage' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
