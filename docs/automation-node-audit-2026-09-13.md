# Automations — node-by-node usability and functionality audit

Date: 2026-09-13. Local commit: `69b29d9` (`spiderman`). Live environment: production Chrome builder, demo client profile, displayed version **1.0.91**. The live deployed Git SHA was not verified. The local simplified implementation is not deployed.

## Verdict

**Not signed off. The current production builder is too complex for an initial support release.** All **31 visible action types** were added temporarily and their inspectors opened. Opening an inspector is not proof of execution. Five primary field edit/reopen checks passed before Chrome blocked automation because another extension UI was open. Real sending, live saving/activation, customer-data changes and provider integration calls were not performed.

The focused local automation suite passed **57 tests / 194 assertions**. This includes legacy and retained nodes, fake providers and queues. It is not 31 independent live functional passes, and it does not certify production.

Evidence labels: **L** = live observed; **M** = automated/mocked; **C** = local code-reviewed; **N** = not tested. Each node below has an L inspector-opening pass. Other evidence is separately stated; no real provider delivery is certified for any node.

## Test method and results

- **UI-01 (L):** click each palette action → Fit View → open Settings. Expected: corresponding inspector opens. Actual: all 31 eventually opened. Settings on off-screen/crowded nodes needed canvas repositioning or an isolated retry. Run Sub-flow initially did not open in the crowded canvas; it opened in isolation.
- **UI-02 (L):** edit a primary field → close inspector → reopen it. Expected: edited value remains. Actual: passed for Send WhatsApp, Send Media caption, Quick Replies body, List Message body and Send SMS body. Remaining field checks were blocked/not executed. A caption persistence pass does not establish upload behavior.
- **UI-03 (L):** observe canvas after repeated click-to-add. Expected: readable, discoverable new nodes. Actual: nodes stack vertically, Fit View shrinks them, and lower nodes can fall beneath the bottom overlay; small settings/connection targets are difficult to use.
- **UI-04 (L):** reload during unsaved inspection. Expected: explicit warning before losing changes. Actual: no warning; canvas returned to an unconfigured trigger. The opening Contact Created trigger and blank WhatsApp node were also unsaved and were discarded by this reload. This was an audit-session handling mistake as well as evidence of absent dirty-state protection. Nothing was saved to the server. Restoring the opening unsaved canvas is pending while browser control is blocked.
- **BE-01 (M):** `php artisan test tests/Feature/Automation --compact`. Actual: 57 tests passed, 194 assertions. Fake delivery/queue behavior only.
- **BE-02 (M):** invoke the real webhook handler with an unsaved run and HTTP fake returning 500. Expected: node failure. Actual: `status: ok`, output status 500. Reproduced functional defect; no external request or customer-data write.
- **BE-03 (M):** render advertised name/phone tokens using an unsaved synthetic contact. Expected/actual: name and phone substituted correctly. This specific suspicion was ruled out.

## Each visible node

| ID | Node | Functionality evidence beyond inspector opening | Problem or missing verification | Recommendation |
| --- | --- | --- | --- | --- |
| N01 | Send WhatsApp | L body persists; M channel/account/workspace sends | Live inspector also offers other channels despite node name; no exact sender shown; real delivery N | Keep as **Reply**, WhatsApp-only initially |
| N02 | Send Template | M payload/variables and wrong-WABA checks | Live selector lists templates but no WABA/phone identity. Selection persistence and delivery N | Keep for follow-ups; require explicit sender |
| N03 | Send Media | L caption persists; M image payload | Upload/URL, video/document/audio delivery N; generic 200 MB upload hint is not provider eligibility evidence | Put attachments inside Reply, not a separate default node |
| N04 | Send Sequence | M sends configured steps; C empty sequence skips | Repeated messages duplicate Reply functionality; empty configuration and partial-delivery UX unclear | Hide initially |
| N05 | Quick Replies | L body persists; M buttons, valid/invalid reply routing | Live editor offers titles but no visible stable branch IDs or direct button-to-branch setup | Keep as **Menu**, simplify branch setup |
| N06 | List Message | L body persists; M list payload | Payload test does not prove list reply waits/routes; multiline item format is advanced | Hide initially |
| N07 | Send SMS | L body persists; M missing-provider failure | No live sender/provider/consent/delivery verification | Hide from WhatsApp-first builder |
| N08 | Send Email | M queues mail and skips missing email | Queueing is not delivery; subject/from-name settings not exercised live | Hide initially |
| N09 | Ask Question | M wait/resume, exact chat, empty answer, timeout | Live inspector lacks timeout, clear next-step guidance and answer testing | Keep as **Question**, provide guided next step |
| N10 | Condition (If/Else) | M branch evaluation; L Yes/No help | User must understand context keys/operators and small handles; live answer-specific choices absent | Move normal routing into Menu/Question; advanced only |
| N11 | Wait / Delay | M early/due/stale wakeup and cancellation | Real worker restart/scheduling N; live UI gives no WhatsApp window/template guidance | Fold into a **Follow-up** setup |
| N12 | Call Webhook | M JSON/header sending; BE-02 reproduced HTTP 500 treated as success | Functional false-success defect; raw JSON configuration | Hide; repair before separate integration release |
| N13 | Run Sub-flow | M queues active target; C inactive target skips | L selector offers paused/draft flows that cannot run; crowded-canvas retry needed | Hide initially |
| N14 | Add Tag | C scoped tag update; suite safety coverage indirect | L free-text input rather than discoverable tag picker; isolated tag browser/data test N | Advanced only |
| N15 | Remove Tag | C scoped tag removal | Typing exact tag is error-prone; isolated execution/data test N | Advanced only |
| N16 | Update Contact | M friendly-field mapping | General data mutation adds risk/complexity; real validation/invalid contact values N | Hide initially |
| N17 | Assign Agent | M assignment and terminal handoff | Live list offers multiple users; role eligibility and selected-user persistence N | Keep as **Human handoff** |
| N18 | Add to Campaign | C creates queued recipient and checks workspace/duplicate | No campaign-state/consent/sender eligibility check in this node; creating recipient alone does not dispatch campaign | Hide initially |
| N19 | Call to Action | M CTA payload | URL validation and provider/button delivery N; overlaps Reply with link | Hide initially |
| N20 | Send Location | M location payload | C converts coordinates without range validation; real map delivery N | Hide initially |
| N21 | Send Poll | M chooses buttons/list; C immediate send | Not a vote-collection/results interaction; no waiting/results logic in this node | Hide; do not present as a poll feature |
| N22 | Smart Bot | L bot choices present; C calls chatbot runner | Optional instructions used only as fallback when inbound message absent, not generally appended; AI cost/fallback/real output N | Hide from initial deterministic support flows |
| N23 | Book Appointment | L Google not connected; C calendar event request | **Blocked in client profile**; send-confirmation failure ignored by node result; availability/double-booking N | Hide initially |
| N24 | Google Meet | L Google not connected; C conference creation | **Blocked in client profile**; link-send failure ignored by node result; permission/calendar checks N | Hide initially |
| N25 | WhatsApp Form | M Flow payload | Raw Flow/screen IDs; published Flow/account eligibility and submission-to-run routing N | Hide initially |
| N26 | WhatsApp Catalog | M catalog payload | Catalog attachment/retailer eligibility and real product browsing N | Hide initially |
| N27 | WooCommerce Product | L no connected store; C product image/text send | **Blocked in client profile**; sends a product summary, not necessarily native catalog commerce; sync/provider N | Hide initially |
| N28 | Shopify Product | L no connected store; C product image/text send | **Blocked in client profile**; product ID and synchronized data dependency; real execution N | Hide initially |
| N29 | Google Sheets | L Google not connected; M missing-config and append-row | **Blocked in client profile**; raw spreadsheet IDs/ranges; real append/read N | Hide initially |
| N30 | Google Docs | L Google not connected; C template-copy/replacements | **Blocked in client profile**; optional link-send result ignored; real document creation N | Hide initially |
| N31 | Google Forms | L Google not connected; M missing-config/share/latest-response | **Blocked in client profile**; C read uses latest response without binding it to this contact/run | Hide; correlate respondent before separate release |

The AI Reply definition exists in local code but is not in the live visible palette audited here; it is not counted as a 32nd live node or certified by this audit.

## Priority findings

| Severity | Finding | Evidence | Required outcome |
| --- | --- | --- | --- |
| P1 | 31 exposed actions, including unavailable integrations | L | Default to a small support workflow; unavailable/advanced actions should not be ordinary palette choices |
| P1 | No unsaved-change protection in live builder | L UI-04 | Warn before navigation/reload and show saved/unsaved state |
| P1 | Exact sender and template/account identity absent in live inspector | L | Show phone/WABA and scope templates to it; no silent sender substitution |
| P1 | Webhook reports success for HTTP 500 | M BE-02 | Explicit failure for unsuccessful responses, appropriate retry/stop policy |
| P1 | Google Forms latest-response mode has no respondent binding | C | Never consume another respondent's submission; hide until correlation tested |
| P1 | Appointment/Meet/Docs success can mask optional message failure | C | Distinguish resource-created from confirmation/link-send failed |
| P2 | Click-to-add instruction, placement, zoom and handles impede operation | L | Say “Click or drag”; reveal new node; readable sizing and explicit connection actions |
| P2 | Reply/sequence/list/poll/CTA/media overlap | L + C | Consolidate routine content into Reply/Menu and remove duplicate concepts |
| P2 | Question variables and conditions require technical knowledge | L | Offer answer selection and labelled menu branches instead of raw context expressions |

These are audit recommendations, not authorization to rewrite stored workflows or deploy changes. Local code findings must be compared with the actual deployed revision before assigning a production root cause.

## Recommended initial user experience

Expose only **Reply, Menu, Question, Human handoff** as default support steps. Reply should contain text, attachment and link controls. Menu choices should connect directly to named branches. Question should offer a human-readable saved-answer selector and timeout without requiring token knowledge.

Provide a separate guided **Follow-up** action containing delay plus eligible approved template/sender selection. Keep raw Condition, tags and other advanced controls out of the default palette. This is a stronger simplification than the existing local ten-node allowlist; it requires a new product/implementation change, not merely deployment of `69b29d9`.

## Remaining checks and safe continuation

- Browser field persistence, dropdowns, uploads, edges, keyboard interaction, validation display, save/reload, activation version and history pagination remain incomplete. Chrome blocked control during Send Email testing; an unsaved inspection node may remain. No Save/Activate was clicked.
- Dismiss the other extension UI before continuing browser work. Restore the opening unsaved Contact Created/blank Reply canvas if desired; do not save it automatically.
- Use a designated test workspace and user-controlled test recipient for actual execution, never a customer contact. Verify each retained behavior through provider IDs and delivery/failure webhooks, including menu → question → branch → reply → handoff and delayed template follow-up.
- Verify real queue restart, concurrent replies and duplicate events separately. Tests with fake queues do not establish these properties.
- Deferred nodes have not passed an unrestricted end-to-end gate. A green local suite or opening inspector must not be displayed as “everything works.”

No application behavior changed during this audit. No production deployment, server-side workflow save/activation or live outbound message was performed. This document remains uncommitted unless requested.
