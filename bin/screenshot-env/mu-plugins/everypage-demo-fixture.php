<?php
/**
 * Plugin Name: EveryPage Demo Fixture (screenshots only)
 *
 * Intercepts every WordPress HTTP request to everypage.co and returns canned
 * demo data, so the real plugin UI renders a fully-populated Pro account for
 * WP.org listing screenshots. Never ships; lives only in the local wp-env
 * used to capture screenshots.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'pre_http_request', 'everypage_demo_intercept', 10, 3 );

function everypage_demo_respond( $body, $type = 'application/json' ) {
	return array(
		'headers'  => array( 'content-type' => $type ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function everypage_demo_files() {
	$now = time();
	$day = DAY_IN_SECONDS;
	return array(
		array(
			'uuid'           => '3f9d2c71-8b4e-4a2f-9c1d-5e7a6b3f8d21',
			'shortId'        => 'aB3xK9mZq2Lp',
			'slug'           => 'q3-catalogue',
			'shareDomain'    => 'docs.studionorth.co',
			'originalName'   => 'Q3 Product Catalogue.pdf',
			'size'           => 18874368,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 21 * $day ),
			'updatedAt'      => gmdate( 'c', $now - 2 * $day ),
			'viewCount'      => 2341,
			'totalPages'     => 24,
			'viewerMode'     => 'flipbook',
			'protected'      => false,
			'allowDownload'  => true,
			'requireEmail'   => false,
			'notifyOnView'   => false,
			'askReceipt'     => false,
			'watermark'      => false,
			'viewsConsumed'  => 0,
			'contentVersion' => 3,
			'anonShareable'  => false,
			'viewerSettings' => array(
				'background' => array(
					'type'  => 'solid',
					'color' => '#1c1a17',
				),
				'page'       => array(
					'shadow'  => 2,
					'rounded' => 1,
					'edges'   => true,
				),
				'flip'       => array(
					'speedMs' => 450,
					'layout'  => 'adaptive',
				),
				'brand'      => array(
					'accentColor'  => '#c96f2b',
					'toolbarTheme' => 'dark',
				),
			),
		),
		array(
			'uuid'           => '7a1e5f42-3c9b-4d87-b2e6-914f8c5a7e33',
			'shortId'        => 'Fq7wN4rT8vXe',
			'originalName'   => '2026 Media Kit.pdf',
			'size'           => 9437184,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 14 * $day ),
			'updatedAt'      => gmdate( 'c', $now - $day ),
			'viewCount'      => 812,
			'totalPages'     => 12,
			'viewerMode'     => 'standard',
			'protected'      => false,
			'allowDownload'  => true,
			'requireEmail'   => true,
			'notifyOnView'   => true,
			'askReceipt'     => false,
			'watermark'      => false,
			'viewsConsumed'  => 0,
			'contentVersion' => 1,
			'anonShareable'  => false,
			'gateFields'     => array(
				array(
					'key'      => 'email',
					'label'    => 'Work email',
					'type'     => 'email',
					'required' => true,
				),
				array(
					'key'      => 'name',
					'label'    => 'Name',
					'type'     => 'text',
					'required' => true,
				),
				array(
					'key'      => 'company',
					'label'    => 'Company',
					'type'     => 'company',
					'required' => false,
				),
			),
			'viewerSettings' => array(),
		),
		array(
			'uuid'           => 'c4b8d926-5e1a-4f73-a8c2-3d7e9b4f6a55',
			'shortId'        => 'Jm2pQ8sV5wYz',
			'originalName'   => 'Client Proposal - Meridian.pdf',
			'size'           => 4194304,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 6 * $day ),
			'updatedAt'      => gmdate( 'c', $now - 6 * $day ),
			'viewCount'      => 47,
			'totalPages'     => 9,
			'viewerMode'     => 'standard',
			'protected'      => true,
			'allowDownload'  => false,
			'requireEmail'   => false,
			'notifyOnView'   => true,
			'askReceipt'     => true,
			'watermark'      => true,
			'viewLimit'      => 100,
			'viewsConsumed'  => 47,
			'contentVersion' => 2,
			'anonShareable'  => false,
			'viewerSettings' => array(
				'protect' => array(
					'contextMenu' => true,
					'print'       => true,
					'select'      => true,
					'blurOnLeave' => true,
				),
			),
		),
		array(
			'uuid'           => '9e3a7c15-2f8d-4b64-9a1e-8c5b2d7f4e88',
			'shortId'        => 'Rt6kB3nH9cMd',
			'originalName'   => 'Onboarding Workbook.pdf',
			'size'           => 12582912,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 30 * $day ),
			'updatedAt'      => gmdate( 'c', $now - 4 * $day ),
			'viewCount'      => 356,
			'totalPages'     => 32,
			'viewerMode'     => 'swipe',
			'protected'      => false,
			'allowDownload'  => true,
			'requireEmail'   => false,
			'notifyOnView'   => false,
			'askReceipt'     => false,
			'watermark'      => false,
			'viewsConsumed'  => 0,
			'contentVersion' => 1,
			'anonShareable'  => false,
			'viewerSettings' => array(
				'swipe' => array(
					'autoAdvance' => false,
					'intervalMs'  => 6000,
				),
			),
		),
		array(
			'uuid'           => 'b2d6f834-7a9c-4e15-8f3b-6c1d9e2a5b77',
			'shortId'        => 'Wx4gL7dP2qZn',
			'originalName'   => 'Price List July 2026.pdf',
			'size'           => 2097152,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 9 * $day ),
			'updatedAt'      => gmdate( 'c', $now - 9 * $day ),
			'deleteAt'       => gmdate( 'c', $now + 45 * $day ),
			'viewCount'      => 129,
			'totalPages'     => 4,
			'viewerMode'     => 'standard',
			'protected'      => false,
			'allowDownload'  => false,
			'requireEmail'   => false,
			'notifyOnView'   => false,
			'askReceipt'     => false,
			'watermark'      => true,
			'viewsConsumed'  => 0,
			'contentVersion' => 5,
			'anonShareable'  => false,
			'viewerSettings' => array(),
		),
		array(
			'uuid'           => 'e5c9a147-6b2d-4f38-a7e1-9d4c8b5f2a66',
			'shortId'        => 'Hn8vC5jR4tKs',
			'originalName'   => 'Retreat Brochure 2026.pdf',
			'size'           => 7340032,
			'mimeType'       => 'application/pdf',
			'createdAt'      => gmdate( 'c', $now - 2 * 3600 ),
			'updatedAt'      => gmdate( 'c', $now - 2 * 3600 ),
			'viewCount'      => 421,
			'totalPages'     => 16,
			'viewerMode'     => 'magazine',
			'protected'      => false,
			'allowDownload'  => true,
			'requireEmail'   => false,
			'notifyOnView'   => false,
			'askReceipt'     => false,
			'watermark'      => false,
			'viewsConsumed'  => 0,
			'contentVersion' => 1,
			'anonShareable'  => false,
			'viewerSettings' => array(),
		),
	);
}

function everypage_demo_find_file( $id ) {
	foreach ( everypage_demo_files() as $f ) {
		if ( $f['uuid'] === $id || $f['shortId'] === $id || ( isset( $f['slug'] ) && $f['slug'] === $id ) ) {
			return $f;
		}
	}
	return null;
}

function everypage_demo_readership( $file ) {
	$now  = time();
	$day  = DAY_IN_SECONDS;
	$over = array();
	$base = array( 41, 55, 38, 62, 74, 58, 49, 83, 91, 66, 72, 88, 104, 79, 95, 112, 87, 76, 118, 96, 84, 71, 102, 93, 108, 85, 97, 121, 89, 77 );
	for ( $i = 29; $i >= 0; $i-- ) {
		$over[] = array(
			'day'   => gmdate( 'Y-m-d', $now - $i * $day ),
			'count' => $base[ 29 - $i ],
		);
	}
	$funnel = array();
	$pages  = max( 1, (int) $file['totalPages'] );
	$reach  = 1876;
	for ( $p = 1; $p <= $pages; $p++ ) {
		$funnel[] = array(
			'page'    => $p,
			'reached' => $reach,
			'pct'     => round( $reach / 1876 * 100, 1 ),
		);
		$reach = (int) floor( $reach * ( 1 - ( 0.018 + 0.02 * ( $p / $pages ) ) ) );
	}
	return array(
		'file'         => $file,
		'tier'         => 'pro',
		'summary'      => array(
			'totalViews'        => $file['viewCount'],
			'totalSessions'     => 1876,
			'uniqueVisitors'    => 1204,
			'uniqueCountries'   => 23,
			'avgTimeMs'         => 274000,
			'medianTimeMs'      => 196000,
			'downloads'         => 189,
			'completionRate'    => 68.4,
			'totalTurns'        => 28419,
			'backTurnShare'     => 11.2,
			'activeReaders'     => 3,
			'newSessions'       => 1402,
			'returningSessions' => 474,
			'invitedNotViewed'  => 2,
		),
		'viewsOverTime' => $over,
		'funnel'        => $funnel,
		'countries'     => array(
			array( 'label' => 'United Kingdom', 'count' => 642 ),
			array( 'label' => 'United States', 'count' => 517 ),
			array( 'label' => 'Germany', 'count' => 214 ),
			array( 'label' => 'Netherlands', 'count' => 158 ),
			array( 'label' => 'France', 'count' => 121 ),
			array( 'label' => 'Spain', 'count' => 84 ),
		),
		'devices'       => array(
			array( 'label' => 'Desktop', 'count' => 1094 ),
			array( 'label' => 'Mobile', 'count' => 651 ),
			array( 'label' => 'Tablet', 'count' => 131 ),
		),
		'sources'       => array(
			array( 'label' => 'Direct link', 'count' => 1123 ),
			array( 'label' => 'Embed', 'count' => 486 ),
			array( 'label' => 'QR code', 'count' => 267 ),
		),
		'referrers'     => array(
			array( 'label' => 'studionorth.co', 'count' => 412 ),
			array( 'label' => 'linkedin.com', 'count' => 236 ),
			array( 'label' => 'mail.google.com', 'count' => 187 ),
		),
		'browsers'      => array(
			array( 'label' => 'Chrome', 'count' => 998 ),
			array( 'label' => 'Safari', 'count' => 542 ),
			array( 'label' => 'Edge', 'count' => 201 ),
			array( 'label' => 'Firefox', 'count' => 135 ),
		),
		'viewerModes'   => array(
			array( 'label' => 'Flipbook', 'count' => 1512 ),
			array( 'label' => 'Standard', 'count' => 364 ),
		),
		'peakTimes'     => array(
			array( 'dow' => 2, 'hour' => 9, 'count' => 84 ),
			array( 'dow' => 3, 'hour' => 14, 'count' => 78 ),
			array( 'dow' => 4, 'hour' => 10, 'count' => 71 ),
		),
		'sessions'      => array(
			array(
				'startedAt'   => gmdate( 'c', $now - 1500 ),
				'totalTimeMs' => 431000,
				'pagesViewed' => 24,
				'country'     => 'United Kingdom',
				'deviceType'  => 'Desktop',
				'browser'     => 'Chrome',
				'returning'   => true,
				'email'       => 'j.morris@acmegroup.co',
				'name'        => 'Jane Morris',
			),
			array(
				'startedAt'   => gmdate( 'c', $now - 5400 ),
				'totalTimeMs' => 187000,
				'pagesViewed' => 15,
				'country'     => 'Germany',
				'deviceType'  => 'Mobile',
				'browser'     => 'Safari',
				'returning'   => false,
			),
			array(
				'startedAt'   => gmdate( 'c', $now - 9800 ),
				'totalTimeMs' => 322000,
				'pagesViewed' => 21,
				'country'     => 'United States',
				'deviceType'  => 'Desktop',
				'browser'     => 'Chrome',
				'returning'   => false,
				'email'       => 'sofia@meridianstudio.com',
				'name'        => 'Sofia Reyes',
			),
			array(
				'startedAt'   => gmdate( 'c', $now - 14200 ),
				'totalTimeMs' => 96000,
				'pagesViewed' => 8,
				'country'     => 'Netherlands',
				'deviceType'  => 'Tablet',
				'browser'     => 'Safari',
				'returning'   => true,
			),
			array(
				'startedAt'   => gmdate( 'c', $now - 20100 ),
				'totalTimeMs' => 254000,
				'pagesViewed' => 19,
				'country'     => 'France',
				'deviceType'  => 'Desktop',
				'browser'     => 'Firefox',
				'returning'   => false,
			),
		),
		'contacts'      => array(
			array(
				'fields'    => array(
					'email'   => 'j.morris@acmegroup.co',
					'name'    => 'Jane Morris',
					'company' => 'Acme Group',
				),
				'createdAt' => gmdate( 'c', $now - 1500 ),
			),
			array(
				'fields'    => array(
					'email'   => 'sofia@meridianstudio.com',
					'name'    => 'Sofia Reyes',
					'company' => 'Meridian Studio',
				),
				'createdAt' => gmdate( 'c', $now - 9800 ),
			),
		),
	);
}

function everypage_demo_events() {
	$now   = time();
	$rows  = array(
		array( 'Q3 Product Catalogue.pdf', 'United Kingdom', 24, 1500 ),
		array( '2026 Media Kit.pdf', 'United States', 12, 3900 ),
		array( 'Q3 Product Catalogue.pdf', 'Germany', 15, 5400 ),
		array( 'Retreat Brochure 2026.pdf', 'United Kingdom', 16, 7300 ),
		array( 'Onboarding Workbook.pdf', 'Netherlands', 27, 9100 ),
		array( 'Client Proposal - Meridian.pdf', 'United States', 9, 12800 ),
		array( 'Q3 Product Catalogue.pdf', 'France', 21, 14200 ),
		array( 'Price List July 2026.pdf', 'Spain', 4, 16600 ),
		array( '2026 Media Kit.pdf', 'Germany', 10, 19400 ),
		array( 'Retreat Brochure 2026.pdf', 'United Kingdom', 11, 22100 ),
	);
	$out   = array();
	$id    = 48211;
	foreach ( $rows as $r ) {
		$out[] = array(
			'id'          => $id--,
			'fileUuid'    => '3f9d2c71-8b4e-4a2f-9c1d-5e7a6b3f8d21',
			'fileName'    => $r[0],
			'readAt'      => gmdate( 'c', $now - $r[3] ),
			'country'     => $r[1],
			'pagesViewed' => $r[2],
			'timeMs'      => $r[2] * 14000,
			'type'        => 'view',
		);
	}
	return $out;
}

/** Canned lead-capture form submissions for the leads panel. */
function everypage_demo_gate_responses() {
	$now = time();
	return array(
		array(
			'id'       => 412,
			'fileUuid' => '3f9d2c71-8b4e-4a2f-9c1d-5e7a6b3f8d21',
			'fileName' => 'Q3 Product Catalogue.pdf',
			'readAt'   => gmdate( 'c', $now - 2 * HOUR_IN_SECONDS ),
			'type'     => 'gate',
			'fields'   => array(
				'email'   => 'priya.raman@northwind.example',
				'name'    => 'Priya Raman',
				'company' => 'Northwind Interiors',
			),
		),
		array(
			'id'       => 411,
			'fileUuid' => '3f9d2c71-8b4e-4a2f-9c1d-5e7a6b3f8d21',
			'fileName' => 'Q3 Product Catalogue.pdf',
			'readAt'   => gmdate( 'c', $now - DAY_IN_SECONDS ),
			'type'     => 'gate',
			'fields'   => array(
				'email' => 't.okafor@brightfold.example',
				'name'  => 'Tunde Okafor',
			),
		),
	);
}

function everypage_demo_intercept( $pre, $args, $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( 'everypage.co' !== $host ) {
		return $pre;
	}
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

	if ( '/api/v1/user' === $path ) {
		return everypage_demo_respond(
			array(
				'name'         => 'Studio North',
				'email'        => 'hello@studionorth.co',
				'subscription' => 'pro',
				'isAdmin'      => false,
				'created_at'   => '2026-02-11T09:24:00Z',
			)
		);
	}

	if ( '/api/v1/files' === $path && 'POST' === $method ) {
		return everypage_demo_respond(
			array(
				'uuid'    => 'e5c9a147-6b2d-4f38-a7e1-9d4c8b5f2a66',
				'shortId' => 'Hn8vC5jR4tKs',
				'message' => 'PDF uploaded successfully',
			)
		);
	}

	if ( '/api/v1/files' === $path ) {
		return everypage_demo_respond( everypage_demo_files() );
	}

	// Lead-capture form submissions, cursor-paged exactly like the real
	// endpoint: `since` returns only rows above it, ascending.
	if ( '/api/v1/gate-responses' === $path ) {
		$since = (int) ( wp_parse_url( $url, PHP_URL_QUERY ) ? ( wp_parse_args( wp_parse_url( $url, PHP_URL_QUERY ) )['since'] ?? 0 ) : 0 );
		$rows  = everypage_demo_gate_responses();
		if ( $since > 0 ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $since ) {
						return $row['id'] > $since;
					}
				)
			);
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['id'] - $b['id'];
				}
			);
		}
		return everypage_demo_respond( $rows );
	}

	if ( preg_match( '#^/api/v1/files/([^/]+)/qr-code$#', $path, $m ) || preg_match( '#^/api/files/([^/]+)/qr-code$#', $path, $m ) ) {
		$png = @file_get_contents( __DIR__ . '/demo-qr.png' );
		if ( false !== $png ) {
			return everypage_demo_respond( $png, 'image/png' );
		}
		return $pre;
	}

	if ( preg_match( '#^/api/v1/files/([^/]+)/readership$#', $path, $m ) ) {
		$file = everypage_demo_find_file( $m[1] );
		if ( $file ) {
			return everypage_demo_respond( everypage_demo_readership( $file ) );
		}
	}

	if ( preg_match( '#^/api/v1/files/([^/]+)/settings$#', $path, $m ) && 'PUT' === $method ) {
		return everypage_demo_respond( '' );
	}

	if ( preg_match( '#^/api/v1/files/([^/]+)$#', $path, $m ) ) {
		$file = everypage_demo_find_file( $m[1] );
		if ( $file ) {
			return everypage_demo_respond( $file );
		}
		return array(
			'headers'  => array(),
			'body'     => 'File not found',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	if ( '/api/v1/events' === $path ) {
		return everypage_demo_respond( everypage_demo_events() );
	}

	return $pre;
}
