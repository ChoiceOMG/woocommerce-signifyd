# Changelog

## 1.2.2 (2026-08-15)

- Renamed the plugin to "Riskloom Fraud Screening for Signifyd", slug
  `riskloom-fraud-screening-for-signifyd`, in response to the WordPress.org
  pre-review (ID `P0TDX352313HGN`, flagged TRM). The 1.2.0 name satisfied the
  trademark-placement rule by putting Signifyd at the end after "with", but
  failed the separate distinctiveness rule: "Fraud Screening" is generic and
  sits close to existing listings such as "Antiro Order Risk Screening for
  WooCommerce". Reviewers require a distinguishing term at the *front* of the
  name, and state explicitly that adding a generic qualifier does not satisfy
  it. "Riskloom" is coined, matching the pattern the review team endorses.
  Verified unused as a plugin slug, absent from the plugin directory, and free
  of any company or product collision. An earlier candidate, "Casewire", was
  rejected during this work because CaseWare International is an active
  trademark holder that has litigated to defend the mark.
- Removed the `load_plugin_textdomain()` call. WordPress has loaded
  translations just in time since 4.6, and this plugin's `Requires at least`
  is 6.0, so the call could never reach a version that needed it. The 1.2.0
  rationale for keeping it (a site owner adding their own `.mo` on a manual
  install) was wrong: just-in-time loading resolves the `Domain Path` header
  for manual installs too. This removes the package's only Plugin Check
  warning.
- Added `jaffray` to `Contributors`. WordPress.org requires the owning
  account to appear there, and the submission was made under that account.
  `choiceomg` is retained alongside it.
- Internal naming is unchanged, as at 1.2.0: classes stay `WC_Signifyd_*`,
  hooks and options stay `wc_signifyd_*`, and the REST namespace stays
  `wc-signifyd/v1`, so no integration and no registered webhook URL breaks.

## 1.2.1 (2026-08-15)

- Renamed the plugin in its two admin notices, which still read "Signifyd for
  WooCommerce" after the 1.2.0 rename. Both are the first thing an operator
  sees when WooCommerce is inactive or no API key is set, and both led with
  the trademark placement WordPress.org rejects, so the listing would have
  contradicted the plugin's own screens at review.
- Corrected the `Plugin URI` capitalisation to `github.com/ChoiceOMG/...`,
  matching the canonical repository path instead of relying on GitHub's
  case-insensitive redirect.
- Regenerated the translation template against the corrected strings.

## 1.2.0 (2026-08-08)

- Renamed the plugin to "Fraud Screening with Signifyd" for
  WordPress.org compliance. Directory listing rules prohibit leading a plugin
  slug with someone else's trademark, so `signifyd-for-woocommerce` would have
  been rejected or force-renamed at review. The text domain moved to the
  matching `fraud-screening-with-signifyd` (WordPress.org
  requires text domain and slug to be identical for language-pack delivery),
  and the main plugin file was renamed to match. Internal naming is
  deliberately unchanged: classes stay `WC_Signifyd_*`, hooks and options stay
  `wc_signifyd_*`, and the REST namespace stays `wc-signifyd/v1`, so no
  integration and no already-registered webhook URL breaks.
- Added `.distignore` so the released package excludes development files.
  WordPress.org's Plugin Check reports hidden files as an error and
  unexpected root markdown as a warning, both of which the raw repository
  tree would have triggered.
- Added `languages/fraud-screening-with-signifyd.pot`
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
