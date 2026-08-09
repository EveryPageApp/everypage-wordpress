# EveryPage — WordPress plugin

Share PDFs as secure, trackable links from wp-admin, embed them as flipbooks,
and see who actually read them — without leaving your dashboard. A client for
the EveryPage [API](https://everypage.co/developers), authenticated with a
personal API key.

**On the directory:** <https://wordpress.org/plugins/everypage/> — or search
*EveryPage* under **Plugins → Add New**.

The installable plugin lives in `everypage/`. WP.org listing assets (icons,
banners, screenshots) live in `wporg-assets/` and are **not** part of the
plugin zip.

Requires WordPress 6.6+ and PHP 7.4+. GPL-2.0-or-later. No build step or
framework at runtime — plain PHP plus one Gutenberg block.

## Features (v1.1.0)

- **Settings** — paste your `ep_live_…` API key; shows a live connection status
  (`GET /api/v1/user`).
- **Files page** — upload a PDF, list your files with view counts + expiry, and
  a share trio on every row: copy link, QR code (with PNG download), copy
  iframe embed code. Rows prefer the short share URL and show custom-domain
  vanity URLs when configured.
- **Per-file settings drawer** — edit viewer mode/appearance, protection
  (password, view limits, watermark, copy protection), capture (email gate,
  lead form, notifications), and link settings (expiry, vanity slug, page
  range) without leaving WordPress. Controls above the connected plan are
  visible but disabled, with upgrade links.
- **Analytics** — per-file readership inside wp-admin (summary, breakdowns,
  recent sessions) plus the "Recent reads" dashboard widget.
- **EveryPage Document block** (`everypage/document`) — pick or upload a PDF in
  the editor, embed it inline (live viewer on the CSP-safe `/embed/` path) or
  as a link/button, tune per-document viewer settings from the sidebar, and
  one-click "Match my theme's colours". Dynamic block: PHP renders the front
  end via the same renderer as the shortcode.
- **Media Library integration** — "Share via EveryPage" on any PDF attachment
  (row action, attachment details, bulk action), a views column, and "Replace
  links in content" with a dry-run preview and revision-backed rewrites.
- **Shortcode** — `[everypage uuid="…" text="View" button="yes"]` for the
  classic editor and widgets.
- **Trusted oEmbed** — paste an EveryPage share link on its own line and it
  embeds the full tracked viewer. The plugin registers `everypage.co` as a
  trusted provider, which is what lets the viewer run unsandboxed.

## Architecture notes

- The API key is stored server-side in `everypage_settings` and never reaches
  the browser: the block editor talks to an `everypage/v1` REST proxy, and admin
  screens use admin-ajax — both call EveryPage server-side.
- GET responses are transient-cached (`everypage_` prefix) with a version-bump
  invalidation (`everypage_cache_v`) after any mutation.
- Override the API base for local testing with the `everypage_base_url` filter.
- Media shares store `_everypage_uuid` / `_everypage_short_id` /
  `_everypage_shared_at` attachment meta; `uninstall.php` removes options,
  transients, and meta (multisite-aware).
- Embeds always use the durable `/embed/{shortId}` path. A bare share URL sends
  `frame-ancestors 'none'` and will not render in an iframe.

## Development

```sh
cd everypage
npm ci            # install @wordpress/scripts
npm run build     # compile src/document → build/document
npm run start     # watch mode
```

The block source is `everypage/src/document/`; the committed `build/` output is
what `register_block_type` loads. Coding standards: `composer install` then
`vendor/bin/phpcs --standard=WordPress-Extra everypage`.

To test on a WordPress site, copy (or symlink) the `everypage/` folder into
`wp-content/plugins/`, activate, and paste an API key under
**EveryPage → Settings**.

### Screenshot environment

`.wp-env.json` boots a local WordPress with the plugin active and
`bin/screenshot-env/mu-plugins/` mapped in. That mu-plugin intercepts every
outbound request to everypage.co and answers with canned data for a fictional
Pro account, so the listing screenshots can be recaptured against a fully
populated UI without touching a real account. It is a fixture, not a mock
library — it ships no credentials and never runs outside wp-env.

## Packaging

```sh
bin/package.sh    # from the repo root
```

Runs `npm ci && npm run build` inside `everypage/`, then produces
`everypage.zip` at the repo root: a single top-level `everypage/` folder with
the PHP, `assets/`, `src/`, `build/`, `readme.txt`, `uninstall.php`, and
package manifests — no `node_modules`, no dev config, no OS cruft. Verify with
`unzip -l everypage.zip`.

## Releasing

Releases go to the directory from CI. Bump the version in all three places —
the `Version:` header and `EVERYPAGE_VERSION` in `everypage/everypage.php`, and
`Stable tag` in `everypage/readme.txt` — add a `== Changelog ==` entry, then:

```sh
git tag v1.2.0 && git push origin v1.2.0
```

`.github/workflows/deploy.yml` rebuilds the block, refuses to continue if the
tag and the three versions disagree, and syncs `everypage/` into SVN `trunk/`
plus `tags/1.2.0`, with `wporg-assets/` into `assets/`. It needs `SVN_USERNAME`
and `SVN_PASSWORD` repo secrets (a WordPress.org SVN password from Account &
Security on the wp.org profile, not the account password).

Screenshots on the listing come from `assets/screenshot-N.png` in SVN, matching
the numbered captions in `readme.txt`'s Screenshots section — reorder one and
you reorder the other. The banners are rendered from `bin/banner-source.html`
(one file, screenshotted at 772x250 at 1x and 2x; the recipe is in its header
comment), so a brand tweak is an edit rather than a redraw.

## License

[GPL-2.0-or-later](./LICENSE)
