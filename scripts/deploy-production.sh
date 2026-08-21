#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_BRANCH="${CERQLE_DEPLOY_BRANCH:-main}"

cd "$PROJECT_DIR"

CURRENT_BRANCH="$(git branch --show-current)"
if [[ "$CURRENT_BRANCH" != "$DEPLOY_BRANCH" ]]; then
    echo "Refusing to deploy: current branch is '$CURRENT_BRANCH', expected '$DEPLOY_BRANCH'." >&2
    exit 1
fi

echo "Fetching origin/$DEPLOY_BRANCH..."
git pull --ff-only origin "$DEPLOY_BRANCH"

# Laravel's deploy user and PHP-FPM must both be able to create cache and log
# files. Normalize these narrowly scoped runtime directories before Composer
# invokes Artisan so a file created by either user cannot break the next deploy.
mkdir -p storage/logs bootstrap/cache
if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    DEPLOY_USER="$(id -un)"
    sudo -n chown -R "$DEPLOY_USER":www-data storage bootstrap/cache
    sudo -n find storage bootstrap/cache -type d -exec chmod 2775 {} +
    sudo -n find storage bootstrap/cache -type f -exec chmod 0664 {} +
elif [[ ! -w storage/logs || ! -w bootstrap/cache ]]; then
    echo "ERROR: storage/logs and bootstrap/cache must be writable by the deploy user and PHP-FPM." >&2
    exit 1
fi

echo "Installing production dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci

APP_IS_DOWN=0
restore_application() {
    if [[ "$APP_IS_DOWN" -eq 1 ]]; then
        php artisan up
    fi
}
trap restore_application EXIT

php artisan down --retry=60
APP_IS_DOWN=1

# Clear stale configuration before the build so newly deployed config files
# and environment values are available to the release recorder.
php artisan optimize:clear

# The postbuild hook records the successful deployment and automatically bumps
# the visible patch version (for example 1.0.0 -> 1.0.1).
npm run build

php artisan migrate --force
php artisan optimize
php artisan queue:restart

# `queue:restart` asks current Laravel workers to exit. Supervisor normally
# starts replacements, but a manually-stopped process group remains STOPPED
# forever and leaves imports/messages silently queued. Ensure the general
# worker group is enabled after every deployment when Supervisor is present.
SUPERVISOR=()
if command -v supervisorctl >/dev/null 2>&1; then
    if supervisorctl status >/dev/null 2>&1; then
        SUPERVISOR=(supervisorctl)
    elif command -v sudo >/dev/null 2>&1 && sudo -n supervisorctl status >/dev/null 2>&1; then
        SUPERVISOR=(sudo -n supervisorctl)
    fi
fi

if [[ ${#SUPERVISOR[@]} -gt 0 ]]; then
    "${SUPERVISOR[@]}" reread
    "${SUPERVISOR[@]}" update
    # `start` reports an error when a process is already running, so verify
    # the resulting state explicitly instead of treating that as a failure.
    "${SUPERVISOR[@]}" start 'cerqle-worker:*' || true
    sleep 3
    WORKER_STATUS="$("${SUPERVISOR[@]}" status 'cerqle-worker:*')"
    echo "$WORKER_STATUS"
    if echo "$WORKER_STATUS" | grep -Eq '(STOPPED|FATAL|BACKOFF|EXITED|UNKNOWN)'; then
        echo "ERROR: One or more Cerqle queue workers failed to start." >&2
        exit 1
    fi
else
    echo "WARNING: Supervisor is unavailable; verify queue workers manually." >&2
fi

php artisan up
APP_IS_DOWN=0
trap - EXIT

echo "Deployment completed."
php artisan app:release --show
