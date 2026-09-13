# Grouped inbox AI automation

Date: 2026-09-13
Status: Accepted, implemented locally; provider release gate pending

## Decision

Use independent workspace-level messaging (WhatsApp/Instagram/Messenger) and email groups, not per-account schedules. Modes are Off/On/Scheduled. Use one interval/day, optional all-day and overnight hours, with explicit timezone. New accounts inherit the group. No row preserves legacy links; the first explicit save supersedes them without deleting metadata. Owners/admins may change settings with revision conflict protection.

Human ownership and requests always win. Waiting/matched workflows own the inbound before reply rules and AI. Durable unique inbound ownership coordinates both legacy listener entry points; AI generation is queued with conversation overlap protection and durable attempt claims. Bind email sends to the original inbound and original sender rather than the most recent message or Reply-To. No CC/BCC. Unknown provider delivery is reviewed, not retried. Recheck eligibility before generation and send; stale jobs older than ten minutes are skipped rather than releasing a backlog after worker recovery.

Default email human triage does not mean assignment. Assigned users, manual replies and handover markers stop AI; explicit handback clears assignment/handover. Initial/history, automated/list/bounce/self mail and empty/attachment-only answers are excluded. Provider generation fallback remains available, but credit/rate-limit/in-progress failures never send a fallback.

Explicit handback records the latest message ID. A later manual reply stops AI even if the conversation still says bot; earlier manual replies cannot prevent intentional handback. ID ordering avoids same-second timestamp ambiguity.

## Consequences

Existing workflows are preserved. Off does not disable reply rules or workflows. Redis worker delivery/restart/concurrency and real provider receipts need designated test recipients and separate release approval. AI receipt states are durable; failed generation/send and interrupted attempts surface as failed bot conversation entries. No migration starts AI or rewrites existing accounts.
