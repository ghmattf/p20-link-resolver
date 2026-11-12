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
```

PHP is expected on `PATH` (CI uses 7.4, 8.1 and 8.3; the code must stay 7.4-compatible: no
`match`, no named arguments, no `str_contains`, no union types, no `?->`).
There is no WordPress install, database or network in the test environment, so **everything in
`tests/` must run offline** against stubs. If you add a WordPress function to the plugin that the
harness doesn't stub yet, add the stub to the harness.

## Architecture (all in `p20-link-resolver.php`, in this order)

1. **Options / credentials**: `opt()`, `credentials()`. Constants `P20LINK_API_KEY` / `P20LINK_API_SECRET` override settings.
2. **Identifier guards**: `guard_show()` (podcast:guid or numeric feed id, lowercased, `[A-Za-z0-9-]{1,64}`), `guard_episode()` (arbitrary guid, control chars stripped, max 512). Every visitor-supplied id goes through these.
3. **Database**: `p20link_cache` (one row per show/episode, keyed on `md5(show|episode)`) and `p20link_log`. Created by `create_tables()` on activation **and** on the next admin load (`maybe_create_tables()`), because deployment is by direct file overwrite and activation hooks don't fire.
4. **Podcast Index API**: `api_headers()` (sha1 auth), `api_get()`, `fetch_show()`, `fetch_episode()`. Negative caching via `neg_ttl()` / `neg_key()`.
5. **Resolution**: `resolve_release()` is cache-first, refreshes stale rows on the `shutdown` hook (`run_queued_refresh()`), and returns the show even when the episode is unknown (`episode_missing`).
6. **Apps**: `app_registry()` and `app_link()` (the URL patterns), plus per-app settings, ordering and icon helpers. Fountain alone needs an id lookup: `fountain_id()` / `fountain_lookup()` (see Gotchas).
7. **Front end**: `render()` (shortcode), `render_html()`, `render_search_ui()`, social tags, `inline_css()` / `inline_js()` (a nowdoc, so PHP lint does not check it; see Gotchas).
8. **AJAX**: `ajax_search()` (search relay), `ajax_log_open()` / `ajax_log_app()` (usage beacons).
9. **Admin**: settings page with Settings / View Log / Reports / Cache tabs and `admin-post` handlers.

## Conventions and invariants (do not break these)

- **Naming/isolation**: everything is prefixed `p20link` / `p20l_` / `P20_Link_` (options `p20link_*`, tables `{prefix}p20link_*`, shortcode `[p20link]`, AJAX actions `p20link_*`, nonces `p20link_search` / `p20link_log`, asset handle `p20-link`). Keep new names inside that namespace.
- **Output escaping**: every value reaching HTML is escaped (`esc_html` / `esc_attr` / `esc_url`). The inline JS builds DOM with `textContent`, never `innerHTML` with data.
- **Outbound HTTP**: always `redirection => 0` (SSRF hardening), short timeouts, Podcast Index only. Never follow a remote redirect.
- **Rate limits are per-IP on `REMOTE_ADDR` only** (never `X-Forwarded-For`). Upstream lookups driven by visitor-supplied ids spend the `resolve` budget (`may_call_upstream()`); cached serves and remembered misses are free.
- **Logging stores resolved titles only, never raw guids**, and page renders are never logged (only search picks and app launches).
- **Failure policy**: a definitive answer (HTTP 200 with an empty feed/episode, or 404) is remembered for an hour; anything unreliable (timeout, 5xx, 401/403, undecodable body) for 30 seconds. Stale cache rows keep serving through an outage.
- **Graceful degradation**: an app whose link can't be built is omitted; an app that can only link the show gets "(opens the show)"; an unknown episode degrades to the show with a notice.
- **No secrets in output**: the API secret is never echoed (settings show a placeholder; a blank field keeps the stored secret).
- Bump `NEG_SCHEMA` if you change what a remembered miss means; bump `TABLE_VERSION` if a `CREATE TABLE` changes.

## External facts and where they came from

- **Podcast Index API**: base `https://api.podcastindex.org/api/1.0`. Auth headers: `User-Agent`, `X-Auth-Key`, `X-Auth-Date` (unix time), `Authorization` = `sha1(key . secret . time)`. Endpoints used: `podcasts/byguid?guid=`, `podcasts/byfeedid?id=`, `episodes/byguid?guid=&feedid=`, `episodes/byfeedid?id=&max=`, `search/byterm?q=&max=`. Source of truth is the OpenAPI source in [`Podcastindex-org/docs-api`](https://github.com/Podcastindex-org/docs-api) under `api_src/` (paths in `api_src/paths/**`, fields in `api_src/components/**`).
- **App URL patterns**: [`Podcastindex-org/podcast-platform-links`](https://github.com/Podcastindex-org/podcast-platform-links) `README.md` (show and episode tables). `app_link()` mirrors it - **except Fountain** (see Gotchas). If that guide changes, update `app_link()`, the README table and the tests together.
- The Podcast Index `feed.id` is what the guide calls `podcastIndexShowID`; `episode.id` is `podcastIndexEpisodeID`; `feed.itunesId` is `appleID`.

## Gotchas

- **Fountain's URLs use opaque ids, not Podcast Index ids.** The platform-links guide's `fountain.fm/show|episode/{PI id}` patterns render Fountain's client-side 404 (verified live 2026-11-12; a wrong path still returns HTTP 200). `fountain_id()` resolves `podcast:guid` / episode guid through Fountain's public lookup API (`POST api.fountain.fm/v1/search/lookup`, documented, **no key required**), cached in `p20l_fnt_*` transients (30-day hits, 1 h / 30 s misses via `fountain_ttl()`). Don't "simplify" it back to a plain URL pattern.
- **The agent sandbox usually cannot reach the apps or the Podcast Index** (network allowlist). Read those docs through the GitHub tools (`get_file_contents`) instead of `curl`. Never claim a link pattern or API field was "tested live" unless you actually hit it; the README's "Not verified live" section must stay honest. (fountain.fm and api.fountain.fm were reachable from Matt's machine on 2026-11-12 - don't assume that holds.)
- **Inline JS is inside a PHP nowdoc**, so `php -l` can't see JS syntax errors. After editing it, extract and check it: `awk "/<<<'JS'/{f=1;next}/^JS;/{f=0}f" p20-link-resolver.php > /tmp/inline.js && node --check /tmp/inline.js`.
- Activation hooks don't fire on direct file overwrite (Matt's deploy method), hence `maybe_create_tables()`.
- Stale transients are the first suspect when something looks broken; bump `NEG_SCHEMA` rather than telling users to clear them by hand.
- Podfriend needs the Apple Podcasts id; shows without one legitimately have no Podfriend button.
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
6. Update `OUTSTANDING.md` in the same turn and remove anything just shipped.

## Not part of the project

- `.env*`: never read or report on these.
- `dist/` and `*.zip`: build output, gitignored.
- Don't add third-party libraries or a build step; the plugin is deliberately one dependency-free PHP file so it can be deployed by copying it.
