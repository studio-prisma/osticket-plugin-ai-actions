#!/usr/bin/env bash
# Stops the local test stack and deletes its data (the database runs on tmpfs).
# SPA_LOCAL picks a second stack, see up.sh.
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
LOCAL="${SPA_LOCAL:-$DEV/local}"
CRED="$LOCAL/credentials.env"
set -a; . "$CRED"; set +a
ARGS=(-p "${SPA_PROJECT:-spa-dev}" --env-file "$CRED" -f "$DEV/docker-compose.yml")
[ -f "$LOCAL/compose.override.yml" ] && ARGS+=(-f "$LOCAL/compose.override.yml")
docker compose "${ARGS[@]}" down --remove-orphans
