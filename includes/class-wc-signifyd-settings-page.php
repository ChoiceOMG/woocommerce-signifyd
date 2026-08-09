<?php
/**
 * WooCommerce settings tab for Signifyd.
 *
 * Field definitions only. WooCommerce's settings API owns rendering, saving,
 * sanitization, and the nonce check for this screen, so there is no save
 * handler here.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

// WC_Settings_Page only exists once WooCommerce builds its settings screen.
// Bailing here keeps a direct or mistimed include from fataling on a missing
// parent class.
if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return;
}

/**
 * The Signifyd tab under WooCommerce > Settings.
 *
 * Instantiated by WC_Signifyd_Settings::register_page() through the
 * woocommerce_get_settings_pages filter.
 */
class WC_Signifyd_Settings_Page extends WC_Settings_Page {

	/**
	 * Set the tab slug and label, then let WC_Settings_Page wire up its own
	 * render and save hooks.
	 */
	public function __construct() {
		$this->id    = 'signifyd';
		$this->label = __( 'Signifyd', 'fraud-screening-with-signifyd' );
		parent::__construct();
	}

	/**
	 * Field definitions for this tab, called by the WC_Settings_Page parent
	 * when it renders and when it saves the WooCommerce > Settings > Signifyd
	 * screen.
	 *
	 * @return array
	 */
	public function get_settings() {
		$gateway_options = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				$gateway_options[ $gateway->id ] = $gateway->get_method_title() . ' (' . $gateway->id . ')';
			}
		}

		$key_desc = __( 'Your Signifyd team API key. Find it in the Signifyd console under Settings > Teams.', 'fraud-screening-with-signifyd' );
		if ( WC_Signifyd_Settings::key_is_from_constant() ) {
			$key_desc = __( 'Currently supplied by the WC_SIGNIFYD_API_KEY constant in wp-config.php, which overrides this field.', 'fraud-screening-with-signifyd' );
		}

		$settings = array(
			array(
				'title' => __( 'Signifyd', 'fraud-screening-with-signifyd' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: webhook URL */
					__( 'Fraud screening for WooCommerce. Register this webhook URL in the Signifyd console for the Case Creation, Case Rescore, Case Review and Guarantee Completion events: <code>%s</code>', 'fraud-screening-with-signifyd' ),
					esc_html( WC_Signifyd_Settings::webhook_url() )
				),
				'id'    => 'wc_signifyd_options',
			),
			array(
				'title'    => __( 'API key', 'fraud-screening-with-signifyd' ),
				'desc'     => $key_desc,
				'id'       => WC_Signifyd_Settings::OPTION_KEY,
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => false,
				'custom_attributes' => WC_Signifyd_Settings::key_is_from_constant()
					? array( 'disabled' => 'disabled' )
					: array(),
			),
			array(
				'title'   => __( 'Screened payment methods', 'fraud-screening-with-signifyd' ),
				'desc'    => __( 'Only orders paid with these gateways are sent to Signifyd. Credit card gateways only.', 'fraud-screening-with-signifyd' ),
				'id'      => WC_Signifyd_Settings::OPTION_ELIGIBLE_GWS,
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $gateway_options,
				'default' => array( 'moneris' ),
				'desc_tip' => true,
			),
			array(
				'title'   => __( 'Create cases on', 'fraud-screening-with-signifyd' ),
				'desc'    => __( 'Which order event submits the case. Payment complete is recommended.', 'fraud-screening-with-signifyd' ),
				'id'      => WC_Signifyd_Settings::OPTION_CREATE_ON,
				'type'    => 'select',
				'options' => array(
					'payment_complete' => __( 'Payment complete', 'fraud-screening-with-signifyd' ),
					'processing'       => __( 'Order status: processing', 'fraud-screening-with-signifyd' ),
				),
				'default'  => 'payment_complete',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wc_signifyd_options',
			),
		);

		return apply_filters( 'wc_signifyd_settings', $settings );
	}
}
