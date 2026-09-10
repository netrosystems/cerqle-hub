# Cerqle Hub project context

Cerqle Hub is a multi-tenant customer communication and automation platform. It combines omnichannel conversations, email, website chat, WhatsApp Cloud API, social publishing, AI knowledge bases and agents, visual automations, campaigns, ecommerce context, subscriptions, and a developer API in one workspace-scoped application.

## Product boundaries

The product is organized around ten capabilities documented in [`PLAN.md`](PLAN.md):

1. Multi-tenancy, authentication and team management.
2. Omni-channel agent inbox and master email inbox.
3. Embeddable website chatbot widget.
4. WhatsApp Cloud API and template management, including a guarded coexistence pilot.
5. Social publishing and scheduling.
6. AI knowledge bases and autonomous smart bots.
7. Visual workflow automation.
8. SMS and WhatsApp campaigns.
9. Ecommerce integrations and customer context.
10. Subscriptions, add-ons and external APIs.

## Technical shape

- Laravel modular monolith with domain code primarily under `app/Modules/*`.
- React/Inertia frontend under `resources/js`.
- Workspace-scoped relational data with queues for `whatsapp`, `ai`, `social`, `automation`, and `broadcast` workloads.
- Reverb/Pusher-compatible real-time events.
- Session/CSRF authentication for browser requests and Sanctum tokens for mobile/external APIs.
- External integrations include Meta/WhatsApp, Instagram/Facebook, TikTok, Google/Microsoft email, SMS gateways, ecommerce providers, Qdrant and managed AI providers.

See [`ARCHITECTURE.md`](ARCHITECTURE.md) for concrete routes, services, data boundaries and queue contracts.

## Non-negotiable system properties

- No cross-workspace data or asset access.
- Signed, idempotent webhook ingestion.
- Secrets remain encrypted and server-side.
- Campaign and automation sends respect consent, subscription state, provider capabilities, message quotas and queue pacing.
- Public chat history is isolated by stable visitor/session identity.
- Provider limitations are represented explicitly rather than hidden behind generic UI.
- Production deployment is recoverable and preserves runtime data, secrets, uploads and unrelated server files.

## Sources of truth

- Code and migrations: implemented behavior.
- [`PLAN.md`](PLAN.md): product workflows and verification criteria.
- [`ARCHITECTURE.md`](ARCHITECTURE.md): technical contracts.
- [`DESIGNSYSTEM.md`](DESIGNSYSTEM.md): visual and interaction standards.
- [`DEPLOYMENT.md`](DEPLOYMENT.md): release procedure.
- [`PROJECT_STATUS.md`](PROJECT_STATUS.md): dated operational checkpoint; verify before relying on it.
- [`docs/decisions/`](docs/decisions/): durable decisions and their rationale.

## Working agreement for AI models

Start with [`AGENTS.md`](AGENTS.md) and [`docs/README.md`](docs/README.md). Read only the domain documents relevant to the task, but read those completely before changing behavior. Preserve unrelated work, cite verified provider contracts, update documentation with behavior, and clearly separate code-complete, deployed, and provider-validated states.
