<?php
/**
 * REST proxy for the block editor (namespace everypage/v1). Every route calls
 * the EveryPage API server-side through EveryPage_API, so the site's API key
 * never reaches the browser. Reads and uploads require `upload_files`; viewer
 * settings writes require `manage_options` (the key is shared site-wide, so
 * per-file settings that apply everywhere a document is shared are an admin
 * decision, not an author one).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Rest {

	const NS = 'everypage/v1';

	/** Route pattern for a share identifier (UUID or short id). Reject, never truncate. */
	const ID_PATTERN = '[A-Za-z0-9_-]{1,64}';

	private $api;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::NS,
			'/user',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		register_rest_route(
			self::NS,
			'/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_files' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_file' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/files/(?P<id>' . self::ID_PATTERN . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_file' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NS,
			'/files/(?P<id>' . self::ID_PATTERN . ')/settings',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/** Shared arg schema for the {id} route segment. */
	private function id_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_id' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	public function validate_id( $value ) {
		return '' !== EveryPage_Renderer::validate_id( $value );
	}

	public function can_read() {
		return current_user_can( 'upload_files' );
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Attach an HTTP status to an EveryPage_API WP_Error so the REST server
	 * relays the upstream status (401/403/410/413/429/...) instead of a 500.
	 */
	private function pass_error( WP_Error $err ) {
		$code   = (string) $err->get_error_code();
		$status = 502;
		if ( 'everypage_no_key' === $code ) {
			$status = 400;
		} elseif ( 'everypage_unauthorized' === $code ) {
			$status = 401;
		} elseif ( preg_match( '/^everypage_http_(\d{3})$/', $code, $m ) ) {
			$status = (int) $m[1];
		}
		return new WP_Error( $code, $err->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * Allowlisted view of a file object — only the fields the editor and the
	 * Files-page settings drawer need. Never the password (only the
	 * `protected` flag reaches the browser).
	 */
	private function shape_file( $f ) {
		$page_range = null;
		if ( isset( $f['pageRange'] ) && is_array( $f['pageRange'] ) ) {
			$page_range = array(
				'from' => isset( $f['pageRange']['from'] ) ? (int) $f['pageRange']['from'] : 0,
				'to'   => isset( $f['pageRange']['to'] ) ? (int) $f['pageRange']['to'] : 0,
			);
		}
		$gate_domains = array();
		if ( isset( $f['gateDomains'] ) && is_array( $f['gateDomains'] ) ) {
			foreach ( $f['gateDomains'] as $d ) {
				if ( is_string( $d ) && '' !== $d ) {
					$gate_domains[] = $d;
				}
			}
		}
		$gate_fields = array();
		if ( isset( $f['gateFields'] ) && is_array( $f['gateFields'] ) ) {
			foreach ( $f['gateFields'] as $gf ) {
				if ( ! is_array( $gf ) ) {
					continue;
				}
				$gate_fields[] = array(
					'key'      => isset( $gf['key'] ) ? (string) $gf['key'] : '',
					'label'    => isset( $gf['label'] ) ? (string) $gf['label'] : '',
					'type'     => isset( $gf['type'] ) ? (string) $gf['type'] : 'text',
					'required' => ! empty( $gf['required'] ),
				);
			}
		}
		return array(
			'uuid'           => isset( $f['uuid'] ) ? (string) $f['uuid'] : '',
			'shortId'        => isset( $f['shortId'] ) ? (string) $f['shortId'] : '',
			'slug'           => isset( $f['slug'] ) ? (string) $f['slug'] : '',
			'shareDomain'    => isset( $f['shareDomain'] ) ? (string) $f['shareDomain'] : '',
			'originalName'   => isset( $f['originalName'] ) ? (string) $f['originalName'] : '',
			'size'           => isset( $f['size'] ) ? (int) $f['size'] : 0,
			'createdAt'      => isset( $f['createdAt'] ) ? (string) $f['createdAt'] : '',
			'deleteAt'       => isset( $f['deleteAt'] ) ? (string) $f['deleteAt'] : '',
			'viewCount'      => isset( $f['viewCount'] ) ? (int) $f['viewCount'] : 0,
			'totalPages'     => isset( $f['totalPages'] ) ? (int) $f['totalPages'] : 0,
			'viewerMode'     => isset( $f['viewerMode'] ) ? (string) $f['viewerMode'] : 'standard',
			'viewerSettings' => isset( $f['viewerSettings'] ) && is_array( $f['viewerSettings'] ) ? $f['viewerSettings'] : array(),
			'protected'      => ! empty( $f['protected'] ),
			'allowDownload'  => ! empty( $f['allowDownload'] ),
			'requireEmail'   => ! empty( $f['requireEmail'] ),
			'notifyOnView'   => ! empty( $f['notifyOnView'] ),
			'askReceipt'     => ! empty( $f['askReceipt'] ),
			'watermark'      => ! empty( $f['watermark'] ),
			'viewLimit'      => isset( $f['viewLimit'] ) ? (int) $f['viewLimit'] : 0,
			'viewsConsumed'  => isset( $f['viewsConsumed'] ) ? (int) $f['viewsConsumed'] : 0,
			'burnedAt'       => isset( $f['burnedAt'] ) ? (string) $f['burnedAt'] : '',
			'gateDomains'    => $gate_domains,
			'gateFields'     => $gate_fields,
			'pageRange'      => $page_range,
		);
	}

	/** GET /user — connection state, plan, and editor bootstrap info (never the key). */
	public function get_user() {
		$out = array(
			'connected'         => false,
			'baseUrl'           => everypage_base_url(),
			'settingsUrl'       => admin_url( 'admin.php?page=everypage-settings' ),
			'pricingUrl'        => 'https://everypage.co/pricing',
			'canManageSettings' => current_user_can( 'manage_options' ),
			'subscription'      => 'free',
		);
		if ( ! $this->api->has_key() ) {
			return rest_ensure_response( $out );
		}
		$user = $this->api->get_user();
		if ( is_wp_error( $user ) ) {
			return $this->pass_error( $user );
		}
		$out['connected']    = true;
		$out['subscription'] = isset( $user['subscription'] ) ? (string) $user['subscription'] : 'free';
		return rest_ensure_response( $out );
	}

	/** GET /files — the account's files (bare array upstream, no pagination). */
	public function get_files() {
		$files = $this->api->list_files();
		if ( is_wp_error( $files ) ) {
			return $this->pass_error( $files );
		}
		$out = array();
		foreach ( (array) $files as $f ) {
			if ( is_array( $f ) ) {
				$out[] = $this->shape_file( $f );
			}
		}
		return rest_ensure_response( $out );
	}

	/** GET /files/{id} — a single file (accepts UUID or short id). */
	public function get_file( WP_REST_Request $request ) {
		$file = $this->api->get_file( $request['id'] );
		if ( is_wp_error( $file ) ) {
			return $this->pass_error( $file );
		}
		return rest_ensure_response( $this->shape_file( $file ) );
	}

	/** POST /files — upload a PDF to the connected EveryPage account. */
	public function upload_file( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'everypage_no_file', __( 'Please choose a PDF to upload.', 'everypage' ), array( 'status' => 400 ) );
		}
		$name = isset( $files['file']['name'] ) ? sanitize_file_name( $files['file']['name'] ) : 'document.pdf';
		// Validate by extension, not the browser-supplied MIME (which is often
		// application/octet-stream for a genuine PDF); the backend re-validates.
		if ( 'pdf' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'everypage_not_pdf', __( 'Only PDF files can be shared.', 'everypage' ), array( 'status' => 400 ) );
		}
		$bytes = file_get_contents( $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading the just-uploaded temp file
		if ( false === $bytes ) {
			return new WP_Error( 'everypage_read_failed', __( 'Could not read the uploaded file.', 'everypage' ), array( 'status' => 500 ) );
		}
		$res = $this->api->upload_pdf( $bytes, $name ); // Busts the transient cache on success.
		if ( is_wp_error( $res ) ) {
			return $this->pass_error( $res );
		}
		return rest_ensure_response(
			array(
				'uuid'    => isset( $res['uuid'] ) ? (string) $res['uuid'] : '',
				'shortId' => isset( $res['shortId'] ) ? (string) $res['shortId'] : '',
			)
		);
	}

	/**
	 * PUT /files/{id}/settings — the full per-file settings surface (viewer
	 * mode/appearance, protection, capture, link), strictly allowlisted.
	 */
	public function update_settings( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$payload = $this->sanitize_settings( is_array( $body ) ? $body : array() );
		if ( empty( $payload ) ) {
			return new WP_Error( 'everypage_bad_settings', __( 'No valid settings were supplied.', 'everypage' ), array( 'status' => 400 ) );
		}
		if ( isset( $payload['viewerSettings'] ) ) {
			$payload = $this->merge_viewer_settings( $request['id'], $payload );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}
		$res = $this->api->update_settings( $request['id'], $payload ); // Busts the transient cache on success.
		if ( is_wp_error( $res ) ) {
			if ( 'everypage_http_403' === $res->get_error_code() ) {
				// A plan gate fired upstream, so the cached /user plan may be
				// stale (e.g. a downgrade). Bust the cache so the client's
				// follow-up GET /user re-reads the real subscription.
				$this->api->flush_cache();
			}
			return $this->pass_error( $res );
		}
		// Success upstream is an empty 200; re-GET so the editor sees the
		// clamped, tier-checked values actually stored.
		$file = $this->api->get_file( $request['id'] );
		if ( is_wp_error( $file ) ) {
			return $this->pass_error( $file );
		}
		return rest_ensure_response( $this->shape_file( $file ) );
	}

	/**
	 * The upstream settings PUT REPLACES the whole viewerSettings blob, and
	 * re-sending a non-default Pro group from a non-Pro account is rejected
	 * outright (403) rather than ignored. So a partial client payload is
	 * merged over the file's stored blob first — otherwise groups the plugin
	 * has no UI for (CTA, GA4, watermark style, image assets) would be wiped
	 * on every save — and the merged result is then stripped back to what the
	 * account's tier may store.
	 */
	private function merge_viewer_settings( $id, $payload ) {
		$file = $this->api->get_file( $id );
		if ( is_wp_error( $file ) ) {
			return $this->pass_error( $file );
		}
		$merged = isset( $file['viewerSettings'] ) && is_array( $file['viewerSettings'] ) ? $file['viewerSettings'] : array();
		foreach ( $payload['viewerSettings'] as $key => $value ) {
			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = array_merge( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}

		$user = $this->api->get_user();
		// On a failed plan lookup, assume pro and send everything: upstream
		// re-checks anyway, and its 403 busts our stale caches.
		$tier = ( ! is_wp_error( $user ) && isset( $user['subscription'] ) ) ? (string) $user['subscription'] : 'pro';
		if ( 'free' === $tier ) {
			// Free accounts may only store default viewer settings; omitting
			// the blob keeps the stored one unchanged instead of failing the
			// whole save.
			unset( $payload['viewerSettings'] );
			if ( empty( $payload ) ) {
				return new WP_Error( 'everypage_plan_gated', __( 'Viewer customisation requires a paid EveryPage plan.', 'everypage' ), array( 'status' => 403 ) );
			}
			return $payload;
		}
		if ( 'pro' !== $tier ) {
			unset( $merged['brand'], $merged['cta'], $merged['ga4Id'], $merged['watermarkStyle'] );
			if ( isset( $merged['protect']['blurOnLeave'] ) ) {
				unset( $merged['protect']['blurOnLeave'] );
				if ( empty( $merged['protect'] ) ) {
					unset( $merged['protect'] );
				}
			}
		}
		$payload['viewerSettings'] = $merged;
		return $payload;
	}

	/**
	 * Explicit allowlist for the settings PUT. Only known keys pass through;
	 * arbitrary JSON is never forwarded. The backend clamps values and
	 * re-checks the plan tier, so this is shape validation, not policy.
	 */
	private function sanitize_settings( $body ) {
		$out = array();

		if ( isset( $body['viewerMode'] ) && in_array( $body['viewerMode'], array( 'standard', 'flipbook', 'swipe', 'magazine' ), true ) ) {
			$out['viewerMode'] = $body['viewerMode'];
		}

		// --- Top-level file settings (Files-page drawer) -------------------
		// Presence is checked with array_key_exists so explicit "clear" values
		// ('' password/slug, [] lists, 0 viewLimit, {0,0} pageRange) pass
		// through; the upstream PUT treats omitted keys as unchanged. Booleans
		// are re-typed so the JSON always carries real true/false.
		foreach ( array( 'allowDownload', 'requireEmail', 'notifyOnView', 'askReceipt', 'watermark', 'neverExpire' ) as $flag ) {
			if ( array_key_exists( $flag, $body ) ) {
				$out[ $flag ] = rest_sanitize_boolean( $body[ $flag ] );
			}
		}

		// Password: '' clears (basic+ to set). Passed through verbatim apart
		// from a control-character/length check — trimming would corrupt it.
		if ( array_key_exists( 'password', $body )
			&& is_string( $body['password'] )
			&& strlen( $body['password'] ) <= 128
			&& ! preg_match( '/[\r\n\t\0]/', $body['password'] ) ) {
			$out['password'] = $body['password'];
		}

		if ( array_key_exists( 'viewLimit', $body ) && is_numeric( $body['viewLimit'] ) && (int) $body['viewLimit'] >= 0 ) {
			$out['viewLimit'] = min( (int) $body['viewLimit'], 1000000 ); // 0 clears.
		}

		// Expiry: RFC 3339 only; the backend enforces the per-plan cap.
		if ( array_key_exists( 'deleteAt', $body )
			&& is_string( $body['deleteAt'] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $body['deleteAt'] ) ) {
			$out['deleteAt'] = $body['deleteAt'];
		}

		// Vanity slug (pro): lowercase a-z0-9-hyphen; '' clears.
		if ( array_key_exists( 'slug', $body )
			&& is_string( $body['slug'] )
			&& ( '' === $body['slug'] || preg_match( '/^[a-z0-9-]{1,64}$/', $body['slug'] ) ) ) {
			$out['slug'] = $body['slug'];
		}

		// Email-gate domain allowlist (pro): bare hostnames only; [] clears.
		if ( array_key_exists( 'gateDomains', $body ) && is_array( $body['gateDomains'] ) && count( $body['gateDomains'] ) <= 20 ) {
			$domains = array();
			$valid   = true;
			foreach ( $body['gateDomains'] as $d ) {
				if ( ! is_string( $d ) || strlen( $d ) > 253
					|| ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $d ) ) {
					$valid = false;
					break;
				}
				$domains[] = $d;
			}
			if ( $valid ) {
				$out['gateDomains'] = $domains;
			}
		}

		// Lead-capture form (pro): up to 5 {key,label,type,required} fields
		// with exactly one email field; [] clears. Mirrors the upstream
		// validator so a bad shape is dropped here rather than forwarded.
		if ( array_key_exists( 'gateFields', $body ) && is_array( $body['gateFields'] ) && count( $body['gateFields'] ) <= 5 ) {
			$fields = array();
			$emails = 0;
			$seen   = array();
			$valid  = true;
			foreach ( $body['gateFields'] as $gf ) {
				$key   = is_array( $gf ) && isset( $gf['key'] ) ? (string) $gf['key'] : '';
				$label = is_array( $gf ) && isset( $gf['label'] ) ? sanitize_text_field( (string) $gf['label'] ) : '';
				$type  = is_array( $gf ) && isset( $gf['type'] ) ? (string) $gf['type'] : '';
				if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) || isset( $seen[ $key ] )
					|| '' === $label || mb_strlen( $label ) > 60
					|| ! in_array( $type, array( 'text', 'email', 'phone', 'company' ), true ) ) {
					$valid = false;
					break;
				}
				$seen[ $key ] = true;
				if ( 'email' === $type ) {
					++$emails;
				}
				$fields[] = array(
					'key'      => $key,
					'label'    => $label,
					'type'     => $type,
					'required' => rest_sanitize_boolean( isset( $gf['required'] ) ? $gf['required'] : false ),
				);
			}
			if ( $valid && ( empty( $fields ) || 1 === $emails ) ) {
				$out['gateFields'] = $fields; // Non-empty forces requireEmail upstream.
			}
		}

		// Page range (pro): {0,0} clears.
		if ( array_key_exists( 'pageRange', $body ) && is_array( $body['pageRange'] )
			&& isset( $body['pageRange']['from'], $body['pageRange']['to'] )
			&& is_numeric( $body['pageRange']['from'] ) && is_numeric( $body['pageRange']['to'] ) ) {
			$from = (int) $body['pageRange']['from'];
			$to   = (int) $body['pageRange']['to'];
			if ( $from >= 0 && $to >= 0 ) {
				$out['pageRange'] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}

		if ( isset( $body['viewerSettings'] ) && is_array( $body['viewerSettings'] ) ) {
			$vs       = $body['viewerSettings'];
			$settings = array();

			// assetId round-trips: the upstream PUT REPLACES the whole
			// viewerSettings blob, so an existing background/logo image
			// reference must survive an unrelated edit or it gets wiped.
			$background = $this->pick(
				$vs,
				'background',
				array(
					'type'     => array( 'enum', array( 'solid', 'gradient', 'image' ) ),
					'color'    => array( 'hex' ),
					'gradient' => array( 'slug' ),
					'assetId'  => array( 'uuid' ),
					'fit'      => array( 'slug' ),
					'blur'     => array( 'int', 0, 20 ),
					'dim'      => array( 'int', 0, 80 ),
				)
			);
			if ( $background ) {
				$settings['background'] = $background;
			}

			$logo = $this->pick(
				$vs,
				'logo',
				array(
					'assetId'   => array( 'uuid' ),
					'position'  => array( 'enum', array( 'tl', 'tr', 'bl', 'br' ) ),
					'size'      => array( 'enum', array( 's', 'm', 'l', 'xl' ) ),
					'linkUrl'   => array( 'url' ),
					'hideBadge' => array( 'bool' ),
				)
			);
			if ( $logo ) {
				$settings['logo'] = $logo;
			}

			$page = $this->pick(
				$vs,
				'page',
				array(
					'shadow'     => array( 'int', 0, 3 ),
					'rounded'    => array( 'int', 0, 3 ),
					'edges'      => array( 'bool' ),
					'coverAlone' => array( 'bool' ),
				)
			);
			if ( $page ) {
				$settings['page'] = $page;
			}

			$flip = $this->pick(
				$vs,
				'flip',
				array(
					'speedMs' => array( 'int', 200, 1200 ),
					'sound'   => array( 'bool' ),
					'rtl'     => array( 'bool' ),
					'layout'  => array( 'enum', array( 'adaptive', 'single', 'double' ) ),
				)
			);
			if ( $flip ) {
				$settings['flip'] = $flip;
			}

			$swipe = $this->pick(
				$vs,
				'swipe',
				array(
					'autoAdvance' => array( 'bool' ),
					'intervalMs'  => array( 'int', 3000, 30000 ),
				)
			);
			if ( $swipe ) {
				$settings['swipe'] = $swipe;
			}

			$protect = $this->pick(
				$vs,
				'protect',
				array(
					'contextMenu' => array( 'bool' ),
					'print'       => array( 'bool' ),
					'select'      => array( 'bool' ),
					'blurOnLeave' => array( 'bool' ),
				)
			);
			if ( $protect ) {
				$settings['protect'] = $protect;
			}

			$brand = $this->pick(
				$vs,
				'brand',
				array(
					'accentColor'   => array( 'hex' ),
					'toolbarTheme'  => array( 'enum', array( 'light', 'dark', 'auto' ) ),
					'badgePosition' => array( 'enum', array( 'tl', 'tr', 'bl', 'br' ) ),
				)
			);
			if ( $brand ) {
				$settings['brand'] = $brand;
			}

			// The remaining groups have no plugin UI, but must round-trip:
			// the blob is replaced wholesale upstream, so dropping them here
			// would erase settings configured on the EveryPage dashboard.
			if ( isset( $vs['ga4Id'] ) && is_string( $vs['ga4Id'] ) && preg_match( '/^G-[A-Z0-9]{4,20}$/', $vs['ga4Id'] ) ) {
				$settings['ga4Id'] = $vs['ga4Id'];
			}

			$cta = $this->pick(
				$vs,
				'cta',
				array(
					'label'    => array( 'text', 40 ),
					'url'      => array( 'https' ),
					'style'    => array( 'enum', array( 'solid', 'outline' ) ),
					'color'    => array( 'hex' ),
					'position' => array( 'enum', array( 'tl', 'tr', 'bl', 'br', 'bar' ) ),
				)
			);
			if ( $cta ) {
				$settings['cta'] = $cta;
			}

			$watermark_style = $this->pick(
				$vs,
				'watermarkStyle',
				array(
					'opacity' => array( 'float', 0.05, 0.5 ),
					'density' => array( 'enum', array( 'sparse', 'normal', 'dense' ) ),
				)
			);
			if ( $watermark_style ) {
				$settings['watermarkStyle'] = $watermark_style;
			}

			if ( $settings ) {
				$out['viewerSettings'] = $settings;
			}
		}

		return $out;
	}

	/**
	 * Extract one settings group ($key) from $vs, keeping only $fields that
	 * validate. Returns array() (dropped) when the group is absent or empty.
	 */
	private function pick( $vs, $key, $fields ) {
		if ( ! isset( $vs[ $key ] ) || ! is_array( $vs[ $key ] ) ) {
			return array();
		}
		$group = array();
		foreach ( $fields as $field => $rule ) {
			if ( ! array_key_exists( $field, $vs[ $key ] ) ) {
				continue;
			}
			$value = $vs[ $key ][ $field ];
			switch ( $rule[0] ) {
				case 'enum':
					if ( in_array( $value, $rule[1], true ) ) {
						$group[ $field ] = $value;
					}
					break;
				case 'hex':
					if ( is_string( $value ) && preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ) {
						$group[ $field ] = strtoupper( $value );
					}
					break;
				case 'int':
					if ( is_numeric( $value ) ) {
						$group[ $field ] = max( $rule[1], min( $rule[2], (int) $value ) );
					}
					break;
				case 'bool':
					$group[ $field ] = rest_sanitize_boolean( $value );
					break;
				case 'url':
					if ( is_string( $value ) ) {
						$url = esc_url_raw( $value );
						if ( '' !== $url ) {
							$group[ $field ] = $url;
						}
					}
					break;
				case 'slug':
					if ( is_string( $value ) && preg_match( '/^[a-z0-9-]{1,32}$/', $value ) ) {
						$group[ $field ] = $value;
					}
					break;
				case 'uuid':
					if ( is_string( $value ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
						$group[ $field ] = $value;
					}
					break;
				case 'https':
					if ( is_string( $value ) ) {
						$url = esc_url_raw( $value, array( 'https' ) );
						if ( '' !== $url ) {
							$group[ $field ] = $url;
						}
					}
					break;
				case 'text':
					if ( is_string( $value ) ) {
						$text = sanitize_text_field( $value );
						if ( '' !== $text && mb_strlen( $text ) <= $rule[1] ) {
							$group[ $field ] = $text;
						}
					}
					break;
				case 'float':
					if ( is_numeric( $value ) ) {
						$group[ $field ] = max( $rule[1], min( $rule[2], (float) $value ) );
					}
					break;
			}
		}
		return $group;
	}
}
