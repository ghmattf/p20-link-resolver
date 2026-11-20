# Changelog

## [0.1.12] - 2026-11-20

- Podfriend: the button now appears for every resolved show. Podfriend's guide-listed Apple-id route is gone (410, live 2026-11-20) - links are built on the Podcast Index feed id (or the `podcast:guid`), which Podfriend's site accepts (live 2026-11-20). Its episode URLs need Podfriend-internal ids (every `{base}/{episode guid}` form 404s), so an episode request opens the show.
- Podcast Guru: shows listed only in the Podcast Index (no Apple id) now get a button too - the link falls back to the site's `md5(feed URL)` addressing (live 2026-11-20). This stores the feed URL on the cache row (new `feed_url` column); rows cached before this release pick it up on their next background refresh (12 h default).
- TrueFans icon: `truefans.fm` refuses cross-site image requests (403), so the default icon is now a referer-safe mirror of the same favicon instead of a broken image.

## [0.1.11] - 2026-11-20

- Search: paste a share link for any target app - CurioCaster, Podcast Index, Castamatic, TrueFans, BoostMeBuddy, Podverse or the Apple-id keyed Podfriend and Podcast Guru - and jump straight to that show (or episode where the link carries one). Guid/feed-id links resolve with no upstream call, Apple-id links need one Podcast Index lookup, and Podverse web ids reverse through Podverse's own API (live-verified 2026-11-18); results are cached, a dead link shows "no results".

## [0.1.10] - 2026-11-18

- Podverse episode links: an episode request now opens the episode on Podverse when possible. The Podverse id is resolved from the Podcast Index feed id + episode guid through Podverse's public url-resolver (`GET api.podverse.fm/api/v1/url-resolver/episode`, live-verified 2026-11-16) and cached; a miss or lookup failure still opens the show as before.
- Search: paste a Fountain share link (`fountain.fm/show/...` or `fountain.fm/episode/...`) into the search box and jump straight to that page. The link is matched against the cache row's remembered Fountain URL first (no upstream call), then reverse-resolved to guids through Fountain's content API (`GET api.fountain.fm/v1/content/{type}:id:{id}`, live-verified 2026-11-16); results are cached, a dead link shows "no results".

## [0.1.9] - 2026-11-16

- New target app: Podcast Guru (`app.podcastguru.io/podcast/{Apple id}`) - show links keyed on the Apple Podcasts id; an episode request opens the show, since episode URLs need Podcast Guru's internal id. Pattern from the platform-links guide, not yet verified live.
- Fixed: `app_link()`'s note now reaches the rendered HTML, so Fountain's "(opens Fountain)" note appears on episode pages where Fountain can only link the show (previously it always said "(opens the show)").
- Settings: each app's Icon URL field now shows a preview of the icon the buttons will use - the override if set, otherwise the app's default favicon; a broken URL hides the preview.

## [0.1.8] - 2026-11-15

- New target apps: Castamatic (`castamatic.com/guid/{podcast:guid}`), TrueFans (`truefans.fm/{podcast:guid}`) and Podcast Index (`podcastindex.org/podcast/{podcast:guid}`, PI feed id accepted as fallback) - show links only; an episode request opens the show for these three, since their episode URLs need app-internal ids. Patterns from the platform-links guide, not yet verified live.

## [0.1.7] - 2026-11-15

- Search screen H1 renamed to "PodcastLink" (was "PodcastLinker"), matching the footer promo.

## [0.1.6] - 2026-11-15

- Footer promo renamed: "Share a show with PodcastLink", linking to `https://phafe.com/podcastlink` (page moved from `/podcastlinker`).

## [0.1.5] - 2026-11-14

- Fountain: definitive lookup misses are now remembered for 30 minutes instead of an hour (`FOUNTAIN_FAIL_TTL`); unreliable answers stay at 30 seconds.
- Fountain: the derived show/episode URL is now remembered on the cache row (`fountain_url` / `fountain_date`, valid 30 days). A fresh entry is served with no Fountain call at all; a stale one is re-derived, and when re-derivation fails the stale URL is served silently instead of degrading to discover (discover only appears when nothing was ever cached). `TABLE_VERSION` bumped to 2 - the new columns are added on the next admin page load.

## [0.1.4] - 2026-11-13

- Social previews: on `?show=` pages the `wp_head` output is now buffered so the theme's (or an SEO plugin's) competing `og:` / `twitter:` meta tags are stripped before the plugin's own set is emitted. Scrapers that read the first `og:description` (e.g. Telegram's Webpage Bot) were getting the theme's blank one. A failed resolve leaves existing tags alone rather than blanking the preview.

## [0.1.3] - 2026-11-12

- Fountain links always render a button now, degrading episode → show → `fountain.fm/discover`; the note says "(opens the show)" or "(opens Fountain)". Supersedes 0.1.2's "omit the button on an unreliable lookup" policy.
- New footer line above "Powered by Podcast Index": "Share a show with PodcastLinker", linking to `https://phafe.com/podcastlinker`.

## [0.1.2] - 2026-11-12

- Fountain: an unreliable episode lookup (transport error, 5xx, spent rate-limit budget) now omits the button instead of falling back to the show; only a definitive miss (HTTP 200/404 - Fountain genuinely lacks it) degrades to the show link. Soft misses are cached under a sentinel so they can never read as definitive.
- New target app: BoostMeBuddy - show and episode links via the app's own `?podcast={guid}` / `?podcast={guid}&episode={guid}` query params (both URLs verified live 2026-11-12). Its icon defaults to the site's SVG favicon because it serves no `/favicon.ico`.

## [0.1.1] - 2026-11-12

- Fountain links fixed (show and episode): Fountain's public URLs use opaque ids, not the platform-links guide's dead numeric patterns (verified live 2026-11-12). Ids are now resolved from the `podcast:guid` / episode guid through Fountain's public lookup API (`api.fountain.fm`, no key required) and cached in transients; an unknown episode opens the show, an unknown show omits the button.
- Docs: README verification section split into verified-live (Fountain) vs documentation-only; agent docs and contributing notes updated.

## [0.1.0] - 2026-11-11

- Initial release: `[p20link]` shortcode, `?show=` / `?episode=` URL mode, search UI with episode picker.
- Target apps: Fountain, CurioCaster, Podverse, Podfriend (link patterns from the Podcast Index platform-links guide).
- Podcast Index API lookups with cache table, stale-while-revalidate refresh and negative caching.
- Usage log, reports and cache admin tabs; Open Graph / Twitter Card previews; per-IP rate limits.
