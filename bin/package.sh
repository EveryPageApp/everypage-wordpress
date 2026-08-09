#!/usr/bin/env bash
#
# Build the WordPress.org submission zip: everypage.zip at the repo root,
# containing a single top-level everypage/ folder (PHP, assets/, src/, build/,
# readme.txt, uninstall.php, package manifests) and none of the dev cruft.
#
# Usage: bin/package.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/everypage"
ZIP="$ROOT/everypage.zip"

# 1. Fresh block build from source.
echo "==> npm ci && npm run build (everypage/)"
( cd "$PLUGIN" && npm ci && npm run build )

# 2. Stage a clean copy so the zip's top-level folder is exactly 'everypage/'.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a "$PLUGIN/" "$STAGE/everypage/" \
	--exclude 'node_modules/' \
	--exclude '.DS_Store' \
	--exclude '*.map' \
	--exclude '.eslintrc*' \
	--exclude '.editorconfig' \
	--exclude '.prettierrc*' \
	--exclude '.stylelintrc*'

# 3. Zip it.
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" everypage -x '*.DS_Store' )

echo "==> Wrote $ZIP"
unzip -l "$ZIP" | tail -n 1
echo "==> Top-level entries:"
unzip -l "$ZIP" | awk '{print $4}' | grep -E '^everypage/[^/]+/?$' | sort -u
