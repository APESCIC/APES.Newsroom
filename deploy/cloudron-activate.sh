#!/bin/bash
#
# Runs INSIDE the Cloudron LAMP app container (via `cloudron exec`), never
# on the GitHub Actions runner. Takes a release identifier (short git SHA)
# that has already been uploaded to /app/data/releases/<id> by the
# "Deploy to Cloudron (beta)" GitHub Action, and activates it.
#
# Responsible for exactly the "run migrations ... activate atomically"
# part of the guarded deploy sequence in issue #3. Backup, upload, restart,
# and health/smoke checks are orchestrated by the calling GitHub Actions
# workflow (.github/workflows/deploy.yml) - this script does not restart
# the app or check external health itself, so a failure here always
# leaves the previous release live and serving traffic.
#
# Usage (run as root inside the container, as `cloudron exec` does):
#   bash /app/data/releases/<id>/deploy/cloudron-activate.sh <id>
#
# <id> is typically <short-sha>-<github-run-id> so same-commit redeploys
# never sync into an already-activated release directory.

set -euo pipefail

RELEASE_SHA="${1:?usage: cloudron-activate.sh <release-sha>}"
DATA_DIR="/app/data"
RELEASE_DIR="${DATA_DIR}/releases/${RELEASE_SHA}"
SHARED_DIR="${DATA_DIR}/shared"
CURRENT_LINK="${DATA_DIR}/current"
PREVIOUS_FILE="${DATA_DIR}/previous_release"

if [ ! -d "${RELEASE_DIR}" ]; then
    echo "Release directory ${RELEASE_DIR} does not exist - upload must have failed." >&2
    exit 1
fi

echo "==> Preparing shared, persistent state"
mkdir -p \
    "${SHARED_DIR}/storage/app/public" \
    "${SHARED_DIR}/storage/app/private" \
    "${SHARED_DIR}/storage/logs"

# The .env file is never part of the uploaded artifact (see .gitignore /
# CI). It lives once in shared storage and is symlinked into every
# release, so APP_KEY (and therefore existing sessions/encrypted data)
# survives across deploys. Cloudron's own CLOUDRON_* addon variables take
# priority over it at runtime via CloudronEnvironmentServiceProvider - this
# file mainly needs to exist and hold APP_KEY.
if [ ! -f "${SHARED_DIR}/.env" ]; then
    echo "==> First deploy: creating persistent .env from .env.example"
    cp "${RELEASE_DIR}/.env.example" "${SHARED_DIR}/.env"
    sed -i 's/^APP_ENV=local$/APP_ENV=production/' "${SHARED_DIR}/.env"
    sed -i 's/^APP_DEBUG=true$/APP_DEBUG=false/' "${SHARED_DIR}/.env"
    chown www-data:www-data "${SHARED_DIR}/.env"
fi

ln -sfn "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"

echo "==> Linking persistent storage into the new release"
rm -rf "${RELEASE_DIR}/storage/app" "${RELEASE_DIR}/storage/logs"
ln -sfn "${SHARED_DIR}/storage/app" "${RELEASE_DIR}/storage/app"
ln -sfn "${SHARED_DIR}/storage/logs" "${RELEASE_DIR}/storage/logs"

mkdir -p \
    "${RELEASE_DIR}/storage/framework/cache/data" \
    "${RELEASE_DIR}/storage/framework/sessions" \
    "${RELEASE_DIR}/storage/framework/views" \
    "${RELEASE_DIR}/bootstrap/cache"

chown -R www-data:www-data "${SHARED_DIR}/storage" "${SHARED_DIR}/.env"

# PHP session storage must not live on Cloudron's read-only path (see
# docs.cloudron.io/packaging/cheat-sheet#php).
mkdir -p /run/php/sessions
chown www-data:www-data /run/php/sessions

chown -R www-data:www-data "${RELEASE_DIR}"

WWW_DATA_PHP=(bash "${RELEASE_DIR}/deploy/cloudron-www-data.sh" /usr/bin/php)

if [ -f "${CURRENT_LINK}" ] || [ -L "${CURRENT_LINK}" ]; then
    readlink "${CURRENT_LINK}" > "${PREVIOUS_FILE}" || true
fi

echo "==> Generating APP_KEY if this is a first-ever deploy"
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" key:generate --force --no-interaction \
    --ansi 2>&1 | grep -v 'Application key set' || true

# Fail closed: if Cloudron MySQL is provisioned, www-data artisan must see
# mysql — otherwise migrate would write to sqlite and leave MySQL empty.
if [ -n "${CLOUDRON_MYSQL_HOST:-}" ]; then
    echo "==> Asserting www-data artisan targets MySQL (not sqlite fallback)"
    db_default="$("${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" tinker --execute="echo config('database.default');")"
    if [ "${db_default}" != "mysql" ]; then
        echo "FATAL: CLOUDRON_MYSQL_HOST is set but artisan database.default is '${db_default:-empty}' (expected mysql)." >&2
        echo "sudo likely stripped CLOUDRON_* — use deploy/cloudron-www-data.sh." >&2
        exit 1
    fi
fi

echo "==> Running database migrations"
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" migrate --force --no-interaction

echo "==> Syncing Change Log Hub releases from changelog/releases"
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" newsroom:sync-releases --no-interaction

echo "==> Ensuring public/storage symlink for uploaded media"
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" storage:link --force --no-interaction \
    || ln -sfn ../storage/app/public "${RELEASE_DIR}/public/storage"

echo "==> Caching config/routes/views for the new release"
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" config:cache
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" route:cache
"${WWW_DATA_PHP[@]}" "${RELEASE_DIR}/artisan" view:cache

echo "==> Activating release ${RELEASE_SHA} atomically"
ln -sfn "${RELEASE_DIR}" "${CURRENT_LINK}.new"
mv -Tf "${CURRENT_LINK}.new" "${CURRENT_LINK}"

echo "==> Activated. Previous release recorded at ${PREVIOUS_FILE} (if any) for rollback."
echo "NOTE: the queue worker and Apache still need an app restart to pick up"
echo "this release - the caller (deploy.yml) issues 'cloudron restart' next."
