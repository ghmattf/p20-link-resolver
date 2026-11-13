# Changelog

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
