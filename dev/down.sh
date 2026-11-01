#!/usr/bin/env bash
# Stops the local test stack and deletes its data (the database runs on tmpfs).
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
CRED="$DEV/local/credentials.env"
ARGS=(--env-file "$CRED" -f "$DEV/docker-compose.yml")
[ -f "$DEV/local/compose.override.yml" ] && ARGS+=(-f "$DEV/local/compose.override.yml")
docker compose "${ARGS[@]}" down --remove-orphans
