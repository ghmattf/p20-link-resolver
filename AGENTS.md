# Project instructions

Read this first. It is the single entry point for any agent (or human) working in this repo.
`CLAUDE.md` just imports this file, so there is one source of truth.

## What this is

**Podcast 2.0 Link Resolver** (`p20-link-resolver`): a WordPress plugin that renders pod.link-style
smart links to a podcast show or one episode in **Podcasting 2.0 apps only** (Fountain, CurioCaster,
Podverse, Podfriend). Metadata comes from the Podcast Index API. It is the podcast sibling of
[`ghmattf/v4v-musiclink-pro`](https://github.com/ghmattf/v4v-musiclink-pro) (music, Music Index API)
and deliberately follows its architecture and conventions. When a design question comes up, check
how that plugin solved it. There is **one edition only**, with the "Pro"-style feature set.

Author/maintainer: Matt Finlay. Licence: GPL-2.0.

## Repository map

| Path | What |
|---|---|
| `p20-link-resolver.php` | The whole plugin (single file, one class `P20_Link_Resolver`) |
| `tests/resolver_test.php` | Offline regression harness (WordPress stubs + mocked Podcast Index API) |
| `tests/run.sh` | Lint + run every `tests/*_test.php` |
| `bump-version.sh` | Bumps the version in both places and commits the plugin file |
| `README.md` | User-facing docs (usage, app link table, "not verified live") |
| `CHANGELOG.md` | Keep-a-Changelog, newest first |
| `CONTRIBUTING.md` | Workflow, how to add an app, release flow |
| `OUTSTANDING.md` | The single running list of open items (see below) |
| `.github/workflows/ci.yml` | CI: lint + tests on PHP 7.4 / 8.1 / 8.3 |

## Commands

```bash
./tests/run.sh                      # lint + all tests (do this before every commit)
php -l p20-link-resolver.php        # lint only
php tests/resolver_test.php         # one harness
./build-zip.sh                      # release zip in dist/ (wrapper-folder safe; release flow only)
```

PHP is expected on `PATH` (CI uses 7.4, 8.1 and 8.3; the code must stay 7.4-compatible: no
`match`, no named arguments, no `str_contains`, no union types, no `?->`).
There is no WordPress install, database or network in the test environment, so **everything in
`tests/` must run offline** against stubs. If you add a WordPress function to the plugin that the
harness doesn't stub yet, add the stub to the harness.

## Architecture (all in `p20-link-resolver.php`, in this order)

1. **Options / credentials**: `opt()`, `credentials()`. Constants `P20LINK_API_KEY` / `P20LINK_API_SECRET` override settings.
2. **Identifier guards**: `guard_show()` (podcast:guid or numeric feed id, lowercased, `[A-Za-z0-9-]{1,64}`), `guard_episode()` (arbitrary guid, control chars stripped, max 512). Every visitor-supplied id goes through these.
3. **Database**: `p20link_cache` (one row per show/episode, keyed on `md5(show|episode)`; carries the derived Fountain URL in `fountain_url` / `fountain_date` and the feed URL in `feed_url` for Podcast Guru's md5 addressing) and `p20link_log`. Created by `create_tables()` on activation **and** on the next admin load (`maybe_create_tables()`), because deployment is by direct file overwrite and activation hooks don't fire.
4. **Podcast Index API**: `api_headers()` (sha1 auth), `api_get()`, `fetch_show()`, `fetch_episode()`. Negative caching via `neg_ttl()` / `neg_key()`.
5. **Resolution**: `resolve_release()` is cache-first, refreshes stale rows on the `shutdown` hook (`run_queued_refresh()`), and returns the show even when the episode is unknown (`episode_missing`).
6. **Apps**: `app_registry()` and `app_link()` (the URL patterns), plus per-app settings, ordering and icon helpers. Fountain alone needs an id lookup: `fountain_id()` / `fountain_lookup()` / `fountain_derive()` (see Gotchas); its derived URL is remembered on the cache row for 30 days.
7. **Front end**: `render()` (shortcode), `render_html()`, `render_search_ui()`, social tags, `inline_css()` / `inline_js()` (a nowdoc, so PHP lint does not check it; see Gotchas).
8. **AJAX**: `ajax_search()` (search relay; a pasted share link short-circuits it via `search_direct_link()` - Fountain first, then the other apps' URL shapes in `search_app_link()` / `apple_ref()` / `feedid_ref()` / `podverse_reverse_ref()`), `ajax_log_open()` / `ajax_log_app()` (usage beacons).
9. **Admin**: settings page with Settings / View Log / Reports / Cache tabs and `admin-post` handlers.

## Conventions and invariants (do not break these)

- **Naming/isolation**: everything is prefixed `p20link` / `p20l_` / `P20_Link_` (options `p20link_*`, tables `{prefix}p20link_*`, shortcode `[p20link]`, AJAX actions `p20link_*`, nonces `p20link_search` / `p20link_log`, asset handle `p20-link`). Keep new names inside that namespace.
- **Output escaping**: every value reaching HTML is escaped (`esc_html` / `esc_attr` / `esc_url`). The inline JS builds DOM with `textContent`, never `innerHTML` with data.
- **Outbound HTTP**: always `redirection => 0` (SSRF hardening), short timeouts, Podcast Index only. Never follow a remote redirect.
- **Rate limits are per-IP on `REMOTE_ADDR` only** (never `X-Forwarded-For`). Upstream lookups driven by visitor-supplied ids spend the `resolve` budget (`may_call_upstream()`); cached serves and remembered misses are free.
- **Logging stores resolved titles only, never raw guids**, and page renders are never logged (only search picks and app launches).
- **Failure policy**: a definitive answer (HTTP 200 with an empty feed/episode, or 404) is remembered for an hour on the Podcast Index side and 30 minutes for Fountain lookups (`FOUNTAIN_FAIL_TTL`); anything unreliable (timeout, 5xx, 401/403, undecodable body) for 30 seconds. Stale cache rows keep serving through an outage.
- **Graceful degradation**: an app whose link can't be built is omitted; an app that can only link the show gets "(opens the show)"; an unknown episode degrades to the show with a notice. Exception: Fountain never omits - it degrades episode → show → `fountain.fm/discover` and says "(opens Fountain)" when it lands on discover.
- **No secrets in output**: the API secret is never echoed (settings show a placeholder; a blank field keeps the stored secret).
- Bump `NEG_SCHEMA` if you change what a remembered miss means; bump `TABLE_VERSION` if a `CREATE TABLE` changes.

## External facts and where they came from

- **Podcast Index API**: base `https://api.podcastindex.org/api/1.0`. Auth headers: `User-Agent`, `X-Auth-Key`, `X-Auth-Date` (unix time), `Authorization` = `sha1(key . secret . time)`. Endpoints used: `podcasts/byguid?guid=`, `podcasts/byfeedid?id=`, `episodes/byguid?guid=&feedid=`, `episodes/byfeedid?id=&max=`, `search/byterm?q=&max=`. Source of truth is the OpenAPI source in [`Podcastindex-org/docs-api`](https://github.com/Podcastindex-org/docs-api) under `api_src/` (paths in `api_src/paths/**`, fields in `api_src/components/**`).
- **App URL patterns**: [`Podcastindex-org/podcast-platform-links`](https://github.com/Podcastindex-org/podcast-platform-links) `README.md` (show and episode tables). `app_link()` mirrors it - **except Fountain** (see Gotchas); **BoostMeBuddy** (the app's own `?podcast=&episode=` guid params, both URLs verified live 2026-11-12; its site has no `/favicon.ico`, so the registry carries a default `icon`); **Podfriend** (the guide's Apple-id show route is 410 and every `{base}/{episode guid}` subpath 404s - keyed on the PI feed id / `podcast:guid`, episode opens the show, live 2026-11-20); and **Podcast Guru** (guide's Apple id first, `md5(feed URL)` fallback for PI-only shows, live 2026-11-20 - see Gotchas). Icons: five apps can't use the generic `https://{domain}/favicon.ico` default and carry a registry `icon` instead - BoostMeBuddy (no `/favicon.ico`), Podcast Index (every asset 403s), TrueFans (cross-site referers 403) and Podfriend / Podcast Guru (no `/favicon.ico`, and their real icon URLs send a `Cross-Origin-Resource-Policy` the browser enforces on `<img>`) - see Gotchas. If that guide changes, update `app_link()`, the README table and the tests together.
- The Podcast Index `feed.id` is what the guide calls `podcastIndexShowID`; `episode.id` is `podcastIndexEpisodeID`; `feed.itunesId` is `appleID`.

## Gotchas

- **Fountain's URLs use opaque ids, not Podcast Index ids.** The platform-links guide's `fountain.fm/show|episode/{PI id}` patterns render Fountain's client-side 404 (verified live 2026-11-12; a wrong path still returns HTTP 200). `fountain_id()` resolves `podcast:guid` / episode guid through Fountain's public lookup API (`POST api.fountain.fm/v1/search/lookup`, documented, **no key required**), cached in `p20l_fnt_*` transients (30-day hits, 30 min / 30 s misses via `fountain_ttl()` - `FOUNTAIN_FAIL_TTL` is deliberately shorter than `neg_ttl()`'s hour). Misses are split for caching: **definitive** (200/404 - Fountain really lacks it) remembered 30 minutes; **unreliable** (transport/5xx/budget) return `null`, are cached under the `~` sentinel (outside the id alphabet) so they can't read as definitive, and retry in 30 s. `app_link()` degrades episode → show → `fountain.fm/discover` for both kinds (user decision 2026-11-12) - the button always renders and never opens the wrong episode; the UI note is "(opens the show)" or "(opens Fountain)". Don't "simplify" it back to a plain URL pattern. The derived URL is additionally remembered on the cache row (`fountain_url` / `fountain_date`, `DERIVED_URL_TTL` 30 days): a fresh one is served with **no** Fountain call; a stale one is re-derived via `fountain_derive()`; when re-derivation produces no real id the stale URL is served silently instead of degrading to discover, and only with no cache at all does discover appear. `cache_store()` must carry both columns across its REPLACE or a metadata refresh silently drops the derived URL.
- **Podverse's episode id comes from its public url-resolver**, not the cache row: `GET api.podverse.fm/api/v1/url-resolver/episode?podcastIndexId={PI feed id}&episodeGuid={guid}` returns `{"webUrl":"https://podverse.fm/episode/{id}"}` (200) or `{"message":"Episode not found"}` (404) - verified live 2026-11-16. `podverse_episode_url()` caches it in `p20l_pve_*` transients exactly like `fountain_id()` (definitive 404 = hour, unreliable = 30 s under the `~` sentinel), refuses any `webUrl` outside the guide's `https://podverse.fm/episode/{id}` shape, and degrades to the show link on miss/failure. Don't "simplify" it back to show-only.
- **Pasted Fountain share links reverse to guids via `GET /v1/content/{type}:id:{id}`** (`search_fountain_link()` → `fountain_reverse()`, verified live 2026-11-16): the path regex demands exactly 20 id chars; 200 gives `hit._guid` (+ the show guid in `parent._guid` or `links[]` for episodes), 404 is definitive. Cache order is deliberate: match the row's remembered `fountain_url` first (no HTTP), then the API, with transients `p20l_frv_*`. Don't reorder those steps, and don't route plain-title searches through it (regex must return null for non-Fountain input).
- **The other apps' pasted share links resolve through `search_direct_link()`** (Fountain first, then `search_app_link()`'s host switch; same null / `array()` / `false` contract as the Fountain search, same ajax wiring). Guid/feed-id shapes (CurioCaster `pi{id}`, Podcast Index / Castamatic / TrueFans paths, BoostMeBuddy query params, `api.podverse.fm/.../podcastindex/{id}`) parse with **no HTTP**; schemeless pastes get `https://` prepended, `www.` is stripped, query strings ignored. Podfriend's digits go through `feedid_ref()` (PI `podcasts/byfeedid`, transients `p20l_fid_*`) **first** - Podfriend's current URL space - and only fall back to `apple_ref()` (`podcasts/byitunesid`, transients `p20l_aid_*`) for legacy Apple-id pastes; a `podcast:guid` pastes straight through, a slug-canonical paste is `null` (can't be reversed offline). Podcast Guru's Apple-id pastes use `apple_ref()` - **that endpoint is doc-only** (OpenAPI on `master`; the sandbox has no PI credentials, so never call it live-verified). Podverse web ids reverse through Podverse's own API via `podverse_reverse_ref()` (transients `p20l_pvr_*`, verified live 2026-11-18): the **show** response carries `podcastGuid` / `podcastIndexId` at the top level, the **episode** response nests them under `podcast` alongside the RSS `guid` - reading both with `$body['podcast']` silently breaks show links; an episode whose payload has no usable guid degrades to its show. Misses: apple/feedid = `''`/`~` through `neg_ttl()` (404 counts as definitive), podverse = `array()` remembered `PODVERSE_FAIL_TTL` / `~` at `SOFT_FAIL_TTL`. Bump `DIRECT_SCHEMA` (with `p20l_aid_` / `p20l_fid_` / `p20l_pvr_`) to invalidate all of these. Regex delimiter: never `#` when the pattern contains `[^/?#]` - it cuts the pattern short (bit both this and the fountain path regex).
- **The agent sandbox usually cannot reach the apps or the Podcast Index** (network allowlist). Read those docs through the GitHub tools (`get_file_contents`) instead of `curl`. Never claim a link pattern or API field was "tested live" unless you actually hit it; the README's "Not verified live" section must stay honest. (fountain.fm and api.fountain.fm were reachable from Matt's machine on 2026-11-12 - don't assume that holds.)
- **Inline JS is inside a PHP nowdoc**, so `php -l` can't see JS syntax errors. After editing it, extract and check it: `awk "/<<<'JS'/{f=1;next}/^JS;/{f=0}f" p20-link-resolver.php > /tmp/inline.js && node --check /tmp/inline.js`.
- Activation hooks don't fire on direct file overwrite (Matt's deploy method), hence `maybe_create_tables()`.
- Stale transients are the first suspect when something looks broken; bump `NEG_SCHEMA` rather than telling users to clear them by hand.
- **Podfriend links on the PI feed id, not the Apple id** (live 2026-11-20): the guide's `podfriend.com/podcast/{appleID}` route is 410 Gone; `/podcast/{PI feed id}` is 200 (301 to the slug-canonical), `/podcast/{podcast:guid}` is 200, and **every** `/podcast/{base}/{episode guid}` form 404s - so `app_link()` builds show URLs only and an episode request opens the show (guide's episode pattern is dead too; Podfriend's own episode URLs are `/podcast/{slug}/episode/{internal id}-{slug}`, not buildable from PI data).
- **Podcast Guru can address shows with no Apple id** (live 2026-11-20): its show id is `md5(feed URL)` - `/podcast/{appleID}` 301s to `/podcast/{slug}-{md5(feed URL)}` and the bare hex route renders directly; `podcast:guid` and `pi{id}` forms 404. `app_link()` therefore prefers the Apple id (guide pattern) and falls back to `md5($row->feed_url)` - hence the `feed_url` column (`TABLE_VERSION` 3); rows cached before the upgrade pick it up on their next background refresh (12 h default). Don't trim/normalise the feed URL before hashing - it must match what Podcast Guru stored.
- **TrueFans' favicon refuses cross-site referers** (403, Cloudflare error 1011, live 2026-11-20): every `truefans.fm` asset 403s when the page's `Referer` is another site, so the generic `https://truefans.fm/favicon.ico` default renders as a broken image on link pages. The registry's `icon` for TrueFans is the referer-safe DuckDuckGo mirror `icons.duckduckgo.com/ip3/truefans.fm.ico` (serves the byte-identical favicon with or without a referer) - same registry-`icon`-override pattern as BoostMeBuddy.
- **Podfriend, Podcast Guru and Podcast Index have no usable `/favicon.ico`** (checked live 2026-11-20): `podfriend.com/favicon.ico` and `app.podcastguru.io/favicon.ico` are 404, and `podcastindex.org` answers **every** path with 403 and the body "don't scrape our website. use our api instead." - so the direct replacements shipped in 0.1.13 (`podfriend.com/favicon-192.png`, `app.podcastguru.io/favicons/android-icon-192x192.png`) looked right to `curl` (200 with a cross-site referer) but still did not render in a browser: Podfriend sends `cross-origin-resource-policy: same-site` and Podcast Guru `same-origin`, which browsers enforce on cross-origin `<img>` loads (curl ignores the header). 0.1.14 points all three at `icons.duckduckgo.com/ip3/{domain}.ico`, which sends no CORP and serves each app's own logo (all three eyeballed: Podfriend's blue face, Podcast Guru's lotus mic, Podcast Index's microphone). **When checking icons, `curl` is not enough** - load them from a cross-origin page in a browser: `.scratch/` has a `registry-icons.html` harness served over `python3 -m http.server` and screenshotted with `firefox --headless --screenshot` (the load event is held open by a slow script so Firefox can't capture early; a `background:#eee` box left grey = refused). Caveat: an icon with a transparent centre (CurioCaster's) shows the grey box through it - count distinct colours before calling it broken. Settings previews hide a broken URL via `onerror`, which is why these three simply vanished from the Settings tab.
- `encodeURIComponent()` and `rawurlencode()` differ on `!*'()`; `encode_uri_component()` exists to match the guide. Don't "simplify" it.

## Outstanding items (running list)

`OUTSTANDING.md` is the single running list of open items. Maintain it as follows:

- Any finding, idea or request that is discussed but **not implemented in the same turn** must be added before the turn ends, with enough context to continue cold: what, where (file + symbol), why, a plan sketch, and a status.
- Statuses: `Planned` (the user picked it; implement only on request), `On hold` (user deferred), `Open` (identified, awaiting triage).
- Remove an item once it is **implemented and released** (CHANGELOG + git history carry it) or **explicitly declined**. Never leave decided items on the list, and never implement an item the user hasn't picked.
- Update the item's status in the same turn as any work on it.
- "Closed decisions" holds one-line context for declined/concluded items so they aren't re-proposed; it is not a work list.
- Anything that also affects the music plugin is tracked in **both** repos with a cross-reference; implement only this repo's half here.

## Release flow (code releases only, never docs-only changes)

1. `./tests/run.sh` must be green.
2. `./bump-version.sh x.y.z`: updates the plugin header `Version:` and `const VERSION`, re-lints, and commits **only** the plugin file with subject `Bump x.y.z`. Use `+0.0.1` for small changes and `+0.1.0` for larger features.
3. Add a `CHANGELOG.md` entry at the top, Keep-a-Changelog style, dated today: `## [x.y.z] - YYYY-MM-DD`.
4. Commit `CHANGELOG.md` (plus `OUTSTANDING.md` if statuses changed) with subject `x.y.z: <summary>`. Never `git add -A` blindly; check `git status` first.
5. Annotated, `v`-prefixed tag: `git tag -a vx.y.z -m "x.y.z"`, then `git push && git push origin vx.y.z`.
6. `./build-zip.sh`: builds `dist/p20-link-resolver.zip` - deliberately **without** the version in the filename (the `vx.y.z` release tag identifies the build) - and fails if any file sits outside the top-level `p20-link-resolver/` folder. **Never hand-roll the `zip` command** - a flat zip makes WordPress install a new plugin folder (named after the zip) on every upload instead of overwriting the existing one. Then `gh release create vx.y.z dist/p20-link-resolver.zip --title "x.y.z" --notes "<summary>"`.
7. Update `OUTSTANDING.md` in the same turn and remove anything just shipped.

## Not part of the project

- `.env*`: never read or report on these.
- `dist/` and `*.zip`: build output, gitignored.
- `.scratch/`: local probe downloads (icons, fetched HTML), gitignored - use it instead of `/tmp/` for throwaway files.
- Don't add third-party libraries or a build step; the plugin is deliberately one dependency-free PHP file so it can be deployed by copying it.
