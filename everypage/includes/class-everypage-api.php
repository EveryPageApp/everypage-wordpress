<?php
/**
 * Thin client for the EveryPage API (/api/v1), authenticated with the site's
 * personal API key (ep_live_...) stored in plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_API {

	const OPTION = 'everypage_settings';

	/** Stored API key, or '' if unset. */
	public function get_key() {
		$opts = get_option( self::OPTION, array() );
		return isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';
	}

	public function set_key( $key ) {
		$opts            = get_option( self::OPTION, array() );
		$opts['api_key'] = sanitize_text_field( $key );
		update_option( self::OPTION, $opts );
		$this->flush_cache();
	}

	public function has_key() {
		return '' !== $this->get_key();
	}

	/** Monotonic cache version; bumping it invalidates every cached GET. */
	private function cache_version() {
		return (int) get_option( 'everypage_cache_v', 1 );
	}

	/** Bust all cached GETs (after a mutation or an API-key change). */
	public function flush_cache() {
		update_option( 'everypage_cache_v', $this->cache_version() + 1 );
	}

	/**
	 * A GET request with a short-lived transient cache, so rendering an admin
	 * screen does not block on a fresh round-trip every time. Keyed on the API
	 * key + cache version + path, so a key change or mutation never serves
	 * stale data. Errors are never cached.
	 */
	private function cached_get( $path, $ttl ) {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'everypage_no_key', __( 'No EveryPage API key set.', 'everypage' ) );
		}
		$cache_key = 'everypage_' . md5( $key . '|' . $this->cache_version() . '|' . $path );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$data = $this->request( 'GET', $path );
		if ( ! is_wp_error( $data ) ) {
			set_transient( $cache_key, $data, $ttl );
		}
		return $data;
	}

	/**
	 * Make an authenticated JSON request. Returns the decoded body on success
	 * (2xx) or a WP_Error.
	 */
	private function request( $method, $path, $body = null ) {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'everypage_no_key', __( 'No EveryPage API key set.', 'everypage' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 12,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$resp = wp_remote_request( everypage_base_url() . $path, $args );
		return $this->handle( $resp );
	}

	private function handle( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			if ( 401 === $code ) {
				return new WP_Error( 'everypage_unauthorized', __( 'Invalid or revoked API key.', 'everypage' ) );
			}
			return new WP_Error( 'everypage_http_' . $code, sprintf( /* translators: %d: HTTP status */ __( 'EveryPage error (%d).', 'everypage' ), $code ) . ' ' . wp_strip_all_tags( $raw ) );
		}
		if ( '' === trim( (string) $raw ) ) {
			return array(); // Empty 2xx (e.g. 204 No Content).
		}
		$data = json_decode( $raw, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'everypage_bad_response', __( 'Unexpected response from EveryPage. Please try again.', 'everypage' ) );
		}
		return null === $data ? array() : $data;
	}

	public function get_user() {
		return $this->cached_get( '/api/v1/user', 5 * MINUTE_IN_SECONDS );
	}

	public function list_files() {
		return $this->cached_get( '/api/v1/files', MINUTE_IN_SECONDS );
	}

	/**
	 * A single file object. The endpoint accepts a UUID or a short id. Cached
	 * briefly (live-ish view counts) and busted by flush_cache() after any
	 * mutation, so a settings write is never followed by a stale read-back.
	 */
	public function get_file( $id ) {
		return $this->cached_get( '/api/v1/files/' . rawurlencode( $id ), 30 );
	}

	/**
	 * Update a file's settings (viewer mode/appearance, protection, capture,
	 * link). All fields are optional upstream; omitted fields are unchanged.
	 * Success is a 200 with an EMPTY body — re-GET the file to read the
	 * clamped result.
	 */
	public function update_settings( $uuid, $settings ) {
		$res = $this->request( 'PUT', '/api/v1/files/' . rawurlencode( $uuid ) . '/settings', $settings );
		if ( ! is_wp_error( $res ) ) {
			$this->flush_cache();
		}
		return $res;
	}

	public function get_events( $limit = 25 ) {
		return $this->cached_get( '/api/v1/events?limit=' . absint( $limit ), MINUTE_IN_SECONDS );
	}

	public function delete_file( $uuid ) {
		$res = $this->request( 'DELETE', '/api/v1/files/' . rawurlencode( $uuid ) );
		if ( ! is_wp_error( $res ) ) {
			$this->flush_cache();
		}
		return $res;
	}

	/** Per-file readership analytics (tier-shaped). */
	public function get_analytics( $uuid ) {
		return $this->cached_get( '/api/v1/files/' . rawurlencode( $uuid ) . '/readership', MINUTE_IN_SECONDS );
	}

	/**
	 * The QR-code PNG for a file's share link, as raw bytes (or WP_Error).
	 * Binary, so it bypasses the JSON request() helper.
	 */
	public function get_qr_png( $uuid ) {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'everypage_no_key', __( 'No EveryPage API key set.', 'everypage' ) );
		}
		$resp = wp_remote_get(
			everypage_base_url() . '/api/v1/files/' . rawurlencode( $uuid ) . '/qr-code',
			array(
				'timeout' => 12,
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'everypage_qr_failed', __( 'Could not load the QR code.', 'everypage' ) );
		}
		return wp_remote_retrieve_body( $resp );
	}

	/**
	 * Upload a PDF (raw bytes) as multipart/form-data. WP's HTTP API has no
	 * native file-upload helper, so we build the body manually.
	 */
	public function upload_pdf( $bytes, $filename ) {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'everypage_no_key', __( 'No EveryPage API key set.', 'everypage' ) );
		}
		$boundary = 'ep' . wp_generate_password( 24, false );
		$name     = $filename ? $filename : 'document.pdf';
		$body     = '--' . $boundary . "\r\n";
		$body    .= 'Content-Disposition: form-data; name="file"; filename="' . $name . '"' . "\r\n";
		$body    .= "Content-Type: application/pdf\r\n\r\n";
		$body    .= $bytes . "\r\n";
		$body    .= '--' . $boundary . "--\r\n";

		$resp = wp_remote_post(
			everypage_base_url() . '/api/v1/files',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		$res  = $this->handle( $resp );
		if ( ! is_wp_error( $res ) ) {
			$this->flush_cache();
		}
		return $res;
	}

	/** The public share link for a file UUID. */
	public function share_url( $uuid ) {
		return everypage_base_url() . '/' . rawurlencode( $uuid );
	}
}
