<?php
/**
 * Media Library integration: "Share via EveryPage" on PDF attachments.
 *
 * Surfaces: a row action on every application/pdf attachment in the Media
 * list, a field in the attachment details pane (edit screen + media modal,
 * via attachment_fields_to_edit), a "Share via EveryPage" bulk action, and
 * an "EveryPage" column showing shared state / view counts.
 *
 * Sharing reads the attachment file from disk (get_attached_file) and streams
 * the bytes to EveryPage as multipart — never a URL import (the upstream
 * import endpoint is Canva-host-allowlisted and rejects WP media URLs). The
 * result lands in attachment meta (_everypage_uuid / _everypage_short_id /
 * _everypage_shared_at) so re-sharing is idempotent.
 *
 * Also home to the "Replace links in content" tool: find posts/pages whose
 * content links to the attachment file and — after a dry-run preview and an
 * explicit confirm — rewrite those URLs to the tracked EveryPage share link
 * via wp_update_post (so revisions keep the before-state).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Media {

	const META_UUID      = '_everypage_uuid';
	const META_SHORT_ID  = '_everypage_short_id';
	const META_SHARED_AT = '_everypage_shared_at';

	const NONCE         = 'everypage_media';
	const NONCE_REPLACE = 'everypage_media_replace';

	/** Post types / statuses the link-replace tool will touch. */
	const REPLACE_POST_TYPES = array( 'post', 'page' );
	const REPLACE_STATUSES   = array( 'publish', 'draft', 'private', 'pending', 'future' );

	private $api;

	/** Set when a media screen enqueued our assets, so the footer prints the modals. */
	private $assets_loaded = false;

	/** Lazy uuid => viewCount map from the cached files list (null until built). */
	private $views_map = null;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	public function hooks() {
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_args' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_footer', array( $this, 'modals' ) );
		add_action( 'wp_ajax_everypage_media_share', array( $this, 'ajax_share' ) );
		add_action( 'wp_ajax_everypage_media_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_everypage_media_replace', array( $this, 'ajax_replace' ) );
	}

	/** Sharing is offered only with a key configured and to users who may upload. */
	private function available() {
		return $this->api->has_key() && current_user_can( 'upload_files' );
	}

	private function is_pdf( $post ) {
		return $post instanceof WP_Post
			&& 'attachment' === $post->post_type
			&& 'application/pdf' === get_post_mime_type( $post );
	}

	/**
	 * The share trigger everywhere is a real link to the attachment edit
	 * screen with a #everypage-share fragment: where our JS is loaded
	 * (Media list, attachment edit) the click is intercepted and handled in
	 * place; anywhere else (e.g. the media modal inside a post editor, where
	 * we deliberately don't load) it degrades to navigation, and the edit
	 * screen auto-opens the share flow from the fragment.
	 */
	private function share_link( $post_id, $label ) {
		$edit = get_edit_post_link( $post_id );
		if ( ! $edit ) {
			return '';
		}
		return sprintf(
			'<a href="%1$s" class="everypage-media-share" data-id="%2$d">%3$s</a>',
			esc_url( $edit . '#everypage-share' ),
			absint( $post_id ),
			esc_html( $label )
		);
	}

	/* ---- Surfaces: row action, details field, column, bulk --------------- */

	public function row_actions( $actions, $post ) {
		if ( ! $this->is_pdf( $post ) || ! $this->available() ) {
			return $actions;
		}
		$shared = (string) get_post_meta( $post->ID, self::META_UUID, true );
		$label  = '' !== $shared ? __( 'EveryPage share', 'everypage' ) : __( 'Share via EveryPage', 'everypage' );
		$link   = $this->share_link( $post->ID, $label );
		if ( '' !== $link ) {
			$actions['everypage_share'] = $link;
		}
		return $actions;
	}

	public function attachment_fields( $form_fields, $post ) {
		if ( ! $this->is_pdf( $post ) || ! $this->available() ) {
			return $form_fields;
		}
		$shared = (string) get_post_meta( $post->ID, self::META_UUID, true );
		$status = '' !== $shared
			? __( 'Shared on EveryPage.', 'everypage' )
			: __( 'Not shared yet.', 'everypage' );
		$label  = '' !== $shared ? __( 'Share options', 'everypage' ) : __( 'Share via EveryPage', 'everypage' );
		$link   = $this->share_link( $post->ID, $label );
		if ( '' === $link ) {
			return $form_fields;
		}
		$form_fields['everypage'] = array(
			'label' => __( 'EveryPage', 'everypage' ),
			'input' => 'html',
			'html'  => '<span class="everypage-media-status">' . esc_html( $status ) . '</span> ' . $link,
		);
		return $form_fields;
	}

	public function columns( $cols ) {
		if ( $this->available() ) {
			$cols['everypage'] = __( 'EveryPage', 'everypage' );
		}
		return $cols;
	}

	public function column( $name, $post_id ) {
		if ( 'everypage' !== $name ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $this->is_pdf( $post ) ) {
			return; // The column is about PDFs; other types stay blank.
		}
		$uuid = (string) get_post_meta( $post->ID, self::META_UUID, true );
		if ( '' === $uuid ) {
			echo '<span class="everypage-media-notshared">' . esc_html__( 'Not shared', 'everypage' ) . '</span>';
			return;
		}
		$views = $this->views_for( $uuid );
		$text  = null === $views
			? '—' // Unknown (list unavailable, or deleted upstream).
			: sprintf(
				/* translators: %s: a number of views */
				_n( '%s view', '%s views', $views, 'everypage' ),
				number_format_i18n( $views )
			);
		// The Files page is manage_options-gated; only link users who can open it.
		if ( current_user_can( 'manage_options' ) ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=everypage' ) ),
				esc_html( $text )
			);
		} else {
			echo esc_html( $text );
		}
	}

	/** viewCount from the cached files list, or null when unknown. */
	private function views_for( $uuid ) {
		if ( null === $this->views_map ) {
			$this->views_map = array();
			$files           = $this->api->list_files(); // Transient-cached; one round-trip at most.
			if ( ! is_wp_error( $files ) ) {
				foreach ( (array) $files as $f ) {
					if ( is_array( $f ) && ! empty( $f['uuid'] ) ) {
						$this->views_map[ (string) $f['uuid'] ] = isset( $f['viewCount'] ) ? (int) $f['viewCount'] : 0;
					}
				}
			}
		}
		return isset( $this->views_map[ $uuid ] ) ? $this->views_map[ $uuid ] : null;
	}

	public function bulk_actions( $actions ) {
		if ( $this->available() ) {
			$actions['everypage_share'] = __( 'Share via EveryPage', 'everypage' );
		}
		return $actions;
	}

	/**
	 * Bulk "Share via EveryPage": share each selected PDF that isn't already
	 * shared; count the rest as skipped. Core has verified the bulk nonce
	 * before this filter fires.
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'everypage_share' !== $action || ! $this->available() ) {
			return $redirect;
		}
		$shared  = 0;
		$skipped = 0;
		$failed  = 0;
		foreach ( (array) $ids as $id ) {
			$id   = absint( $id );
			$post = get_post( $id );
			if ( ! $this->is_pdf( $post ) || '' !== (string) get_post_meta( $id, self::META_UUID, true ) ) {
				++$skipped;
				continue;
			}
			$res = $this->share_new( $id );
			if ( is_wp_error( $res ) ) {
				++$failed;
			} else {
				++$shared;
			}
		}
		return add_query_arg(
			array(
				'everypage_shared'  => $shared,
				'everypage_skipped' => $skipped,
				'everypage_failed'  => $failed,
			),
			$redirect
		);
	}

	public function bulk_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Read-only counts from our own bulk redirect; sanitized to ints. No state change, no nonce.
		if ( ! $screen || 'upload' !== $screen->id || ! isset( $_GET['everypage_shared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display
			return;
		}
		$shared  = absint( $_GET['everypage_shared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['everypage_skipped'] ) ? absint( $_GET['everypage_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = isset( $_GET['everypage_failed'] ) ? absint( $_GET['everypage_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parts   = array(
			/* translators: %s: a number of files */
			sprintf( _n( '%s PDF shared', '%s PDFs shared', $shared, 'everypage' ), number_format_i18n( $shared ) ),
		);
		if ( $skipped ) {
			/* translators: %s: a number of files */
			$parts[] = sprintf( _n( '%s skipped (not a PDF, or already shared)', '%s skipped (not PDFs, or already shared)', $skipped, 'everypage' ), number_format_i18n( $skipped ) );
		}
		if ( $failed ) {
			/* translators: %s: a number of files */
			$parts[] = sprintf( _n( '%s failed — check your API key and plan limits', '%s failed — check your API key and plan limits', $failed, 'everypage' ), number_format_i18n( $failed ) );
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>EveryPage:</strong> %2$s.</p></div>',
			esc_attr( $failed ? 'warning' : 'success' ),
			esc_html( implode( ', ', $parts ) )
		);
	}

	public function removable_args( $args ) {
		$args[] = 'everypage_shared';
		$args[] = 'everypage_skipped';
		$args[] = 'everypage_failed';
		return $args;
	}

	/* ---- Assets + modal markup ------------------------------------------- */

	public function assets( $hook ) {
		if ( ! $this->available() ) {
			return;
		}
		$screen             = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_media_list      = 'upload.php' === $hook;
		$is_attachment_edit = 'post.php' === $hook && $screen && 'attachment' === $screen->post_type;
		if ( ! $is_media_list && ! $is_attachment_edit ) {
			return;
		}
		$this->assets_loaded = true;
		// admin.css carries the modal / copy-button / QR idioms; media.css only
		// adds the media-specific bits (z-index over the WP media modal, the
		// replace preview table, column tweaks).
		wp_enqueue_style( 'everypage-admin', EVERYPAGE_PLUGIN_URL . 'assets/admin.css', array(), EVERYPAGE_VERSION );
		wp_enqueue_style( 'everypage-media', EVERYPAGE_PLUGIN_URL . 'assets/media.css', array( 'everypage-admin' ), EVERYPAGE_VERSION );
		wp_enqueue_script( 'everypage-media', EVERYPAGE_PLUGIN_URL . 'assets/media.js', array(), EVERYPAGE_VERSION, true );
		wp_localize_script(
			'everypage-media',
			'EveryPageMedia',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'replaceNonce' => wp_create_nonce( self::NONCE_REPLACE ),
				'qrNonce'      => wp_create_nonce( 'everypage_qr' ),
				'canReplace'   => current_user_can( 'manage_options' ),
				'i18n'         => array(
					'sharing'        => __( 'Sharing…', 'everypage' ),
					'checking'       => __( 'Checking…', 'everypage' ),
					'justShared'     => __( 'Shared! Your tracked link is ready.', 'everypage' ),
					'alreadyShared'  => __( 'Already shared — here is its link.', 'everypage' ),
					'sharedCell'     => __( 'Shared', 'everypage' ),
					'copied'         => __( 'Copied!', 'everypage' ),
					'copyFailed'     => __( 'Copy failed — select the text and copy it manually.', 'everypage' ),
					'copyEmbed'      => __( 'Copy embed code', 'everypage' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'everypage' ),
					'scanning'       => __( 'Looking for links in your posts and pages…', 'everypage' ),
					'noLinks'        => __( 'No posts or pages link to this PDF.', 'everypage' ),
					/* translators: 1: a number of links, 2: a number of posts */
					'replaceConfirm' => __( 'Replace %1$s links across %2$s posts', 'everypage' ),
					'replacing'      => __( 'Replacing…', 'everypage' ),
					'replaceDone'    => __( 'Done', 'everypage' ),
					/* translators: %s: a number of links */
					'replacedIn'     => __( '%s replaced', 'everypage' ),
					'noChanges'      => __( 'No links found (already replaced?)', 'everypage' ),
					'adminOnly'      => __( 'Only administrators can rewrite post content. Ask an administrator to run the replacement.', 'everypage' ),
					'linksHeading'   => __( 'Post or page', 'everypage' ),
					'countHeading'   => __( 'Links', 'everypage' ),
				),
			)
		);
	}

	/**
	 * The share modal (link / QR / embed trio) and the replace-links modal.
	 * Printed once in the footer of the screens that enqueue our assets; the
	 * wrapper carries .everypage-screen so the design tokens resolve.
	 */
	public function modals() {
		if ( ! $this->assets_loaded ) {
			return;
		}
		?>
		<div class="everypage-screen everypage-media-wrap">

			<div class="everypage-modal everypage-media-modal" id="everypage-media-share-modal" hidden>
				<div class="everypage-modal-backdrop" data-close></div>
				<div class="everypage-modal-card everypage-media-card" role="dialog" aria-modal="true" aria-labelledby="ep-m-share-title">
					<button type="button" class="everypage-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'everypage' ); ?>">&times;</button>
					<h2 class="everypage-modal-title" id="ep-m-share-title"><?php esc_html_e( 'Share via EveryPage', 'everypage' ); ?></h2>
					<p class="everypage-modal-sub" id="ep-m-share-name"></p>
					<p class="ep-drawer-loading" id="ep-m-share-busy" hidden></p>
					<p class="ep-error" id="ep-m-share-error" hidden></p>
					<div id="ep-m-share-body" hidden>
						<p class="ep-hint" id="ep-m-share-status"></p>
						<div class="ep-m-row">
							<input type="text" class="everypage-link" id="ep-m-link" readonly onfocus="this.select();" />
							<button type="button" class="everypage-icon-btn everypage-copy-btn" id="ep-m-copy-link" title="<?php esc_attr_e( 'Copy link', 'everypage' ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'everypage' ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10.6 13.4a1 1 0 0 0 1.4 1.4l4.6-4.6a3 3 0 0 0-4.2-4.2l-2.4 2.4a1 1 0 0 0 1.4 1.4l2.4-2.4a1 1 0 0 1 1.4 1.4l-4.6 4.6Zm2.8-2.8a1 1 0 0 0-1.4-1.4l-4.6 4.6a3 3 0 0 0 4.2 4.2l2.4-2.4a1 1 0 0 0-1.4-1.4l-2.4 2.4a1 1 0 0 1-1.4-1.4l4.6-4.6Z"/></svg>
							</button>
						</div>
						<div class="everypage-qr-frame ep-m-qr">
							<img id="ep-m-qr-img" alt="<?php esc_attr_e( 'QR code for the share link', 'everypage' ); ?>" />
						</div>
						<div class="ep-m-actions">
							<button type="button" class="everypage-button everypage-copy-btn" id="ep-m-copy-embed"><?php esc_html_e( 'Copy embed code', 'everypage' ); ?></button>
							<a class="everypage-button" id="ep-m-qr-download" href="#" download><?php esc_html_e( 'Download QR', 'everypage' ); ?></a>
						</div>
						<p class="ep-m-meta"><a id="ep-m-files-link" href="#"><?php esc_html_e( 'Manage this file on the EveryPage Files page', 'everypage' ); ?></a></p>
						<p class="ep-m-meta"><button type="button" class="button-link" id="ep-m-replace-open"><?php esc_html_e( 'Replace links in content…', 'everypage' ); ?></button></p>
					</div>
				</div>
			</div>

			<div class="everypage-modal everypage-media-modal" id="everypage-media-replace-modal" hidden>
				<div class="everypage-modal-backdrop" data-close></div>
				<div class="everypage-modal-card everypage-replace-card" role="dialog" aria-modal="true" aria-labelledby="ep-m-replace-title">
					<button type="button" class="everypage-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'everypage' ); ?>">&times;</button>
					<h2 class="everypage-modal-title" id="ep-m-replace-title"><?php esc_html_e( 'Replace links in content', 'everypage' ); ?></h2>
					<p class="everypage-modal-sub" id="ep-m-replace-name"></p>
					<p class="ep-hint"><?php esc_html_e( 'Find links to this PDF file in your posts and pages, and point them at the tracked EveryPage link instead. Nothing is changed until you confirm below.', 'everypage' ); ?></p>
					<p class="ep-replace-urls" id="ep-m-replace-urls" hidden></p>
					<p class="ep-drawer-loading" id="ep-m-replace-busy" hidden></p>
					<p class="ep-error" id="ep-m-replace-error" hidden></p>
					<p class="ep-hint" id="ep-m-replace-empty" hidden></p>
					<div class="ep-replace-tablewrap" id="ep-m-replace-tablewrap" hidden>
						<table class="ep-replace-table">
							<thead><tr>
								<th id="ep-m-replace-th-post"></th>
								<th id="ep-m-replace-th-count"></th>
								<th></th>
							</tr></thead>
							<tbody id="ep-m-replace-rows"></tbody>
						</table>
					</div>
					<p class="ep-hint" id="ep-m-replace-note" hidden><?php esc_html_e( 'Replacements are saved with wp_update_post, so your posts’ revision history keeps the previous version of every updated post.', 'everypage' ); ?></p>
					<p class="ep-hint" id="ep-m-replace-adminonly" hidden></p>
					<div class="ep-drawer-foot">
						<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'everypage' ); ?></button>
						<button type="button" class="everypage-button" id="ep-m-replace-confirm" disabled></button>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	/* ---- Share pipeline --------------------------------------------------- */

	/** Plan slug for the connected account ('free' when unknown). */
	private function plan_slug() {
		$user = $this->api->get_user();
		if ( is_wp_error( $user ) || empty( $user['subscription'] ) ) {
			return 'free';
		}
		$plan = (string) $user['subscription'];
		return in_array( $plan, array( 'basic', 'pro' ), true ) ? $plan : 'free';
	}

	/** Per-plan upload caps: max bytes per file, max file count (0 = unlimited). */
	private function plan_limits( $plan ) {
		$limits = array(
			'free'  => array(
				'bytes' => 20 * MB_IN_BYTES,
				'files' => 3,
			),
			'basic' => array(
				'bytes' => 200 * MB_IN_BYTES,
				'files' => 100,
			),
			'pro'   => array(
				'bytes' => 2 * GB_IN_BYTES,
				'files' => 0,
			),
		);
		return isset( $limits[ $plan ] ) ? $limits[ $plan ] : $limits['free'];
	}

	/**
	 * Upload the attachment's file from disk to EveryPage and store the share
	 * meta. Pre-flights the plan's size cap for a clear error before any bytes
	 * move; maps upstream 403/413 to readable plan-limit messages.
	 *
	 * @param int $post_id Attachment ID (caller has validated it is a PDF).
	 * @return array{uuid:string,shortId:string}|WP_Error
	 */
	private function share_new( $post_id ) {
		$path = get_attached_file( $post_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'everypage_missing_file', __( 'The attachment file could not be found on this server.', 'everypage' ) );
		}
		$size = (int) filesize( $path );
		$plan = $this->plan_slug();
		$cap  = $this->plan_limits( $plan );
		if ( $size > $cap['bytes'] ) {
			return new WP_Error(
				'everypage_too_large',
				sprintf(
					/* translators: 1: the file size, 2: the plan's per-file limit, 3: plan name */
					__( 'This PDF is %1$s — over the %2$s per-file limit of your %3$s EveryPage plan. Upgrade at everypage.co/pricing to share larger files.', 'everypage' ),
					size_format( $size ),
					size_format( $cap['bytes'] ),
					$plan
				)
			);
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a local attachment file for upload
		if ( false === $bytes ) {
			return new WP_Error( 'everypage_read_failed', __( 'Could not read the attachment file.', 'everypage' ) );
		}
		$res = $this->api->upload_pdf( $bytes, sanitize_file_name( wp_basename( $path ) ) );
		unset( $bytes );
		if ( is_wp_error( $res ) ) {
			return $this->friendly_upload_error( $res, $plan, $cap );
		}
		$uuid = isset( $res['uuid'] ) ? (string) $res['uuid'] : '';
		if ( '' === $uuid ) {
			return new WP_Error( 'everypage_bad_response', __( 'Unexpected response from EveryPage. Please try again.', 'everypage' ) );
		}
		$short = isset( $res['shortId'] ) ? (string) $res['shortId'] : '';
		update_post_meta( $post_id, self::META_UUID, $uuid );
		update_post_meta( $post_id, self::META_SHORT_ID, $short );
		update_post_meta( $post_id, self::META_SHARED_AT, time() );
		return array(
			'uuid'    => $uuid,
			'shortId' => $short,
		);
	}

	/** Map upstream plan-cap failures (413 size, 403 file count) to readable messages. */
	private function friendly_upload_error( WP_Error $err, $plan, $cap ) {
		$code = (string) $err->get_error_code();
		if ( 'everypage_http_413' === $code ) {
			return new WP_Error(
				$code,
				sprintf(
					/* translators: 1: the plan's per-file limit, 2: plan name */
					__( 'EveryPage rejected the file as too large: the limit on your %2$s plan is %1$s per file.', 'everypage' ),
					size_format( $cap['bytes'] ),
					$plan
				)
			);
		}
		if ( 'everypage_http_403' === $code && $cap['files'] > 0 ) {
			return new WP_Error(
				$code,
				sprintf(
					/* translators: 1: plan name, 2: the plan's file limit */
					__( 'Your %1$s EveryPage plan allows up to %2$d files (free: 3, basic: 100, pro: unlimited). Delete a file on EveryPage or upgrade to share more.', 'everypage' ),
					$plan,
					$cap['files']
				)
			);
		}
		return $err;
	}

	/** The payload the share modal renders: link, embed snippet, admin links. */
	private function share_payload( $post_id, $uuid, $short, $views, $existing ) {
		$base    = everypage_base_url();
		$durable = '' !== $short ? $short : $uuid; // Durable id: shortId, uuid fallback — never a slug.
		$name    = get_the_title( $post_id );
		if ( '' === trim( (string) $name ) ) {
			$name = wp_basename( (string) get_attached_file( $post_id ) );
		}
		return array(
			'id'       => (int) $post_id,
			'uuid'     => $uuid,
			'shortId'  => $short,
			'name'     => $name,
			'shareUrl' => $base . '/' . rawurlencode( $durable ),
			'embed'    => sprintf(
				'<iframe src="%1$s" width="800" height="600" frameborder="0" allowfullscreen title="%2$s"></iframe>',
				esc_url( $base . '/embed/' . rawurlencode( $durable ) ),
				esc_attr( $name )
			),
			'filesUrl' => admin_url( 'admin.php?page=everypage' ),
			'canFiles' => current_user_can( 'manage_options' ),
			'views'    => $views,
			'existing' => (bool) $existing,
		);
	}

	/**
	 * admin-ajax: share one PDF attachment (or return the existing share).
	 *
	 * Idempotent: with _everypage_uuid meta present, the upstream file is
	 * re-checked first — alive means "show the existing share"; a 404/410
	 * means it was deleted on EveryPage, so the stale meta is cleared and the
	 * caller is invited to share afresh.
	 */
	public function ajax_share() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to share files.', 'everypage' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
		if ( ! $this->api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'No EveryPage API key set. Add one under EveryPage → Settings.', 'everypage' ) ), 400 );
		}
		$id   = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$post = get_post( $id );
		if ( ! $this->is_pdf( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PDF attachments can be shared via EveryPage.', 'everypage' ) ), 400 );
		}

		$uuid = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' !== $uuid ) {
			$file = $this->api->get_file( $uuid );
			if ( is_wp_error( $file ) ) {
				$code = (string) $file->get_error_code();
				if ( 'everypage_http_404' === $code || 'everypage_http_410' === $code ) {
					// Deleted (or dead) upstream: clear the stale meta so the
					// next click uploads a fresh copy.
					delete_post_meta( $id, self::META_UUID );
					delete_post_meta( $id, self::META_SHORT_ID );
					delete_post_meta( $id, self::META_SHARED_AT );
					wp_send_json_error(
						array(
							'code'    => 'stale',
							'message' => __( 'This file no longer exists on EveryPage (it may have been deleted or expired). Click “Share via EveryPage” again to create a fresh link.', 'everypage' ),
						),
						410
					);
				}
				wp_send_json_error( array( 'message' => $file->get_error_message() ), 502 );
			}
			$short = isset( $file['shortId'] ) ? (string) $file['shortId'] : (string) get_post_meta( $id, self::META_SHORT_ID, true );
			$views = isset( $file['viewCount'] ) ? (int) $file['viewCount'] : null;
			wp_send_json_success( $this->share_payload( $id, $uuid, $short, $views, true ) );
		}

		$res = $this->share_new( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $this->share_payload( $id, $res['uuid'], $res['shortId'], 0, false ) );
	}

	/* ---- Replace links in content ----------------------------------------- */

	/**
	 * The URL forms of the attachment file that count as "a link to this PDF":
	 * the canonical wp_get_attachment_url() in https, http, and
	 * protocol-relative form. PDFs get no intermediate image sizes, so the
	 * exact upload URL is the whole surface. Ordered longest-first so
	 * sequential counting/replacement can never double-hit the
	 * protocol-relative form (a substring of both absolute forms).
	 *
	 * @return string[] Empty when the URL cannot be determined.
	 */
	private function url_variants( $post_id ) {
		$url = wp_get_attachment_url( $post_id );
		if ( ! $url ) {
			return array();
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return array();
		}
		$host_path = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $parts['path'];
		return array(
			'https://' . $host_path,
			'http://' . $host_path,
			'//' . $host_path,
		);
	}

	/**
	 * Exact occurrence count of the URL variants in one content blob.
	 * Counted sequentially with matched spans blanked out, so the
	 * protocol-relative form never re-counts an absolute match.
	 */
	private function count_matches( $content, $variants ) {
		$count = 0;
		foreach ( $variants as $v ) {
			$n = substr_count( $content, $v );
			if ( $n > 0 ) {
				$count  += $n;
				$content = str_replace( $v, "\x00", $content );
			}
		}
		return $count;
	}

	/**
	 * Replace every exact occurrence of the URL variants with the share URL.
	 * A plain string replacement of the exact URL: precise by construction —
	 * it touches hrefs, plain text, and only those shortcode/JSON occurrences
	 * that are the exact URL string. Longest-first ordering (see
	 * url_variants) keeps the pass single-hit per occurrence.
	 *
	 * @return array{0:string,1:int} New content and the number of replacements.
	 */
	private function replace_matches( $content, $variants, $new_url ) {
		$total = 0;
		foreach ( $variants as $v ) {
			$content = str_replace( $v, $new_url, $content, $n );
			$total  += $n;
		}
		return array( $content, $total );
	}

	/**
	 * Posts/pages whose content contains the attachment URL. One indexed-free
	 * LIKE sweep on the relative upload path returns candidate IDs only; each
	 * candidate is then loaded singly and verified in PHP against the exact
	 * URL variants — never the whole table in memory.
	 *
	 * @return array[] [{id:int, matches:int}]
	 */
	private function find_posts_with_url( $variants ) {
		global $wpdb;
		$path = (string) wp_parse_url( $variants[0], PHP_URL_PATH );
		if ( '' === $path ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $path ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted content search; candidates are re-verified in PHP
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ('post','page')
				   AND post_status IN ('publish','draft','private','pending','future')
				   AND post_content LIKE %s
				 ORDER BY ID ASC",
				$like
			)
		);
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$pid     = (int) $pid;
			$content = (string) get_post_field( 'post_content', $pid, 'raw' );
			$count   = $this->count_matches( $content, $variants );
			if ( $count > 0 ) {
				$out[] = array(
					'id'      => $pid,
					'matches' => $count,
				);
			}
			clean_post_cache( $pid ); // Keep memory flat across large sweeps.
		}
		return $out;
	}

	/** Share URL the replaced links point at (human-facing: shortId, uuid fallback). */
	private function replace_target( $post_id, $uuid ) {
		$short   = (string) get_post_meta( $post_id, self::META_SHORT_ID, true );
		$durable = '' !== $short ? $short : $uuid;
		return everypage_base_url() . '/' . rawurlencode( $durable );
	}

	/**
	 * admin-ajax: the dry run. Returns the preview table — post title, edit
	 * link, exact match count, old URL → new URL — and never writes anything.
	 */
	public function ajax_scan() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'everypage' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
		$id   = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$post = get_post( $id );
		if ( ! $this->is_pdf( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PDF attachments can be scanned.', 'everypage' ) ), 400 );
		}
		$uuid = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => __( 'Share this PDF via EveryPage first, then replace its links.', 'everypage' ) ), 400 );
		}
		$variants = $this->url_variants( $id );
		if ( empty( $variants ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine the attachment URL.', 'everypage' ) ), 400 );
		}
		$found = $this->find_posts_with_url( $variants );
		$posts = array();
		$total = 0;
		foreach ( $found as $row ) {
			$total  += $row['matches'];
			$posts[] = array(
				'id'      => $row['id'],
				'title'   => html_entity_decode( (string) get_the_title( $row['id'] ), ENT_QUOTES ),
				'editUrl' => (string) get_edit_post_link( $row['id'], 'raw' ),
				'matches' => $row['matches'],
			);
		}
		wp_send_json_success(
			array(
				'oldUrl'       => $variants[0],
				'newUrl'       => $this->replace_target( $id, $uuid ),
				'posts'        => $posts,
				'totalMatches' => $total,
				'canReplace'   => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * admin-ajax: the write step, after an explicit confirm. manage_options +
	 * its own nonce. Each selected post is re-verified (fresh count of the
	 * exact URL variants) immediately before rewriting, and the rewrite goes
	 * through wp_update_post so a revision captures the before-state.
	 */
	public function ajax_replace() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can rewrite post content.', 'everypage' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_REPLACE );
		$id   = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$post = get_post( $id );
		if ( ! $this->is_pdf( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PDF attachments can be processed.', 'everypage' ) ), 400 );
		}
		$uuid = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => __( 'This PDF is not shared on EveryPage.', 'everypage' ) ), 400 );
		}
		$post_ids = isset( $_POST['posts'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['posts'] ) ) : array();
		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No posts were selected.', 'everypage' ) ), 400 );
		}
		$variants = $this->url_variants( $id );
		if ( empty( $variants ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine the attachment URL.', 'everypage' ) ), 400 );
		}
		$new_url = $this->replace_target( $id, $uuid );

		$results        = array();
		$replaced_total = 0;
		foreach ( $post_ids as $pid ) {
			$target = get_post( $pid );
			$row    = array(
				'id'       => $pid,
				'title'    => $target ? html_entity_decode( (string) get_the_title( $target ), ENT_QUOTES ) : '#' . $pid,
				'replaced' => 0,
				'ok'       => false,
				'message'  => '',
			);
			if ( ! $target
				|| ! in_array( $target->post_type, self::REPLACE_POST_TYPES, true )
				|| ! in_array( $target->post_status, self::REPLACE_STATUSES, true ) ) {
				$row['message'] = __( 'Skipped: not an editable post or page.', 'everypage' );
				$results[]      = $row;
				continue;
			}
			list( $new_content, $n ) = $this->replace_matches( $target->post_content, $variants, $new_url );
			if ( 0 === $n ) {
				$row['ok']      = true;
				$row['message'] = __( 'No links found (already replaced?).', 'everypage' );
				$results[]      = $row;
				continue;
			}
			// wp_update_post so a revision keeps the before-state. Content must
			// be slashed on the way in (wp_insert_post unslashes).
			$updated = wp_update_post(
				array(
					'ID'           => $pid,
					'post_content' => wp_slash( $new_content ),
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				$row['message'] = $updated->get_error_message();
			} else {
				$row['ok']       = true;
				$row['replaced'] = $n;
				$replaced_total += $n;
			}
			$results[] = $row;
			clean_post_cache( $pid );
		}
		wp_send_json_success(
			array(
				'results'  => $results,
				'replaced' => $replaced_total,
			)
		);
	}
}
