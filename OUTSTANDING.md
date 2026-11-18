# Outstanding items

The single running list of open work. Rules for maintaining it are in `AGENTS.md`
("Outstanding items"). Statuses: `Planned` (user picked it), `On hold`, `Open` (awaiting triage).

## Open

### 1. Direct input in the search box (feed URL, Apple id, podcast guid)
- **Status:** Open (Fountain share links shipped in 0.1.10; the rest below still open)
- **What:** the search box only does a title search (`search/byterm`). People often have a feed URL or an Apple Podcasts id instead. **Done so far:** a pasted Fountain share link (`fountain.fm/show/...` or `fountain.fm/episode/...`, any scheme/www/query-string variant) is recognised, matched against the cache row's remembered `fountain_url` first, then reverse-resolved through `GET api.fountain.fm/v1/content/{type}:id:{id}` (guids cached in `p20l_frv_*`), and the UI jumps straight to `?show=&episode=`.
- **Where:** `ajax_search()`, `search_fountain_link()` / `fountain_reverse()` / `cache_row_by_fountain_url()`, `inline_js()` search handler.
- **Plan:** detect a feed URL (`podcasts/byfeedurl`), an `id123456` / numeric Apple id (`podcasts/byitunesid`) or a UUID (`podcasts/byguid`) and jump straight to the result, following the same null / direct / miss shape.

### 2. Cache tab: per-row editing
- **Status:** Open
- **What:** the music plugin lets an admin hand-correct a cached link/artwork URL; here the Cache tab only deletes rows. Fountain now stores a derived URL per row (`fountain_url`), so there is at least one column worth hand-editing (bumping `fountain_date` would force a re-derive).
- **Where:** `render_cache_tab()`; add an `admin-post` handler like `handle_delete_cache_row()` with validation (https, app's own domain for links).
- **Plan:** a Fountain URL/date editor on the Cache tab; the other apps' links stay formulaic.

### 3. `uninstall.php`
- **Status:** Open
- **What:** uninstalling leaves the two tables and all `p20link_*` options behind.
- **Plan:** `uninstall.php` that drops `{prefix}p20link_cache` / `{prefix}p20link_log` and deletes the options and `p20l_*` transients. Confirm with the user first (the log is usage data).

### 4. Document-title and canonical-URL overrides for shared links
- **Status:** Open
- **What:** the music plugin rewrites `<title>` and the canonical URL for guid'd URLs; this plugin only adds Open Graph / Twitter tags (`output_social_preview_meta()`), so an SEO plugin may emit its own title/canonical for `?show=` pages.
- **Plan:** port `override_document_title` / canonical fix from `ghmattf/v4v-musiclink-pro`, behind the existing social-preview setting. Needs the harness extended with a few more WordPress stubs.

### 5. Internationalisation
- **Status:** Open
- **What:** strings use the `p20-link` text domain but there is no `.pot` file or `load_plugin_textdomain()` call.
- **Plan:** generate a `.pot` (`wp i18n make-pot`) and load the domain on `init`.

### 6. Custom date range in Reports
- **Status:** Open
- **What:** `render_reports_tab()` offers only 7 / 30 / 90 days and all time; the music plugin also has custom ranges and an artist filter (here: a show filter).

### 7. Music plugin: consider the official Fountain API
- **Status:** Open (cross-repo; music half not yet added to `ghmattf/v4v-musiclink-pro`)
- **What:** Fountain now has a documented public API (`api.fountain.fm/v1`, see `fountain.fm/docs.md`) - verified working 2026-11-12. This repo switched `fountain_id()` to it; the music plugin still uses the unofficial Next.js server-action (`fountain_try_resolve()`) with a self-healing `next-action` fingerprint that breaks on every Fountain redeploy. `POST /v1/search/content` (query + `types`) might replace it for TRACK/ALBUM if its hits' `_guid` values match Music Index guids - unverified for music.
- **Plan (music repo only):** probe `/v1/search/content` against a known music guid; if it matches, port `fountain_resolve()` to it, keeping the search-URL fallback. Implement only that repo's half there.

### 8. Document hardcoded shortcode attributes in the plugin description
- **Status:** Open
- **What:** `[p20link show="..." episode="..."]` hardcoding works and is documented in the file's USAGE comment block and README ("Inline placements"), but the plugin header `Description:` - what WordPress shows on the admin Plugins screen - only says "Shortcode: [p20link]" with no attribute hint.
- **Where:** `p20-link-resolver.php` plugin header `Description:` (line 4).
- **Plan:** append a compact example to the Description, e.g. `Shortcode: [p20link] or [p20link show="guid" episode="guid"]` (watch overall length - WP truncates long descriptions in the list view), and keep README in sync if the wording changes.

### 9. Telegram preview not showing (reported 2026-11-12)
- **Status:** Open (dedupe shipped in 0.1.4; page verified serving a single clean og set on 2026-11-16 - the @WebpageBot retest is still outstanding)
- **What:** Matt tested a link with Telegram's Webpage Bot and got no OG image/text. Verified live 2026-11-12: the plugin emits full OG tags (title/description/image, `summary_large_image`) on `https://phafe.com/podcastlink/?show=…&episode=…`, fetched with the `TelegramBot` UA (200; robots.txt doesn't block Telegram; og:image is a reachable 1000×1000 2.4 MB gif, under Telegram's 5 MB limit). Likely causes: (a) the tested URL was the old `/podcast2-0linker/…` slug, which now 404s (page renamed) - Telegram shows nothing for 404s; (b) the bare `/podcastlink/` URL without `?show=` params, where the plugin early-returns and only the theme's tags remain (its `og:description` is a single space, no `og:image`); (c) theme duplicate og tags print before the plugin's - if Telegram reads the first `og:description` it gets the blank one. **Cause (c) addressed in 0.1.4:** `og_buffer_start()` / `og_buffer_end()` now buffer `wp_head`, strip every theme/SEO `og:`/`twitter:` meta, and append the plugin's own set (failed resolve leaves existing tags alone). **Retest 2026-11-16:** fetched the live param'd URL with the `TelegramBot` UA - the page now serves exactly one og set (`og:type`/`title`/`description`/`url`/`site_name` + `twitter:card`/`title`/`description`/`image`), all plugin-owned with real content, so the dedupe is deployed and cause (c) is gone. If @WebpageBot still shows nothing, the remaining suspects are Telegram's own preview cache of the old blank snapshot, and the og:image being a 2.4 MB GIF (Telegram link previews may skip GIFs - a jpg would be safer).
- **Where:** `output_social_preview_meta()`, `og_buffer_start()`, `og_buffer_end()`; phafe.com theme og output; feed artwork (gif, feed-side).
- **Plan:** re-send the full param'd `/podcastlink/` URL to @WebpageBot (the crawler's cache of the old snapshot may need to expire first); if text/image is still blank, swap the feed artwork gif for a jpg as og:image (feed-side, outside this repo).

### 10. Breez as a link target
- **Status:** Planned (user picked 2026-11-14; boost-capable apps shortlist)
- **What:** Breez (iOS, Android) is a Lightning wallet with a built-in podcast player and boost support (Boostagrams, Sat Streaming, Value) but is not in the resolver's registry.
- **GUID support (platform-links guide, read 2026-11-15):** show - no podcast:guid form, keyed on `feedURL` (`breez.link/p?feedURL=${encodeURIComponent(feedURL)}`); episode - the episode guid IS used, but as a query param next to the feed URL (`...&episodeID=${encodeURIComponent(episodeGUID)}`), so both patterns still need `feedURL`. The resolver does not store the feed URL today - a Breez case needs a new column (or a fetch) first.
- **Where:** `app_registry()` / `app_link()` in `p20-link-resolver.php`; cache schema (`create_tables()`) if `feed_url` is added; README app table; `tests/resolver_test.php`.
- **Plan:** only proceed if we decide to store `feedURL` on the cache row; then show/episode query-param cases + schema bump (`TABLE_VERSION`) + tests.

## Closed decisions

- **One edition only** (no standard/Pro split): decided by the user, 2026-11-11.
- **Apps limited to Podcasting 2.0 apps** (Fountain, CurioCaster, Podverse, Podfriend, BoostMeBuddy, Castamatic, TrueFans, Podcast Index, Podcast Guru); no Apple/Spotify-style mainstream targets.
- **Link level is show + episode** (not show-only).
- **Fountain always renders a button**, degrading episode → show → `fountain.fm/discover` (user decision 2026-11-12); supersedes 0.1.2's "omit the button on an unreliable lookup" policy.
- **Podfans**: dropped by the user 2026-11-16 - no documented URL pattern, site unreachable from the sandbox.
- **CurioCaster episode links**: concluded 2026-11-16 - no episode route exists (client route list verified live); nothing to build until the guide gains a pattern.
