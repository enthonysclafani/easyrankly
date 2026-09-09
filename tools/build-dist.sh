#!/usr/bin/env bash
# Build the WordPress.org distributable zip from this plugin tree.
# Dev-only: excluded from the zip via .distignore.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="easyrankly"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/erankly-dist.XXXXXX")"
DEST_DIR="${ERANKLY_DIST_DIR:-$ROOT/.dist}"
DEST_ZIP="${ERANKLY_DIST_ZIP:-$DEST_DIR/${PLUGIN_SLUG}.zip}"

cleanup() {
	rm -rf "$STAGING"
}
trap cleanup EXIT

mkdir -p "$STAGING/$PLUGIN_SLUG" "$DEST_DIR"

rsync -a \
	--delete \
	--exclude-from="$ROOT/.distignore" \
	"$ROOT/" "$STAGING/$PLUGIN_SLUG/"

# Belt-and-suspenders: never ship VCS, tests, or build tooling.
rm -rf \
	"$STAGING/$PLUGIN_SLUG/.git" \
	"$STAGING/$PLUGIN_SLUG/.github" \
	"$STAGING/$PLUGIN_SLUG/.delta" \
	"$STAGING/$PLUGIN_SLUG/.dist" \
	"$STAGING/$PLUGIN_SLUG/.playwright-cli" \
	"$STAGING/$PLUGIN_SLUG/tests" \
	"$STAGING/$PLUGIN_SLUG/tools" \
	"$STAGING/$PLUGIN_SLUG/vendor"
rm -f \
	"$STAGING/$PLUGIN_SLUG/.gitignore" \
	"$STAGING/$PLUGIN_SLUG/.distignore" \
	"$STAGING/$PLUGIN_SLUG/.DS_Store" \
	"$STAGING/$PLUGIN_SLUG/phpunit.xml.dist" \
	"$STAGING/$PLUGIN_SLUG/WORDPRESS-ORG-READINESS.md"

if [[ ! -f "$STAGING/$PLUGIN_SLUG/easyrankly.php" ]] || [[ ! -f "$STAGING/$PLUGIN_SLUG/readme.txt" ]] || [[ ! -f "$STAGING/$PLUGIN_SLUG/uninstall.php" ]]; then
	echo "error: staging tree is missing required plugin files" >&2
	exit 1
fi

rm -f "$DEST_ZIP"
(
	cd "$STAGING"
	zip -X -r "$DEST_ZIP" "$PLUGIN_SLUG" -x "*.DS_Store" -x "*__MACOSX*" -x "*.git*"
)

echo "Wrote $DEST_ZIP"
if command -v shasum >/dev/null 2>&1; then
	shasum -a 256 "$DEST_ZIP"
elif command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$DEST_ZIP"
fi
