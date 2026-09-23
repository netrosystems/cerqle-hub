# Decision: automations return to Setup with the validated action set

- Date: 2026-09-23
- Status: Accepted
- Supersedes: [SEND-only automation creation palette](2026-09-13-send-only-automation-palette.md)
- Keeps: every runtime safeguard in [WhatsApp-first automations](2026-09-13-whatsapp-first-automations.md)

## Context

On 2026-09-13 the builder was cut to four Send actions because it was hard
to operate, and its menu entry was hidden. The product owner has now asked
for the Automation feature from the sibling product, Wisperbot, and for it to
sit under Setup.

The two codebases share an origin, so this was a comparison, not a port.
Cerqle already had every model, page and handler. What differed:

| | Wisperbot | Cerqle before |
|---|---|---|
| Actions in the palette | ~25, every trigger | 4 Send actions, Message Received |
| Generate a workflow from a description | Yes | No |
| Retry a failed run | Yes, paused runs only | No |
| Engine safety (step claims, pinned workflows, validator) | No | Yes |

Copying Wisperbot's files would have removed Cerqle's safety work, so only
the missing capabilities were brought across.

## Decision

**The palette is exactly the ten types `WorkflowValidator::TYPES` activates.**
Send WhatsApp, Send Template, Send Media, Quick Replies, Ask Question,
Condition, Wait, Add Tag, Remove Tag, Assign Agent. The engine was already
hardened and tested for these; six of them were only hidden, never unsafe.
Wisperbot's other ~15 actions stay out: none has release coverage here. The
product owner chose this middle set over both extremes.

A PHP test reads the JavaScript palette and fails if it and the validator
drift, so the builder can never offer a step the server refuses to activate.

**Generate with AI drafts, never activates.** The generator was restricted to
the same ten types and the Message Received trigger; before, it would build
drafts from any of 31 types and 11 triggers, most of which Cerqle refuses to
switch on. Drafts are created paused because the AI cannot know which
templates are approved. A single connected WhatsApp number is chosen for the
draft automatically; with several, the choice is left to the person. It costs
the `automation_workflow_generate` rate, shown on the button — 20 credits
from 2026-09-24 (was 5), with `rates_version` bumped to `2026-09-24` so the
ledger shows which price each charge used.

**Retry respects the step claims.** The engine refuses to repeat a claimed
step, because after a crash it cannot know whether a message went out.
Wisperbot's retry reset the status and ran again, which here would fail
straight back into that claim. Cerqle's retry distinguishes:

- a step that reported an error and finished — rejected, nothing delivered —
  is retried from that step with its claim released;
- a step with no recorded outcome, or whose error says delivery needs review,
  may have reached the customer, so an operator must confirm they checked the
  chat before it runs again.

The Runs page shows which case applies before anyone clicks.

## Consequences

- The Sep 13 record's "do not reintroduce" list is lifted for the six
  restored types only. SMS, Email, Sequence, List and every other type remain
  legacy: flagged, not rewritten, not activatable.
- Retried runs return to `pending`, the status every new run starts in; the
  column does not allow anything else for a waiting run.
- `ConfirmProvider` moved to the application root. Pages called `useConfirm()`
  above their own layout's provider and silently fell back to
  `window.confirm`, which browsers can suppress — the same dead delete button
  this component was built to fix, reintroduced on every page that rendered
  its own layout.
- Generate with AI is also in the builder toolbar. There it returns the graph
  without saving (`persist: false`) and draws it on the canvas; only Save
  writes it. Two deliberate differences from Wisperbot, whose version
  overwrote both: the automation keeps its name, and a WhatsApp number already
  chosen is kept — Wisperbot's `graph.trigger_config ?? current` kept the
  AI's empty object and silently unset the sender. Replacing steps already on
  the canvas asks first, before any credit is spent.

## Node audit (2026-09-24)

Each of the ten nodes was configured and run through Preview in the browser.
What changed, and why:

- **Save and Preview kept only keys with a validation rule.** An edge's
  Yes/No handle and a node's position had none, so every Condition failed
  Preview and Activate and saved steps lost their places. Present on `main`
  since 2026-09-13; exposure was limited because the menu was hidden and
  Condition was not in the palette. Save and Preview now share one rule set.
- **Customer text is compared as a person reads it.** Condition equals and
  contains ignore case and surrounding spaces ("Price?" contains "price"); tag
  checks likewise. A typed reply to a button menu counts when it names a
  button or its number, instead of leaving the run waiting 24 hours.
- **Remove Tag no longer creates the tag** it was asked to remove.
- **The template picker lists only templates activation accepts** (no media
  headers, variable headers or buttons, call or copy-code buttons, named
  parameters), and says how many it hid.
- **Removed as unnecessary:** the one-option Channel picker on four nodes, and
  the token hint on nodes that do not render text. The hint now names the
  variables the workflow actually collects.
- **Added guidance where a step was easy to misuse:** tag conditions offer only
  has / does not have; menu and tag values are suggested; waits of 24 hours or
  more warn that only templates deliver afterwards; Assign Agent says it ends
  the automation.
- Builder: steps show their summary without a manual edit (AI drafts said
  "Click to configure"), a clicked palette item lands in view, the settings
  panel no longer covers Save, and the WhatsApp icon renders.
