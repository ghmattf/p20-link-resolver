# Podcast 2.0 Link Resolver

A WordPress plugin that renders smart links to a podcast show or a single episode in **Podcasting 2.0 apps only** (Fountain, CurioCaster, Podverse, Podfriend, BoostMeBuddy, Castamatic, TrueFans, Podcast Index, Podcast Guru, Breez). A pod.link alternative for general podcasts, modelled on V4V MusicLink Pro.

Metadata (title, author, artwork, ids) comes from the [Podcast Index API](https://api.podcastindex.org) (free key + secret).

## Usage

1. Activate the plugin and enter your Podcast Index API key and secret under **Settings → Podcast 2.0 Link** (or define `P20LINK_API_KEY` / `P20LINK_API_SECRET` in `wp-config.php`).
2. Create a page containing `[p20link]`, e.g. slug `podlink`.

```
https://example.com/podlink                                   search box
https://example.com/podlink?show={podcast-guid}               a show
https://example.com/podlink?show={podcast-guid}&episode={episode-guid}
```

`show` is the `podcast:guid` (or a numeric Podcast Index feed id); `episode` is the episode's RSS `<guid>`.

Inline placements work in any post or page:

```
[p20link show="{podcast-guid}"]
[p20link show="{podcast-guid}" episode="{episode-guid}"]
[p20link show="{podcast-guid}" header="false"]
```

## How each app is linked

Patterns come from the Podcast Index's [podcast-platform-links](https://github.com/Podcastindex-org/podcast-platform-links) guide, except Fountain (see below) and BoostMeBuddy (the app's own guid query params).

| App | Show link | Episode link |
|---|---|---|
| Fountain | `fountain.fm/show/{Fountain id}` resolved from `podcast:guid` | `fountain.fm/episode/{Fountain id}` resolved from the episode guid |
| CurioCaster | `curiocaster.com/podcast/pi{PI feed id}` | no episode route (verified 2026-11-16) - opens the show |
| Podverse | `api.podverse.fm/api/v1/podcast/podcastindex/{PI feed id}` | `podverse.fm/episode/{Podverse id}` resolved from PI feed id + episode guid |
| Podfriend | `podfriend.com/podcast/{PI feed id}` (a `podcast:guid` also works) | needs a Podfriend-internal id - opens the show |
| BoostMeBuddy | `www.boostmebuddy.com/?podcast={podcast:guid}` | `www.boostmebuddy.com/?podcast={podcast:guid}&episode={episode guid}` |
| Castamatic | `castamatic.com/guid/{podcast:guid}` | needs a Castamatic-internal id - opens the show |
| TrueFans | `truefans.fm/{podcast:guid}` | needs a TrueFans-internal id - opens the show |
| Podcast Index | `podcastindex.org/podcast/{podcast:guid}` (PI feed id also accepted) | no episode permalink documented - opens the show |
| Podcast Guru | `app.podcastguru.io/podcast/{Apple id}`, or `app.podcastguru.io/podcast/{md5(feed URL)}` for shows with no Apple id | needs a Podcast Guru-internal id - opens the show |
| Breez | `breez.link/p?feedURL={feed URL}` | `breez.link/p?feedURL={feed URL}&episodeID={episode guid}` |

Podfriend keys on the Podcast Index feed id (its guide-listed Apple-id route is gone - 410), so it appears for every resolved show. Podcast Guru prefers the Apple id and falls back to `md5(feed URL)` for shows listed only in the Podcast Index (the feed URL is stored on the cache row; rows cached before the upgrade pick it up on their next background refresh). Breez is keyed entirely on the feed URL (both its show and its episode pattern need `feedURL`), so it is hidden when the row has no feed URL - rows cached before the `feed_url` column was added pick it up on their next background refresh. Castamatic, TrueFans and Podcast Index are keyed on the `podcast:guid` (Podcast Index falls back to the feed id) and are hidden when neither is known. When an episode was requested but an app can only link the show, its button says "(opens the show)". If the Podcast Index doesn't know the episode yet, every app falls back to the show and a notice explains why.

Podverse episode links are resolved through Podverse's public url-resolver (`GET api.podverse.fm/api/v1/url-resolver/episode?podcastIndexId={PI feed id}&episodeGuid={guid}` -> `{"webUrl":"https://podverse.fm/episode/{id}"}`, no key required) and cached like the Fountain lookups; a miss or lookup failure falls back to the show link. CurioCaster has no episode route at all - its client route list contains only `/`, `/fn`, `/podcast` and `/podcast/[podcastid]` (verified live 2026-11-16) - so its episode link opens the show.

Fountain's public URLs use **opaque Fountain ids**, not Podcast Index ids - the guide's numeric `fountain.fm/show|episode/{PI id}` patterns render Fountain's client-side 404 (verified live 2026-11-12). The plugin resolves guid → id through Fountain's public [lookup API](https://fountain.fm/docs/endpoints/search/search-lookup.md) (`POST api.fountain.fm/v1/search/lookup`, no key required) and caches the result: lookups in short transients, the derived URL on the cache row for 30 days (a fresh row entry is served without calling Fountain at all). Links degrade **episode → show → `fountain.fm/discover`**, so the Fountain button always appears and never opens the wrong episode; when it can't open the requested episode the note says what it opens instead ("(opens the show)" / "(opens Fountain)"). Nothing unofficial or fragile - unlike the music plugin's Fountain integration, no build fingerprint is involved.

## Features

- Search UI: search shows, then link to the whole show or pick one of its 25 latest episodes; paste a share link (Fountain, CurioCaster, Podcast Index, Castamatic, TrueFans, BoostMeBuddy, Podfriend, Podcast Guru or Podverse) to jump straight to that page
- `?show=` / `?episode=` URL mode and inline shortcode attributes (attributes win)
- Per-app controls: enable, order, icon URL (with a live preview of the icon the buttons will use), icon background; optional random order
- Cache table with stale-while-revalidate refresh (default 12 h, configurable), plus short negative caching for misses and a 30-day derived Fountain URL per row
- Usage log (search picks and app launches - titles only, never raw ids) with View Log, Reports and Cache admin tabs
- Open Graph / Twitter Card previews on link pages
- Per-IP rate limits on search, logging and visitor-driven upstream lookups

## Development

```bash
php -l p20-link-resolver.php
php tests/resolver_test.php     # offline: WordPress stubs + mocked Podcast Index API
```

Like the music plugin, tables are created on activation and again on the next admin page load, so deploying by overwriting the file also works.

## Verification status

Verified live on 2026-11-12:

- Fountain: guid → id lookup (show and episode, no API key) and the rendered `fountain.fm/show/{id}` / `fountain.fm/episode/{id}` pages; the guide's numeric patterns confirmed dead (client-side 404)
- BoostMeBuddy: the show and episode query-param URLs both render the right target; its site has no `/favicon.ico`, so the plugin defaults its icon to `https://www.boostmebuddy.com/icons/buddy/favicon.svg`

Verified live on 2026-11-16 (tested by Matt):

- The CurioCaster, Podverse, Podfriend, Castamatic, TrueFans and Podcast Index URL patterns (from the platform-links guide)
- The Podcast Index response field names (`feed.id`, `feed.podcastGuid`, `feed.itunesId`, `episode.id`, `items[]`, `feeds[]`)
- The default favicons at `https://{domain}/favicon.ico` (the three exceptions found on 2026-11-20 are listed below)

Verified live on 2026-11-16 (agent):

- Podverse's url-resolver endpoint: `GET api.podverse.fm/api/v1/url-resolver/episode?podcastIndexId={PI feed id}&episodeGuid={guid}` returns `{"webUrl":"https://podverse.fm/episode/{id}"}` (200) or `{"message":"Episode not found"}` (404); the show resolver returns the `podverse.fm/podcast/{id}` redirect target
- CurioCaster's client route list: only `/`, `/fn`, `/podcast` and `/podcast/[podcastid]` - no episode route exists
- Fountain's content endpoint as the reverse of the guid lookup: `GET api.fountain.fm/v1/content/{show|episode}:id:{Fountain id}` returns `hit._guid` plus, for episodes, the show guid in `parent._guid` / `links` (200; 404 for unknown ids); round-tripped back through `POST /search/lookup` with the example show/episode ids from a real share link

Verified live on 2026-11-18 (agent):

- Podverse's reverse lookups for pasted share links: `GET api.podverse.fm/api/v1/podcast/{web id}` returns `podcastGuid` and `podcastIndexId` at the top level, `GET api.podverse.fm/api/v1/episode/{web id}` returns the RSS `guid` plus the parent `podcast.podcastGuid` / `podcast.podcastIndexId` (200; 404 for unknown ids)

Verified live on 2026-11-20 (agent):

- TrueFans' hotlink protection: every `truefans.fm` asset (favicon, `/images/*`, even `www.`) returns 403 to a cross-site `Referer` (Cloudflare error 1011), so the button icon uses DuckDuckGo's icon mirror `icons.duckduckgo.com/ip3/truefans.fm.ico`, which serves the byte-identical favicon with or without a referer
- Podfriend's current URL space: `/podcast/{PI feed id}` is 200 (301 to the slug-canonical), `/podcast/{podcast:guid}` is 200, the guide's Apple-id route is 410 Gone, and `/podcast/{base}/{episode guid}` 404s on every base - episode links open the show
- Podcast Guru's addressing: `/podcast/{appleID}` is 301 to `/podcast/{slug}-{md5(feed URL)}` (200), the bare `md5(feed URL)` id renders directly, and `podcast:guid` / `pi{id}` forms 404
- Default icon URLs (checked 2026-11-20 with a cross-site `Referer`, then loaded in Firefox from a cross-origin page): `podfriend.com/favicon.ico` and `app.podcastguru.io/favicon.ico` are 404, `podcastindex.org` answers **every** asset request with 403 ("don't scrape our website"), and - the part `curl` can't see - Podfriend's real icons (`favicon-192.png`, `favicon.svg`) send `cross-origin-resource-policy: same-site` and Podcast Guru's (`favicons/android-icon-192x192.png`) send `same-origin`, so a browser refuses to draw them on another site's page. Those three apps therefore carry an explicit registry icon: the DuckDuckGo mirror `icons.duckduckgo.com/ip3/{domain}.ico` (sends no CORP, and serves each app's own logo - Podfriend's face, Podcast Guru's lotus mic, Podcast Index's microphone). Verified in Firefox: direct URLs show the broken-image marker, mirrors render. An icon with a transparent centre (CurioCaster's) still counts as loaded.
- Breez's icon (checked 2026-11-20): `breez.link/favicon.ico` is an HTML shell, not an image, so the registry points at the PNG Breez's own `<link rel="icon">` uses (`breez.technology/oim/favicon-32x32.png` - 200, no `Cross-Origin-Resource-Policy`, loaded from a cross-origin page in Firefox)

Not yet verified live - confirmed only against the documentation:

- The Podcast Guru pattern (added 2026-11-16; from the platform-links guide)
- The Breez pattern (added 2026-11-20; from the platform-links guide) - `breez.link/p?feedURL=...` answers 200 but returns the site's marketing shell byte-for-byte for a bogus feed URL too, so a correct target can't be confirmed from the sandbox (same shape as Fountain's client-side 404)
- The Podcast Index `podcasts/byitunesid` endpoint (from the API's OpenAPI spec on the `master` branch; the agent sandbox has no API credentials) - it resolves legacy Apple-id Podfriend pastes and Podcast Guru pasted links; `podcasts/byfeedid` (same spec) resolves current Podfriend feed-id pastes

Override any app icon in settings if one doesn't load.
