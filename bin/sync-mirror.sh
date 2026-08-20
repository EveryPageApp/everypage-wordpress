#!/usr/bin/env bash
#
# Copy this working tree into the public GitHub mirror
# (EveryPageApp/everypage-wordpress), which is what wp.org releases are cut
# from: pushing a `vX.Y.Z` tag there runs .github/workflows/deploy.yml, which
# syncs everypage/ to SVN trunk and creates the tag.
#
# This private tree is the source of truth; the mirror is generated. It keeps a
# separate, squashed history and must only ever carry the EveryPage identity
# (never a personal account), so the identity is set on the clone itself rather
# than relying on whatever the machine's global git config happens to be.
#
# Usage:
#   bin/sync-mirror.sh "commit message"            # sync + commit, review, push by hand
#   bin/sync-mirror.sh "commit message" --push     # sync + commit + push main
#
# Override the checkout location with EVERYPAGE_MIRROR_DIR.

set -euo pipefail

MESSAGE="${1:-}"
PUSH="${2:-}"

if [ -z "$MESSAGE" ]; then
	echo "usage: bin/sync-mirror.sh \"commit message\" [--push]" >&2
	exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIRROR="${EVERYPAGE_MIRROR_DIR:-$SRC/../.everypage-wordpress-mirror}"
REMOTE="https://github.com/EveryPageApp/everypage-wordpress.git"

if [ ! -d "$MIRROR/.git" ]; then
	echo "==> cloning the mirror into $MIRROR"
	git clone "$REMOTE" "$MIRROR"
fi

cd "$MIRROR"
git config user.name "EveryPage"
git config user.email "hello@everypage.co"
git checkout main
git pull --ff-only

echo "==> syncing files"
# --delete so a file removed here is removed there; the excludes keep build
# artefacts, dependencies, and local scratch out of a public repository.
rsync -a --delete \
	--exclude '.git/' \
	--exclude 'node_modules/' \
	--exclude 'vendor/' \
	--exclude '*.zip' \
	--exclude '.DS_Store' \
	--exclude '.phpunit.result.cache' \
	--exclude 'LICENSE' \
	"$SRC/everypage" "$SRC/wporg-assets" "$SRC/bin" "$SRC/tests" "$SRC/.github" \
	"$MIRROR/"

for f in .gitignore .wp-env.json composer.json composer.lock phpcs.xml.dist phpunit.xml.dist README.md; do
	cp "$SRC/$f" "$MIRROR/$f"
done

if git diff --quiet && git diff --cached --quiet && [ -z "$(git status --porcelain)" ]; then
	echo "==> mirror already matches; nothing to commit"
	exit 0
fi

git add -A
git status --short
git commit -m "$MESSAGE"

if [ "$PUSH" = "--push" ]; then
	git push origin main
	echo "==> pushed. Tag a release with:"
else
	echo "==> committed locally in $MIRROR. Review, then:"
	echo "    git -C \"$MIRROR\" push origin main"
fi

VERSION=$(sed -n "s/.*EVERYPAGE_VERSION', *'\([^']*\)'.*/\1/p" "$SRC/everypage/everypage.php" | head -1)
echo "    git -C \"$MIRROR\" tag v$VERSION && git -C \"$MIRROR\" push origin v$VERSION"
