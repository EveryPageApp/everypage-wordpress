<?php
/**
 * PHPUnit bootstrap. Runs against the WordPress test library that wp-env
 * mounts at /wordpress-phpunit; WP_TESTS_DIR overrides it for a hand-rolled
 * wordpress-develop checkout.
 *
 * Run with:
 *   wp-env run tests-cli --env-cwd=wp-content/plugins/everypage \
 *     ../../../vendor/bin/phpunit -c ../../../phpunit.xml.dist
 */

$everypage_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $everypage_tests_dir ) {
	$everypage_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $everypage_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$everypage_tests_dir}.\n";
	echo "Start the test environment with `npx wp-env start` first.\n";
	exit( 1 );
}

require_once $everypage_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/everypage/everypage.php';
	}
);

require $everypage_tests_dir . '/includes/bootstrap.php';

/**
 * Shared helper: queue canned HTTP responses so tests never touch the network.
 * Each entry is matched against the request URL by substring, in order.
 */
class EveryPage_HTTP_Stub {

	/** @var array<int,array{match:string,code:int,body:mixed}> */
	public static $responses = array();

	/** @var array<int,string> Every URL requested while stubbed, in order. */
	public static $requests = array();

	public static function reset() {
		self::$responses = array();
		self::$requests  = array();
	}

	/** Queue a response for the next request whose URL contains $match. */
	public static function on( $match, $body, $code = 200 ) {
		self::$responses[] = array(
			'match' => $match,
			'code'  => $code,
			'body'  => $body,
		);
	}

	/**
	 * Priority 99: the screenshot fixture mu-plugin also filters
	 * pre_http_request (at 10) and ignores whatever an earlier filter
	 * returned, so a stub registered at or below its priority would be
	 * silently overridden wherever both are loaded.
	 */
	public static function hook() {
		add_filter( 'pre_http_request', array( __CLASS__, 'respond' ), 99, 3 );
	}

	public static function unhook() {
		remove_filter( 'pre_http_request', array( __CLASS__, 'respond' ), 99 );
	}

	public static function respond( $preempt, $args, $url ) {
		self::$requests[] = $url;
		foreach ( self::$responses as $i => $canned ) {
			if ( false !== strpos( $url, $canned['match'] ) ) {
				unset( self::$responses[ $i ] );
				return array(
					'headers'  => array(),
					'response' => array( 'code' => $canned['code'], 'message' => '' ),
					'body'     => is_string( $canned['body'] ) ? $canned['body'] : wp_json_encode( $canned['body'] ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'everypage_test_unstubbed', 'Unstubbed request: ' . $url );
	}
}
