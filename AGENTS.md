# Cerqle Hub repository instructions

These instructions apply to every file in this repository and to every person or coding agent making a change.

## Source of truth

The running code and database migrations are authoritative. Documentation explains the intended system. If code and documentation disagree, investigate the discrepancy; do not silently preserve contradictory behavior.

Start work by reading `docs/README.md`, and the authoritative domain specification for your change from the table below:

---

## 🧭 Domain specification & documentation impact matrix

Read the authoritative document for your task domain before writing code, and update it in the same commit if your changes affect that domain:

| Task / Change domain | Authoritative document | What it guarantees & when to update |
| :--- | :--- | :--- |
| **UI, Styling, Colors, Layouts & Components** | [`DESIGNSYSTEM.md`](DESIGNSYSTEM.md) | Pixel-perfect UI using Poppins typography, exact Cerqle Plum (`#3E2A49`) & Lilac (`#8F5FA7`) tokens, `.page` vs `.viewport-table-page` (InboxLayout 100vh) layouts, XYFlow canvas, and accessible UI components. |
| **Backend, APIs, Modules, Queues & Tenancy** | [`ARCHITECTURE.md`](ARCHITECTURE.md) | Correct modular monolith patterns (`app/Modules/*`), strict `workspace_id` query scoping, queue assignments (`whatsapp`, `ai`, `social`, `automation`, `broadcast`), Reverb/Pusher WebSockets, and health probes. |
| **Features, Workflows, State Machines & Roadmap** | [`PLAN.md`](PLAN.md) | Complete business logic compliance across all 10 modules (Omni-Channel Inbox, Master Email Inbox, Widget with HMAC, WhatsApp Cloud API, AI Knowledge Bases, XYFlow Automations, Social Publishing, SMS Campaigns, Billing). |
| **External Integrations & OAuth Credentials** | [`ARCHITECTURE.md`](ARCHITECTURE.md#5-integrations--external-service-contracts) and setup guides | Correct Meta OAuth scopes, Instagram limitations, Google/Microsoft OAuth, Telegram, SMS Gateways, SP-API / eBay, and Qdrant vectors. |

If a code change has no documentation impact, say so in the commit/hand-off summary. Do not bump the application version manually for ordinary commits; production deployment finalization owns patch-version increments.

---

## Documentation standards

- Record facts verified from code or provider documentation; label assumptions and pending decisions.
- Include exact route names, queue names, configuration key names, and platform limitations where useful.
- Never include passwords, API keys, access tokens, app secrets, private keys, license codes, reviewer credentials, or real customer personal data.
- Use placeholders such as `YOUR-DOMAIN`, `YOUR_APP_ID`, and `REDACTED`.
- Distinguish local, staging, and production behavior explicitly.
- Add dates to status snapshots and decisions that may become stale.

---

## Engineering invariants

- Preserve workspace isolation on every query, webhook, broadcast channel, job, and API action.
- Keep public widget conversations private to a stable visitor/session identity; never expose a shared public transcript.
- Treat inbound webhooks as untrusted: verify signatures/tokens, apply idempotency, and queue expensive work.
- Do not expose encrypted credentials back to the browser. Blank credential fields mean “keep the stored value.”
- Browser authentication uses session cookies and CSRF; mobile and external APIs use Sanctum bearer tokens.
- Do not conflate provider capabilities. In particular, Facebook and Instagram post-edit/delete support differ and must be capability-driven.
- Keep production license enforcement enabled. Any local testing bypass must be explicitly local-only and fail closed outside local environments.
- Preserve unrelated user changes in a dirty worktree.

---

## Required checks

Use focused tests first. For a typical backend/frontend change, consider:

```bash
php artisan test --filter=RelevantTest
npm test -- --run
npm run build
./vendor/bin/pint --test
composer analyse
```

For route, config, or deployment changes, also clear/rebuild relevant caches in a safe environment. Never claim an external integration works merely because a unit test passes; identify what still requires provider-side verification.
