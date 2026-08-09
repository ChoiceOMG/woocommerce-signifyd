# Changelog

## 1.2.0 (2026-08-08)

- Renamed the plugin to "Fraud Screening for WooCommerce with Signifyd" for
  WordPress.org compliance. Directory listing rules prohibit leading a plugin
  slug with someone else's trademark, so `signifyd-for-woocommerce` would have
  been rejected or force-renamed at review. The text domain moved to the
  matching `fraud-screening-for-woocommerce-with-signifyd` (WordPress.org
  requires text domain and slug to be identical for language-pack delivery),
  and the main plugin file was renamed to match. Internal naming is
  deliberately unchanged: classes stay `WC_Signifyd_*`, hooks and options stay
  `wc_signifyd_*`, and the REST namespace stays `wc-signifyd/v1`, so no
  integration and no already-registered webhook URL breaks.
- Added `.distignore` so the released package excludes development files.
  WordPress.org's Plugin Check reports hidden files as an error and
  unexpected root markdown as a warning, both of which the raw repository
  tree would have triggered.
- Added `languages/fraud-screening-for-woocommerce-with-signifyd.pot`
  (55 strings). The plugin header has always declared `Domain Path:
  /languages`, but no template was shipped.
- Refreshed compatibility metadata after testing: `Tested up to: 7.0` (was
  6.7) and `WC tested up to: 11.0` (was 10.3).
- Documented why the case-id lookup in `find_by_case_id()` uses a
  meta_key/meta_value query, with targeted `phpcs:ignore` annotations.
  WooCommerce exposes no indexed lookup for arbitrary order meta; the query
  is bounded by `limit => 1` and never runs on the storefront path.

- Fixed the AVS-mapping comment written by `WC_Signifyd_Case_Builder::build()`
  never being persisted: the order object it was written to was discarded and
  re-read from the database before anything called `save()`, so the metabox's
  AVS-mapping line was always empty. `build()` now saves that meta
  immediately.
- Fixed the case-creation lock in `WC_Signifyd_Orders::maybe_create_case()`
  being ineffective on any site with a persistent object cache configured
  (common on production WooCommerce stores): `set_transient()` defers entirely
  to the object cache in that setup and always reports success, so it could
  not prevent two near-simultaneous hook firings from creating duplicate
  Signifyd cases for the same order. Replaced it with an `add_option()`-based
  lock, which stays atomic at the database level regardless of object cache
  configuration, with the same self-healing timeout as before.
- Stopped writing a truncated copy of failed API responses into the persistent
  WooCommerce log; only the HTTP status code is logged now. The detailed
  message is still surfaced to the staff member who triggered the request.
  This brings the code in line with the "never logs request or response
  bodies" claim already made in README.md and readme.txt.
- Documented four filters that existed in code but were missing from
  README.md's hook list: `wc_signifyd_eligible_gateways`,
  `wc_signifyd_default_shipper`, `wc_signifyd_tracking_number`, and
  `wc_signifyd_case_payload`.
- Minor WPCS whitespace fix in the case-builder's product array (missing space
  around one `=>`).
- Corrected the card-expiry comment in the case builder. It described the
  accepted formats as "YY/MM or YYMM", which understated the constraint: the
  regex requires year-first ordering, so a gateway storing `MM/YY` parses
  without error and silently swaps month and year. The comment now states the
  ordering requirement and points at the `wc_signifyd_case_payload` filter as
  the correct place to fix it.
- Documented every function, class, constant, and property in the plugin
  (53 functions, 19 constants and properties, 9 classes). The docblocks
  record behavior that is not visible from a signature: that
  `WC_Signifyd_Admin::validate_request()` terminates the request rather than
  returning on failure, the full response-code contract of the webhook
  handler and why an unknown case id answers 200, why the webhook route's
  `permission_callback` is `__return_true`, that webhook signature
  verification requires the raw unmodified request body, and the constraint
  on what may appear in a log message.
- Added file headers and inline commentary to `assets/js/admin.js` and
  `assets/css/admin.css`. No behavior change.
- Added `docs/ARCHITECTURE.md`, `CONTRIBUTING.md`, and `AGENTS.md`.

## 1.1.0

- Rewrote the API client on top of the WordPress HTTP API, removing the bundled
  Signifyd PHP SDK and its vendored dependencies entirely.
- Added High-Performance Order Storage (HPOS) compatibility; all order access now
  goes through the WooCommerce CRUD API instead of post meta functions.
- Made the payment-gateway meta mapping and AVS code mapping filterable
  (`wc_signifyd_gateway_meta_map`, `wc_signifyd_avs_map`) instead of hardcoded to one
  gateway.
- Added a WooCommerce settings tab (API key, screened gateways, case-creation
  trigger) in place of hardcoded configuration.
- Added a "Purchase guarantee" button to the order metabox, alongside a new
  "Refresh" button that re-fetches case data on demand.
- Replaced globally-scoped functions with prefixed classes to avoid collisions with
  other plugins.
- Switched webhook signature comparison to `hash_equals()` for timing-safe
  verification.
- Routed all logging through the WooCommerce logger; the plugin no longer writes
  its own log files.
- Added translator comments and a text domain for full i18n support.

## 1.0.0

- Initial internal version: server-side case creation, signed webhook receiver,
  order metabox with view/close actions.
