#!/usr/bin/env bash
#
# Build distributable ZIPs for Social Proof for HivePress.
#
# Produces, in dist/:
#   - social-proof-for-hivepress.zip            (clean; attach this as the GitHub Release asset)
#   - social-proof-for-hivepress-<version>.zip  (same contents, version-tagged filename for your own tracking)
#
# Both archives contain a top-level "social-proof-for-hivepress/" folder, so
# WordPress installs/updates them into the correct plugin directory with no
# folder-name mismatch warnings. The main plugin file is never renamed —
# WordPress identifies the plugin by folder + main-file path.
#
# Usage: ./build.sh
#
set -euo pipefail

SLUG="social-proof-for-hivepress"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"
BUILD_TMP="$(mktemp -d)"
STAGE="$BUILD_TMP/$SLUG"
trap 'rm -rf "$BUILD_TMP"' EXIT

# Read the version from the plugin header.
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$ROOT/$SLUG.php" | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "ERROR: could not read Version from $SLUG.php" >&2
	exit 1
fi

echo "Building $SLUG $VERSION ..."

# Paths to exclude from the distributable (dev/tooling only).
EXCLUDES=(
	".git" ".github" ".gitignore" ".gitattributes"
	"build.sh" "dist" "node_modules" "tests" ".DS_Store"
)

# Staging directory (in a temp location) named exactly after the plugin slug.
mkdir -p "$STAGE" "$DIST"

# Copy everything, then prune the excluded paths from the staging copy.
cp -R "$ROOT/." "$STAGE/"
for item in "${EXCLUDES[@]}"; do
	rm -rf "$STAGE/$item"
done
# Remove any stray VCS/editor cruft.
find "$STAGE" \( -name ".DS_Store" -o -name ".gitkeep" \) -delete 2>/dev/null || true

# Build the archives from the parent so the ZIP root is "social-proof-for-hivepress/".
CLEAN_ZIP="$DIST/$SLUG.zip"
VERSIONED_ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$CLEAN_ZIP" "$VERSIONED_ZIP"

( cd "$BUILD_TMP" && zip -rq "$CLEAN_ZIP" "$SLUG" )
cp "$CLEAN_ZIP" "$VERSIONED_ZIP"

echo "Done:"
echo "  $CLEAN_ZIP        <- attach this to the GitHub Release"
echo "  $VERSIONED_ZIP"
echo
echo "Always-latest download URL (post this on the forum):"
echo "  https://github.com/irapidchris-del/$SLUG/releases/latest/download/$SLUG.zip"
