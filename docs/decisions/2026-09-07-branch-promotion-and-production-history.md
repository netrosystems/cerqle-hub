# Decision: branch promotion and linear production history

- Date: 2026-09-07
- Status: Accepted

## Context

Work may occur on `spiderman` while other developers commit to `dev`. Production synchronization must preserve server-only data and cannot rely on destructive Git commands. Historical Git merges can also leave branch tips divergent even when their file trees are identical.

## Decision

- Develop and commit on `spiderman`.
- Fetch and incorporate current `origin/dev` before promotion.
- Validate, then fast-forward `dev` to the resulting state without force-pushing.
- Promote to `main` only with explicit user approval.
- Keep new production history linear. If `main` and `dev` have history-only divergence but the expected trees match, replay only missing validated commits onto `main` in an isolated worktree, prove final tree equality, and fast-forward `main`.
- Deploy only GitHub `main` through `scripts/deploy-production.sh`.

## Consequences

Branch SHAs can differ while trees are identical. Reports must distinguish commit identity from deployed content. Shared branches are never rewritten merely to make hashes match.
