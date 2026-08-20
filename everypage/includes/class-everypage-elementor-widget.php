<?php
/**
 * The Elementor widget itself. Loaded only from EveryPage_Elementor::register(),
 * which runs after Elementor is confirmed present — the parent class does not
 * exist otherwise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EveryPage_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'everypage-document';
	}

	public function get_title() {
		return __( 'EveryPage Document', 'everypage' );
	}

	public function get_icon() {
		return 'eicon-document-file';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'pdf', 'document', 'flipbook', 'embed', 'everypage' );
	}

	/**
	 * The account's documents as a select list. An API failure (or no key)
	 * yields an empty list and the notice control below explains why, rather
	 * than an Elementor error.
	 */
	private function document_options() {
		$api = EveryPage_Elementor::api();
		if ( ! $api instanceof EveryPage_API || ! $api->has_key() ) {
			return array();
		}
		$files = $api->list_files();
		if ( is_wp_error( $files ) || ! is_array( $files ) ) {
			return array();
		}
		$options = array();
		foreach ( $files as $file ) {
			if ( empty( $file['uuid'] ) ) {
				continue;
			}
			$options[ (string) $file['uuid'] ] = ! empty( $file['originalName'] )
				? (string) $file['originalName']
				: (string) $file['uuid'];
		}
		return $options;
	}

	protected function register_controls() {
		$documents = $this->document_options();

		$this->start_controls_section(
			'everypage_document',
			array( 'label' => __( 'Document', 'everypage' ) )
		);

		if ( empty( $documents ) ) {
			$this->add_control(
				'everypage_no_files',
				array(
					'type'            => \Elementor\Controls_Manager::RAW_HTML,
					'raw'             => sprintf(
						/* translators: %s: link to the plugin's settings screen */
						esc_html__( 'No documents found. Connect your API key and upload a PDF on the %s screen.', 'everypage' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=everypage-settings' ) ) . '" target="_blank">' . esc_html__( 'EveryPage settings', 'everypage' ) . '</a>'
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$this->add_control(
			'uuid',
			array(
				'label'       => __( 'Document', 'everypage' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $documents,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'mode',
			array(
				'label'   => __( 'Display as', 'everypage' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'embed',
				'options' => array(
					'embed'  => __( 'Embedded viewer', 'everypage' ),
					'button' => __( 'Link or button', 'everypage' ),
				),
			)
		);

		$this->add_control(
			'height',
			array(
				'label'      => __( 'Viewer height', 'everypage' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 200,
						'max'  => 2000,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 600,
				),
				'condition'  => array( 'mode' => 'embed' ),
			)
		);

		$this->add_control(
			'text',
			array(
				'label'     => __( 'Link text', 'everypage' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'View document', 'everypage' ),
				'condition' => array( 'mode' => 'button' ),
			)
		);

		$this->add_control(
			'buttonStyle',
			array(
				'label'     => __( 'Style', 'everypage' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'button',
				'options'   => array(
					'button' => __( 'Button', 'everypage' ),
					'link'   => __( 'Plain link', 'everypage' ),
				),
				'condition' => array( 'mode' => 'button' ),
			)
		);

		$this->add_control(
			'everypage_settings_link',
			array(
				'type'      => \Elementor\Controls_Manager::RAW_HTML,
				'raw'       => sprintf(
					/* translators: %s: link to the plugin's Files screen */
					esc_html__( 'Viewer mode, branding, and protection are set per document on the %s screen and apply everywhere it appears.', 'everypage' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=everypage' ) ) . '" target="_blank">' . esc_html__( 'EveryPage Files', 'everypage' ) . '</a>'
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end (and Elementor preview) output. Delegates wholly to the shared
	 * renderer so the widget, the block, and the shortcode stay identical.
	 */
	protected function render() {
		$api = EveryPage_Elementor::api();
		if ( ! $api instanceof EveryPage_API ) {
			return;
		}
		$settings = $this->get_settings_for_display();
		$height   = isset( $settings['height']['size'] ) ? (int) $settings['height']['size'] : 600;

		$html = EveryPage_Renderer::document(
			array(
				'uuid'        => isset( $settings['uuid'] ) ? $settings['uuid'] : '',
				'mode'        => isset( $settings['mode'] ) ? $settings['mode'] : 'embed',
				'height'      => $height,
				'text'        => isset( $settings['text'] ) ? $settings['text'] : '',
				'buttonStyle' => isset( $settings['buttonStyle'] ) ? $settings['buttonStyle'] : 'button',
			),
			$api
		);

		if ( '' === $html && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<p>' . esc_html__( 'Choose an EveryPage document to display.', 'everypage' ) . '</p>';
			return;
		}
		// Renderer output is built from escaped parts (esc_url/esc_attr/esc_html).
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
