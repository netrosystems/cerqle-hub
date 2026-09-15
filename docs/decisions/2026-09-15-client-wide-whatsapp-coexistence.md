# Decision: client-wide WhatsApp coexistence availability

- Date: 2026-09-15
- Status: Accepted
- Supersedes: workspace pilot availability in `2026-09-09-whatsapp-coexistence-pilot.md`; other safety and import decisions remain.

## Decision

Every client sees Connect WABA and WhatsApp Business App in Channel Setup.
Coexistence availability is installation-wide, not workspace allowlisted. The
existing global switch remains an operational kill switch, default false; when
off the Business App option is disabled with a short explanation and WABA works.
Retire and ignore `WHATSAPP_COEXISTENCE_WORKSPACES` rather than rewriting `.env`.

Feature availability does not relax workspace account ownership, actor-bound
single-use onboarding, subscription/channel limits, phone mode verification,
signed webhooks, or human-takeover safeguards. History/contact import stays off.
Meta may still reject ineligible numbers; displaying a mode is not delivery proof.

## Validation and release

Test unrelated clients with empty/stale allowlists, global switch off, UI mode
visibility, coexistence launch flag, standard signup, replay and cross-workspace
rejection. Real Meta signup remains a separate provider check. Commit on spiderman
and promote validated dev; main promotion/deployment requires separate approval.
