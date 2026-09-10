# Cerqle Hub repository instructions

These instructions apply to every file in this repository and to every person or coding agent making a change.

## Start here

Before changing code, read [`docs/README.md`](docs/README.md), then the authoritative document for the task domain. Read [`PROJECT_STATUS.md`](PROJECT_STATUS.md) when the task depends on current release state, provider blockers, or unfinished work.

Running code and database migrations are authoritative. Documentation describes intended behavior. Investigate discrepancies instead of silently preserving contradictions.

| Change domain | Authoritative document |
| --- | --- |
| Product scope, workflows and state machines | [`PLAN.md`](PLAN.md) |
| Backend, APIs, modules, queues and tenancy | [`ARCHITECTURE.md`](ARCHITECTURE.md) |
| UI, styling, layouts and components | [`DESIGNSYSTEM.md`](DESIGNSYSTEM.md) |
| Production release operations | [`DEPLOYMENT.md`](DEPLOYMENT.md) |
| Durable technical/product decisions | [`docs/decisions/`](docs/decisions/) |

Update the relevant authoritative document in the same commit when behavior changes. If a change has no documentation impact, say so in the handoff summary.

## Git promotion and deployment

- Commit changes on `spiderman` first.
- Other developers may commit directly to `dev`; fetch origin and incorporate current `origin/dev` into `spiderman` before promotion.
- Run proportionate checks, then fast-forward `dev` to the validated `spiderman` state. Never force-push a shared branch.
- Promote `dev` to `main` only after validation and explicit user release approval.
- Keep production history linear. Do not add a Git merge commit to production; when historical merge commits make branch tips diverge but trees align, use the documented linear replay procedure.
- Deploy only GitHub `main`, using `bash scripts/deploy-production.sh` as documented in [`DEPLOYMENT.md`](DEPLOYMENT.md).
- Do not bump the application version manually for ordinary commits. Production deployment finalization owns patch-version increments.

## Engineering invariants

- Preserve strict `workspace_id` isolation on every query, webhook, broadcast channel, job and API action.
- Keep public widget conversations private to a stable visitor/session identity; never expose a shared public transcript.
- Treat inbound webhooks as untrusted: verify signatures/tokens, apply idempotency and queue expensive work.
- Never expose encrypted credentials to the browser. Blank credential fields mean “keep the stored value.”
- Browser authentication uses session cookies and CSRF; mobile and external APIs use Sanctum bearer tokens.
- Keep provider capabilities distinct. Facebook and Instagram post-edit/delete behavior must remain capability-driven.
- Keep production license enforcement enabled. Local bypasses must be explicitly local-only and fail closed elsewhere.
- Preserve unrelated user changes in dirty worktrees.
- Never put passwords, tokens, app secrets, private keys, license codes, reviewer credentials, or real customer personal data in documentation or commits. Use placeholders such as `YOUR_APP_ID` and `REDACTED`.

## Collaboration expectations

- Work agentically through authorized steps. Ask only for genuinely missing facts, consequential approvals, or user-controlled OTP/phone participation.
- Do not repeat permission questions already answered, and do not claim completion without verification.
- Prefer compact UI, short primary descriptions and expandable help. Connection drawers contain connection controls; existing channels belong in the page body.
- A model change does not authorize deployment, task creation, provider submission, or unrelated setting changes.
- Historical status is context, not standing authorization. Recheck current code, Git and provider state before acting.

## Required checks

Use focused checks first and broaden them in proportion to risk:

```bash
php artisan test --filter=RelevantTest
npm test -- --run
npm run build
./vendor/bin/pint --test
composer analyse
```

For route, configuration, migration, or deployment changes, safely rebuild the relevant caches. Never claim an external integration works only because unit tests pass; state what still requires provider-side verification.

## Maintaining cross-model context

- Keep `AGENTS.md` stable and rule-oriented; do not append chronological work logs here.
- Update [`PROJECT_STATUS.md`](PROJECT_STATUS.md) for dated checkpoints, current blockers and next actions.
- Add or supersede a record in [`docs/decisions/`](docs/decisions/) when a durable product, architecture, security, or operational decision changes.
- Keep [`PROJECT_CONTEXT.md`](PROJECT_CONTEXT.md) focused on long-lived scope and system boundaries.
- Adapter files (`CLAUDE.md`, `GEMINI.md`, Copilot and Cursor instructions) point to these canonical sources; do not duplicate policy in them.
