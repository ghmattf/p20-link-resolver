#!/usr/bin/env bash
# Usage: ./bump-version.sh x.y.z
# Updates the plugin header "Version:" and `const VERSION`, lints, and commits
# ONLY the plugin file with subject "Bump x.y.z". The CHANGELOG entry, tag and
# push are separate steps (see AGENTS.md > Release flow).
set -euo pipefail
cd "$(dirname "$0")"

FILE="p20-link-resolver.php"
NEW="${1:-}"

if ! [[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "usage: $0 x.y.z" >&2
	exit 1
fi

CUR="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$FILE" | head -1)"
if [ -z "$CUR" ]; then
	echo "could not read current version from $FILE header" >&2
	exit 1
fi
if [ "$CUR" = "$NEW" ]; then
	echo "already at $NEW" >&2
	exit 1
fi

sed -E \
	-e "s/^( \* Version: )[0-9]+\.[0-9]+\.[0-9]+/\1$NEW/" \
	-e "s/^([[:space:]]*const VERSION[[:space:]]*=[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(';)/\1$NEW\2/" \
	"$FILE" > "$FILE.tmp"
mv "$FILE.tmp" "$FILE"

# Both places must have changed.
grep -q "^ \* Version: $NEW\$" "$FILE" || { echo "header version not updated" >&2; exit 1; }
grep -q "const VERSION[[:space:]]*= '$NEW';" "$FILE" || { echo "const VERSION not updated" >&2; exit 1; }

"${PHP:-php}" -l "$FILE"

git add -- "$FILE"
git commit -q -m "Bump $NEW" -- "$FILE"
echo "Bumped $CUR -> $NEW (committed $FILE only). Next: CHANGELOG entry, commit, tag v$NEW, push."
