# Production deployment and four-node SQA report

Date: 2026-09-13. Release: **v1.0.92**. Production/main commit: `aac2befdf9375432661eea39d82c8648296a8d61`. Source validation: `spiderman`/`dev` at `2ecd0d1`; main was linearly replayed and its tree verified equal before promotion. Deployed through the existing **Termius** production session using `bash scripts/deploy-production.sh`.

## Final verdict

**Deployment passed. Unrestricted node functionality signoff did not pass.** The simplified SEND-only palette is live. Text, Media URL and valid Quick Replies configurations pass no-send Preview. Template is blocked for the two templates available in the audited sender's WABA. Two further Preview UX defects were reproduced. Actual WhatsApp delivery for all four nodes remains **not tested**, pending a designated user-controlled test recipient/sender.

No customer messages were sent. No automation was saved or activated during this production QA. All temporary canvas changes were discarded; the inspected draft returned to its saved unconfigured trigger, and its Runs page displayed **No runs yet**.

Evidence labels used below:

- **Live observed:** production browser or Termius behavior.
- **Automated/mocked:** local backend tests with fake queues/providers.
- **Code-reviewed:** inspected implementation, not proof of production execution.
- **Not tested:** no execution evidence; never interpreted as PASS.

## Deployment checks

| ID | Steps / expected outcome | Actual result | Evidence |
| --- | --- | --- | --- |
| DEP-01 | Fetch origin; incorporate current dev; promote validated code without force or merge commits | dev already ancestor of source; pushed source/dev; replayed two missing commits onto main; final source/main trees equal | Live observed — PASS |
| DEP-02 | Check tracked production changes and current revision | No dirty tracked changes; existing untracked server files preserved; prior commit `7d84a8d` | Live observed — PASS |
| DEP-03 | Count legacy running/waiting runs before migration | Zero; no in-flight interactions needed cancellation | Live observed — PASS |
| DEP-04 | Restricted server-local MySQL backup; verify gzip integrity | Backup succeeded, compressed size 37,545,524 bytes; `gzip -t` passed; file mode 0600 in private storage | Live observed — PASS |
| DEP-05 | Pause general workers; run documented main deployment; migrate/cache/build | New hardening migration applied; caches/build completed; `DEPLOY_EXIT=0`; recorded v1.0.92 at `aac2bef` | Live observed — PASS with worker warning below |
| DEP-06 | Restore/verify queue workers after deploy | Script failed to detect Supervisor and used cache restart fallback. Manually started paused general group; two general plus two dedicated broadcast workers verified RUNNING with new process IDs | Live observed — PASS after manual recovery |
| DEP-07 | Check application and provider webhook registration | Production browser displayed v1.0.92; Meta registration command reported active subscription, callback match and inbound messages subscribed | Live observed — PASS for registration only, not message delivery |
| DEP-08 | Verify new palette | Exactly Send WhatsApp, Send Template, Send Media, Quick Replies. Removed category headings/nodes absent. Preview options collapsed; click/drag hint visible | Live observed — PASS |
| DEP-09 | Recheck settled workers, queue arguments and schema | All four managed workers still RUNNING after over six minutes; general Redis worker arguments include `automation`; run identity/snapshot/wake columns, claims and receipts tables verified present | Live observed — PASS |

Backup retained on production at `storage/app/private/backups/pre-automation-20260913-075732.sql.gz`; it was not downloaded or uploaded. Git deployment recovery ref: `deploy-backup/20260913T075830Z-7d84a8d9c41d`. Neither is permission for an automatic database rollback.

Build/installation warnings: existing large bundles, deprecated packages, abandoned `nunomaduro/larastan`, and npm audit reporting six vulnerabilities (five moderate, one high). Dependency remediation was not performed as part of this feature deployment. Supervisor detection needs separate operational investigation even though worker health was restored and checked.

Process inspection also showed additional database-connection workers outside the four verified Redis worker processes. Their ownership/backlog was not audited or changed; include them in the separate worker-configuration review.

## Node-by-node tests

### Send WhatsApp

| ID | Steps | Expected | Actual / verdict | Evidence |
| --- | --- | --- | --- | --- |
| TXT-01 | Add; open editor; enter synthetic text with `{{message.body}}`; close/reopen | Text persists; only WhatsApp channel offered | Persisted; single WhatsApp channel — PASS | Live observed |
| TXT-02 | Select Message Received and exact account; connect Trigger → Text; Preview | Sample message renders; no send | `SQA preview only: Hi`; one simulated step — PASS | Live observed |
| TXT-03 | Inbound trigger, duplicate events/jobs, same-contact chat isolation, consent/takeover/window guards | Safe actual engine continuation/no resend | Relevant focused local tests passed — PASS for mocked behavior only | Automated/mocked |
| TXT-04 | Execute designated-phone flow and inspect sent/delivered/failed webhooks | Verified real recipient delivery | NOT TESTED: no designated test recipient supplied | Not tested |

### Send Template

| ID | Steps | Expected | Actual / verdict | Evidence |
| --- | --- | --- | --- | --- |
| TPL-01 | Select account; open template selector | Only approved selected-WABA templates | Two selected-WABA options, rather than previous cross-account list — PASS for displayed scope | Live observed + code-reviewed |
| TPL-02 | Choose first template; set two synthetic variables; close/reopen | Choice/language and both fields persist | Choice and both variables persisted — PASS | Live observed |
| TPL-03 | Preview first template with required body variables filled | Either supported preview or explicit unsupported-content rejection | Header/button requirements rejected — safety check PASS; this template is FUNCTIONALLY BLOCKED in editor | Live observed |
| TPL-04 | Select second template; rerun Preview | Same eligibility validation | Again rejected for unsupported header/button parameters — FUNCTIONALLY BLOCKED | Live observed |
| TPL-05 | Wrong WABA, missing variables, rejection/disconnection, approved outside-window send | Engine enforces sender/eligibility | Relevant local tests passed — PASS for mocked behavior only | Automated/mocked |
| TPL-06 | Real supported-template delivery/webhook evidence | Verified real delivery | NOT TESTED; compatible template plus designated test recipient required | Not tested |

TPL-03/04 also returned an unreachable-node error because the attempted Text → Template connection did not attach. Unsupported-template validation is independently present for both choices, but **no successful connected template Preview** is claimed. The selector currently offers templates that the editor subsequently rejects; setup should communicate this before the user fills fields. No provider-side template was created/changed/submitted.

### Send Media

| ID | Steps | Expected | Actual / verdict | Evidence |
| --- | --- | --- | --- | --- |
| MED-01 | Open; switch Upload → URL; enter synthetic URL and caption; reopen | URL/caption remain; only image/video/document offered | Persisted; audio absent — PASS | Live observed |
| MED-02 | Connect Trigger → Media; Preview HTTP URL | Inline invalid-URL error, no send | Public HTTPS requirement returned — PASS | Live observed |
| MED-03 | Correct URL to synthetic HTTPS; rerun Preview | Simulation describes configured image | One simulated image step — PASS for configuration only | Live observed |
| MED-04 | Inspect existing local payload test | Image payload constructed correctly | Existing image-payload test passed | Automated/mocked |
| MED-05 | Actual upload, file limits/MIME handling, video/document, accessible remote asset, real provider delivery | Verified upload and recipient media delivery | NOT TESTED | Not tested |

The HTTPS test URL was synthetic. Preview does not establish existence or provider accessibility. Upload mode returns on inspector reopen despite a URL-originated media selection; the URL value remains but editing it requires selecting URL again. The displayed generic 200 MB upload hint is not evidence of WhatsApp provider acceptance.

### Quick Replies

| ID | Steps | Expected | Actual / verdict | Evidence |
| --- | --- | --- | --- | --- |
| QR-01 | Add menu body/titles; reopen | Values persist | Body and titles persisted — PASS | Live observed |
| QR-02 | Connect Trigger → Menu with no continuation; Preview | Reject terminal reply wait | Exactly-one-next-step error — PASS | Live observed |
| QR-03 | Add/configure reply continuation; connect Menu → Reply; leave duplicate titles | Reject duplicates | One-to-three unique titles, maximum 20 characters error — PASS | Live observed |
| QR-04 | Change second title to Support; provide sample answer Support; Preview | Menu then reply reflects selected title | Two simulated steps; `SQA choice: Support` — PASS for valid sample | Live observed |
| QR-05 | Supply `Not a menu choice`; rerun Preview | Invalid choice stays waiting or fails simulation; no valid continuation claimed | Preview continues and renders invalid answer — FAIL | Live observed |
| QR-06 | Correct invalid configuration and obtain successful Preview | Old validation messages clear | Prior duplicate-title alert still visible — FAIL | Live observed |
| QR-07 | Stable button IDs, actual invalid reply remains waiting, valid reply resumes without greeting repeat | Correct reply routing | Relevant local mocked tests passed | Automated/mocked |
| QR-08 | Real button delivery and reply webhook continuation on designated phone | Verified complete interaction | NOT TESTED | Not tested |

With LOGIC removed, new menus have **one shared continuation**, not separate choice branches. This is a consequence of the requested palette reduction. Users cannot currently configure distinct Sales/Support branches through the remaining creation UI.

## Supporting evidence and unresolved work

- Focused backend rerun before deployment: **57 tests passed, 194 assertions** (`php artisan test tests/Feature/Automation --compact`). Earlier source validation: 134 frontend tests across 27 files passed; Vite build passed. Backend counts are not 57 live-provider passes.
- Production inspected-draft history opened successfully with No runs yet and Previous/1/Next navigation. Waiting/failed/completed rows and multiple populated pages were not browser-tested.
- **P1:** eligible-looking template options cannot execute with their required header/button content. Need supported-template filtering/disclosure or complete parameter support, then real delivery verification.
- **P1:** Preview accepts invalid menu sample answers, unlike actual reply-consumption rules. It must not claim valid continuation for an invalid choice.
- **P2:** validation alerts are stale after a corrected successful Preview. Clear/recompute errors when rerunning and when editing relevant configuration.
- **P2:** menus cannot branch by selected option with this palette. Either communicate the shared continuation clearly or implement simple direct choice routing without re-exposing a raw Condition node.
- **Operational:** investigate why the documented script did not detect available sudo Supervisor access; no code fix was applied during audit.

## Remaining live gate

User must identify a designated test recipient and sender. Then verify Text → reply, supported Template → delivery, real uploaded image/video/document → delivery, and Quick Replies → button click → shared continuation without greeting repeat. Inspect provider response IDs and sent/delivered/failed webhooks. Perform worker-restart/concurrent-event checks in controlled test data. Keep flows keyword-scoped and disable audit flows afterward; never activate a catch-all client flow merely to obtain evidence.

This is the final report of the checks actually completed, not a claim that all requested end-to-end testing is finished. Report/checkpoint files were written after deployment and are not part of the deployed commit.
