# Changelog

## [0.1.1] - 2026-11-12

- Fountain links fixed (show and episode): Fountain's public URLs use opaque ids, not the platform-links guide's dead numeric patterns (verified live 2026-11-12). Ids are now resolved from the `podcast:guid` / episode guid through Fountain's public lookup API (`api.fountain.fm`, no key required) and cached in transients; an unknown episode opens the show, an unknown show omits the button.
- Docs: README verification section split into verified-live (Fountain) vs documentation-only; agent docs and contributing notes updated.

## [0.1.0] - 2026-11-11

- Initial release: `[p20link]` shortcode, `?show=` / `?episode=` URL mode, search UI with episode picker.
- Target apps: Fountain, CurioCaster, Podverse, Podfriend (link patterns from the Podcast Index platform-links guide).
- Podcast Index API lookups with cache table, stale-while-revalidate refresh and negative caching.
- Usage log, reports and cache admin tabs; Open Graph / Twitter Card previews; per-IP rate limits.
