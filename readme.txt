=== Fraud Screening for WooCommerce with Signifyd ===
Contributors: choiceomg
Tags: woocommerce, signifyd, fraud, chargebacks, fraud prevention
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fraud screening for WooCommerce with Signifyd. Creates cases automatically, receives signed webhook decisions, and adds order-screen controls.

== Description ==

Signifyd has no official WooCommerce integration. It ships supported plugins for
Magento 2, Salesforce Commerce Cloud, and BigCommerce, leaving WooCommerce stores
to build their own. This plugin is that integration, written directly against
Signifyd's REST API using WordPress's own HTTP client.

When an eligible order is paid, the plugin builds a case from the order and
submits it to Signifyd for screening. Signifyd returns its risk score and
guarantee decision over a signed webhook, which the plugin verifies and stores on
the order. Staff get a compact panel on the order screen showing the score and
disposition, with buttons to refresh the case, dismiss it, or purchase Signifyd's
financial guarantee.

= Features =

* Creates a Signifyd case automatically when an eligible order is paid
* Verifies signed webhooks (HMAC-SHA256) and stores the resulting score and
  guarantee disposition on the order
* Order-screen panel for staff: view case, refresh, close case, purchase guarantee
* Filterable payment-gateway meta mapping, so the plugin is not tied to one gateway
* Compatible with High-Performance Order Storage (HPOS)
* No bundled SDK and no vendor dependencies; the API client is a few hundred lines
  on top of the WordPress HTTP API
* API key can live in `wp-config.php` instead of the database

= Choosing which orders get screened =

Screening is limited to the payment gateways you select on the settings screen,
so only credit-card orders are sent. You choose whether the case is created on
payment completion or when the order reaches processing status. A filter
(`wc_signifyd_order_is_eligible`) lets you refine that per order.

= What it deliberately does not do =

* It never handles card numbers or CVV values. Only risk-signal fields your
  gateway has already stored are read: AVS and CVV match results, card BIN, last
  four digits, and expiry.
* It never writes request or response bodies to logs. Logging goes through the
  WooCommerce logger and records order IDs, case IDs, and status codes only.
* It never changes order status on its own. A `DECLINED` guarantee stores the
  disposition and fires the `wc_signifyd_case_updated` action so each store can
  apply its own policy, rather than cancelling orders behind your back.

= Which Signifyd API this uses =

This plugin calls the Signifyd **V2 Cases API**. Signifyd's current documentation
promotes the V3 Decisions API for new integrations, and V2 remains available to
existing teams. Confirm with your Signifyd account team which API version your
credentials are provisioned for before installing.

= Extending it =

Ten filters and actions are available, covering gateway meta mapping, AVS code
translation, order eligibility, the full case payload, request timeout, and a
post-update action for custom workflow. The full reference lives in
`docs/ARCHITECTURE.md` in the source repository.

== External services ==

This plugin connects to Signifyd, a third-party fraud-screening service. It is
not usable without a Signifyd account, because screening happens on Signifyd's
servers rather than in WordPress.

**When the plugin contacts Signifyd**

* When an eligible order is paid, to create a fraud case.
* When a staff member clicks Refresh, Close Case, or Purchase Guarantee on the
  order screen.

Signifyd also sends data *to* your site, over a signed webhook, when a case is
created, rescored, reviewed, or a guarantee completes.

**What is sent**

Creating a case transmits the information Signifyd needs to assess risk:

* Order details: order ID, order key, total, currency, creation time, payment
  gateway, and transaction ID
* Line items: product IDs, names, URLs, images, quantities, and prices
* Customer details: name, email address, phone number, billing address, shipping
  address, and the IP address recorded on the order
* Account details, for registered customers: username, email, account ID, and
  registration date
* Payment risk signals: the gateway's AVS and CVV match results, plus the card
  BIN, last four digits, and expiry month and year

Full card numbers and CVV values are never transmitted; the plugin does not have
access to them.

Other calls (refresh, close, guarantee) transmit only the Signifyd case ID.

**Service, terms, and privacy policy**

* Service: [Signifyd](https://www.signifyd.com/)
* Terms of service: [https://www.signifyd.com/terms/](https://www.signifyd.com/terms/)
* Privacy policy: [https://www.signifyd.com/privacy/](https://www.signifyd.com/privacy/)

Because customer personal data is transmitted to a third party, review your own
privacy policy and any applicable data-protection obligations (GDPR, CCPA, PIPEDA
and similar) before enabling this plugin on a live store.

== Installation ==

1. Upload the plugin to `wp-content/plugins/fraud-screening-for-woocommerce-with-signifyd/`, or install it from the Plugins screen.
2. Activate it through the Plugins screen. WooCommerce must be active.
3. Go to **WooCommerce > Settings > Signifyd** and enter your Signifyd team API key.
4. On the same screen, choose which payment gateways are screened and which order event creates the case.
5. Copy the webhook URL shown on that screen into the Signifyd console under Settings > Webhooks, subscribed to at least Case Creation, Case Rescore, Case Review, and Guarantee Completion.

= Keeping the API key out of the database =

Define it in `wp-config.php` instead of using the settings field:

`define( 'WC_SIGNIFYD_API_KEY', 'your-team-api-key' );`

The constant overrides the stored option, and the settings field is shown
disabled with a note explaining why.

== Frequently Asked Questions ==

= Do I need a Signifyd account? =

Yes. Signifyd performs the screening, so the plugin cannot function without an
account and an API key. See the External services section for what is sent.

= Does this support the Signifyd V3 Decisions API? =

Not currently. It targets the V2 Cases API, which remains available to existing
Signifyd teams. Porting to V3 would mainly affect the API client.

= Does this store or transmit credit card numbers? =

No. It reads only the risk-signal fields your payment gateway has already stored
on the order: AVS and CVV match results, card BIN, last four digits, and expiry.
The full card number and the CVV value never pass through the plugin.

= Will it cancel orders that Signifyd declines? =

No. The plugin stores the score and disposition and fires the
`wc_signifyd_case_updated` action. Acting on a declined guarantee is left to your
own code or workflow, so the plugin never cancels or holds an order on its own.

= My payment gateway is not Moneris. Will this work? =

Yes, with a small amount of configuration. The order meta keys the plugin reads
card risk signals from are filterable through `wc_signifyd_gateway_meta_map`, and
AVS code translation through `wc_signifyd_avs_map`. The defaults target the
WooCommerce Moneris gateway because that is the gateway the plugin has run
against in production.

= Is it compatible with High-Performance Order Storage? =

Yes. All order access goes through the WooCommerce CRUD API, and the plugin
declares HPOS compatibility. It works with both legacy post storage and HPOS.

= Does purchasing a guarantee cost money? =

Purchasing a guarantee is billable on your Signifyd account, so the button asks
for confirmation before submitting.

== Changelog ==

= 1.2.0 =
* Fixed the AVS mapping comment never being saved, which left that line blank in the order panel on every order.
* Fixed the duplicate-case guard being ineffective on sites with a persistent object cache, where it could allow two Signifyd cases to be created for one order.
* Stopped writing Signifyd error-response bodies into the WooCommerce log; only the HTTP status code is recorded now.
* Renamed the plugin for WordPress.org directory compliance. Internal hooks, options, and the webhook URL are unchanged.
* Added a translation template, documented every function and class, and added architecture and contributor documentation.
* Refreshed compatibility: tested against WordPress 7.0 and WooCommerce 11.0.

= 1.1.0 =
* Removed the bundled SDK in favor of the WordPress HTTP API
* HPOS compatibility
* Filterable gateway meta map and AVS map
* Added WooCommerce settings tab and Purchase Guarantee button

= 1.0.0 =
* Initial internal version

== Upgrade Notice ==

= 1.2.0 =
Fixes a duplicate-case bug affecting sites with a persistent object cache, and stops error-response bodies reaching the log. Hooks and webhook URL are unchanged; no reconfiguration needed.
