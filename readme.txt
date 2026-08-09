=== Fraud Screening for WooCommerce with Signifyd ===
Contributors: choiceomg
Tags: woocommerce, fraud, signifyd, chargebacks, fraud prevention
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fraud screening for WooCommerce with Signifyd. Creates cases automatically, receives signed webhook decisions, and adds order-screen controls.

== Description ==

There is no official Signifyd plugin for WooCommerce. This plugin integrates
WooCommerce directly with Signifyd's Cases API (V2) using WordPress's own HTTP
client, with no bundled SDK.

= Features =

* Creates a Signifyd case automatically when an eligible order is paid
* Verifies signed webhooks (HMAC-SHA256) and stores the resulting score and
  disposition on the order
* Order-screen metabox for staff: view case, refresh, close case, purchase
  guarantee
* Filterable payment-gateway meta mapping, so it is not tied to one gateway
* HPOS compatible

= What it does not do =

* Never touches card numbers or CVVs
* Never logs request or response bodies
* Never changes order status automatically; use the `wc_signifyd_case_updated`
  action to build your own policy

== Installation ==

1. Upload the plugin to `wp-content/plugins/fraud-screening-for-woocommerce-with-signifyd/`.
2. Activate it through the Plugins screen.
3. Go to WooCommerce > Settings > Signifyd and enter your API key.
4. Register the webhook URL shown there in the Signifyd console.

== Frequently Asked Questions ==

= Does this support the Signifyd V3 Decisions API? =

No, not yet. It targets the V2 Cases API, which remains supported for existing
Signifyd teams. Porting to V3 would mainly touch includes/class-wc-signifyd-api.php.

= Does this store credit card numbers? =

No. It reads only the risk-signal fields your payment gateway already stores
(AVS/CVV match results, card BIN, last four digits, expiry), never the PAN or CVV
itself.

== Changelog ==

= 1.1.0 =
* Removed the bundled SDK in favor of the WordPress HTTP API
* HPOS compatibility
* Filterable gateway meta map and AVS map
* Added WooCommerce settings tab and Purchase Guarantee button

= 1.0.0 =
* Initial internal version
