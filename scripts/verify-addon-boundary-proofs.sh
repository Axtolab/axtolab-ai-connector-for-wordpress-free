#!/usr/bin/env bash
#
# Run the free-build boundary proofs. These are standalone PHP scripts
# with WordPress-function stubs that exercise security-sensitive
# behaviour without needing a full WP test harness.
#
# Each proof exits non-zero on regression, so this script fails CI if
# any boundary check breaks.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$REPO_ROOT"

# Proofs cover:
#   free-image-generation-gate-proof — confirms BYOK AI image generation
#     is exposed by default and the opt-out filter behaves correctly.
#   service-account-namespace-guard-proof — confirms the service-account
#     user cannot bypass the connector's REST namespace.
proofs=(
  "wp-plugin/axtolab-ai-connector/tests/free-image-generation-gate-proof.php"
  "wp-plugin/axtolab-ai-connector/tests/service-account-namespace-guard-proof.php"
)

for proof in "${proofs[@]}"; do
  php -l "$proof" >/dev/null
  php "$proof"
done

echo "Free-build boundary proofs OK."
