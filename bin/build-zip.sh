#!/usr/bin/env bash
#
# Build an installable ZIP of the plugin.
#
# WordPress (and the WordPress.org Plugin Check) identify a plugin by the name of its folder, which must be
# the plugin slug: cache-purge-control-for-cloudflare. This repository is not named after the slug, so a
# plain "Download ZIP" from GitHub produces a folder with the wrong name and the text domain no longer
# matches. This script packages the tracked files under the correct folder name, honouring .distignore.
#
# Usage: bin/build-zip.sh [output-dir]   (default: ./dist)

set -euo pipefail

SLUG="cache-purge-control-for-cloudflare"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"

trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$OUT"
mkdir -p "$STAGE/$SLUG"

# Copy everything except the paths listed in .distignore (and the output dir itself).
cd "$ROOT"
tar --exclude-from=.distignore --exclude=./dist --exclude=./.git -cf - . | tar -xf - -C "$STAGE/$SLUG"

VERSION="$(grep -E '^ \* Version:' "$STAGE/$SLUG/$SLUG.php" | awk '{print $3}')"
ZIP="$OUT/$SLUG${VERSION:+-$VERSION}.zip"

rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )

echo "Built $ZIP"
echo "Top-level folder inside the ZIP: $SLUG"
