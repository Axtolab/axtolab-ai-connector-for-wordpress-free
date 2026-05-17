#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR="$REPO_ROOT/wp-plugin/wp-mcp-gateway"
PLUGIN_FILE="wp-mcp-gateway.php"
PLUGIN_SLUG="axtolab-ai-connector"
WPORGIGNORE="$PLUGIN_DIR/.wporgignore"

VERSION=$(grep -m1 "^\s*\* Version:" "$PLUGIN_DIR/$PLUGIN_FILE" | sed "s/.*Version:[[:space:]]*//" | tr -d '[:space:]')
OUTPUT_VERSIONED="$REPO_ROOT/dist/$PLUGIN_SLUG-$VERSION.zip"
OUTPUT_STABLE="$REPO_ROOT/dist/$PLUGIN_SLUG.zip"

mkdir -p "$REPO_ROOT/dist"

# Rebuild the .mcpb bundle from current TS source. Without this, the .mcpb
# inside the plugin assets can drift behind the source (verify-release-
# consistency.sh only checks version alignment, NOT tool-surface alignment),
# meaning Claude Desktop users who download the bundle from the plugin admin
# would get an outdated tool list. Always rebuild so source = bundle.
echo "Rebuilding MCP bundle (.mcpb) so the plugin ships current tool surface..."
( cd "$REPO_ROOT/mcp-server" && npm run --silent build:mcpb >/dev/null )

"$SCRIPT_DIR/verify-release-consistency.sh"

# Remove any previous plugin zips so the dist dir reflects only the current build
rm -f "$REPO_ROOT"/dist/$PLUGIN_SLUG*.zip

# Stage into a clean temp dir using .wporgignore as the single source of truth
# for what gets excluded from the published zip. rsync handles gitignore-style
# patterns naturally; zip's -x flag is fiddly by comparison.
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

if [[ ! -f "$WPORGIGNORE" ]]; then
  echo "ERROR: $WPORGIGNORE not found." >&2
  exit 1
fi

rsync -a --delete --exclude-from="$WPORGIGNORE" "$PLUGIN_DIR/" "$STAGE_DIR/$PLUGIN_SLUG/"

echo "Packaging WordPress plugin..."
( cd "$STAGE_DIR" && zip -rq "$OUTPUT_VERSIONED" "$PLUGIN_SLUG/" )

cp "$OUTPUT_VERSIONED" "$OUTPUT_STABLE"

bash "$SCRIPT_DIR/verify-package.sh" "$OUTPUT_VERSIONED" "$PLUGIN_SLUG" "$PLUGIN_FILE"
bash "$SCRIPT_DIR/verify-package.sh" "$OUTPUT_STABLE" "$PLUGIN_SLUG" "$PLUGIN_FILE"

LC_ALL=C LANG=C shasum -a 256 "$OUTPUT_VERSIONED" | tee "$OUTPUT_VERSIONED.sha256"
LC_ALL=C LANG=C shasum -a 256 "$OUTPUT_STABLE" | tee "$OUTPUT_STABLE.sha256"

echo "✓ Plugin packaged: $OUTPUT_VERSIONED"
echo "✓ Stable alias updated: $OUTPUT_STABLE"
echo
echo "Zip contents:"
unzip -l "$OUTPUT_STABLE" | tail -n +2 | head -n 80
echo
echo "Upload either zip in WordPress Admin > Plugins > Add New > Upload Plugin"
