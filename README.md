# Fraud Screening for WooCommerce with Signifyd

Fraud screening for WooCommerce via [Signifyd](https://www.signifyd.com/). Creates a case
server-side after payment, receives the decision back over a signed webhook, and gives
staff a small metabox on the order screen to view the case, refresh it, close it, or
purchase a guarantee.

There is no official Signifyd plugin for WooCommerce (Signifyd ships official
integrations for Magento 2, Salesforce Commerce Cloud, and BigCommerce, but not
WooCommerce). This plugin fills that gap directly against Signifyd's REST API using
WordPress's own HTTP client, with no bundled SDK and no vendor dependencies.

## What it targets

This plugin calls the Signifyd **V2 Cases API**. Signifyd's current documentation
promotes the V3 Decisions API for new integrations; V2 remains live and supported for
existing teams as of this writing, but V3 is where new capability will land. If your
Signifyd contract is V3-only, this plugin will need porting before it works. Check with
your Signifyd account team if you're unsure which API version your credentials use.

## Requirements

- WordPress 6.0+
- WooCommerce 6.0+ (tested through 11.0), HPOS-compatible
- PHP 7.4+
- A Signifyd account with API access

## Installation

1. Copy this plugin into `wp-content/plugins/fraud-screening-for-woocommerce-with-signifyd/` and activate it.
2. Go to **WooCommerce > Settings > Signifyd** and enter your Signifyd team API key,
   or define it in `wp-config.php` instead (see below).
3. In the same settings screen, choose which payment gateways should be screened
   (only credit-card gateways should be in this list) and which order event creates
   the case (payment complete, or order status: processing).
4. Copy the webhook URL shown on that settings screen into the Signifyd console
   (Settings > Webhooks), subscribed to at least Case Creation, Case Rescore, Case
   Review, and Guarantee Completion.

### Keeping the API key out of the database

Define it as a constant instead of using the settings field:

```php
define( 'WC_SIGNIFYD_API_KEY', 'your-team-api-key' );
```

When this constant is set it overrides the stored option, and the settings field is
shown disabled with a note explaining why.

## Staff workflow

The metabox appears on both the classic order-edit screen and the HPOS order screen.
It shows the case score, disposition, and last-updated time, and offers:

- **View case** - open the case in the Signifyd console
- **Refresh** - re-fetch the latest case data on demand
- **Close case** - dismiss the case in Signifyd
- **Purchase guarantee** - submit the order for Signifyd's financial guarantee

Purchasing or cancelling a guarantee has billing consequences on your Signifyd
account; the button asks for confirmation before submitting.

## Extending it

The plugin is deliberately generic about payment gateways. Two filters carry the
gateway-specific mapping:

- `wc_signifyd_gateway_meta_map` - maps case-builder fields (AVS response, CVV
  response, card BIN, last four, expiry) to the order meta keys your gateway stores
  them under. Defaults to the meta keys used by the WooCommerce Moneris gateway.
- `wc_signifyd_avs_map` - maps your gateway's AVS response codes to the single-letter
  codes Signifyd's API expects.

Other useful hooks:

- `wc_signifyd_order_is_eligible` - filter whether a specific order gets screened at all
- `wc_signifyd_eligible_gateways` - filter the list of gateway ids eligible for
  screening, read from the settings screen by default
- `wc_signifyd_case_updated` - action fired whenever case data is stored on an order
  (webhook or manual refresh); use this to drive custom workflow such as holding an
  order on a DECLINED disposition. The plugin itself never changes order status.
- `wc_signifyd_settings` - filter the full WooCommerce settings array for the tab
- `wc_signifyd_request_timeout` - filter the HTTP timeout (seconds) used for API calls
- `wc_signifyd_default_shipper` - filter the shipper name reported in the case payload
  (null by default; WooCommerce core has no shipping-carrier field)
- `wc_signifyd_tracking_number` - filter the tracking number reported in the case
  payload (null by default, for the same reason)
- `wc_signifyd_case_payload` - filter the complete case payload right before it is
  submitted to Signifyd, for changes the other filters above do not cover

## What this plugin does not do

- It does not touch payment card data. Card numbers and CVVs never pass through this
  plugin; it reads only the risk-signal fields (AVS/CVV match results, card BIN, last
  four, expiry) that your gateway already stores on the order.
- It does not log request or response bodies. All logging goes through WooCommerce's
  own logger (`WooCommerce > Status > Logs`, source `signifyd`) and contains only
  order IDs, case IDs, and error messages.
- It does not automatically cancel, hold, or refund orders on a bad disposition. That
  decision is left to the `wc_signifyd_case_updated` action so each store can apply
  its own policy.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
