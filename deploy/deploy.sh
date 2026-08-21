#!/usr/bin/env bash
#
# Deploy the platform.
#
# Run as the web user that owns the application directory, not as root — a
# deploy run as root leaves files the web server cannot write, and the first
# symptom is a session or a cache write failing at random hours later.
#
# The order below is not arbitrary. Migrations run after the new code is in
# place, because migrating first runs the old application against the new
# schema. Caches are rebuilt after migrating, because a cached config that
# still names the old queue is a worker that consumes nothing. The queue
# restart is last, because a worker started before the caches are warm holds
# the stale ones in memory for its whole life.

set -euo pipefail

APP_DIR="${APP_DIR:-/home/agriplatform/web/agriplatform}"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php}"

cd "$APP_DIR"

say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }

# ---------------------------------------------------------------------------
# Refuse to deploy over uncommitted work.
#
# Somebody edits a file on the server to fix something urgent, and a later
# deploy silently reverts it. Better to stop and make that visible.
# ---------------------------------------------------------------------------
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "There are uncommitted changes in $APP_DIR. Commit or stash them first." >&2
    exit 1
fi

say "Putting the site into maintenance mode"
# --render pre-renders the branded 503 page, so the maintenance page does not
# need the application to boot in order to be shown.
$PHP artisan down --render="errors.503" --retry=60 || true

# From here on, come back up whatever happens. A failed deploy that leaves the
# site in maintenance mode is a much longer outage than a failed deploy.
trap '$PHP artisan up || true' EXIT

say "Pulling $BRANCH"
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

say "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

say "Building assets"
npm ci
npm run build

say "Migrating"
$PHP artisan migrate --force

say "Rebuilding caches"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

say "Linking storage"
$PHP artisan storage:link || true

say "Restarting queue workers"
# Signals running workers to finish their current job and exit; Supervisor
# starts fresh ones with the new code and the new configuration.
$PHP artisan queue:restart

say "Checking health before letting anybody back in"
$PHP artisan about --only=environment >/dev/null

say "Bringing the site up"
$PHP artisan up
trap - EXIT

say "Deployed. Verify:"
echo "  curl -fsS https://\$APP_DOMAIN/health"
echo "  $PHP artisan ledger:reconcile     # should say the books agree"
