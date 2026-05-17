#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_FILE="$REPO_ROOT/wp-plugin/wp-mcp-gateway/wp-mcp-gateway.php"
MANIFEST_FILE="$REPO_ROOT/mcp-server/manifest.json"
PACKAGE_FILE="$REPO_ROOT/mcp-server/package.json"
PACKAGE_LOCK_FILE="$REPO_ROOT/mcp-server/package-lock.json"
SERVER_SOURCE_FILE="$REPO_ROOT/mcp-server/src/index.ts"
README_FILE="$REPO_ROOT/README.md"
MCPB_FILE="$REPO_ROOT/wp-plugin/wp-mcp-gateway/assets/axtolab-ai-connector.mcpb"

plugin_version=$(grep -m1 "^\s*\* Version:" "$PLUGIN_FILE" | sed "s/.*Version:[[:space:]]*//" | tr -d '[:space:]')
manifest_version=$(grep -m1 '"version"' "$MANIFEST_FILE" | sed 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/')
package_version=$(node -p "require(process.argv[1]).version" "$PACKAGE_FILE")
package_lock_version=$(node -p "require(process.argv[1]).version" "$PACKAGE_LOCK_FILE")
package_lock_root_version=$(node -p "require(process.argv[1]).packages[''].version" "$PACKAGE_LOCK_FILE")
server_source_version=$(grep -m1 "version: '[0-9][^']*'" "$SERVER_SOURCE_FILE" | sed "s/.*version: '\([^']*\)'.*/\1/")

if [[ -z "$plugin_version" || -z "$manifest_version" || -z "$package_version" || -z "$package_lock_version" || -z "$package_lock_root_version" || -z "$server_source_version" ]]; then
  echo "ERROR: Could not resolve one or more release versions." >&2
  exit 1
fi

if [[ "$plugin_version" != "$manifest_version" ]]; then
  echo "ERROR: Plugin version ($plugin_version) does not match manifest version ($manifest_version)." >&2
  exit 1
fi

if [[ "$manifest_version" != "$package_version" ]]; then
  echo "ERROR: Manifest version ($manifest_version) does not match npm package version ($package_version)." >&2
  exit 1
fi

if [[ "$package_version" != "$package_lock_version" ]]; then
  echo "ERROR: npm package version ($package_version) does not match package-lock version ($package_lock_version)." >&2
  exit 1
fi

if [[ "$package_lock_version" != "$package_lock_root_version" ]]; then
  echo "ERROR: package-lock root version ($package_lock_root_version) does not match package-lock version ($package_lock_version)." >&2
  exit 1
fi

if [[ "$package_version" != "$server_source_version" ]]; then
  echo "ERROR: MCP server source version ($server_source_version) does not match npm package version ($package_version)." >&2
  exit 1
fi

if ! grep -q 'axtolab-ai-connector-<version>.zip' "$README_FILE"; then
  echo "ERROR: Root README does not document the versioned plugin zip artifact name." >&2
  exit 1
fi

if ! grep -q 'axtolab-ai-connector.zip' "$README_FILE"; then
  echo "ERROR: Root README no longer mentions the stable axtolab-ai-connector.zip alias." >&2
  exit 1
fi

python3 - "$MANIFEST_FILE" "$MCPB_FILE" "$manifest_version" <<'PY'
import json
import sys
import zipfile
from pathlib import PurePosixPath

manifest_file, mcpb_file, expected_version = sys.argv[1:]
source = json.load(open(manifest_file, encoding='utf-8'))

entry_point = source.get('server', {}).get('entry_point')
args = source.get('server', {}).get('mcp_config', {}).get('args', [])
if not entry_point:
    raise SystemExit('ERROR: MCPB source manifest is missing server.entry_point.')
expected_arg = '${__dirname}/' + entry_point
if expected_arg not in args:
    raise SystemExit(
        f'ERROR: MCPB source manifest args {args!r} do not reference {expected_arg!r}.'
    )

with zipfile.ZipFile(mcpb_file) as archive:
    names = set(archive.namelist())
    bundled = json.loads(archive.read('manifest.json'))

if bundled.get('version') != expected_version:
    raise SystemExit(
        f"ERROR: bundled MCPB version ({bundled.get('version')}) does not match release version ({expected_version})."
    )

for key in ('name', 'display_name'):
    if bundled.get(key) != source.get(key):
        raise SystemExit(
            f"ERROR: bundled MCPB {key} ({bundled.get(key)!r}) does not match source manifest ({source.get(key)!r})."
        )

bundled_entry = bundled.get('server', {}).get('entry_point')
if bundled_entry != entry_point:
    raise SystemExit(
        f"ERROR: bundled MCPB entry_point ({bundled_entry!r}) does not match source ({entry_point!r})."
    )

if PurePosixPath(entry_point).as_posix() not in names:
    raise SystemExit(
        f"ERROR: bundled MCPB entry_point {entry_point!r} is not present in the archive."
    )

bundled_args = bundled.get('server', {}).get('mcp_config', {}).get('args', [])
if expected_arg not in bundled_args:
    raise SystemExit(
        f'ERROR: bundled MCPB args {bundled_args!r} do not reference {expected_arg!r}.'
    )

print(f'MCPB consistency OK: {bundled.get("name")} {bundled.get("version")} -> {entry_point}')
PY

REGISTER_TOOLS_FILE="$REPO_ROOT/mcp-server/src/tools/registerTools.ts"

python3 - "$REGISTER_TOOLS_FILE" "$MCPB_FILE" <<'PY'
"""Tool-surface drift check.

Extracts registered MCP tool names (anything matching `server.tool("wp_xxx"`)
from the TypeScript source AND from the bundled `server/index.js` inside the
.mcpb zip, then diffs the sets. Fails if a tool is registered in source but
missing from the bundle (or vice versa).

Catches the failure mode where the .mcpb is rebuilt from stale `dist/`, where
someone added a tool registration in source but forgot to rebuild the bundle,
or where esbuild's static-import trace misses a registration.

The version-only consistency check above CANNOT detect this — it shipped
silently on 2026-05-04 with two missing tools (wp_search_content,
wp_*_permalink_structure) until we caught it manually.
"""
import re
import sys
import zipfile

source_path, mcpb_path = sys.argv[1:]

# Match `server.tool("wp_xxx"` allowing whitespace (incl. newlines) between
# the open paren and the quoted name. Multi-line registrations are common.
source_pattern = re.compile(r'server\.tool\(\s*"(wp_[a-z_][a-z0-9_]*)"')

with open(source_path, encoding='utf-8') as f:
    source_text = f.read()
source_tools = sorted(set(source_pattern.findall(source_text)))

# In the bundled JS, the `server` identifier may be mangled by esbuild —
# match `<id>.tool("wp_xxx"` instead. Same set semantics.
bundle_pattern = re.compile(r'\.tool\(\s*"(wp_[a-z_][a-z0-9_]*)"')

with zipfile.ZipFile(mcpb_path) as archive:
    bundle_js = archive.read('server/index.js').decode('utf-8', errors='replace')
bundle_tools = sorted(set(bundle_pattern.findall(bundle_js)))

if not source_tools:
    raise SystemExit('ERROR: tool-surface check could not extract any tools from source — regex/parsing broke?')
if not bundle_tools:
    raise SystemExit('ERROR: tool-surface check could not extract any tools from .mcpb bundle — regex/parsing broke?')

missing_in_bundle = [t for t in source_tools if t not in bundle_tools]
extra_in_bundle = [t for t in bundle_tools if t not in source_tools]

if missing_in_bundle or extra_in_bundle:
    msg = ['ERROR: MCPB tool-surface drift detected.']
    if missing_in_bundle:
        msg.append(f'  Registered in source but MISSING from .mcpb: {", ".join(missing_in_bundle)}')
        msg.append('  Fix: run `npm run build:mcpb` from mcp-server/, or `bash scripts/package-plugin.sh` (auto-rebuilds).')
    if extra_in_bundle:
        msg.append(f'  In .mcpb but NOT registered in source: {", ".join(extra_in_bundle)}')
        msg.append('  Fix: bundle is stale relative to current source; rebuild as above.')
    raise SystemExit('\n'.join(msg))

print(f'MCPB tool surface OK: {len(source_tools)} tools in sync')
PY

echo "Release consistency OK: version $plugin_version"
