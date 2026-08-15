#!/usr/bin/env bash
#
# Build the uploadable plugin zip: wp-admin -> Plugins -> Add New -> Upload ->
# "Replace current with uploaded".
#
# The zip is the deploy unit because the host has neither Node nor a reliable
# Composer, so everything it needs has to arrive already built: build/ compiled
# and committed, vendor/ installed here with dev packages left out.
#
# What ships is decided by .gitattributes (export-ignore), not by this script —
# git archive applies those rules, so adding a dev-only file to the repo means
# adding a rule there rather than a prune step here.
#
# Usage:  bin/package.sh [git-ref]        (default HEAD)
#         ALLOW_DIRTY=1 bin/package.sh    to package a ref while the tree is dirty
#
set -euo pipefail

cd "$(dirname "$0")/.."

NAME=club-competition-plugin
REF="${1:-HEAD}"
OUT_DIR=dist

# The plugin header is the one place the version lives; SCS_VERSION derives from
# it at runtime and the app footer shows it, so the file name matches what a
# person reads off the page. Read from the ref rather than the working tree, or
# packaging an older ref names the zip after the version you happen to have
# checked out.
VERSION=$(git show "$REF:$NAME.php" | sed -n 's/^ \* Version: *//p' | head -1 | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
    echo "package: no Version header in $NAME.php" >&2
    exit 1
fi

# git archive reads a committed ref, so anything uncommitted is silently left
# out — which is a release that doesn't match the tree it was cut from.
if [ -z "${ALLOW_DIRTY:-}" ] && [ -n "$(git status --porcelain)" ]; then
    echo "package: working tree is dirty, and only committed files are packaged." >&2
    echo "         Commit first, or re-run with ALLOW_DIRTY=1 to package $REF as it stands." >&2
    exit 1
fi

# The one deploy mistake this repo can actually make: editing the frontend and
# shipping the previous bundle, which fails silently because build/ is committed
# and therefore always present.
#
# Asked of the ref being packaged, not of the working tree: the zip carries the
# ref's committed build/, so working-tree mtimes answer a different question —
# they pass for an older ref, pass under ALLOW_DIRTY once a rebuild has touched
# the tree, and fail for a comment-only edit that the bundle rightly ignores.
if ! git cat-file -e "$REF:build/viewer.js" 2>/dev/null; then
    echo "package: $REF has no build/viewer.js — run 'npm run build' and commit it." >&2
    exit 1
fi

# The only exact answer is to build the sources and see whether the committed
# bundle changes, which needs those sources in the tree — so it holds when we
# are packaging HEAD with nothing uncommitted, and not otherwise. Saying so is
# the point: reporting a pass it cannot back is what the mtime check did.
VERIFIED="rebuilt and matched"
if [ "$(git rev-parse "$REF")" = "$(git rev-parse HEAD)" ] && [ -z "$(git status --porcelain)" ]; then
    # webpack reports compile errors on stdout, so the output is held rather
    # than discarded: silent when it succeeds, and the reason when it doesn't.
    if ! BUILD_LOG=$(npm run build --silent 2>&1); then
        echo "package: 'npm run build' failed, so the bundle could not be verified." >&2
        echo "$BUILD_LOG" >&2
        exit 1
    fi
    if [ -n "$(git status --porcelain build)" ]; then
        echo "package: build/ is out of date — it has just been rebuilt, so commit it and re-run." >&2
        git status --short build >&2
        exit 1
    fi
else
    VERIFIED="NOT VERIFIED — ships as committed in $REF, which isn't the tree being built"
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

git archive --format=tar --prefix="$NAME/" "$REF" | tar -x -C "$STAGE"

# Production dependencies only. The classmap is authoritative because the plugin
# never autoloads anything outside it, and a resolved map beats a filesystem
# probe on shared hosting.
composer install \
    --working-dir="$STAGE/$NAME" \
    --no-dev \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-interaction \
    --no-progress \
    --quiet

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$NAME-$VERSION.zip"
rm -f "$ZIP"

# python3 rather than zip(1), which isn't installed here or on the host.
( cd "$STAGE" && python3 -m zipfile -c "$OLDPWD/$ZIP" "$NAME" )

echo "$ZIP"
echo "  ref     $REF ($(git rev-parse --short "$REF"))"
echo "  version $VERSION"
echo "  size    $(du -h "$ZIP" | cut -f1)"
echo "  files   $(python3 -m zipfile -l "$ZIP" | tail -n +2 | wc -l)"
echo "  build/  $VERIFIED"
