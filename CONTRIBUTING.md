# Contributing

Humans and agents follow the same rules. `AGENTS.md` is the canonical, more detailed version of this file.

## Getting set up

You need PHP (7.4 or newer) and, optionally, Node (only used to syntax-check the inline JavaScript).
There is no WordPress install, database or network needed: the test harness stubs WordPress and mocks the Podcast Index API.

```bash
git clone https://github.com/ghmattf/p20-link-resolver
cd p20-link-resolver
./tests/run.sh          # lint + inline-JS check + all tests
```

To try it for real, copy `p20-link-resolver.php` into `wp-content/plugins/p20-link-resolver/` on a WordPress site, activate it, add a Podcast Index API key and secret, and put `[p20link]` on a page.

## Workflow

1. Make the change in `p20-link-resolver.php` (it is deliberately one dependency-free file).
2. Add or update a test in `tests/resolver_test.php` (or a new `tests/*_test.php`) for any behaviour change. Tests must run offline.
3. `./tests/run.sh` must be green. CI runs the same thing on PHP 7.4, 8.1 and 8.3.
4. Update `README.md` if user-visible behaviour changed, and `OUTSTANDING.md` if you found something you didn't fix.
5. Open a pull request. Describe what changed and how you checked it, and say plainly if anything was not verified live.

## Adding or changing a target app

1. Find the app's URL pattern in the Podcast Index [podcast-platform-links](https://github.com/Podcastindex-org/podcast-platform-links) guide (or the app's own docs) and note which identifiers it needs.
2. Add the app to `app_registry()` (`label`, `domain` used for the default favicon).
3. Add a `case` to `app_link()` returning `array( 'url' => ..., 'episode' => bool )`, or `null` when the needed id is missing. `episode` is `true` only when the URL really points at the episode.
4. If it needs a new piece of metadata (another Podcast Index field), add it to `fetch_show()` / `fetch_episode()`, the cache table (bump `TABLE_VERSION`), `cache_store()`, `release_from_row()` and `release_from_data()`.
5. Extend the "How each app is linked" table in `README.md` and add tests for show, episode and missing-id cases.
6. Don't depend on an unofficial or reverse-engineered endpoint without a very good reason; plain URL patterns are the point. A *documented* public API is fine when the app has no stable URL pattern (Fountain's guid lookup is the precedent).

## Code style

- WordPress PHP conventions: tabs, Yoda conditions, spaces inside parentheses, `esc_*` on every output.
- Keep PHP 7.4 compatible.
- Comment the *why* (a failure mode, a security decision, a surprising upstream behaviour), not the what.
- Everything stays inside the `p20link` / `P20_Link_` namespace.

## Releases

See "Release flow" in `AGENTS.md`: green tests, `./bump-version.sh x.y.z`, CHANGELOG entry, annotated `vx.y.z` tag, push.

## Reporting problems

Open a GitHub issue with the plugin version, the show/episode identifiers involved (public podcast guid is fine) and what you expected to see. Never paste your Podcast Index API secret.
