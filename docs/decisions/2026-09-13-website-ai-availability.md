# Website chatbot AI availability

Date: 2026-09-13. Status: Accepted.

## Context

The website AI toggle did not support scheduled availability. Wisperbot UI inspection showed Permanent/Scheduled, multiple windows, timezone and overnight controls; its backend was not verified. The user approved matching availability with a compact editor.

## Decision

Store per-widget Off/Permanent/Scheduled, timezone, Monday–Sunday windows and revision. Five intervals/day maximum; reject overlaps across overnight/week boundaries. All-day supersedes stored intervals on that day. Default draft weekdays 09:00–17:00 uses application timezone because the current Workspace model has no timezone setting. Off retains bot/hours. Legacy enabled widgets migrate Permanent.

Resolve webchat eligibility through the original widget, not account metadata. Pin queued widget revision and bot; recheck availability/access/takeover before generation and sending. Receipt and send must be within hours; no backlog catch-up. Old unpinned jobs skip safely. Workflow/rule precedence and duplicate protection remain. Human support is available outside hours without inventing human presence. Messaging/email group settings remain independent.

## Consequences

Public visitor responses refresh effective availability; private schedules are not exposed. Holiday exceptions are excluded. A provider request already in flight cannot be retracted. Deployment and actual delivery/concurrent-worker verification need separate approval/evidence.
