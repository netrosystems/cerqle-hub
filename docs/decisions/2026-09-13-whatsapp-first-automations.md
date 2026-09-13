# WhatsApp-first support automations

Date: 2026-09-13
Status: Accepted for implementation; provider release gate pending

Creation-palette decision superseded by [SEND-only creation palette](2026-09-13-send-only-automation-palette.md). Runtime safety decisions below remain in effect.

## Context

The initial palette exposed many provider-dependent capabilities while continuation, sender identity and preview semantics were insufficiently explicit. The user selected WhatsApp-first support plus follow-ups.

## Decision

Expose Message Received with exact account scope and ten support actions. Preserve legacy definitions without rewriting them; block unsupported new activation. Runs pin graph/configuration and chat/account identity but recheck live safety state. Questions/menus own their reply interaction and expire without unsolicited resend. Assignment ends interaction. A node attempt is claimed before execution; ambiguous crashes require delivery review rather than automatic replay. Preview is simulation, not delivery signoff.

Body-parameter templates are supported initially. Media/dynamic headers, dynamic buttons and voice-call templates are blocked until their configuration/provider behavior has dedicated coverage. Audio is deferred from new Media nodes; image/video/document remain.

## Consequences

Migration cancels unsnapshotted legacy waiting/running runs. Deploy with workers paused through migrations, restart afterward, and review cancelled runs instead of automatically resending. Claims/receipts are retained with automation history and purged during workspace deletion. Mocked tests alone cannot unlock production signoff: a designated phone must verify support/handoff, delayed template delivery and delivery webhooks. This record authorizes no deployment or live submission.
