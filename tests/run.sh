#!/usr/bin/env bash
# Lint the plugin and run every offline harness. Exit non-zero on any failure.
set -uo pipefail
cd "$(dirname "$0")/.."

PHP_BIN="${PHP:-php}"
status=0

echo "== lint =="
"$PHP_BIN" -l p20-link-resolver.php || status=1

echo "== inline JS syntax =="
if command -v node >/dev/null 2>&1; then
	tmp="$(mktemp -t p20inline.XXXXXX.js)"
	awk "/<<<'JS'/{f=1;next}/^JS;/{f=0}f" p20-link-resolver.php > "$tmp"
	if [ -s "$tmp" ]; then
		node --check "$tmp" && echo "ok" || status=1
	else
		echo "could not extract inline JS" >&2
		status=1
	fi
	rm -f "$tmp"
else
	echo "node not found - skipping JS check"
fi

echo "== tests =="
for f in tests/test_*.php tests/*_test.php; do
	[ -e "$f" ] || continue
	echo "-- $f"
	"$PHP_BIN" "$f" | tail -1
	[ "${PIPESTATUS[0]}" -eq 0 ] || status=1
done

if [ "$status" -eq 0 ]; then echo "ALL GREEN"; else echo "FAILURES" >&2; fi
exit "$status"
