# Contributing

This plugin has no build step, no Composer dependencies, and no npm packages,
and contributions are expected to keep it that way. Read the hard rules in
[AGENTS.md](AGENTS.md) before making any structural change.

## Local development setup

You need:

- PHP 7.4 or later (match the plugin's `Requires PHP` header; test against the
  lowest supported version if you can, since newer PHP tolerates some things
  older PHP does not).
- A WordPress install with WooCommerce active. A local site via
  [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/),
  [Local](https://localwp.com/), or a plain `wp-cli` + Docker MySQL setup all
  work; there is nothing WooCommerce-specific about the setup beyond having
  WooCommerce installed and a payment gateway active so orders can reach a
  paid state.
- No Signifyd account is required for most changes. See "Testing without a
  live Signifyd account" below.

To install the plugin for development, clone or copy this repository into
`wp-content/plugins/fraud-screening-with-signifyd/` and activate it from the Plugins screen.
There is no build step and no Composer or npm dependency to install; the
plugin is plain PHP, JS, and CSS.

## Coding standards

Follow WordPress-Extra (WPCS) conventions:

- Tabs for indentation, not spaces.
- Yoda-optional but consistent: match whatever the surrounding file already
  does rather than mixing styles within a file.
- Every translatable string uses the `fraud-screening-with-signifyd` text domain, is a literal
  (no concatenation or interpolation inside `__()` / `_e()` / `esc_html__()`),
  and gets a `/* translators: ... */` comment immediately above it if it
  contains a `%s` / `%d` / numbered placeholder.
- Docblocks on any method whose behavior isn't obvious from its name and
  signature. `@param` / `@return` on anything with a non-trivial signature.
- New classes are named `WC_Signifyd_*`; new hooks, options, and global
  functions are prefixed `wc_signifyd_`.

If you have [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
and the [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
ruleset installed, you can lint against them directly:

```
phpcs --standard=WordPress-Extra includes/ fraud-screening-with-signifyd.php
```

CI does not enforce this ruleset yet, so matching the existing style by eye
is acceptable for small changes.

## Running php -l across the tree

Every PHP file must pass `php -l` before a change is submitted:

```
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

All of them should print `No syntax errors detected`. The check covers syntax
only: logic bugs and WordPress API misuse pass it cleanly, so treat it as a
minimum bar and still exercise the flow you changed.

## Testing without a live Signifyd account

You don't need production Signifyd credentials to test most of this plugin:

- **Case creation and the admin UI** can be exercised against a fake API key.
  Requests will fail (Signifyd will reject the key), but you can confirm the
  plugin builds the right payload, handles the failure path correctly, and
  that `WC_Signifyd_Logger` records what you expect in
  `WooCommerce > Status > Logs`.
- **The webhook endpoint** can be tested without any Signifyd account at all
  using Signifyd's console-initiated test webhook, which POSTs a payload with
  topic `cases/test`, signed with the fixed, publicly-documented key `ABCDE`
  instead of your team's real API key. `WC_Signifyd_API::is_valid_webhook()`
  already special-cases this topic, so you can reproduce it locally without
  a tunnel or a Signifyd account:

  ```
  BODY='{"some":"test payload"}'
  HASH=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac 'ABCDE' -binary | base64)
  curl -X POST 'https://your-local-site.test/wp-json/wc-signifyd/v1/webhook' \
    -H "Content-Type: application/json" \
    -H "X-SIGNIFYD-TOPIC: cases/test" \
    -H "X-SIGNIFYD-SEC-HMAC-SHA256: $HASH" \
    -d "$BODY"
  ```

  A correct response is HTTP 200 with `{"message":"Test OK"}`, and an info
  line in the WooCommerce log. If your local site isn't reachable from the
  public internet and you want to test against Signifyd's own
  console-triggered version of this request rather than a local `curl`, use
  a tunnel (ngrok or similar) to expose it temporarily.
- **A reachability probe** (no signature, no body) gets a 200 with a generic
  "reached the webhook endpoint" message and is never logged; that's the
  fastest way to confirm the route is registered at all:

  ```
  curl -X POST 'https://your-local-site.test/wp-json/wc-signifyd/v1/webhook'
  ```

## Pull request expectations

- Keep changes scoped to one concern; a bug fix and a refactor in the same PR
  make review harder without a good reason.
- Describe what you tested and how, especially for anything touching case
  creation, webhook handling, or the AJAX handlers, since none of those have
  automated test coverage yet.
- If you add or rename a hook, update the hook reference table in
  [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and the "Extending it" section
  of [README.md](README.md) in the same change; they're expected to stay in
  exact sync with the code.
- If you change how card, AVS, CVV, or other gateway risk-signal data is
  read or sent, say so explicitly in the PR description. This plugin's
  scope was deliberately kept to risk-signal fields only, never PAN or full
  CVV, and that boundary is easy to erode by accident.

## Reporting security issues

Report security issues privately to the maintainers, never as a public GitHub
issue. This plugin handles fraud-screening and payment risk data, so a public
issue against it discloses the vulnerability to everyone running it before a
fix exists.

This covers anything in the family of forging a webhook, bypassing a nonce or
capability check, or leaking card data or an API key. Please allow a
reasonable window for a fix before any public disclosure.
