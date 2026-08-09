# AGENTS.md

Guidance for AI coding agents and assistants working on this repository.

## What this project is

Signifyd for WooCommerce is a WordPress plugin that integrates WooCommerce
with Signifyd's fraud-screening service. It creates a Signifyd case server-side
when an eligible order is paid, receives the fraud decision back over a signed
webhook, and gives store staff a metabox on the order screen to view a case,
refresh it, close it, or purchase a financial guarantee. It targets Signifyd's
V2 Cases API specifically, built on `wp_remote_request()` with no bundled SDK
and no vendor dependencies, because there is no official Signifyd plugin for
WooCommerce and the 78MB Signifyd PHP SDK this plugin used to bundle was
unnecessary weight for the handful of endpoints it actually calls.

## File layout

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the file-by-file
breakdown, the three request flows (order paid, webhook received, staff clicks
a metabox button), and the complete hook and filter reference table. Read it
before making any change that touches more than one file.

## Naming conventions

- Classes: `WC_Signifyd_*` (e.g. `WC_Signifyd_Orders`, `WC_Signifyd_API`).
- Hooks, options, and the AJAX nonce action: prefixed `wc_signifyd_`
  (e.g. `wc_signifyd_case_updated`, `wc_signifyd_api_key`).
- Text domain: `fraud-screening-with-signifyd`, on every
  translatable string. It is long because WordPress.org requires the text
  domain to equal the plugin slug for language-pack delivery, and the slug
  has to lead with a descriptive word rather than the Signifyd trademark.
- Order meta keys live as class constants on `WC_Signifyd_Orders`
  (`META_CASE_ID`, `META_DISPOSITION`, etc.); reference the constant, never
  the literal meta-key string, when reading or writing them from another file.

Two naming systems coexist here on purpose. The **public slug** is the long
`fraud-screening-with-signifyd`, used only for the plugin
folder, the main file, and the text domain. Everything **internal** keeps the
short `wc_signifyd_` / `WC_Signifyd_` / `wc-signifyd` prefix: class names,
hooks, options, the REST namespace (`wc-signifyd/v1`), asset handles, CSS
classes, and the `includes/class-wc-signifyd-*.php` filenames. Do not
"consistency-fix" the internal names to match the slug. Renaming the hooks
would break every integration, and renaming the REST namespace would change
the webhook URL that stores have already registered in the Signifyd console.

## Hard rules

Each rule below encodes a deliberate design decision made when this plugin
was built. Breaking one silently undoes that decision.

- **No bundled SDKs or vendor dependencies.** The entire point of the rewrite
  this plugin is built on was replacing a 78MB vendored Signifyd SDK with a
  client under 300 lines built on WordPress's own HTTP API. Do not reintroduce
  Composer dependencies, vendored libraries, or a package manager of any kind.
- **Never log card data or request/response bodies.** `WC_Signifyd_Logger`
  exists specifically so nothing in this plugin writes a full API request or
  response payload, or any card/AVS/CVV value, to a persistent log. Log
  messages should carry order IDs, case IDs, HTTP status codes, and short
  error strings, never raw payloads. If you add a new logged message, check
  it against this rule before committing.
- **Never change order status automatically.** The plugin stores whatever
  disposition and score Signifyd returns, and fires `wc_signifyd_case_updated`
  so a store can implement its own policy (hold, cancel, flag) on top. It does
  not call `$order->update_status()` anywhere, and should not start.
- **Keep the gateway meta map and AVS map filterable.** `WC_Signifyd_Case_Builder`
  reads gateway-specific order meta (AVS result, CVV result, BIN, last four,
  expiry) through `wc_signifyd_gateway_meta_map`, and translates AVS codes
  through `wc_signifyd_avs_map`, rather than hardcoding one gateway's meta
  keys or AVS vocabulary. The defaults target the Moneris gateway; a change
  that hardcodes a different gateway's keys in place of the filter, instead of
  alongside it, breaks every other integration relying on that filter.
- **Keep all order access HPOS-safe.** Read and write orders through
  `wc_get_order()`, `WC_Order` CRUD methods (`get_meta()`,
  `update_meta_data()`, `save()`), and `wc_get_orders()`. Never call
  `get_post_meta()`, `update_post_meta()`, or read `$order->ID` directly on an
  order object; those bypass High-Performance Order Storage and will silently
  read or write the wrong place on stores that have it enabled.
- **Never hardcode a store name, API key, or real case or order id.** This
  plugin was genericized from a working production integration for public
  release. Any example, test fixture, comment, or default value you add must
  use placeholder values, not anything that could identify the original store
  or a real Signifyd case.

## How to verify a change

1. Run `php -l` on every file you touched (and ideally the whole tree; see
   [CONTRIBUTING.md](CONTRIBUTING.md) for the one-liner). All files must pass.
2. If you added, removed, or renamed a hook, filter, or its arguments, update
   the hook reference table in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
   and the "Extending it" list in [README.md](README.md) so both still match
   the code exactly. A hook documented but not implemented, or implemented but
   undocumented, is a bug for the purposes of this repository.
3. Trace the request flow your change sits in
   (docs/ARCHITECTURE.md has all three) end to end by reading the code, since
   there is no live WordPress/WooCommerce/Signifyd environment available for
   an automated test run in most contexts. If you do have a WordPress test
   site available, exercise the actual flow rather than relying on `php -l`
   alone; a syntax check catches typos and stops there.
4. If you touched the AJAX handlers in `class-wc-signifyd-admin.php`, confirm
   the JS `data-action` value, the `wp_ajax_*` hook name, and the nonce action
   passed to `wp_create_nonce()` / `check_ajax_referer()` still all agree, for
   every button you touched.
