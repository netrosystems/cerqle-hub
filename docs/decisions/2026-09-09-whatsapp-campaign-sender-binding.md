# Decision: explicit sender binding for WhatsApp Campaigns

- Date: 2026-09-09
- Status: Accepted

## Context

A workspace can own multiple WhatsApp Business Accounts and phone numbers. Falling back to the first workspace client or selecting a template only by name/language can send from the wrong asset.

## Decision

- Every WhatsApp campaign persists an explicit WABA ID and phone-number ID.
- The selected WABA, phone, active channel account, approved template and workspace ownership are validated on final save, launch and queued send.
- Templates are scoped to the selected WABA. Prepared campaigns cannot switch sender, template or audience.
- WhatsApp campaigns use channel-specific consent, bounded audience preparation, staged pacing, finite retry behavior, monthly message quotas and exact-channel Inbox mirroring.
- Ambiguous provider outcomes without a message ID are not automatically retried, reducing duplicate-send risk.
- Header media downloads require public HTTPS resolution, reject private/reserved addresses and redirects, pin DNS resolution where supported, and enforce a configured size ceiling.

## Consequences

There is no silent sender fallback. A disconnected phone, inactive channel, revoked template, cross-workspace asset, missing parameter, quota exhaustion or unsafe media URL stops or safely pauses work instead of switching assets.
