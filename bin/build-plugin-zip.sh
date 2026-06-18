#!/usr/bin/env bash
#
# Build a WordPress.org-ready plugin ZIP using .distignore rules.
#
# Mirrors .github/workflows/release.yml: build in isolation, then rsync + zip.
# Requires: bash, php, rsync, zip, composer (optional), npm (optional).
#
# Usage:
#   npm run package
#   bash bin/build-plugin-zip.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="debi-payment-for-woocommerce"
DISTIGNORE="$ROOT/.distignore"
OUTPUT_DIR="$ROOT/dist"

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		case "$1" in
			rsync) echo "  Arch:   sudo pacman -S rsync" >&2 ;;
			zip)   echo "  Arch:   sudo pacman -S zip" >&2 ;;
		esac
		exit 1
	fi
}

require_command php
require_command rsync
require_command zip

cd "$ROOT"

VERSION="$(php -r '$h = file_get_contents("debi-payment-for-woocommerce.php"); preg_match("/Version:\s*([0-9.]+)/", $h, $m); echo $m[1] ?? "0.0.0";')"
ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_DIR/$ZIP_NAME"

STAGING="$(mktemp -d)"
WORK="$STAGING/work"
PACK="$STAGING/pack"

cleanup() {
	rm -rf "$STAGING"
}
trap cleanup EXIT

mkdir -p "$WORK/$SLUG" "$OUTPUT_DIR"

# Build in an isolated copy so local dev dependencies are untouched.
rsync -a \
	--exclude .git \
	--exclude node_modules \
	--exclude vendor \
	--exclude build \
	--exclude dist \
	./ "$WORK/$SLUG/"

cd "$WORK/$SLUG"

if [[ -f composer.json ]] && command -v composer >/dev/null 2>&1; then
	COMPOSER_ROOT_VERSION="$VERSION" composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
fi

if [[ -f src/index.js || -f src/index.jsx || -f src/index.ts ]]; then
	if ! command -v npm >/dev/null 2>&1; then
		echo "npm is required to build JS assets (src/index.* detected)." >&2
		exit 1
	fi
	npm ci --no-audit --no-fund
	npm run build
fi

mkdir -p "$PACK/$SLUG"
rsync -a --delete --exclude-from="$DISTIGNORE" ./ "$PACK/$SLUG/"

rm -f "$ZIP_PATH"
( cd "$PACK" && zip -rq "$ZIP_PATH" "$SLUG" )

echo "Created $ZIP_PATH"
