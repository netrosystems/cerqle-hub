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

php artisan up
APP_IS_DOWN=0
trap - EXIT

echo "Deployment completed."
php artisan app:release --show
