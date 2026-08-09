<?php
/**
 * Plugin Name:       Fraud Screening for WooCommerce with Signifyd
 * Plugin URI:        https://github.com/choiceomg/woocommerce-signifyd
 * Description:       Fraud screening for WooCommerce via Signifyd. Creates cases server-side, receives decisions by signed webhook, and lets staff close cases or purchase a guarantee from the order screen.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Choice OMG
 * Author URI:        https://choice.marketing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fraud-screening-for-woocommerce-with-signifyd
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   11.0
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/** Plugin version, also used to cache-bust the admin CSS and JS. */
define( 'WC_SIGNIFYD_VERSION', '1.1.0' );

/** Absolute path to this file, required by the HPOS compatibility declaration. */
define( 'WC_SIGNIFYD_FILE', __FILE__ );

/** Filesystem path to the plugin directory, with a trailing slash. */
define( 'WC_SIGNIFYD_PATH', plugin_dir_path( __FILE__ ) );

/** Public URL of the plugin directory, with a trailing slash. */
define( 'WC_SIGNIFYD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin bootstrap and shared service container.
 *
 * Responsible for load order only: declaring HPOS compatibility early enough
 * for WooCommerce to see it, deferring every include until WooCommerce is
 * confirmed active, and owning the single shared API client. Feature logic
 * lives in the WC_Signifyd_* classes under includes/.
 */
final class WC_Signifyd {

	/**
	 * Lazily-constructed shared API client.
	 *
	 * @var WC_Signifyd_API|null
	 */
	protected static $api = null;

	/**
	 * Register the two bootstrap hooks.
	 *
	 * Called at file scope on every request. The compatibility declaration
	 * must run on before_woocommerce_init, which fires earlier than
	 * plugins_loaded, so the two stages cannot be collapsed into one hook.
	 */
	public static function init() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load' ) );
	}

	/**
	 * Declare High-Performance Order Storage compatibility.
	 *
	 * All order data access goes through the WooCommerce CRUD API, so the
	 * plugin works with both the legacy post storage and HPOS.
	 */
	public static function declare_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WC_SIGNIFYD_FILE,
				true
			);
		}
	}

	/**
	 * Load the plugin once WooCommerce is known to be present.
	 *
	 * Runs on plugins_loaded. Every include is deferred to here rather than
	 * to file scope because several classes extend or call WooCommerce types
	 * that do not exist until WooCommerce itself has loaded. Admin-only code
	 * is loaded behind is_admin() so front-end requests do not pay for it.
	 */
	public static function load() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing_woocommerce' ) );
			return;
		}

		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-logger.php';
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-api.php';
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-settings.php';
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-case-builder.php';
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-orders.php';
		require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-webhook.php';

		WC_Signifyd_Settings::init();
		WC_Signifyd_Orders::init();
		WC_Signifyd_Webhook::init();

		if ( is_admin() ) {
			require_once WC_SIGNIFYD_PATH . 'includes/class-wc-signifyd-admin.php';
			WC_Signifyd_Admin::init();
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing_key' ) );
		}

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	/**
	 * Load translations from the bundled languages directory.
	 *
	 * Hooked to init rather than called directly, because WordPress requires
	 * the current locale to be resolved before translations are registered.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'fraud-screening-for-woocommerce-with-signifyd', false, dirname( plugin_basename( WC_SIGNIFYD_FILE ) ) . '/languages' );
	}

	/**
	 * Shared API client, constructed on first use.
	 *
	 * Every class calls this rather than instantiating WC_Signifyd_API
	 * directly, so the API key is resolved once per request and one client
	 * is reused across case creation, the webhook receiver, and the admin
	 * AJAX handlers.
	 *
	 * @return WC_Signifyd_API
	 */
	public static function api() {
		if ( self::$api === null ) {
			self::$api = new WC_Signifyd_API( WC_Signifyd_Settings::api_key() );
		}
		return self::$api;
	}

	/**
	 * Admin notice shown when the plugin is active but WooCommerce is not.
	 *
	 * Registered from load() on the branch where the WooCommerce class is
	 * missing, which is also the branch where nothing else is loaded.
	 */
	public static function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Signifyd for WooCommerce requires WooCommerce to be installed and active.', 'fraud-screening-for-woocommerce-with-signifyd' );
		echo '</p></div>';
	}

	/**
	 * Admin notice shown when no API key is configured.
	 *
	 * Without a key the plugin silently creates no cases, so this is the only
	 * cue an operator gets that screening is inactive. Suppressed for users
	 * who cannot fix it, and on the settings screen that fixes it.
	 */
	public static function notice_missing_key() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( WC_Signifyd_Settings::api_key() !== '' ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && $screen->id === 'woocommerce_page_wc-settings' ) {
			return; // do not nag on the screen that fixes it
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Signifyd for WooCommerce has no API key configured, so no cases are being created.', 'fraud-screening-for-woocommerce-with-signifyd' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=signifyd' ) ),
			esc_html__( 'Add your API key', 'fraud-screening-for-woocommerce-with-signifyd' )
		);
	}
}

// Register the bootstrap hooks. Safe at file scope: init() only calls
// add_action(), and touches no WooCommerce code.
WC_Signifyd::init();
