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

# Keep campaign delivery independent from noisy general-purpose jobs. Older
# installations used a single priority-ordered worker, so a sustained default
# queue backlog could prevent the broadcast queue from ever being inspected.
BROADCAST_PROGRAM='cerqle-broadcast-worker'
BROADCAST_CONFIG='/etc/supervisor/conf.d/cerqle-broadcast-worker.conf'
LEGACY_BROADCAST_CONFIG='/etc/supervisor/conf.d/cerqle-broadcast.conf'
LEGACY_BROADCAST_BACKUP='/etc/supervisor/cerqle-broadcast.conf.disabled'
if command -v supervisorctl >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    PHP_CLI_BINARY="$(command -v php)"
    TEMP_CONFIG="$(mktemp)"
    sed -e "s|@PHP_BINARY@|$PHP_CLI_BINARY|g" \
        -e "s|@PROJECT_DIR@|$PROJECT_DIR|g" \
        deploy/server/cerqle-broadcast-worker.conf.template > "$TEMP_CONFIG"
    sudo -n install -m 0644 "$TEMP_CONFIG" "$BROADCAST_CONFIG"
    rm -f "$TEMP_CONFIG"

    # A former server-local configuration launched twelve campaign workers and
    # can conflict with the repository-managed group. Disable only that exact
    # legacy program and retain a recoverable copy outside conf.d.
    if [[ -f "$LEGACY_BROADCAST_CONFIG" ]] && sudo -n grep -qF '[program:cerqle-broadcast]' "$LEGACY_BROADCAST_CONFIG"; then
        if [[ -e "$LEGACY_BROADCAST_BACKUP" ]]; then
            echo "ERROR: Legacy broadcast worker backup already exists; review both Supervisor files manually." >&2
            exit 1
        fi
        sudo -n mv "$LEGACY_BROADCAST_CONFIG" "$LEGACY_BROADCAST_BACKUP"
    fi
fi

# Explicitly cycling the known Supervisor groups guarantees every long-running
# process loads the newly deployed PHP source and configuration. Do not also
# broadcast Laravel's cache-backed restart signal: combining both restart
# mechanisms can make freshly started workers exit during the health check.
SUPERVISOR=()
if command -v supervisorctl >/dev/null 2>&1; then
    if supervisorctl status >/dev/null 2>&1; then
        SUPERVISOR=(supervisorctl)
    elif command -v sudo >/dev/null 2>&1 && sudo -n supervisorctl status >/dev/null 2>&1; then
        SUPERVISOR=(sudo -n supervisorctl)
    fi
fi

if [[ ${#SUPERVISOR[@]} -gt 0 ]]; then
    wait_for_supervisor_group() {
        local program="$1"
        local expected="$2"
        local status=''
        local running=0

        for _attempt in {1..45}; do
            status="$("${SUPERVISOR[@]}" status "$program:*" 2>&1 || true)"
            running="$(grep -cE "^$program:.*[[:space:]]RUNNING[[:space:]]" <<< "$status" || true)"
            if [[ "$running" -eq "$expected" ]] && ! grep -Eq '(STARTING|STOPPING|STOPPED|FATAL|BACKOFF|EXITED|UNKNOWN)' <<< "$status"; then
                echo "$status"
                return 0
            fi
            sleep 1
        done

        echo "$status"
        return 1
    }

    "${SUPERVISOR[@]}" reread
    "${SUPERVISOR[@]}" update

    GENERAL_STATUS="$("${SUPERVISOR[@]}" status 'cerqle-worker:*' 2>&1 || true)"
    EXPECTED_GENERAL_WORKERS="$(grep -cE '^cerqle-worker:' <<< "$GENERAL_STATUS" || true)"
    if [[ "$EXPECTED_GENERAL_WORKERS" -gt 0 ]]; then
        "${SUPERVISOR[@]}" stop 'cerqle-worker:*' || true
        # Supervisor can return non-zero while an autorestarting process is
        # being cycled. Judge the settled group state below instead.
        "${SUPERVISOR[@]}" start 'cerqle-worker:*' || true
        if ! wait_for_supervisor_group 'cerqle-worker' "$EXPECTED_GENERAL_WORKERS"; then
            echo "ERROR: One or more Cerqle queue workers failed to start." >&2
            exit 1
        fi
    fi

    BROADCAST_STATUS="$("${SUPERVISOR[@]}" status "$BROADCAST_PROGRAM:*" 2>&1 || true)"
    if ! grep -qF "$BROADCAST_PROGRAM:" <<< "$BROADCAST_STATUS"; then
        echo "ERROR: Dedicated broadcast workers are not installed; refusing to leave campaign delivery without isolated capacity." >&2
        exit 1
    fi

    "${SUPERVISOR[@]}" stop "$BROADCAST_PROGRAM:*" || true
    "${SUPERVISOR[@]}" start "$BROADCAST_PROGRAM:*" || true
    if ! wait_for_supervisor_group "$BROADCAST_PROGRAM" 2; then
        echo "ERROR: Expected two dedicated campaign workers to be RUNNING." >&2
        exit 1
    fi
else
    # Without Supervisor access, Laravel's cache-backed signal is the safest
    # available way to ask long-running queue workers to load the new release.
    php artisan queue:restart
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
