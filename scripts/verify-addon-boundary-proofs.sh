#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$REPO_ROOT"

proofs=(
  "wp-plugin/wp-mcp-gateway/tests/free-image-generation-gate-proof.php"
  "wp-plugin/wp-mcp-gateway/tests/service-account-namespace-guard-proof.php"
)
# Note: free-multisite-gate-proof.php was removed when the paid multisite
# gate was lifted in 2026-05. Free now allows multisite by default; the
# behavior is a one-line `return true` in MCP_Gateway_Free_Gates and
# doesn't need a dedicated proof.
# Note: shared-license-sdk-proof.php was removed when the WordPress.org free
# core stopped loading the paid add-on licensing SDK. Paid add-ons now own that
# SDK boundary outside the submitted core package.

for proof in "${proofs[@]}"; do
  php -l "$proof" >/dev/null
  php "$proof"
done

echo "Add-on boundary regression proofs OK."
