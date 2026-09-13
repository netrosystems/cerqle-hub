# Automations SQA — WhatsApp-first support and follow-ups

Date: 2026-09-13. Baseline: `7aef101` on `spiderman`; results below describe the implementation working tree, not a production release.

## Verdict and evidence boundaries

The reduced feature is implemented locally. **Production signoff is pending.** No client automation was activated, no customer messages were sent, and no production deployment/provider submission was performed.

Evidence labels:

- **Live observed**: Chrome inspection of the existing client list, draft builder, trigger, Question and Template inspectors in v1.0.90. Unsaved inspection nodes were discarded.
- **Automated/mocked**: PHPUnit exercises real Laravel models/controllers/engine with fake queues/provider sends; Vitest exercises the retained-catalog contract. These do not prove real delivery or concurrent Redis-worker behavior.
- **Code-reviewed**: state-machine, sender and graph guards inspected in code.
- **Not tested**: designated-phone support/follow-up delivery, webhook delivery signoff, real concurrent worker execution, process-kill crash recovery, responsive/mobile and assistive-technology browser acceptance.

Environment: local PHP/Laravel with SQLite test databases, React/Vite/Vitest; baseline production Chrome inspection only. No secrets or customer transcript are included here.

## Node disposition

| Disposition | Nodes/triggers | Rationale |
| --- | --- | --- |
| Retained, test gate required | Reply text, approved template, image/video/document Media, Quick Replies, Ask Question, Condition, Wait, Add Tag, Remove Tag, Assign Agent | Minimum support, qualification, routing and follow-up workflow |
| Initial trigger | Message Received; explicit WABA/phone channel; optional keywords | Predictable inbound context and sender |
| Deferred from creation | SMS, email, Sequence, List Message, webhook, sub-flow, campaign membership, contact update, AI/Smart Bot, CTA, location, poll, appointment, Meet, WhatsApp Form, catalog/products, Google integrations | Reduce provider dependencies and overlapping capabilities; existing definitions remain visible and are not rewritten |
| Deferred triggers | Contact/tag/form/campaign/commerce/webhook triggers | Separate dependency and semantic validation needed; Campaign Sent historically means campaign completion without a contact |
| Explicitly blocked template capabilities | Media/dynamic headers, dynamic buttons, voice-call buttons; non-positional body variables | Initial editor has no reliable parameter representation for these. Use a supported template rather than assume media/header requirements are optional |
| Deferred Media type | Audio | Initial validation limits new media to image/video/document |

## Defects addressed

| ID | Severity | Baseline finding | Implemented resolution | Evidence |
| --- | --- | --- | --- | --- |
| AUT-01 | P0 | Reply resumes question and starts a new greeting flow | Return consumed automation IDs; durable reply receipts; suppress new runs during an existing interaction | Automated/mocked + code-reviewed |
| AUT-02 | P0 | Contact-only reply routing | Persist exact conversation/account and constrain reply matching | Automated/mocked |
| AUT-03 | P0 | Terminal Wait/Question can restart trigger | Complete validation requires a next step; runtime returns error rather than parking without a cursor | Automated/mocked + code-reviewed |
| AUT-04 | P0 | Shallow activation validation | Shared validator rejects incomplete nodes, unsupported types, cycles, dangling/unreachable nodes, ambiguous edges, incomplete Yes/No conditions and downstream handoff actions | Automated/mocked + code-reviewed |
| AUT-05 | P1 | Preview can look successful with invalid configuration | Complete graph/config validation before preview; overall error flag and actionable error text | Automated/mocked |
| AUT-06 | P1 | Activation differs from reviewed unsaved canvas | Builder submits graph/config plus status in one request; dirty-state/navigation warnings | Controller automated/mocked; browser interaction acceptance pending |
| AUT-07 | P1 | Template selector lacks sender identity | Trigger account selector; WABA-scoped approved templates; send-time rechecks; no bound-run sender fallback | Automated/mocked + code-reviewed |
| AUT-08 | P1 | Edits change waiting runs | Immutable workflow snapshot per run | Automated/mocked |
| AUT-09 | P1 | Duplicate jobs can resend | Completed guards, run overlap middleware, unique trigger/reply receipts, durable per-node claims | Sequential duplicate automated/mocked; real concurrency/crash testing pending |
| AUT-10 | P2 | Waiting/history navigation unclear | Waiting status/reason, next-check time and paginated history links | Code-reviewed; browser acceptance pending |
| AUT-11 | P2 | Oversized/unverified palette | Ten-node creation allowlist and legacy warnings; unsupported activation blocked | Vitest + code-reviewed |
| AUT-12 | P1 | Multiple template field updates overwrite selection | Patch-merging node state rather than stale full-data replacement | Code-reviewed; browser interaction acceptance pending |

## Executed regression cases

Backend evidence: `tests/Feature/Automation/AutomationReleaseSafetyTest.php`. Each row is **Automated/mocked**. All listed cases passed on the implementation working tree.

| Test ID | Steps | Expected and actual result |
| --- | --- | --- |
| QA-01 | Deliver greeting event twice; execute question; deliver same reply twice; execute continuation twice | One run, one question, one final reply; completed run does not resend — PASS |
| QA-02 | Reply from a second chat for the same contact; submit empty reply to originating chat | Original question remains waiting; no new same-chat run — PASS |
| QA-03 | Edit downstream reply while waiting; answer; start another question and advance 25 hours | Original snapshot reply used; later interaction cancels without resend — PASS |
| QA-04 | Execute minute delay early and after due time; hand chat to human before next delayed send | Early job leaves wait intact; due job completes; human chat cancels — PASS |
| QA-05 | Advance a text follow-up past 24 hours; separately revoke consent before text send | No outbound send; run fails safely — PASS |
| QA-06 | Validate valid graph, terminal question, cycle and dangling edge; preview empty question | Invalid graphs rejected; preview returns false; no messages written — PASS |
| QA-07 | Select template belonging to another WABA | Validation exception — PASS |
| QA-08 | Send menu; submit invalid choice; submit valid reply-button ID | Invalid choice stays waiting; valid ID resumes; stable `btn_1` and title stored — PASS |
| QA-09 | Wait two days, then send approved body-parameter template; reject template before a second scheduled send | First completes with template payload; second fails with no extra send — PASS |
| QA-10 | Execute human assignment with a legacy downstream reply | Handoff completes run; downstream reply not sent — PASS |
| QA-11 | Disconnect bound phone before send | Failure, zero outbound messages; no fallback — PASS |
| QA-12 | Preclaim a step, then execute | Delivery-review failure, zero resends — PASS |
| QA-13 | Pause a waiting workflow; separately delete a waiting chat | Both cancel at execution — PASS |
| QA-14 | Omit required positional template variable | Validation exception — PASS |
| QA-15 | Expire subscription access before job execution | Bound work cancelled with zero sends — PASS |
| QA-16 | Activate a draft by submitting reviewed full canvas; call preview with fake provider network forbidden | Reviewed graph persisted active; preview succeeds with zero messages/runs — PASS |
| QA-17 | Save incomplete draft; activate; pause | Draft saves; activation returns 422; pause remains possible — PASS |
| QA-18 | Resume question; execute its obsolete timeout job | Obsolete job does nothing; normal continuation completes — PASS |

Existing automation node tests also cover retained template variables, media payloads, branches, tag/update behavior, assignment and channel scoping, while preserving legacy send/integration tests. Frontend catalog tests assert ten retained nodes and legacy detection without mutation.

Validation checkpoint: 18 new safety tests / 60 assertions passed. Full backend suite passed **837 tests / 3384 assertions**. Frontend suite passed **110 tests / 27 files**; production Vite build and focused Pint passed, with existing large-chunk build warnings. Local prerequisite WhatsApp metadata and the new automation migration applied successfully; route cache rebuilt. PHPStan is **not clean**: broader run reported 593 findings before new-code contract cleanup. Final focused analysis reports four existing executor findings in deferred AI/location/list helpers; the new validator and execution job pass focused analysis. No new suppressions/baseline entries were added.

## How to configure the minimum workflow

1. Select Message Received and the exact connected WABA/phone in Trigger. Use a keyword such as `help` to avoid responding to every unrelated inbound message.
2. Connect Trigger → Quick Replies. Provide one to three unique titles (maximum 20 characters each).
3. Connect menu → Condition using `context.choice_id` equals `btn_1`, or `context.choice` equals the exact title. Connect both Yes/No outputs.
4. Use Ask Question only when another answer is needed. Save it to a named variable; configure 1–168 hour timeout (default 24). Connect to the next step.
5. Send the appropriate reply or finish with Assign Agent. Do not put automated sends after human handoff.
6. For follow-ups, connect Wait → approved Template with all required body variables. Do not use free-form text outside the service window. Recheck sender and Preview before activating.

Preview skips real waiting, supplies sample answers, and does not validate provider delivery. An eligible approved template is necessary but not sufficient for Meta delivery. The 24-hour rule and opt-in requirement were checked against [AWS's provider integration guide, sending messages section](https://docs.aws.amazon.com/pdfs/social-messaging/latest/userguide/social-ug.pdf) on 2026-09-13. Provider account quality, messaging limits, recipient eligibility and webhook configuration still require live verification.

## Migration and operational limits

- Apply the additive migration with outbound workers paused. It adds run snapshots/chat identity/wake time, step claims and reply receipts. It deliberately cancels unsnapshotted legacy waiting/running runs; review rather than automatically resend them. Pending legacy runs snapshot before first execution.
- Use shared Redis cache/queue and run workers on `automation`. Start/restart workers only in a controlled test environment for QA; starting a worker against customer queues can send real messages.
- Step claims guarantee fail-closed replay behavior, not exactly-once delivery. A process crash after a provider accepts a request but before local persistence may leave uncertain delivery. Inspect provider evidence before taking recovery action.
- Invalid choices/media-only answers remain waiting until a valid textual answer or timeout; no unsolicited retry is sent. Paused/deleted/human-handled chats stop at execution. A fresh inbound after timeout may start a new eligible interaction.
- Existing deferred legacy workflows are not certified by the reduced-release QA gate. Do not present them as supported initial-release capabilities.

## Remaining production release gate — NOT TESTED

Use a designated test workspace, connected phone and user-controlled recipient, never customer chats.

1. Verify menu → button reply → question → answer → both branch outcomes → text/media → human handoff. Verify a later inbound does not cause an unwanted automated reply while human-handled.
2. Verify Wait → approved-template follow-up, including original-phone binding, recipient opt-out, disconnected phone, template rejection and quota/provider rejection.
3. Restart real Redis workers while waiting. Inject duplicate inbound events/jobs and concurrent replies. Kill a worker immediately around send/persistence boundaries and confirm no blind resend.
4. Inspect provider response IDs and sent/delivered/read/failed webhooks in designated data. A completed automation or HTTP success is not delivery evidence.
5. Browser-check template selection persistence, unsaved activation/navigation, inline errors, waiting history pagination, small screens, dark mode, keyboard and screen-reader use.
6. Release only after no retained-flow P0/P1 defects remain and these designated-phone/worker/browser checks pass. Git promotion and production deployment require separate approval.
