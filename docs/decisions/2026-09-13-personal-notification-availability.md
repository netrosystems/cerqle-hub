# Personal notification availability

Date: 2026-09-13
Status: Accepted

## Context

Teammates need predictable quiet hours without losing customer activity or changing support routing. Members may work different hours in different workspaces.

## Decision

Availability is personal and workspace-specific: Always, Scheduled or Paused. No row preserves Always. Members edit their own settings; client administrators only inspect summaries. Schedules use an IANA timezone and one interval/day, supporting all-day and overnight with overlap validation. Switching modes retains hours. Default unsaved weekdays are 09:00–17:00; browser timezone is suggested.

Work notifications retain database history and silent broadcasts. External alerts and foreground popups require availability at the original event and delivery, respecting existing preferences. No catch-up backlog. Security, verification/welcome and billing/subscription bypass availability. Unscoped legacy export notifications remain compatible; new export jobs pin the requesting workspace, and old unpinned jobs skip rather than export a different workspace.

## Consequences

This does not control assignment, presence, team access or AI. Paused has no expiry; no split shifts/holidays. Browser boundary/focus/cross-tab refresh preserves silent status. Provider-side delivery and real concurrent-worker recovery require designated recipient verification; mocked tests are not delivery evidence.
