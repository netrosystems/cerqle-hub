# SEND-only automation creation palette

Date: 2026-09-13
Status: Accepted
Supersedes: the ten-action creation palette in [WhatsApp-first automations](2026-09-13-whatsapp-first-automations.md). Execution safeguards and provider release gates remain unchanged.

## Context

The user found the builder difficult to operate and explicitly requested removal of every node under LISTEN, LOGIC, CONTACT, ENGAGE, COMMERCE and INTEGRATIONS.

## Decision

Expose only four previously retained SEND actions: Send WhatsApp, Send Template, Send Media and Quick Replies. Do not reintroduce deferred SMS, Email, Sequence or List actions. Guard click/drop creation with the same allowlist. Put optional preview inputs behind an expandable control and describe both click and drag interaction.

## Consequences

Do not delete or rewrite stored nodes, remove runtime handlers, cancel runs or change server activation eligibility solely for this palette change. Existing hidden nodes receive a legacy warning and remain inspectable. New workflows cannot add questions, conditions, waits, tags or human assignments through the palette; Quick Replies use one continuation and do not provide new conditional branches. This deliberately trades flexibility for simpler setup. No deployment is authorized by this decision.
