# WordPress.org submission

The plugin was submitted on 2026-08-14 and is in review. Version 1.2.2
answers the pre-review feedback and passes Plugin Check with zero errors and
zero warnings. See "Review history" for what was raised and how it was
resolved.

## Identity: what has to match

The account that submits the plugin becomes its permanent owner and receives
the SVN credentials, so these values must agree:

| Field | Value |
|---|---|
| Plugin name | Riskloom Fraud Screening for Signifyd |
| Slug | `riskloom-fraud-screening-for-signifyd` |
| Owning WordPress.org account | `jaffray` |
| Company WordPress.org account | `choiceomg` |
| `readme.txt` `Contributors:` | `jaffray, choiceomg` |
| Plugin header `Author:` | Choice OMG |
| Plugin header `Author URI:` | `https://choice.marketing` |
| Plugin header `Plugin URI:` | `https://github.com/ChoiceOMG/woocommerce-signifyd` |

The plugin was submitted under `jaffray`, so that account owns the listing.
WordPress.org requires the owning account to appear in `Contributors`, and
the pre-review flagged its absence. Ownership does not follow the
`Contributors` line; moving it requires asking the plugins team directly.
`choiceomg` is listed alongside so the company appears on the listing.

`choiceomg`'s account email is a role address rather than an individual's
mailbox, which is why it exists: an account tied to it survives staff
changes. It follows the established `systems.choice.marketing` convention,
where each third-party platform the company signs into gets its own service
identity, alongside `ads@`, `analytics@`, `gbp@`, `gsc@`, `gtm@`, `meta@`,
`social@`, `video@`, and `websites@`. That durability applies to the account,
not to this listing, which sits on `jaffray`.

That subdomain does not resolve to Google. Its MX points at
`webmail.choice.zone`, so mail for every `@systems.choice.marketing` address
is delivered by the company's own Mailu server rather than by Workspace. The
Google-side accounts on that subdomain are Cloud Identity users with no
mailbox, used only to sign in to platforms that require a Google account.
Reviewer mail is therefore read through the mail portal, where the mailbox is
granted to `peter@` and `kaily@` like the rest of the subdomain.

`Plugin URI` uses the canonical `ChoiceOMG` capitalisation. GitHub redirects
the lowercase form, but the header is copied verbatim into the translation
template and the directory listing, so it should not depend on a redirect.

## Account status

All three pieces of the identity exist as of 2026-08-15.

The WordPress.org account `choiceomg` was registered against
`wordpress@systems.choice.marketing`. Registration cannot be scripted: the
form at <https://login.wordpress.org/register> carries a reCAPTCHA, and its
"Pineapple is delicious on pizza" checkbox is an anti-bot honeypot that must
be left unchecked.

The mailbox is live on Mailu and delivery-tested end to end, including an
external inbound message through SES that passed SPF, DKIM, and DMARC.
WordPress.org's own mail arrives cleanly through it.

The matching Google Cloud Identity user (`WordPress Choice OMG`, org unit
`/systems`) exists for platforms that require a Google sign-in. It carries no
mailbox, as intended.

One trap worth knowing, because it cost a round trip here. Peter has a
separate personal WordPress.org account, `jaffray`, dating to 2015. Pointing
that account's email at `wordpress@systems.choice.marketing` would have
consumed the address, since WordPress.org allows one account per email, and
blocked the `choiceomg` registration. `jaffray` stays on
`peter@choice.marketing`. Both can appear on the listing: additional
contributors go on the `Contributors` line, comma-separated, at any time.

## Submission steps

1. Confirm the `Contributors` line matches the submitting account.
2. Build the package:
   ```
   ./build.sh
   ```
   This produces `dist/riskloom-fraud-screening-for-signifyd.zip`
   (roughly 48 KB, 16 files). The script refuses to finish if any hidden file
   reaches the package, because WordPress.org rejects those outright.
3. Upload the zip at <https://wordpress.org/plugins/developers/add/>.
4. An automated scan runs immediately. Anything it reports comes back by
   email, usually within minutes.
5. A human review follows. Turnaround has historically ranged from a few days
   to several weeks depending on queue depth. Expect at least one round of
   questions.
6. On approval you receive SVN credentials and a repository at
   `https://plugins.svn.wordpress.org/<slug>/`. Publishing is a commit to
   `trunk/` plus a copy to `tags/<version>/`. The listing goes live once
   `Stable tag` in `trunk/readme.txt` points at a tag that exists.

## Review history

**2026-08-14, submitted as "Fraud Screening with Signifyd" under `jaffray`.**

**2026-08-15, automated pre-review** (ID `P0TDX352313HGN`, flagged TRM)
raised three items, all answered in 1.2.2.

*The name.* This is the one worth understanding, because the obvious reading
of it is wrong. WordPress.org enforces two separate naming rules, and the
1.2.0 rename satisfied only the first. The trademark rule says a mark you do
not own must not lead the name, and is satisfied by placing it at the end
after "for" or "with"; "Fraud Screening with Signifyd" passed that. The
distinctiveness rule says the name must not resemble other listings, judged
on meaning and pattern rather than character-by-character, and "Fraud
Screening" is generic enough to sit beside existing entries such as "Antiro
Order Risk Screening for WooCommerce". Reviewers require a distinguishing
term at the *front*, and state that adding a generic qualifier such as
"Advanced" or "Simple" does not satisfy it.

The pattern the review team endorses is `<coined term> <description> for
<trademark>`, which the directory bears out: "FraudLabs Pro for WooCommerce",
"Predax Fraud Guard for WooCommerce", "Antiro Order Risk Screening for
WooCommerce". Hence "Riskloom".

Check any replacement against real companies, not just the plugin directory.
"Casewire" was drafted and discarded here: no plugin or slug used it, but
CaseWare International is an active trademark holder that has litigated to
defend the mark, and a one-letter difference is exactly the lookalike the
distinctiveness rule targets. Trading one trademark flag for a worse one is
the failure mode to avoid.

A slug change must be stated explicitly in the reply. Changing it in the code
and display name is not sufficient, and it cannot be altered after approval.

*Contributors.* The owning account was missing from the list. See "Identity"
above.

*`load_plugin_textdomain()`.* Removed. See below.

## What reviewers may still raise

**The external service disclosure.** `readme.txt` has an `== External
services ==` section itemising what is transmitted to Signifyd, when, and
under which terms and privacy policy. This is the single most common cause of
rejection for plugins that call an API. If a reviewer asks for more detail,
expand that section rather than trimming it.

**The unauthenticated REST route.** `permission_callback` is `__return_true`
on the webhook endpoint. That is correct and unavoidable, since Signifyd
cannot present a WordPress nonce or cookie, and the HMAC signature check
inside the handler is the actual authentication. The reasoning is already in
the code comment at `register_route()`; point a reviewer there if it comes up.

**`load_plugin_textdomain()`.** Removed in 1.2.2, and it should not come
back. Releases up to 1.2.1 kept the call on the theory that someone adding
their own `.mo` to `languages/` on a manual install still needed it. That was
wrong: WordPress has loaded translations just in time since 4.6, resolving
them from the `Domain Path` header for manual installs and from language
packs for directory installs. With `Requires at least: 6.0`, the call could
never reach a version that needed it. If a future change reintroduces it,
Plugin Check will warn and the reviewer will ask again.

**Plugin Check result.** Plugin Check 2.0.0 against the built 1.2.2 package
on WordPress 7.0, 2026-08-15, across every category (`general`,
`plugin_repo`, `security`, `performance`, `accessibility`) with
`--include-experimental`: zero errors and zero warnings. That is the complete
output, not a summary.

Worth knowing for next time: Plugin Check's `trademarks` check passed on the
1.2.1 package while the human-facing pre-review flagged the name anyway. The
tool tests trademark placement; it does not test distinctiveness against
other listings. A clean Plugin Check is necessary and not sufficient.

## Optional, worth doing before or soon after launch

**Screenshots.** `readme.txt` has no `== Screenshots ==` section, and the
listing page currently shows none. Screenshots meaningfully affect install
rates. Two are worth capturing on a real store: the order-screen panel
showing a score and disposition, and the settings tab. They go in an
`assets/` directory at the SVN repository root (not inside `trunk/`), named
`screenshot-1.png` upward, with matching numbered captions under a
`== Screenshots ==` heading in `readme.txt`.

**Banner and icon.** Also live in the SVN `assets/` directory. A listing
without them renders a plain grey placeholder. Sizes: `banner-772x250.png`
and `icon-256x256.png`, with optional retina variants.

Do not confuse that directory with this repository's `assets/`, which holds
the admin CSS and JS the plugin actually loads and does ship inside the
package. The SVN `assets/` is listing artwork only, lives beside `trunk/`
rather than inside it, and never reaches an installed site.

**A `Tested up to` refresh each WordPress release.** A plugin more than two
major versions behind gets a "may no longer be maintained" notice on its
listing, which suppresses installs.

## Release checklist for future versions

1. Update the version in three places, which must agree: the `Version:`
   header, the `WC_SIGNIFYD_VERSION` constant, and `Stable tag` in
   `readme.txt`.
2. Add the entry to both `CHANGELOG.md` and the `== Changelog ==` section of
   `readme.txt`, plus an `== Upgrade Notice ==` line if the change affects
   existing installs.
3. Regenerate the translation template if any string changed:
   ```
   wp i18n make-pot . languages/riskloom-fraud-screening-for-signifyd.pot \
     --exclude=dist,docs,.remember,.claude
   ```
   The `--exclude` is load-bearing. Without it `make-pot` walks `dist/`, and
   the previously built copy of the plugin contributes its own strings to the
   template. When a string has changed since the last build, the template ends
   up carrying both the old and the new wording, each attributed to a
   different file. Confirm the result with `grep -c '^#: dist/'`, which must
   return zero.
4. Confirm the hook reference table in `docs/ARCHITECTURE.md` still matches
   the code (see `AGENTS.md` for why this matters).
5. Run `./build.sh`, then Plugin Check against the built package rather than
   the repository tree. The tree contains development files that fail the
   check by design.

   There is no local WordPress in this repository, so the check runs against
   the `wp-pdev` dev site (container `wp-pdev-wordpress`). Stage the built
   package, run, then remove it, because anything left in that plugins
   directory is another project's working environment:

   ```bash
   cd ~/dev/wp-pdev && set -a && . ./.env && set +a
   sudo cp -r ~/dev/woocommerce-signifyd/dist/riskloom-fraud-screening-for-signifyd \
     wp-content/plugins/
   sudo chown -R 33:33 wp-content/plugins/riskloom-fraud-screening-for-signifyd

   docker run --rm --volumes-from wp-pdev-wordpress \
     --network wp-pdev_wp-network -u 33:33 \
     -e WORDPRESS_DB_HOST=db:3306 -e WORDPRESS_DB_USER="$MYSQL_USER" \
     -e WORDPRESS_DB_PASSWORD="$MYSQL_PASSWORD" \
     -e WORDPRESS_DB_NAME="$MYSQL_DATABASE" \
     wordpress:cli wp --path=/var/www/html plugin check \
       riskloom-fraud-screening-for-signifyd \
       --categories=general,plugin_repo,security,performance,accessibility \
       --include-experimental

   sudo rm -rf wp-content/plugins/riskloom-fraud-screening-for-signifyd
   ```

   The `wordpress:cli` image carries no database configuration of its own, so
   the four `-e` variables are required; without them every command that
   touches the database fails with "Error establishing a database connection"
   even though `core version` succeeds. Install Plugin Check once with
   `wp plugin install plugin-check --activate` through the same wrapper, and
   deactivate it afterwards so the dev site is left as it was found.
6. Tag the release in git, then commit to SVN `trunk/` and copy to
   `tags/<version>/`.
