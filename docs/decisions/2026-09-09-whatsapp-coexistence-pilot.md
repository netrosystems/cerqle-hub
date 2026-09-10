# Decision: guarded WhatsApp Business-app coexistence pilot

- Date: 2026-09-09
- Status: Accepted, externally blocked

## Context

Cerqle needs to connect a WhatsApp Business-app number through Meta Embedded Signup while preserving the mobile account and avoiding changes to existing customer assets. Provider access and history-sharing capabilities are separate concerns.

## Decision

- Enable coexistence only for an explicitly allowlisted pilot workspace.
- Use a dedicated authorized test asset; never substitute another customer's WABA or an unrelated API number.
- Verify actor/workspace-bound single-use signup attempts, app/scopes, WABA membership, `is_on_biz_app=true`, `platform_type=CLOUD_API`, exact phone selection and cross-workspace exclusivity.
- Do not call `/register` or `/deregister` for coexistence.
- Keep history/contact import disabled and do not request `smb_app_data` for the pilot.
- Treat signed mobile echoes as outbound Business-app-origin messages, not inbound consent, unread, bot-trigger or duplicate API-send events.
- Mobile echoes trigger human takeover. Offboarding disables matching channels without deleting conversation history.

## Current external blocker

Meta onboarding last returned error `2655111`, indicating the partner app had not satisfied required advanced WhatsApp management/messaging access. No successful phone connection or end-to-end coexistence test has been recorded.

## Consequences

Code-complete does not mean provider-ready. Expansion beyond the pilot, history sharing, permission submission and destructive phone migration each require separate review and authorization.
