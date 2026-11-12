# Outstanding items

The single running list of open work. Rules for maintaining it are in `AGENTS.md`
("Outstanding items"). Statuses: `Planned` (user picked it), `On hold`, `Open` (awaiting triage).

## Open

### 1. Live-verify app URL patterns and Podcast Index field names
- **Status:** Open (highest priority before a public release) - **Fountain done 2026-11-12** (guid lookup + rendered show/episode pages verified live; the guide's numeric Fountain patterns were dead and are replaced by `fountain_id()`, shipped in 0.1.1).
- **What:** the remaining three app link patterns (`app_link()` cases `curiocaster` / `podverse` / `podfriend`) come from the Podcast Index platform-links guide, and the API field names (`feed.id`, `feed.podcastGuid`, `feed.itunesId`, `episode.id`, `items[]`, `feeds[]`) come from the docs-api OpenAPI source. None of those were hit live. 0.1.0 was built and tested offline.
- **Plan:** with real API credentials, resolve one show + one episode and open each remaining app's link by hand; confirm favicon URLs at `https://{domain}/favicon.ico`. Record results in the README's "Verification status" section and delete entries once confirmed. Fix `app_link()` / icon defaults if anything differs, with a test.

### 2. Direct input in the search box (feed URL, Apple id, podcast guid)
- **Status:** Open
- **What:** the search box only does a title search (`search/byterm`). People often have a feed URL or an Apple Podcasts id instead.
- **Where:** `ajax_search()`, `inline_js()` search handler.
- **Plan:** detect a URL (`podcasts/byfeedurl`), an `id123456` / numeric Apple id (`podcasts/byitunesid`) or a UUID (`podcasts/byguid`) and jump straight to the result.

### 3. Episode links for Podverse and CurioCaster
- **Status:** Open
- **What:** Podverse needs a Podverse-internal episode id and CurioCaster has no documented episode pattern, so both degrade to the show link today.
- **Where:** `app_link()` cases `podverse` / `curiocaster`.
- **Plan:** check whether Podverse's API can map a Podcast Index episode id or guid to its id (would need a resolver + cache column), and whether CurioCaster has since added episode routes. Update the platform-links table in the README if so.

### 4. Cache tab: per-row editing
- **Status:** Open
- **What:** the music plugin lets an admin hand-correct a cached link/artwork URL; here the Cache tab only deletes rows.
- **Where:** `render_cache_tab()`; add an `admin-post` handler like `handle_delete_cache_row()` with validation (https, app's own domain for links).
- **Plan:** only worth doing if apps get resolved links that can go stale; today all links are formulaic.

### 5. `uninstall.php`
- **Status:** Open
- **What:** uninstalling leaves the two tables and all `p20link_*` options behind.
- **Plan:** `uninstall.php` that drops `{prefix}p20link_cache` / `{prefix}p20link_log` and deletes the options and `p20l_*` transients. Confirm with the user first (the log is usage data).

### 6. Document-title and canonical-URL overrides for shared links
- **Status:** Open
- **What:** the music plugin rewrites `<title>` and the canonical URL for guid'd URLs; this plugin only adds Open Graph / Twitter tags (`output_social_preview_meta()`), so an SEO plugin may emit its own title/canonical for `?show=` pages.
- **Plan:** port `override_document_title` / canonical fix from `ghmattf/v4v-musiclink-pro`, behind the existing social-preview setting. Needs the harness extended with a few more WordPress stubs.

### 7. Internationalisation
- **Status:** Open
- **What:** strings use the `p20-link` text domain but there is no `.pot` file or `load_plugin_textdomain()` call.
- **Plan:** generate a `.pot` (`wp i18n make-pot`) and load the domain on `init`.

### 8. Custom date range in Reports
- **Status:** Open
- **What:** `render_reports_tab()` offers only 7 / 30 / 90 days and all time; the music plugin also has custom ranges and an artist filter (here: a show filter).

### 9. Music plugin: consider the official Fountain API
- **Status:** Open (cross-repo; music half not yet added to `ghmattf/v4v-musiclink-pro`)
- **What:** Fountain now has a documented public API (`api.fountain.fm/v1`, see `fountain.fm/docs.md`) - verified working 2026-11-12. This repo switched `fountain_id()` to it; the music plugin still uses the unofficial Next.js server-action (`fountain_try_resolve()`) with a self-healing `next-action` fingerprint that breaks on every Fountain redeploy. `POST /v1/search/content` (query + `types`) might replace it for TRACK/ALBUM if its hits' `_guid` values match Music Index guids - unverified for music.
- **Plan (music repo only):** probe `/v1/search/content` against a known music guid; if it matches, port `fountain_resolve()` to it, keeping the search-URL fallback. Implement only that repo's half there.

### 10. Document hardcoded shortcode attributes in the plugin description
- **Status:** Open
- **What:** `[p20link show="..." episode="..."]` hardcoding works and is documented in the file's USAGE comment block and README ("Inline placements"), but the plugin header `Description:` - what WordPress shows on the admin Plugins screen - only says "Shortcode: [p20link]" with no attribute hint.
- **Where:** `p20-link-resolver.php` plugin header `Description:` (line 4).
- **Plan:** append a compact example to the Description, e.g. `Shortcode: [p20link] or [p20link show="guid" episode="guid"]` (watch overall length - WP truncates long descriptions in the list view), and keep README in sync if the wording changes.

### 11. Live test: Fountain episode link opened the show (phafe.com)
- **Status:** Open
- **What:** report from Matt, 2026-11-12: on `https://phafe.com/podcast2-0linker/?show=31e6e997-df26-59d5-9301-1f5928809566&episode=c498fddf-ce54-4e1c-a967-4e84958108ad` the Fountain button opened the show instead of the episode. Verified after the report: the live render already emits `fountain.fm/episode/7KvnNqgwjVyooeb35qd1`, Fountain's lookup with exactly those guids returns that `EPISODE` id, and the page renders the correct episode - so the bad click hit a fallback window: an unreliable (soft/budget) episode-lookup miss degrades to the cached show link for up to 30 s (`fountain_id()` stored soft misses as `''`, indistinguishable from a definitive "not in Fountain"), or the render was served from a pre-deploy cache.
- **Where:** `app_link()` fountain case, `fountain_id()` miss caching.
- **Plan:** split miss kinds - definitive (200/404, Fountain genuinely lacks it) keeps the show fallback; unreliable (transport/5xx/budget) returns `null` and omits the button, never a wrong-target link; soft misses cached under a sentinel outside the id alphabet so they can't read as definitive. Tests for both paths; re-test the URL above after deploy.

## Closed decisions

- **One edition only** (no standard/Pro split): decided by the user, 2026-11-11.
- **Apps limited to Podcasting 2.0 apps** (Fountain, CurioCaster, Podverse, Podfriend); no Apple/Spotify-style mainstream targets.
- **Link level is show + episode** (not show-only).
