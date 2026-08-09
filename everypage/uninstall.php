<?php
/**
 * Uninstall cleanup, run when the plugin is deleted from wp-admin.
 *
 * Removes everything the plugin stored: its options (including the API key),
 * its transient response cache, and the attachment meta written by the Media
 * Library integration. Files shared on EveryPage are NOT touched — they live
 * in the user's EveryPage account, not in WordPress.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Remove all plugin data for the current site. */
function everypage_uninstall_site() {
	global $wpdb;

	// Options.
	delete_option( 'everypage_settings' );
	delete_option( 'everypage_cache_v' );

	// Transient cache: keys are 'everypage_' . md5( ... ), so expired and live
	// entries (values + timeouts) are swept with one LIKE per prefix.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time cleanup of namespaced transients
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_everypage_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_everypage_' ) . '%'
		)
	);
	// phpcs:enable

	// Attachment meta written by the Media Library integration.
	delete_post_meta_by_key( '_everypage_uuid' );
	delete_post_meta_by_key( '_everypage_short_id' );
	delete_post_meta_by_key( '_everypage_shared_at' );
}

everypage_uninstall_site();

// Multisite: clean every site (options, transients, and attachment meta are
// all per-site tables).
if ( is_multisite() ) {
	$everypage_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $everypage_sites as $everypage_site_id ) {
		switch_to_blog( $everypage_site_id );
		everypage_uninstall_site();
		restore_current_blog();
	}
}
