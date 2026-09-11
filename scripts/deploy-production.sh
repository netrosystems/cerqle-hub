#!/usr/bin/env bash

set -Eeuo pipefail

main() {
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_BRANCH="${CERQLE_DEPLOY_BRANCH:-main}"

cd "$PROJECT_DIR"

# Linux production hosts must serialize deploys, including code synchronization.
command -v flock >/dev/null 2>&1 || { echo "flock is required for safe deployment." >&2; exit 1; }
exec 9>"$(git rev-parse --git-path cerqle-deploy.lock)"
flock -n 9 || { echo "Another deployment is running." >&2; exit 1; }

CURRENT_BRANCH="$(git branch --show-current)"
if [[ "$CURRENT_BRANCH" != "$DEPLOY_BRANCH" ]]; then
    echo "Refusing to deploy: current branch is '$CURRENT_BRANCH', expected '$DEPLOY_BRANCH'." >&2
    exit 1
fi

bash scripts/sync-production-code.sh

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

# Record the release only after migrations and worker checks succeed.
npm --ignore-scripts run build

php artisan migrate --force
php artisan optimize
php artisan queue:restart

# Keep campaign delivery independent from noisy general-purpose jobs. Older
# installations used a single priority-ordered worker, so a sustained default
# queue backlog could prevent the broadcast queue from ever being inspected.
BROADCAST_PROGRAM='cerqle-broadcast-worker'
BROADCAST_CONFIG='/etc/supervisor/conf.d/cerqle-broadcast-worker.conf'
if command -v supervisorctl >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    PHP_CLI_BINARY="$(command -v php)"
    TEMP_CONFIG="$(mktemp)"
    sed -e "s|@PHP_BINARY@|$PHP_CLI_BINARY|g" \
        -e "s|@PROJECT_DIR@|$PROJECT_DIR|g" \
        deploy/server/cerqle-broadcast-worker.conf.template > "$TEMP_CONFIG"
    sudo -n install -m 0644 "$TEMP_CONFIG" "$BROADCAST_CONFIG"
    rm -f "$TEMP_CONFIG"
fi

# `queue:restart` relies on the old worker reading the same cache-backed restart
# signal. Explicitly cycling the known Supervisor groups guarantees every
# long-running process loads the newly deployed PHP source and configuration.
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

    if "${SUPERVISOR[@]}" status 'cerqle-worker:*' >/dev/null 2>&1; then
        "${SUPERVISOR[@]}" stop 'cerqle-worker:*' || true
        "${SUPERVISOR[@]}" start 'cerqle-worker:*'
        sleep 3
        WORKER_STATUS="$("${SUPERVISOR[@]}" status 'cerqle-worker:*')"
        echo "$WORKER_STATUS"
        if echo "$WORKER_STATUS" | grep -Eq '(STOPPED|FATAL|BACKOFF|EXITED|UNKNOWN)'; then
            echo "ERROR: One or more Cerqle queue workers failed to start." >&2
            exit 1
        fi
    fi

    if "${SUPERVISOR[@]}" status "$BROADCAST_PROGRAM:*" >/dev/null 2>&1; then
        "${SUPERVISOR[@]}" stop "$BROADCAST_PROGRAM:*" || true
        "${SUPERVISOR[@]}" start "$BROADCAST_PROGRAM:*"
        sleep 2
        BROADCAST_STATUS="$("${SUPERVISOR[@]}" status "$BROADCAST_PROGRAM:*")"
        echo "$BROADCAST_STATUS"
        if echo "$BROADCAST_STATUS" | grep -Eq '(STOPPED|FATAL|BACKOFF|EXITED|UNKNOWN)'; then
            echo "ERROR: The dedicated campaign workers failed to start." >&2
            exit 1
        fi
    else
        echo "ERROR: Dedicated broadcast workers are not installed; refusing to leave campaign delivery without isolated capacity." >&2
        exit 1
    fi
else
    echo "WARNING: Supervisor is unavailable; verify queue workers manually." >&2
fi

php artisan app:release
php artisan up
APP_IS_DOWN=0
trap - EXIT

# Reconcile Meta's external webhook state after the application is reachable.
# Keep a transient Meta outage from rolling back an otherwise healthy deploy,
# while making the failure explicit in deployment logs. The daily scheduler
# retries this repair automatically.
if ! php artisan whatsapp:register-webhook; then
    echo "WARNING: WhatsApp inbound webhook registration is not healthy; the daily repair task will retry." >&2
fi

echo "Deployment completed."
php artisan app:release --show
}

# Parse the complete implementation before synchronization replaces this file.
main "$@"
