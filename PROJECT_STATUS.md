# Cerqle Hub current status

Status date: **2026-09-11**. This file is a checkpoint, not permanent proof. Recheck Git, production and external-provider state before acting.

## Git and release state

- The `spiderman`, `dev` and `main` trees were verified identical before this documentation checkpoint on 2026-09-11. Recheck remote refs before relying on that statement.
- Branch SHAs can differ because validated commits are replayed onto `main` to preserve linear production history without force-pushing. Tree equality, not matching SHA text, is the content check.
- Current `main` includes the duplicate automation-listener fix, real campaign CSV uploads, guarded WhatsApp campaigns and TikTok site-verification file.
- Latest production state independently verified in this repository handoff: commit `489fb63`, release `v1.0.82`. Newer `main` commits are **not documented here as deployed**.
- Last broad validation for the WhatsApp Campaign change: 802 backend tests / 3180 assertions, 97 frontend tests / 22 files, focused Pint and Vite production build passed.
- PHPStan has a known historical backlog of 578 findings. This number is stale until rerun; do not add suppressions merely to claim a green result.

## Recently implemented

- 2026-09-13: Master Email Inbox has a confirmed “Resolve all open” action scoped to the selected mailbox/current workspace. Existing 105 frontend tests and Vite build passed locally; new backend isolation/status regression test, Pint, PHPStan and route cache checks remain unrun because this PC has no PHP runtime. Not promoted or deployed; backend validation and explicit release approval remain required.
- WhatsApp Campaigns are available alongside SMS Campaigns under “Campaigns.”
- WhatsApp campaigns bind an explicit WABA, active phone and approved WABA-scoped template; validate consent and ownership; use paced durable dispatch; honor message quotas; mirror the exact channel into Inbox; and process sent/delivered/read/failed webhooks.
- Campaign CSV upload uses Contact List file-size and row ceilings and channel-specific consent.
- TikTok site verification is present at `public/tiktok8e13R80aQJRTaPTHEatRY77kbUj2WhLl.txt` in `main`; availability on `https://cerqle.ai/` still depends on production deployment.
- Duplicate automation execution caused by repeated event-listener registration was fixed before the campaign work.

## WhatsApp coexistence pilot

- The guarded coexistence implementation exists and production rollout was last documented as limited to one dedicated pilot workspace through `WHATSAPP_COEXISTENCE_ENABLED=true` and a nonempty `WHATSAPP_COEXISTENCE_WORKSPACES` allowlist.
- History/contact import remains off: `WHATSAPP_COEXISTENCE_IMPORT_ENABLED=false`. Do not call `smb_app_data` or consent to history sharing for this pilot.
- Meta onboarding last failed with error `2655111`: the partner app lacked required advanced WhatsApp management/messaging permissions.
- Last observed Meta permission state: `whatsapp_business_management` was Pending App Review; `whatsapp_business_messaging` was Ready to publish. These labels do not prove all Advanced Access gates are complete.
- The dedicated phone was not successfully connected, and end-to-end coexistence messaging was not validated.
- The dedicated test workspace was last observed with only its owner as a member. This does not isolate it from that owner or platform administrators.
- Do not delete/migrate the phone, disconnect its existing mobile account, or substitute another customer WABA to bypass the blocker.
- Next provider-side sequence: resolve Advanced Access, select the dedicated asset, complete phone-side approval, then test inbound, Cerqle reply, Business-app echo and human takeover on both sides.

The complete implementation contract remains in [`WHATSAPP_COEXISTENCE.md`](WHATSAPP_COEXISTENCE.md) and [`docs/decisions/2026-09-09-whatsapp-coexistence-pilot.md`](docs/decisions/2026-09-09-whatsapp-coexistence-pilot.md).

## Known technical follow-ups

- Audit remaining `CloudApiClient::forWorkspace` outbound callers. Its fallback selection can bypass the disconnected-phone check used by `forPhoneNumber` when no active channel exists. WhatsApp Campaigns do not use this fallback.
- Verify the exact live Meta webhook field subscriptions before claiming full coexistence readiness.
- Current coexistence signup code was last recorded with `featureType: whatsapp_business_app_onboarding`, `sessionInfoVersion: '3'`, SDK v20.0 and Graph v26.0. Meta documented Embedded Signup v2 deprecation for 2026-10-08; verify and migrate the configuration separately rather than assuming v4 readiness.

## Operational cautions

- Deploy only from GitHub `main` with `bash scripts/deploy-production.sh` in the production checkout.
- Do not run deployment under `umask 077`; a prior run created unreadable cache/build files, causing PHP-FPM HTTP 500 responses and Supervisor worker BACKOFF. Use the normal `0022` deployment umask and protect logs separately.
- Deployment logs can contain sensitive derived webhook-verification URLs. Do not print or publish full logs.
- Preserve production `.env`, uploads, ignored release metadata and unrelated untracked files.
- The previously inspected `db:backup --no-upload` path was unsafe because of shell password handling, masked pipeline failures and deletion behavior. Do not use it as a substitute for a verified backup process.
- A pre-coexistence production database dump was recorded at `/home/ubuntu/cerqle-backups/pre-coexistence-20260908-220616.sql` with mode `0600`; no restore test was recorded. Keep it private and do not download it.

## Meta review scope

- Review preparation covers implemented client features only. Do not submit review forms or upload screencasts automatically; the user records videos.
- Requested items were `whatsapp_business_management`, Business Asset User Profile Access and `instagram_manage_contents`.
- Do not add advertising, insights, catalog, comments, or Human Agent permissions without an implemented and verified dependency.
- Preserve existing data-handling answers only as user-provided declarations, not independently verified facts about hosting, AI transfers, retention, subprocessors, or legal compliance.
- Existing Embedded Signup configuration includes Marketing Messages API. Do not accept binding terms or expand sensitive access without explicit user confirmation.

## Local workspace note

- If `META_REVIEW_GUIDE.md` exists locally as an untracked file, treat it as user-owned and do not add, overwrite or delete it without explicit scope.
