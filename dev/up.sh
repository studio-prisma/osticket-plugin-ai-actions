#!/usr/bin/env bash
# Builds the local test stack, installs osTicket and seeds test data.
#
# Passwords are generated on first run and kept in dev/local/credentials.env
# (git-ignored). Nothing secret is part of the repository.
set -euo pipefail

DEV="$(cd "$(dirname "$0")" && pwd)"
LOCAL="$DEV/local"
CRED="$LOCAL/credentials.env"
mkdir -p "$LOCAL"

gen() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24; }

if [ ! -f "$CRED" ]; then
  {
    echo "SPA_DB_PASSWORD=$(gen)"
    echo "SPA_ADMIN_USER=aiadmin"
    echo "SPA_ADMIN_PASS=$(gen)"
    echo "SPA_AGENT_USER=salesagent"
    echo "SPA_AGENT_PASS=$(gen)"
    echo "SPA_CLIENT_PASS=$(gen)"
    echo "SPA_HTTP_PORT=8089"
    echo "SPA_MAIL_PORT=8027"
  } > "$CRED"
  echo "Generated $CRED"
fi
set -a; . "$CRED"; set +a

COMPOSE=(docker compose --env-file "$CRED" -f "$DEV/docker-compose.yml")
if [ -f "$LOCAL/compose.override.yml" ]; then
  COMPOSE+=(-f "$LOCAL/compose.override.yml")
  echo "Using $LOCAL/compose.override.yml"
fi

"${COMPOSE[@]}" up -d --build

BASE="http://127.0.0.1:${SPA_HTTP_PORT}"
echo -n "Waiting for osTicket "
for _ in $(seq 1 60); do
  # Any HTTP answer will do: before the install the portal redirects to the
  # installer, afterwards setup/ is gone. 000 means Apache isn't up yet.
  code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/" || true)"
  [ "$code" != "000" ] && break
  echo -n "."; sleep 2
done
echo

if "${COMPOSE[@]}" exec -T osticket grep -q "define('OSTINSTALLED',TRUE)" include/ost-config.php; then
  echo "osTicket already installed."
else
  JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
  curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/setup/install.php"
  curl -s -c "$JAR" -b "$JAR" -o "$LOCAL/install-response.html" "$BASE/setup/install.php" \
    --data-urlencode "s=install" \
    --data-urlencode "name=AI Actions Test Desk" \
    --data-urlencode "email=helpdesk@example.com" \
    --data-urlencode "lang_id=en_US" \
    --data-urlencode "fname=Test" \
    --data-urlencode "lname=Admin" \
    --data-urlencode "admin_email=admin@example.com" \
    --data-urlencode "username=${SPA_ADMIN_USER}" \
    --data-urlencode "passwd=${SPA_ADMIN_PASS}" \
    --data-urlencode "passwd2=${SPA_ADMIN_PASS}" \
    --data-urlencode "prefix=ost_" \
    --data-urlencode "dbhost=db" \
    --data-urlencode "dbname=osticket" \
    --data-urlencode "dbuser=osticket" \
    --data-urlencode "dbpass=${SPA_DB_PASSWORD}" \
    --data-urlencode "timezone=UTC"
  if ! "${COMPOSE[@]}" exec -T osticket grep -q "define('OSTINSTALLED',TRUE)" include/ost-config.php; then
    echo "Install failed, see $LOCAL/install-response.html" >&2
    exit 1
  fi
  echo "osTicket installed."
fi

# Container paths stay inside sh -c '...': Git Bash on Windows rewrites a bare
# /seed/... argument into a Windows path before docker sees it.
"${COMPOSE[@]}" exec -T osticket sh -c 'rm -rf setup && chmod 0644 include/ost-config.php'
"${COMPOSE[@]}" exec -T \
  -e SPA_AGENT_USER -e SPA_AGENT_PASS -e SPA_CLIENT_PASS \
  osticket sh -c 'php /seed/seed.php' > "$LOCAL/seed.out"
# The test suite reads ticket IDs and numbers from seed.json. A re-run on a
# seeded stack prints a notice instead of JSON and keeps the existing file.
if head -c 1 "$LOCAL/seed.out" | grep -q '{'; then
  mv "$LOCAL/seed.out" "$LOCAL/seed.json"
else
  cat "$LOCAL/seed.out"; rm -f "$LOCAL/seed.out"
fi

cat <<EOF

Ready.
  Portal      $BASE/
  Agent panel $BASE/scp/
  Mailpit     http://127.0.0.1:${SPA_MAIL_PORT}/
  Logins      $CRED
EOF
