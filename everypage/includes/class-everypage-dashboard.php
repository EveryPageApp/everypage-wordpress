<?php
/**
 * "Recent reads" dashboard widget - surfaces EveryPage readership right in
 * wp-admin via GET /api/v1/events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Dashboard {

	private $api;

	public function __construct( EveryPage_API $api ) {
		$this->api = $api;
	}

	public function hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	public function add_widget() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->api->has_key() ) {
			return;
		}
		wp_add_dashboard_widget( 'everypage_recent_reads', __( 'EveryPage - Recent reads', 'everypage' ), array( $this, 'render' ) );
	}

	public function render() {
		$events = $this->api->get_events( 15 );
		if ( is_wp_error( $events ) ) {
			echo '<p>' . esc_html( $events->get_error_message() ) . '</p>';
			return;
		}
		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No reads yet. Share a PDF to start tracking.', 'everypage' ) . '</p>';
			return;
		}
		echo '<ul class="everypage-reads">';
		foreach ( $events as $e ) {
			$name    = isset( $e['fileName'] ) ? $e['fileName'] : __( 'a document', 'everypage' );
			$country = isset( $e['country'] ) ? $e['country'] : '';
			$pages   = isset( $e['pagesViewed'] ) ? (int) $e['pagesViewed'] : 0;
			$when    = isset( $e['readAt'] ) ? strtotime( $e['readAt'] ) : false;
			$meta    = array();
			if ( $country ) {
				$meta[] = $country;
			}
			$meta[] = sprintf( /* translators: %d: page count */ _n( '%d page', '%d pages', $pages, 'everypage' ), $pages );
			if ( $when ) {
				// Both timestamps must be UTC: strtotime() of the ISO readAt is
				// UTC, so compare against time() (UTC), not current_time() (local).
				/* translators: %s: human time diff, e.g. "2 hours" */
				$meta[] = sprintf( __( '%s ago', 'everypage' ), human_time_diff( $when, time() ) );
			}
			printf(
				'<li><strong>%1$s</strong><br><span class="everypage-reads-meta">%2$s</span></li>',
				esc_html( $name ),
				esc_html( implode( ' · ', $meta ) )
			);
		}
		echo '</ul>';
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=everypage' ) ),
			esc_html__( 'View all files', 'everypage' )
		);
	}
}
