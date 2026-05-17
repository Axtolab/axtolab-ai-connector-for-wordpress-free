#!/usr/bin/env bash
set -euo pipefail

ZIP_FILE="${1:?zip file required}"
PLUGIN_SLUG="${2:?plugin slug required}"
PLUGIN_FILE="${3:?plugin file required}"

if [[ ! -s "$ZIP_FILE" ]]; then
  echo "ERROR: $ZIP_FILE is missing or empty." >&2
  exit 1
fi

python3 - "$ZIP_FILE" <<'PY'
import sys
from pathlib import Path

zip_path = Path(sys.argv[1])
if zip_path.read_bytes()[:2] != b"PK":
    raise SystemExit(f"ERROR: {zip_path} does not start with ZIP magic PK.")
PY

unzip -tq "$ZIP_FILE" >/dev/null
unzip -l "$ZIP_FILE" "$PLUGIN_SLUG/$PLUGIN_FILE" >/dev/null

if unzip -Z1 "$ZIP_FILE" | grep -E '(^|/)(\.git|\.github|\.DS_Store|node_modules|vendor|tests|docs|dist|Wordpress Theme)(/|$)' >/dev/null; then
  echo "ERROR: $ZIP_FILE contains development-only paths." >&2
  unzip -Z1 "$ZIP_FILE" | grep -E '(^|/)(\.git|\.github|\.DS_Store|node_modules|vendor|tests|docs|dist|Wordpress Theme)(/|$)' >&2
  exit 1
fi

for required in \
  "$PLUGIN_SLUG/$PLUGIN_FILE" \
  "$PLUGIN_SLUG/readme.txt" \
  "$PLUGIN_SLUG/assets/axtolab-ai-connector.mcpb"
do
  unzip -l "$ZIP_FILE" "$required" >/dev/null
done

echo "Package verification OK: $ZIP_FILE"
