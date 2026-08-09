# WordPress.org submission

The plugin passes WordPress.org's Plugin Check with no errors and is packaged
for submission. One prerequisite is outstanding and has to be done by a human
before anything can be uploaded: see "Blocker" below.

## Blocker: the Contributors username does not exist

`readme.txt` declares `Contributors: choiceomg`, and there is no WordPress.org
account with that username (`wordpress.org/support/users/choiceomg/` returns
404, checked 2026-08-08).

`Contributors` must list real WordPress.org usernames. An unrecognised name
leaves the listing with no author attribution and invites a reviewer query.
More importantly, the account that submits the plugin becomes its owner, so
this needs deciding before upload rather than after.

To resolve, either:

1. Register `choiceomg` at <https://login.wordpress.org/register>, then submit
   from that account. Preferred: the plugin is owned by an organisation
   account rather than an individual, so ownership survives staff changes.
2. Submit from an existing personal WordPress.org account and change the
   `Contributors` line to that username.

Additional contributors can be added to the line later, comma-separated.

## Submission steps

1. Confirm the `Contributors` line matches the submitting account.
2. Build the package:
   ```
   ./build.sh
   ```
   This produces `dist/fraud-screening-for-woocommerce-with-signifyd.zip`
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
   `trunk/` plus a copy to `tags/1.2.0/`. The listing goes live once
   `Stable tag` in `trunk/readme.txt` points at a tag that exists.

## What reviewers are likely to raise

**The plugin name and slug.** The submitted name leads with a descriptive
phrase rather than the Signifyd trademark, which is the reason for the
otherwise unwieldy slug. If a reviewer proposes a different name, keep the
trademark out of the leading position. Changing the slug after approval is
not possible, so settle any naming question during review.

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

**`load_plugin_textdomain()`.** Plugin Check flags this as discouraged since
WordPress 4.6. It is the only warning the package produces and it does not
block submission. The rationale for keeping it is documented at the call
site: WordPress.org language packs make it redundant for directory installs,
but it remains the mechanism for someone who adds their own `.mo` to
`languages/` on a GitHub or manual install.

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
   wp i18n make-pot . languages/fraud-screening-for-woocommerce-with-signifyd.pot
   ```
4. Confirm the hook reference table in `docs/ARCHITECTURE.md` still matches
   the code (see `AGENTS.md` for why this matters).
5. Run `./build.sh`, then Plugin Check against the built package rather than
   the repository tree. The tree contains development files that fail the
   check by design.
6. Tag the release in git, then commit to SVN `trunk/` and copy to
   `tags/<version>/`.
