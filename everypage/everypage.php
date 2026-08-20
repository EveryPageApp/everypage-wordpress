<?php
/**
 * Plugin Name:       EveryPage – PDF Viewer, Flipbook Embeds & Reader Analytics
 * Plugin URI:        https://everypage.co/developers
 * Description:        Share PDFs as secure, trackable links and see readership analytics right in your dashboard.
 * Version:           1.2.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            EveryPage
 * Author URI:        https://everypage.co
 * License:           GPL-2.0-or-later
 * Text Domain:       everypage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EVERYPAGE_VERSION', '1.2.0' );
define( 'EVERYPAGE_PLUGIN_FILE', __FILE__ );
define( 'EVERYPAGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVERYPAGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Base URL of the EveryPage API. Override with the `everypage_base_url` filter. */
function everypage_base_url() {
	return apply_filters( 'everypage_base_url', 'https://everypage.co' );
}

require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-api.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-admin.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-dashboard.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-renderer.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-shortcode.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-rest.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-block.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-media.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-leads.php';
require_once EVERYPAGE_PLUGIN_DIR . 'includes/class-everypage-elementor.php';

/** Boot the plugin. */
function everypage_init() {
	$api   = new EveryPage_API();
	$leads = new EveryPage_Leads( $api );
	$leads->hooks();
	( new EveryPage_Admin( $api, $leads ) )->hooks();
	( new EveryPage_Dashboard( $api ) )->hooks();
	( new EveryPage_Shortcode() )->hooks();
	( new EveryPage_Rest( $api ) )->hooks();
	( new EveryPage_Block( $api ) )->hooks();
	( new EveryPage_Media( $api ) )->hooks();
	EveryPage_Elementor::hooks( $api );

	// Keep scheduled work in step after an update: a 1.1.0 install upgrading
	// with lead sync already enabled would otherwise have no cron event.
	if ( get_option( 'everypage_version' ) !== EVERYPAGE_VERSION ) {
		update_option( 'everypage_version', EVERYPAGE_VERSION );
		$leads->sync_schedule();
	}

	// Register everypage.co as a first-class oEmbed provider. Without this,
	// WordPress still resolves pasted links via HTML discovery, but treats
	// the provider as untrusted and wraps the embed in a sandboxed iframe
	// (opaque origin), where the viewer cannot load. Registered providers
	// are trusted: pasting a share link embeds the full tracked viewer.
	wp_oembed_add_provider( '#https?://everypage\.co/.+#i', 'https://everypage.co/oembed', true );
}
add_action( 'plugins_loaded', 'everypage_init' );

/**
 * Deactivation: drop scheduled work. Stored settings, the cursor, and the
 * sweep's de-dup set survive, so reactivating resumes where it left off
 * rather than replaying leads. Uninstall removes them.
 */
function everypage_deactivate() {
	wp_clear_scheduled_hook( EveryPage_Leads::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'everypage_deactivate' );
