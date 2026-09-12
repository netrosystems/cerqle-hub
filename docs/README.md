# Cerqle Hub documentation map

Use this index to load only the context relevant to the task.

## Begin every task

1. Read [`../AGENTS.md`](../AGENTS.md).
2. Read [`../PROJECT_CONTEXT.md`](../PROJECT_CONTEXT.md) for unfamiliar project areas.
3. Read [`../PROJECT_STATUS.md`](../PROJECT_STATUS.md) when current Git, production, provider blockers, or unfinished work matters.
4. Read the authoritative domain document below before changing behavior.

## Authoritative domain documents

| Domain | Document |
| --- | --- |
| Features and workflows | [`../PLAN.md`](../PLAN.md) |
| Architecture, APIs, integrations, queues and tenancy | [`../ARCHITECTURE.md`](../ARCHITECTURE.md) |
| UI and interaction system | [`../DESIGNSYSTEM.md`](../DESIGNSYSTEM.md) |
| Deployment and production synchronization | [`../DEPLOYMENT.md`](../DEPLOYMENT.md) |
| WhatsApp coexistence implementation | [`../WHATSAPP_COEXISTENCE.md`](../WHATSAPP_COEXISTENCE.md) |
| Static-analysis cleanup | [`../PHPSTAN_CLEANUP.md`](../PHPSTAN_CLEANUP.md) |

## Decision records

Mobile team handoff: [`mobile-inbox-api.md`](mobile-inbox-api.md) covers email bulk resolve and whole-chat deletion, including rollout status and response contracts.

Decision records explain durable choices and their rationale. A newer record may supersede an older one; do not edit history to imply a decision was always different.

- [`decisions/README.md`](decisions/README.md) — format and maintenance rules
- [`decisions/2026-09-07-branch-promotion-and-production-history.md`](decisions/2026-09-07-branch-promotion-and-production-history.md)
- [`decisions/2026-09-09-whatsapp-coexistence-pilot.md`](decisions/2026-09-09-whatsapp-coexistence-pilot.md)
- [`decisions/2026-09-09-whatsapp-campaign-sender-binding.md`](decisions/2026-09-09-whatsapp-campaign-sender-binding.md)

## Maintenance rule

- Long-lived scope belongs in `PROJECT_CONTEXT.md`.
- Current checkpoints and blockers belong in `PROJECT_STATUS.md` with a date.
- Durable decisions belong in `docs/decisions/`.
- Agent behavior and repository invariants belong in `AGENTS.md`.
- Do not duplicate detailed content in model-specific adapter files.
