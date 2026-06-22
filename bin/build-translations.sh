#!/usr/bin/env bash
#
# Regenerate translation templates and compiled MO files.
#
# 1. make-pot  — scan PHP sources → languages/debi-payment-for-woocommerce.pot
# 2. update-po — merge new strings into existing .po files
# 3. make-mo   — compile .po → .mo for WordPress to load at runtime
#
# Requires Docker (wp-env) or a local WP-CLI install with the i18n command.
#
# Usage:
#   npm run i18n
#   bash bin/i18n.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="debi-payment-for-woocommerce"
DOMAIN="debi-payment-for-woocommerce"
PLUGIN_CWD="wp-content/plugins/$SLUG"
LANG_DIR="languages"
POT="$LANG_DIR/$SLUG.pot"
EXCLUDE="lib/debi-php,tests,vendor,node_modules,dist"

run_wp() {
	if command -v wp >/dev/null 2>&1; then
		( cd "$ROOT" && wp "$@" )
		return
	fi

	if ! command -v npx >/dev/null 2>&1; then
		echo "Missing WP-CLI. Install it globally or run: npm install" >&2
		exit 1
	fi

	( cd "$ROOT" && npx wp-env run cli --env-cwd="$PLUGIN_CWD" wp "$@" )
}

echo "→ Generating $POT"
run_wp i18n make-pot . "$POT" --domain="$DOMAIN" --exclude="$EXCLUDE"

echo "→ Updating PO files from $POT"
run_wp i18n update-po "$POT" "$LANG_DIR"

echo "→ Compiling MO files"
run_wp i18n make-mo "$LANG_DIR"

echo "Done. Updated files in $LANG_DIR/"
