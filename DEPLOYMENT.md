# Cerqle release workflow

Recorded user preference: 2026-09-07.

1. Commit work on `spiderman`.
2. Fetch `origin`. Incorporate other developers' current `origin/dev` changes into `spiderman`; resolve conflicts and rerun checks there.
3. Fast-forward `dev` to the tested `spiderman` commit. Push without force. If a concurrent push advances `dev`, fetch and repeat reconciliation.
4. After validation and release approval, merge `dev` into up-to-date `main` and push. Do not deploy unapproved `dev` commits.
5. On production, from `/home/ubuntu/cerqle-hub`, run `bash scripts/deploy-production.sh`.

The deployment installs and verifies an additive dedicated Supervisor program
for the `broadcast` queue when passwordless `sudo` is available. This prevents
campaigns from being starved by a busy or failing `default` queue while preserving
the existing worker configuration for other queues. After each release it
explicitly cycles the Cerqle Supervisor groups; the cache-backed Laravel restart
signal alone is not considered proof that long-running workers loaded the new
source. A missing dedicated campaign worker now stops release finalization.
Supervisor's immediate `start` result is treated as advisory because a worker can
exit on Laravel's restart signal during the cycle; deployment waits briefly and
requires the settled group status to be healthy before recording the release.

## Why deployment previously stopped

The production checkout contained server-local merge commits. `git pull --ff-only` cannot follow GitHub when histories diverge, even when those server commits introduce no file changes. Do not solve this by repeatedly merging on the server.

## Deployment safeguards

- A `flock` lock prevents overlapping deployment processes.
- `sync-production-code.sh` fetches GitHub and targets the exact fetched commit. It refuses dirty tracked files or server-only file differences from the common ancestor.
- If divergence is history-only, it creates `deploy-backup/<UTC timestamp>-<old SHA>` and aligns using `git reset --keep`. Normal updates receive the same recovery ref. This never uses `reset --hard`, `git clean`, force pushes, or server merge commits.
- Untracked files, ignored `.env`, uploads, and runtime release metadata remain untouched. A conflicting untracked file blocks synchronization.
- The deployment script is parsed as a function before the checkout changes, so replacing the script during sync does not splice two script versions together.
- Release recording happens after build, migrations, and worker checks, rather than in the build's postbuild hook. Local `npm run build` behavior is unchanged.
- A recovery Git ref preserves code history, not the database. A code rollback must account for applied migrations; never blindly roll back production migrations or erase data.

An unknown server-only change is a deliberate stop, not an instruction to reset it away. Review it, bring intended code through the branch workflow, and retry. Database backups remain an operational prerequisite for schema-changing releases.
