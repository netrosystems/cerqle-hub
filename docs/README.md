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

Automation QA and release gate: [`automation-sqa.md`](automation-sqa.md).

Live node-by-node usability/functionality audit: [`automation-node-audit-2026-09-13.md`](automation-node-audit-2026-09-13.md).

Deployed four-node verification: [`automation-production-qa-2026-09-13.md`](automation-production-qa-2026-09-13.md).

Grouped inbox AI setup QA: [`grouped-ai-automation-qa-2026-09-13.md`](grouped-ai-automation-qa-2026-09-13.md).

Website chatbot AI availability QA: [`website-ai-availability-qa-2026-09-13.md`](website-ai-availability-qa-2026-09-13.md).

Personal notification availability QA: [`personal-notification-availability-qa-2026-09-13.md`](personal-notification-availability-qa-2026-09-13.md).

X social publishing implementation and release checks: [`x-integration-qa-2026-09-15.md`](x-integration-qa-2026-09-15.md).

Client-wide WhatsApp connection modes QA: [`coexistence-client-wide-qa-2026-09-15.md`](coexistence-client-wide-qa-2026-09-15.md).

Security hardening and compatibility evidence: [`security-hardening-qa-2026-09-15.md`](security-hardening-qa-2026-09-15.md).

Grounded Smart Bot and atomic Knowledge Base implementation evidence: [`grounded-smart-bot-qa-2026-09-16.md`](grounded-smart-bot-qa-2026-09-16.md).

Decision records explain durable choices and their rationale. A newer record may supersede an older one; do not edit history to imply a decision was always different.

- [`decisions/README.md`](decisions/README.md) — format and maintenance rules
- [`decisions/2026-09-07-branch-promotion-and-production-history.md`](decisions/2026-09-07-branch-promotion-and-production-history.md)
- [`decisions/2026-09-09-whatsapp-coexistence-pilot.md`](decisions/2026-09-09-whatsapp-coexistence-pilot.md)
- [`decisions/2026-09-09-whatsapp-campaign-sender-binding.md`](decisions/2026-09-09-whatsapp-campaign-sender-binding.md)
- [`decisions/2026-09-13-whatsapp-first-automations.md`](decisions/2026-09-13-whatsapp-first-automations.md)
- [`decisions/2026-09-13-send-only-automation-palette.md`](decisions/2026-09-13-send-only-automation-palette.md)
- [`decisions/2026-09-13-grouped-inbox-ai-automation.md`](decisions/2026-09-13-grouped-inbox-ai-automation.md)
- [`decisions/2026-09-13-website-ai-availability.md`](decisions/2026-09-13-website-ai-availability.md)
- [`decisions/2026-09-15-x-standard-social-publishing.md`](decisions/2026-09-15-x-standard-social-publishing.md)
- [`decisions/2026-09-15-client-wide-whatsapp-coexistence.md`](decisions/2026-09-15-client-wide-whatsapp-coexistence.md)
- [`decisions/2026-09-15-security-boundaries-and-deferred-client-permissions.md`](decisions/2026-09-15-security-boundaries-and-deferred-client-permissions.md)
- [`decisions/2026-09-16-grounded-smart-bot-answering.md`](decisions/2026-09-16-grounded-smart-bot-answering.md)

## Maintenance rule

- Long-lived scope belongs in `PROJECT_CONTEXT.md`.
- Current checkpoints and blockers belong in `PROJECT_STATUS.md` with a date.
- Durable decisions belong in `docs/decisions/`.
- Agent behavior and repository invariants belong in `AGENTS.md`.
- Do not duplicate detailed content in model-specific adapter files.
