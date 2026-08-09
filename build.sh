#!/usr/bin/env bash
#
# Build a distributable plugin package.
#
# The repository directory and the WordPress plugin slug differ on purpose:
# the repo is short for GitHub, while WordPress.org requires the long
# descriptive slug (see the rename note in CHANGELOG.md). This script
# produces a correctly-named folder and zip from the repo contents, with
# everything listed in .distignore stripped out.
#
# Usage:
#   ./build.sh            # build into ./dist
#   ./build.sh /some/dir  # build into a directory of your choosing
#
set -euo pipefail

SLUG="fraud-screening-with-signifyd"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-$SRC/dist}"

if [[ ! -f "$SRC/.distignore" ]]; then
	echo "error: .distignore not found in $SRC" >&2
	exit 1
fi

rm -rf "${OUT:?}/$SLUG" "${OUT:?}/$SLUG.zip"
mkdir -p "$OUT/$SLUG"

# Strip comments and blank lines from .distignore, then hand the rest to
# rsync as exclude patterns.
rsync -a \
	--exclude-from=<(grep -vE '^[[:space:]]*#|^[[:space:]]*$' "$SRC/.distignore") \
	--exclude='dist/' \
	"$SRC/" "$OUT/$SLUG/"

( cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" )

echo "built: $OUT/$SLUG"
echo "       $OUT/$SLUG.zip"
echo
echo "contents:"
find "$OUT/$SLUG" -type f | sed "s|$OUT/$SLUG/|  |" | sort

# Fail loudly if anything that should never ship made it in.
if find "$OUT/$SLUG" -name '.*' -not -name '.' | grep -q .; then
	echo >&2
	echo "error: hidden files present in the package; WordPress.org rejects these" >&2
	find "$OUT/$SLUG" -name '.*' -not -name '.' >&2
	exit 1
fi
