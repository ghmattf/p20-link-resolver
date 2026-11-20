#!/usr/bin/env bash
# Usage: ./build-zip.sh
# Builds dist/p20-link-resolver.zip for the GitHub release. The filename
# carries no version - the release tag (vx.y.z) is what identifies the build.
#
# The zip MUST contain a single top-level "p20-link-resolver/" folder.
# WordPress's installer (WP_Upgrader::install_package) takes the install
# directory name from that folder; a flat zip (files at the zip root) instead
# gets a folder named after the zip file plus a random suffix, so every
# upload installs a NEW plugin next to the old one instead of overwriting it.
set -euo pipefail
cd "$(dirname "$0")"

FILE="p20-link-resolver.php"
VER="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$FILE" | head -1)"
if [ -z "$VER" ]; then
	echo "could not read current version from $FILE header" >&2
	exit 1
fi

STAGE="dist/p20-link-resolver"
ZIP="dist/p20-link-resolver.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"
cp CHANGELOG.md LICENSE "$FILE" README.md "$STAGE/"

# Zip from inside dist/ so every entry is "p20-link-resolver/<file>",
# never "<file>" at the archive root.
( cd dist && zip -q -r "p20-link-resolver.zip" p20-link-resolver )

# Guard: fail loudly if any entry escaped the wrapper folder.
if command -v unzip >/dev/null 2>&1; then
	bad="$(unzip -Z1 "$ZIP" | awk -F/ '$1 != "p20-link-resolver" { print; exit }')"
	if [ -n "$bad" ]; then
		echo "zip entry outside p20-link-resolver/: $bad" >&2
		exit 1
	fi
fi

echo "Built $ZIP (plugin $VER)"
