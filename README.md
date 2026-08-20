# EveryPage — WordPress plugin

Share PDFs as secure, trackable links from wp-admin and see readership analytics
in your dashboard. A client for the EveryPage [API](https://everypage.co/developers),
authenticated with a personal API key.

The installable plugin lives in `everypage/`. WP.org listing assets (icons,
screenshots) live in `wporg-assets/` and are **not** part of the plugin zip.

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

## Architecture notes

- The API key is stored server-side in `everypage_settings` and never reaches
  the browser: the block editor talks to a `everypage/v1` REST proxy, and admin
  screens use admin-ajax — both call EveryPage server-side.
- GET responses are transient-cached (`everypage_` prefix) with a version-bump
  invalidation (`everypage_cache_v`) after any mutation.
- Override the API base for local testing with the `everypage_base_url` filter.
- Media shares store `_everypage_uuid` / `_everypage_short_id` /
  `_everypage_shared_at` attachment meta; `uninstall.php` removes options,
  transients, and meta (multisite-aware).

## Development

```sh
cd everypage
npm ci            # install @wordpress/scripts
npm run build     # compile src/document → build/document
npm run start     # watch mode
```

The block source is `everypage/src/document/`; the committed `build/` output is
what `register_block_type` loads. Requires WordPress 6.6+ (the build targets
`react-jsx-runtime`) and PHP 7.4+.

To test on a WordPress site, copy (or symlink) the `everypage/` folder into
`wp-content/plugins/`, activate, and paste an API key under
**EveryPage → Settings**.

## Packaging

```sh
bin/package.sh    # from the repo root
```

This runs `npm ci && npm run build` inside `everypage/`, then produces
`everypage.zip` at the repo root: a single top-level `everypage/` folder with
the PHP, `assets/`, `src/`, `build/`, `readme.txt`, `uninstall.php`, and
package manifests — no `node_modules`, no dev config, no OS cruft. Verify with
`unzip -l everypage.zip`.

## Releasing

Approved by the WordPress.org review team on 2026-08-09 under the slug
`everypage`; v1.1.0 was committed to SVN by hand. **Every release after that
goes out from the public mirror**, <https://github.com/EveryPageApp/everypage-wordpress>
— tag `vX.Y.Z` there and `.github/workflows/deploy.yml` rebuilds the block,
checks the tag against all three version strings, and syncs `everypage/` into
`trunk/` + `tags/X.Y.Z` with `wporg-assets/` into `assets/`.

That mirror is a fresh-history repo (this one's history is pre-rebrand and
stays private). Changes made here have to be copied across; the mirror is the
one the directory and the outside world see.

Bumping a version means all three of: the `Version:` header and
`EVERYPAGE_VERSION` in `everypage/everypage.php`, and `Stable tag` in
`everypage/readme.txt` — plus a `== Changelog ==` entry. The workflow fails the
release rather than shipping a mismatch.

Manual SVN, if CI is ever unavailable:

```sh
svn co https://plugins.svn.wordpress.org/everypage ~/wp-svn/everypage
# contents of everypage/ into trunk/, wporg-assets/*.png into assets/
svn ci --username nickpears -m "Release X.Y.Z"
# ^/ is the WHOLE plugins repository, not this plugin - the slug is required.
svn cp ^/everypage/trunk ^/everypage/tags/X.Y.Z --username nickpears -m "Tag X.Y.Z"
```

Screenshots shown on the listing come from `assets/screenshot-N.png` in SVN,
matching the numbered captions in readme.txt's Screenshots section. The banners
are rendered from `bin/banner-source.html` (one file, screenshotted at 772x250
at 1x and 2x — its header comment has the recipe), so a brand tweak means
editing that file rather than opening a design tool.
