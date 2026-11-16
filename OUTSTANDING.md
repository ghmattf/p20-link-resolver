# Outstanding items

The single running list of open work. Rules for maintaining it are in `AGENTS.md`
("Outstanding items"). Statuses: `Planned` (user picked it), `On hold`, `Open` (awaiting triage).

## Open

### 1. Direct input in the search box (feed URL, Apple id, podcast guid)
- **Status:** Open
- **What:** the search box only does a title search (`search/byterm`). People often have a feed URL or an Apple Podcasts id instead.
- **Where:** `ajax_search()`, `inline_js()` search handler.
- **Plan:** detect a URL (`podcasts/byfeedurl`), an `id123456` / numeric Apple id (`podcasts/byitunesid`) or a UUID (`podcasts/byguid`) and jump straight to the result.

### 2. Episode links for Podverse and CurioCaster
- **Status:** Open
- **What:** Podverse needs a Podverse-internal episode id and CurioCaster has no documented episode pattern, so both degrade to the show link today.
- **Where:** `app_link()` cases `podverse` / `curiocaster`.
- **Plan:** check whether Podverse's API can map a Podcast Index episode id or guid to its id (would need a resolver + cache column), and whether CurioCaster has since added episode routes. Update the platform-links table in the README if so.

### 3. Cache tab: per-row editing
- **Status:** Open
- **What:** the music plugin lets an admin hand-correct a cached link/artwork URL; here the Cache tab only deletes rows. Fountain now stores a derived URL per row (`fountain_url`), so there is at least one column worth hand-editing (bumping `fountain_date` would force a re-derive).
- **Where:** `render_cache_tab()`; add an `admin-post` handler like `handle_delete_cache_row()` with validation (https, app's own domain for links).
- **Plan:** a Fountain URL/date editor on the Cache tab; the other apps' links stay formulaic.

### 4. `uninstall.php`
- **Status:** Open
- **What:** uninstalling leaves the two tables and all `p20link_*` options behind.
- **Plan:** `uninstall.php` that drops `{prefix}p20link_cache` / `{prefix}p20link_log` and deletes the options and `p20l_*` transients. Confirm with the user first (the log is usage data).

### 5. Document-title and canonical-URL overrides for shared links
- **Status:** Open
- **What:** the music plugin rewrites `<title>` and the canonical URL for guid'd URLs; this plugin only adds Open Graph / Twitter tags (`output_social_preview_meta()`), so an SEO plugin may emit its own title/canonical for `?show=` pages.
- **Plan:** port `override_document_title` / canonical fix from `ghmattf/v4v-musiclink-pro`, behind the existing social-preview setting. Needs the harness extended with a few more WordPress stubs.

### 6. Internationalisation
- **Status:** Open
- **What:** strings use the `p20-link` text domain but there is no `.pot` file or `load_plugin_textdomain()` call.
- **Plan:** generate a `.pot` (`wp i18n make-pot`) and load the domain on `init`.

### 7. Custom date range in Reports
- **Status:** Open
- **What:** `render_reports_tab()` offers only 7 / 30 / 90 days and all time; the music plugin also has custom ranges and an artist filter (here: a show filter).

### 8. Music plugin: consider the official Fountain API
- **Status:** Open (cross-repo; music half not yet added to `ghmattf/v4v-musiclink-pro`)
- **What:** Fountain now has a documented public API (`api.fountain.fm/v1`, see `fountain.fm/docs.md`) - verified working 2026-11-12. This repo switched `fountain_id()` to it; the music plugin still uses the unofficial Next.js server-action (`fountain_try_resolve()`) with a self-healing `next-action` fingerprint that breaks on every Fountain redeploy. `POST /v1/search/content` (query + `types`) might replace it for TRACK/ALBUM if its hits' `_guid` values match Music Index guids - unverified for music.
- **Plan (music repo only):** probe `/v1/search/content` against a known music guid; if it matches, port `fountain_resolve()` to it, keeping the search-URL fallback. Implement only that repo's half there.

### 9. Document hardcoded shortcode attributes in the plugin description
- **Status:** Open
- **What:** `[p20link show="..." episode="..."]` hardcoding works and is documented in the file's USAGE comment block and README ("Inline placements"), but the plugin header `Description:` - what WordPress shows on the admin Plugins screen - only says "Shortcode: [p20link]" with no attribute hint.
- **Where:** `p20-link-resolver.php` plugin header `Description:` (line 4).
- **Plan:** append a compact example to the Description, e.g. `Shortcode: [p20link] or [p20link show="guid" episode="guid"]` (watch overall length - WP truncates long descriptions in the list view), and keep README in sync if the wording changes.

### 10. Telegram preview not showing (reported 2026-11-12)
- **Status:** Open (dedupe shipped in 0.1.4; page verified serving a single clean og set on 2026-11-16 - the @WebpageBot retest is still outstanding)
- **What:** Matt tested a link with Telegram's Webpage Bot and got no OG image/text. Verified live 2026-11-12: the plugin emits full OG tags (title/description/image, `summary_large_image`) on `https://phafe.com/podcastlink/?show=…&episode=…`, fetched with the `TelegramBot` UA (200; robots.txt doesn't block Telegram; og:image is a reachable 1000×1000 2.4 MB gif, under Telegram's 5 MB limit). Likely causes: (a) the tested URL was the old `/podcast2-0linker/…` slug, which now 404s (page renamed) - Telegram shows nothing for 404s; (b) the bare `/podcastlink/` URL without `?show=` params, where the plugin early-returns and only the theme's tags remain (its `og:description` is a single space, no `og:image`); (c) theme duplicate og tags print before the plugin's - if Telegram reads the first `og:description` it gets the blank one. **Cause (c) addressed in 0.1.4:** `og_buffer_start()` / `og_buffer_end()` now buffer `wp_head`, strip every theme/SEO `og:`/`twitter:` meta, and append the plugin's own set (failed resolve leaves existing tags alone). **Retest 2026-11-16:** fetched the live param'd URL with the `TelegramBot` UA - the page now serves exactly one og set (`og:type`/`title`/`description`/`url`/`site_name` + `twitter:card`/`title`/`description`/`image`), all plugin-owned with real content, so the dedupe is deployed and cause (c) is gone. If @WebpageBot still shows nothing, the remaining suspects are Telegram's own preview cache of the old blank snapshot, and the og:image being a 2.4 MB GIF (Telegram link previews may skip GIFs - a jpg would be safer).
- **Where:** `output_social_preview_meta()`, `og_buffer_start()`, `og_buffer_end()`; phafe.com theme og output; feed artwork (gif, feed-side).
- **Plan:** re-send the full param'd `/podcastlink/` URL to @WebpageBot (the crawler's cache of the old snapshot may need to expire first); if text/image is still blank, swap the feed artwork gif for a jpg as og:image (feed-side, outside this repo).

### 11. `note` from `app_link()` never reaches `render_html()` (found 2026-11-13)
- **Status:** Implemented 2026-11-16 (pending release - remove when shipped)
- **What:** `render()` copied only `url` / `label` / `episode` / `icon_url` from `app_link()`'s return into `$links`, dropping the `note` key. `render_html()`'s "(opens Fountain)" branch (`isset($link['note']) && 'discover' === $link['note']`) was therefore dead in production - a discover link on an episode page always said "(opens the show)". The harness passed `note` directly to `render_html()`, which is why the test passed.
- **Where:** `render()`'s `$links[ $slug ] = array(...)` assembly (after the `app_link()` call); `render_html()` note branch.
- **Fixed 2026-11-16:** `render()` now carries `'note' => isset( $link['note'] ) ? $link['note'] : ''`; a render-level test asserts "(opens Fountain)" appears for a discover link on an episode page and the other apps still say "(opens the show)".

### 12. Podcast Guru as a link target
- **Status:** Implemented 2026-11-16 (pending release - remove when shipped)
- **What:** Podcast Guru (iOS, Android, macOS) is a Podcasting 2.0 app with boost support (Boostagrams, Live Boosts, Sat Streaming) but was not in the resolver's registry. GUID support (platform-links guide, read 2026-11-15): show - no podcast:guid form, keyed on `appleID` (`app.podcastguru.io/podcast/${appleID}`); episode - no, needs Podcast Guru's internal episode id, not the episode guid.
- **Built 2026-11-16:** registry entry (`podcastguru`, domain `app.podcastguru.io`), `app_link()` show case keyed on `itunes_id` (episode degrades to show), README intro/table, admin hint (grouped with Podfriend), plugin header app list, 3 tests (show / omitted without Apple id / episode degrades). Not yet verified live (guide only - listed in the README's "Not yet verified live" section).

### 13. Breez as a link target
- **Status:** Planned (user picked 2026-11-14; boost-capable apps shortlist)
- **What:** Breez (iOS, Android) is a Lightning wallet with a built-in podcast player and boost support (Boostagrams, Sat Streaming, Value) but is not in the resolver's registry.
- **GUID support (platform-links guide, read 2026-11-15):** show - no podcast:guid form, keyed on `feedURL` (`breez.link/p?feedURL=${encodeURIComponent(feedURL)}`); episode - the episode guid IS used, but as a query param next to the feed URL (`...&episodeID=${encodeURIComponent(episodeGUID)}`), so both patterns still need `feedURL`. The resolver does not store the feed URL today - a Breez case needs a new column (or a fetch) first.
- **Where:** `app_registry()` / `app_link()` in `p20-link-resolver.php`; cache schema (`create_tables()`) if `feed_url` is added; README app table; `tests/resolver_test.php`.
- **Plan:** only proceed if we decide to store `feedURL` on the cache row; then show/episode query-param cases + schema bump (`TABLE_VERSION`) + tests.

### 14. Podfans as a link target
- **Status:** Planned (user picked 2026-11-14; boost-capable apps shortlist)
- **What:** Podfans (web player, podfans.fm) sends boosts and streams per the bLIP-10 reference table but is not in the resolver's registry.
- **GUID support:** UNKNOWN. Podfans has no entry in the platform-links guide and no show/episode URL pattern is documented anywhere found (site unreachable from the agent sandbox; not verified live). No pattern can be built today.
- **Where:** `app_registry()` / `app_link()` in `p20-link-resolver.php`; README app table; `tests/resolver_test.php`.
- **Plan:** from a machine with network access, open a Podfans show/episode page and record the URL shape (does it accept podcast:guid / episode guid?); only then add registry entry + `app_link()` case + README row + tests.

### 15. App icon preview in settings
- **Status:** Planned (user picked 2026-11-16; implement only on request)
- **What:** the Apps settings tab lets an admin override each app's icon URL (`icon_url` / `icon_bg`) but shows no preview - you can't see what the icon looks like (or whether the URL loads) until you save and view the front end.
- **Where:** the apps table in the settings page render (see `render()`'s Settings tab / apps table where `icon_url` is edited).
- **Plan:** render a small `<img>` next to each icon URL field (the override URL if set, otherwise the app's default from `app_registry()`), with an `onerror` fallback; escape via `esc_url()`/`esc_attr()`.

## Closed decisions

- **One edition only** (no standard/Pro split): decided by the user, 2026-11-11.
- **Apps limited to Podcasting 2.0 apps** (Fountain, CurioCaster, Podverse, Podfriend, BoostMeBuddy, Castamatic, TrueFans, Podcast Index, Podcast Guru); no Apple/Spotify-style mainstream targets.
- **Link level is show + episode** (not show-only).
- **Fountain always renders a button**, degrading episode → show → `fountain.fm/discover` (user decision 2026-11-12); supersedes 0.1.2's "omit the button on an unreliable lookup" policy.
