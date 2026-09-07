#!/usr/bin/env bash
set -Eeuo pipefail

# Production follows origin/main; never create merge commits on the server.
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"
DEPLOY_BRANCH="${CERQLE_DEPLOY_BRANCH:-main}"
if [[ "$(git branch --show-current)" != "$DEPLOY_BRANCH" ]]; then
    echo "Refusing to sync: checkout must be on $DEPLOY_BRANCH." >&2
    exit 1
fi
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Refusing to sync: tracked files have local changes. Preserve and review them first." >&2
    exit 1
fi

git fetch origin "refs/heads/$DEPLOY_BRANCH:refs/remotes/origin/$DEPLOY_BRANCH"
TARGET="$(git rev-parse "origin/$DEPLOY_BRANCH^{commit}")"
CURRENT="$(git rev-parse HEAD)"
if [[ "$CURRENT" == "$TARGET" ]]; then
    echo "Already at $TARGET."
    exit 0
fi

if ! git merge-base --is-ancestor HEAD "$TARGET"; then
    BASE="$(git merge-base HEAD "$TARGET")"
    if ! git diff --quiet "$BASE" HEAD; then
        echo "Refusing to sync: server-only file changes need review:" >&2
        git --no-pager diff --stat "$BASE" HEAD >&2
        exit 1
    fi
fi

# Git may overwrite ignored files during checkout. Protect them explicitly too.
while IFS= read -r -d '' ADDED_PATH; do
    if [[ -e "$ADDED_PATH" || -L "$ADDED_PATH" ]]; then
        echo "Refusing to sync: incoming tracked path collides with a local file: $ADDED_PATH" >&2
        exit 1
    fi
done < <(git diff --name-only --diff-filter=A -z HEAD "$TARGET")

# reset --keep aborts on obstructing untracked files and preserves local files.
# A named ref retains the old history even after a metadata-only realignment.
BACKUP="deploy-backup/$(date -u +%Y%m%dT%H%M%SZ)-${CURRENT:0:12}"
git branch "$BACKUP" "$CURRENT"
echo "Previous checkout preserved at $BACKUP."
git reset --keep "$TARGET"
echo "Production checkout aligned to $TARGET (no server merge commit)."
