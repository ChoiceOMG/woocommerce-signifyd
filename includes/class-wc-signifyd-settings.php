<?php
/**
 * Settings screen: WooCommerce > Settings > Signifyd.
 *
 * Registers the settings tab and provides the typed accessors the rest of
 * the plugin reads configuration through. No other class calls get_option()
 * for plugin settings directly, so behavior such as the API-key constant
 * override is implemented once, here.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configuration accessors and settings-tab registration.
 *
 * Read plugin configuration through these methods rather than calling
 * get_option() directly, so the API-key constant override stays in one place.
 */
class WC_Signifyd_Settings {

	/** Option holding the Signifyd team API key. */
	const OPTION_KEY = 'wc_signifyd_api_key';

	/** Option holding the gateway ids eligible for screening. */
	const OPTION_ELIGIBLE_GWS = 'wc_signifyd_gateways';

	/** Option selecting which order event creates the case. */
	const OPTION_CREATE_ON = 'wc_signifyd_create_on';

	/**
	 * Hook the settings tab into WooCommerce.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'register_page' ) );
	}

	/**
	 * Append the Signifyd tab to WooCommerce's settings pages.
	 *
	 * The settings-page class is required here rather than at load time
	 * because it extends WC_Settings_Page, which does not exist until
	 * WooCommerce builds its settings screen.
	 *
	 * @param array $pages Existing WooCommerce settings pages.
	 * @return array
	 */
	public static function register_page( $pages ) {
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-settings-page.php';
		$pages[] = new WC_Signifyd_Settings_Page();
		return $pages;
	}

	/**
	 * Resolve the API key.
	 *
	 * A WC_SIGNIFYD_API_KEY constant in wp-config.php wins over the stored
	 * option, so deployments can keep the credential out of the database.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'WC_SIGNIFYD_API_KEY' ) && WC_SIGNIFYD_API_KEY !== '' ) {
			return (string) WC_SIGNIFYD_API_KEY;
		}
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Whether the API key comes from the constant rather than the database.
	 *
	 * Drives the settings screen, which disables the key field and explains
	 * why when this is true.
	 *
	 * @return bool
	 */
	public static function key_is_from_constant() {
		return defined( 'WC_SIGNIFYD_API_KEY' ) && WC_SIGNIFYD_API_KEY !== '';
	}

	/**
	 * Payment gateway ids eligible for Signifyd screening.
	 *
	 * Tolerates a comma-separated string as well as an array, because the
	 * option predates the multiselect field and older installs may still
	 * hold the string form. An empty or malformed value falls back to the
	 * default rather than screening nothing.
	 *
	 * @return string[]
	 */
	public static function eligible_gateways() {
		$stored = get_option( self::OPTION_ELIGIBLE_GWS, array( 'moneris' ) );
		if ( is_string( $stored ) ) {
			$stored = array_filter( array_map( 'trim', explode( ',', $stored ) ) );
		}
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$stored = array( 'moneris' );
		}
		return apply_filters( 'wc_signifyd_eligible_gateways', $stored );
	}

	/**
	 * Which order event triggers case creation.
	 *
	 * @return string 'payment_complete' or 'processing'
	 */
	public static function create_on() {
		return (string) get_option( self::OPTION_CREATE_ON, 'payment_complete' );
	}

	/**
	 * Webhook endpoint URL, for display and for registration with Signifyd.
	 *
	 * Built with rest_url() so it reflects the site's actual REST base,
	 * including installs that have relocated or filtered it.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'wc-signifyd/v1/webhook' );
	}
}
