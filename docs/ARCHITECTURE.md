# Architecture

The plugin is nine PHP classes, three runtime flows, and ten public hooks.
Those are the three sections below: file-by-file responsibility, each flow as
a numbered sequence, and a hook reference table kept in exact sync with the
code.

Familiarity with WordPress plugin development and basic WooCommerce concepts
(orders, order meta, the settings API) is assumed. For what the plugin is and
why it exists, see the root [README.md](../README.md); for how to work on it,
see [CONTRIBUTING.md](../CONTRIBUTING.md).

## File-by-file responsibility

- **`fraud-screening-for-woocommerce-with-signifyd.php`**: Bootstrap. Defines the plugin constants, declares HPOS
  (custom order tables) compatibility on `before_woocommerce_init`, and loads
  the rest of the plugin on `plugins_loaded` once WooCommerce is confirmed
  active. Holds the shared `WC_Signifyd_API` instance behind `WC_Signifyd::api()`
  so every class talks to Signifyd through one client built with one resolved
  API key. Also renders the "no API key configured" and "WooCommerce missing"
  admin notices.

- **`includes/class-wc-signifyd-api.php`**: The Signifyd V2 API client. One
  private `request()` method wraps `wp_remote_request()` with auth headers,
  timeout, JSON encode/decode, and error handling; every public method
  (`create_case`, `get_case`, `close_case`, `create_guarantee`,
  `cancel_guarantee`) is a thin wrapper around it. Also owns webhook signature
  verification (`is_valid_webhook()`), since that's the other half of talking
  to the same API.

- **`includes/class-wc-signifyd-orders.php`**: Decides which orders get
  screened and when, creates the case, and is the single place that writes
  Signifyd data onto an order (`store_case_data()`), called by both the
  webhook handler and the admin AJAX handlers. Defines the order-meta key
  constants everything else reads by reference rather than by literal string.
  All order access goes through `wc_get_order()` / `WC_Order` methods /
  `wc_get_orders()`, never `get_post_meta()` or a post ID, which is what makes
  the plugin HPOS-safe.

- **`includes/class-wc-signifyd-webhook.php`**: Registers the
  `wc-signifyd/v1/webhook` REST route and handles inbound Signifyd events.
  The route itself has no WordPress-level auth (`permission_callback` is
  `__return_true`, since Signifyd cannot present a WordPress nonce or cookie);
  the HMAC signature check inside `handle()` is the actual authentication.

- **`includes/class-wc-signifyd-case-builder.php`**: Pure(ish) payload
  construction. `build()` turns a `WC_Order` into the JSON structure the V2
  Cases API expects. Card-adjacent fields (AVS result, CVV result, BIN, last
  four, expiry) are read from order meta through a filterable key map rather
  than hardcoded meta keys, so the plugin isn't welded to one gateway. Defaults
  target the WooCommerce Moneris gateway, the only integration this plugin has
  been run against in production.

- **`includes/class-wc-signifyd-admin.php`**: The order-screen metabox (score,
  disposition, and the four action buttons) and the three AJAX handlers behind
  three of those buttons. `validate_request()` is the shared nonce and
  capability guard every handler calls first.

- **`includes/class-wc-signifyd-settings.php`**: Static accessors for plugin
  configuration (`api_key()`, `eligible_gateways()`, `create_on()`,
  `webhook_url()`) and registration of the settings tab with WooCommerce.
  Nothing else in the plugin reads a `get_option()` call directly; it goes
  through this class so the API-key-from-constant override
  (`WC_SIGNIFYD_API_KEY`) only has to be handled in one place.

- **`includes/class-wc-signifyd-settings-page.php`**: The
  `WC_Settings_Page` subclass that renders WooCommerce > Settings > Signifyd.
  Field definitions only; WooCommerce's settings API handles the actual
  form rendering, saving, and nonce checking.

- **`includes/class-wc-signifyd-logger.php`**: A three-method wrapper
  (`info`/`warning`/`error`) around `wc_get_logger()`. Exists so nothing else
  in the plugin has to know WooCommerce's logging API, and so log source
  filtering (`WooCommerce > Status > Logs`, source `signifyd`) stays
  consistent. Deliberately never writes its own files and never receives full
  request or response bodies (see the "Hard rules" section of
  [AGENTS.md](../AGENTS.md)).

- **`assets/js/admin.js`**: One delegated click handler for `.wc-signifyd-action`
  buttons. Reads the action name, case id, and nonce off `data-*` attributes,
  optionally confirms, then posts to `admin-ajax.php`.

- **`assets/css/admin.css`**: Metabox layout only, no behavior.

## Request flows

### 1. Order paid, case created

1. WooCommerce fires `woocommerce_payment_complete` (default) or
   `woocommerce_order_status_processing` (if configured on the settings
   screen), and separately always fires `woocommerce_thankyou` as a fallback
   for gateway flows that skip the primary event.
2. `WC_Signifyd_Orders::maybe_create_case( $order_id )` runs. It returns early
   if the order already has a case, isn't eligible for screening
   (`wc_signifyd_order_is_eligible`), or no API key is configured.
3. It takes a lock (`add_option()` on a per-order key) so two hooks firing for
   the same order in close succession can't both create a case, and marks the
   order as "creating" in order meta.
4. `WC_Signifyd_Case_Builder::build( $order )` assembles the case payload and
   persists the AVS-mapping comment it computes along the way.
5. `WC_Signifyd_API::create_case()` POSTs to `cases` and returns an
   `investigationId`, or `null` on failure (logged, not thrown).
6. On success, the plugin re-reads the order, stores the case id, and adds an
   order note. Either way, the "creating" meta and the lock are cleared.

### 2. Webhook received, order updated

1. Signifyd POSTs to `/wp-json/wc-signifyd/v1/webhook`.
2. `WC_Signifyd_Webhook::handle()` reads the raw body and the
   `X-SIGNIFYD-SEC-HMAC-SHA256` / `X-SIGNIFYD-TOPIC` headers (tolerating both
   the `X-`-prefixed and bare header spellings).
3. An empty hash or body is treated as an unsigned reachability probe and
   answered with 200 immediately, no further processing.
4. `WC_Signifyd_API::is_valid_webhook()` checks the HMAC against the team API
   key, and, only for the `cases/test` topic, additionally against the fixed
   test key `ABCDE` that Signifyd's console-initiated test webhook uses. An
   invalid signature gets logged and a 403.
5. A verified `cases/test` webhook is logged and answered 200 without touching
   any order (it carries no real case id).
6. The body is parsed as JSON and the case id read from `caseId` (V2) or
   `signifydId` (V3, in case a store's payload ever includes it), then matched
   to an order via `WC_Signifyd_Orders::find_by_case_id()`.
7. An unmatched case id is logged and answered 200 anyway, so Signifyd stops
   retrying a case this store has no record of.
8. A match is passed to `store_case_data()`, which updates score, disposition,
   and topic meta, adds an order note if the disposition changed, saves, and
   fires `wc_signifyd_case_updated`.

### 3. Staff clicks a metabox button

1. `admin.js`'s click handler reads `data-action`, `data-caseid`, and
   `data-nonce` off the button and posts them to `admin-ajax.php` alongside
   jQuery's default `_ajax_nonce` field name.
2. WordPress routes the request by `action` to one of
   `wp_ajax_wc_signifyd_refresh_case`, `wp_ajax_wc_signifyd_close_case`, or
   `wp_ajax_wc_signifyd_purchase_guarantee` (View Case is a plain link, not an
   AJAX call, since it just opens the Signifyd console).
3. `WC_Signifyd_Admin::validate_request()` checks the `wc_signifyd_actions`
   nonce, the `edit_shop_orders` capability, and that `caseid` is present and
   numeric, before any handler-specific code runs.
4. The handler calls the matching `WC_Signifyd_API` method (`get_case`,
   `close_case`, or `create_guarantee`).
5. On failure, `wp_send_json_error()` returns the API's error message with a
   502 status.
6. On success, the handler looks the order up by case id, updates its meta and
   adds a note, logs the action, and `wp_send_json_success()`'s a message.
7. The JS shows that message in an alert and reloads the page so the metabox
   reflects the new state.

## Hook and filter reference

All hooks are prefixed `wc_signifyd_`. This table is meant to stay in exact
sync with the code; if you add, rename, or remove a hook, update both this
table and the "Extending it" section of [README.md](../README.md) in the same
change.

| Name | Type | Fired in | Arguments | Purpose |
|---|---|---|---|---|
| `wc_signifyd_gateway_meta_map` | filter | `WC_Signifyd_Case_Builder::meta_map()` | `array $map` | Map case-builder field names (`avs`, `cvv`, `bin`, `last_four`, `card_expiry`) to the order meta keys your payment gateway stores them under. |
| `wc_signifyd_avs_map` | filter | `WC_Signifyd_Case_Builder::map_avs()` | `array $map` | Map your gateway's raw AVS response codes onto Signifyd's single-letter AVS vocabulary. |
| `wc_signifyd_default_shipper` | filter | `WC_Signifyd_Case_Builder::build()` | `null` or `string $shipper`, `WC_Order $order` | Supply a shipping carrier name for the case payload; WooCommerce core has no such field, so this is `null` unless filtered. |
| `wc_signifyd_tracking_number` | filter | `WC_Signifyd_Case_Builder::build()` | `null` or `string $tracking_number`, `WC_Order $order` | Supply a shipment tracking number for the same reason as above. |
| `wc_signifyd_case_payload` | filter | `WC_Signifyd_Case_Builder::build()` | `array $case, WC_Order $order` | Last-chance override of the complete case payload right before it's submitted, for anything the more specific filters above don't cover. |
| `wc_signifyd_order_is_eligible` | filter | `WC_Signifyd_Orders::is_eligible()` | `bool $eligible, WC_Order $order` | Override whether a specific order should be screened at all. |
| `wc_signifyd_eligible_gateways` | filter | `WC_Signifyd_Settings::eligible_gateways()` | `array $gateway_ids` | Override the list of payment gateway ids eligible for screening, normally set on the settings screen. |
| `wc_signifyd_case_updated` | action | `WC_Signifyd_Orders::store_case_data()` | `WC_Order $order, array $case, string $topic` | Fires whenever case data is stored on an order, from a webhook or a manual refresh. Intended for custom policy such as holding or cancelling an order on a `DECLINED` disposition; the plugin itself never changes order status. |
| `wc_signifyd_settings` | filter | `WC_Signifyd_Settings_Page::get_settings()` | `array $settings` | Modify the full WooCommerce settings-API field array for the Signifyd tab. |
| `wc_signifyd_request_timeout` | filter | `WC_Signifyd_API::request()` | `int $timeout` | Override the HTTP timeout, in seconds, used for every Signifyd API call. Defaults to 30. |

## Known limitations

- **Card expiry parsing assumes `YYMM`.** `WC_Signifyd_Case_Builder::build()`
  parses the gateway's stored expiry string with a regex that assumes a
  4-digit `YYMM` value, matching the Moneris gateway's own storage format. A
  gateway that stores `MM/YY` instead will get its month and year swapped.
  The meta *key* for expiry is filterable (`wc_signifyd_gateway_meta_map`),
  but the value *format* is not; a gateway using a different format should
  correct `card.expiryMonth` / `card.expiryYear` via the `wc_signifyd_case_payload`
  filter rather than expect the regex itself to change.
- **Webhook signatures have no replay protection.** Signifyd's V2 HMAC scheme
  signs the request body with a static key and includes no timestamp or
  nonce, so a captured valid webhook payload could in principle be replayed.
  Signifyd's V2 API defines that scheme, so the plugin cannot fix it
  unilaterally. `store_case_data()` is idempotent (writing the same case data
  twice is harmless), which limits the practical impact.
