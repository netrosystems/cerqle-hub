# Grouped AI Automation — implementation QA

Date: 2026-09-13. Environment: local Laravel/SQLite, React/Vite, Chrome. Base commit: `2ecd0d1` on spiderman; implementation commit is the commit containing this report. Production not changed.

## Scope and operation

Channel Setup → Manage AI Automation controls WhatsApp, Instagram and Messenger together. Email Setup has its own independent setting. Choose On or Scheduled, select an enabled chatbot and save. Scheduled uses one window/day in the selected timezone; earlier end means overnight. Off retains chatbot/hours and does not disable workflows/rules. Only workspace owner/admin may save; conflicting revisions require reload.

No settings row preserves legacy assignments. The first explicit group save supersedes account selectors without deleting stored metadata. New accounts inherit the group. No worker/provider configuration or production setting was changed. Local email was briefly saved Scheduled with no connected mailboxes, then restored Off; messaging was explicitly saved Off to verify selectors disappear. Local theme/viewport were restored. No messages sent.

## Automated/mocked evidence

`tests/Feature/Inbox/GroupedAiAutomationTest.php`: 21 tests, 90 assertions passed. Full backend: 858 tests, 3474 assertions passed. Frontend: 136 tests across 28 files passed (including two compact-card component tests). Vite build and dirty-file Pint passed. Route/config caches rebuilt successfully locally; config cache cleared afterward so test environment overrides remain effective. Global Pint/PHPStan have unrelated repository backlog failures; no suppressions added.

All entries below passed unless explicitly marked otherwise. Mocked provider success is not delivery evidence.

| ID | Steps | Expected/actual | Evidence category |
| --- | --- | --- | --- |
| AI-01 | Save email On, stale revision, then Off | Independent group; stale save rejected; bot/hours retained | Automated/mocked |
| AI-02 | Submit foreign bot, invalid timezone/equal hours/no enabled days, unauthenticated request | Rejected; no settings created | Automated/mocked |
| AI-03 | Check window start/end, overnight next day, timezone and spring/fall DST | Local wall-clock hours, end exclusive, overnight honored | Automated/mocked |
| AI-04 | Duplicate inbound and duplicate job in existing default-human email thread | One queued job and one outbound | Automated/mocked |
| AI-05 | History, self, auto-submitted/list/bounce and attachment-only inputs | No AI job | Automated/mocked |
| AI-06 | Human takeover during generation | No send; receipt skipped | Automated/mocked |
| AI-07 | Change settings revision before job | No generation/send | Automated/mocked |
| AI-08 | Provider timeout and duplicate job | Failed conversation entry, delivery_review, no resend | Automated/mocked |
| AI-09 | Matching existing workflow plus enabled AI | Workflow owns message; no AI job | Automated/mocked |
| AI-10 | Legacy linked bot, then grouped Off | Legacy queued until explicit save; Off supersedes link | Automated/mocked |
| AI-11 | Disconnect sender or expire subscription before queued job | No generation/send; skipped | Automated/mocked |
| AI-12 | Assign user/manual reply then explicit handback | Human stops AI; explicit handback allows AI | Automated/mocked |
| AI-13 | Add second mailbox; run job with wrong account | New mailbox inherits; wrong account rejected | Automated/mocked |
| AI-14 | Replay interrupted sending receipt | Review entry, no provider call | Automated/mocked |
| AI-15 | Switch Off during generation | No send | Automated/mocked |
| AI-16 | Original inbound followed by newer inbound; send through Gmail/Microsoft/IMAP | Original mailbox/anchor/subject/reference and sender-only recipients | Automated/mocked |
| AI-17 | Initial import then repeated fresh sync | Initial history suppressed; one fresh job despite repeated sync | Automated/mocked |
| AI-18 | Inbound outside scheduled hours | No accumulated AI jobs | Automated/mocked |
| AI-19 | Credits exhausted with configured fallback | Visible generation failure; no fallback send | Automated/mocked |
| AI-20 | WhatsApp/Instagram/Messenger without account links | Group-selected bot queued on all three | Automated/mocked |
| AI-21 | Disable selected bot before inbound/job | Visible failed thread entry, no generation | Automated/mocked |
| UI-01 | Card → Scheduled → chatbot → save → reopen | Compact card, conditional controls, persisted mode/bot | Live observed (local Chrome) |
| UI-02 | Mobile 390×844, dark/light, Escape | Dialog fits, controls readable, Escape dismisses | Live observed (local Chrome) |
| UI-03 | Explicit messaging Off save | Competing per-account selectors hidden | Live observed (local Chrome) |
| UI-04 | Cancel unsaved mode/hours; copy Monday weekdays | No save on cancel; Friday updated correctly | Automated/mocked component |

## Code-reviewed safeguards

Unique workspace/group configuration and unique inbound ownership; shared listener pipeline; atomic job claim and shared conversation overlap lock; original sender/account identity; before-generation/before-send eligibility checks; ten-minute freshness guard; no outside-hours backlog; bot fallback excludes credit/rate/in-progress errors; workspace/conversation deletion cleanup. Email header storage is allowlisted and bounded. Historical WhatsApp events cannot trigger AI. Existing workflow definitions and account bot metadata are not rewritten.

Explicit handback uses a conversation message-ID cursor, so subsequent human replies stop AI even while assigned_to still says bot. Both new migrations were applied locally. AI failure explanations render in both inbox thread views. Global PHPStan reports 578 backlog errors; focused new AI classes have no reported errors (the existing AutoReply rule match warning remains).

Provider reference checks: Microsoft [reply documentation](https://learn.microsoft.com/en-us/graph/api/message-reply?view=graph-rest-1.0) explains Reply-To behavior; grouped email sends explicitly set the original sender and empty CC/BCC. Meta's [official API collection](https://www.postman.com/meta/whatsapp-business-platform/folder/fuaee8l/statuses-object) distinguishes the customer-service window; AI free-form sending uses the existing original-phone 24-hour guard. No pricing claim is made.

## Unresolved release checks — not tested

- Designated WhatsApp/Instagram/Messenger and email recipient delivery with provider responses/webhooks and mailbox evidence. Requires user-designated recipients and production release approval.
- Real Redis concurrency, overlap-lock contention, worker restart/kill and delayed recovery. Sequential duplicate-job tests do not establish concurrent queue behavior.
- Real Gmail/Microsoft/IMAP header variations, Reply-To overrides and bounce/autoresponder interoperability. Headers unavailable from a provider cannot be inferred perfectly.
- Exhaustive screen-reader navigation and every narrow scheduled-editor breakpoint; card/dialog were visually checked at mobile width, default expanded schedule at desktop.

Release verdict: locally implemented and regression-tested, **not production/provider signed off**. Do not claim 100% delivery or deploy based on green tests alone.
