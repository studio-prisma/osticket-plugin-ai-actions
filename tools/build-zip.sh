#!/usr/bin/env bash
# Builds the install package: dist/ai-actions-<version>.zip
#
#   ./tools/build-zip.sh            # version read from plugin.php
#   ./tools/build-zip.sh 0.1.1      # explicit version (must match plugin.php)
#
# The archive contains exactly the production files, nested in an `ai-actions/`
# folder so it can be unzipped straight into `include/plugins/`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANIFEST_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" plugin.php | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
CONSTANT_VERSION="$(grep -oE "const VERSION = '[^']+'" class.AiActionsPlugin.php | sed -E "s/.*'([^']+)'\$/\1/")"
VERSION="${1:-$MANIFEST_VERSION}"

if [ "$VERSION" != "$MANIFEST_VERSION" ] || [ "$VERSION" != "$CONSTANT_VERSION" ]; then
  echo "Version mismatch: requested '$VERSION', plugin.php '$MANIFEST_VERSION', AiActionsPlugin::VERSION '$CONSTANT_VERSION'." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/ai-actions/src" "$STAGE/ai-actions/assets"
cp plugin.php config.php class.AiActionsPlugin.php LICENSE "$STAGE/ai-actions/"
cp src/*.php "$STAGE/ai-actions/src/"
cp assets/ai-actions.css assets/ai-actions.js "$STAGE/ai-actions/assets/"

# Reject anything that must never ship.
if grep -rniE "(passw(or)?d|secret|token)[[:space:]]*=[[:space:]]*['\"][A-Za-z0-9]{8,}" "$STAGE/ai-actions"; then
  echo "Refusing to build: something that looks like a literal secret is in the staged files." >&2
  exit 1
fi
# An API key in the package would be published with every release.
if grep -rnE "sk-ant-[A-Za-z0-9_-]{20,}" "$STAGE/ai-actions"; then
  echo "Refusing to build: something that looks like an Anthropic API key is in the staged files." >&2
  exit 1
fi
if find "$STAGE/ai-actions" -name '*.key' -o -name '.env*' | grep -q .; then
  echo "Refusing to build: a key or .env file is in the staged files." >&2
  exit 1
fi
if grep -rnE "^<<<<<<<|^>>>>>>>" "$STAGE/ai-actions"; then
  echo "Refusing to build: merge conflict markers." >&2
  exit 1
fi

mkdir -p dist
rm -f "dist/ai-actions-${VERSION}.zip"
# Reproducible archive: every file gets the commit date, entries are sorted,
# no extra fields. Building the same commit twice yields the same bytes.
EPOCH="$(git log -1 --format=%ct 2>/dev/null || true)"
if [ -z "$EPOCH" ]; then
  EPOCH="$(date +%s)"
  echo "Note: not a git checkout, using the current time for file dates." >&2
fi
find "$STAGE" -exec touch -d "@$EPOCH" {} +
( cd "$STAGE" && find ai-actions -type f | LC_ALL=C sort | TZ=UTC zip -X -q "ai-actions-${VERSION}.zip" -@ )
mv "$STAGE/ai-actions-${VERSION}.zip" "dist/"

echo "Built dist/ai-actions-${VERSION}.zip"
unzip -l "dist/ai-actions-${VERSION}.zip"
