# Security hardening — implementation and QA

Date: 2026-09-15

## Scope and environment

Authorized defensive fixes to Cerqle's own repository. The owner explicitly excluded **`view_clients` permits impersonation and plan assignment**; that behavior remains unchanged and has a regression assertion. The owner subsequently approved committing and promoting these changes through `dev` to `main`. Production deployment, environment/license edits, provider submission and customer flows are not included.

Baseline: `spiderman`, `97dcc6c3ce5f4c8dfd5b4fec0da9355f98b3104d`. `origin/dev` was fetched and remained at that baseline. This report records the implementation prepared for approved branch promotion, not a production deployment. Exact promoted refs and tree equality are verified in the handoff. Existing unrelated local release checkpoints, website QA edits and the untracked review guide are excluded from this security commit.

Runtime: PHP CLI 8.5.7, Node 24.15.0, Laravel 12.69.2, Vitest 4.1.11. Composer resolves dependencies against PHP 8.2.0 to retain the application's declared minimum. Backend fixtures use isolated SQLite `:memory:`; migration execution on production MySQL is not established by these tests. HTTP/provider calls in final targeted tests are mocked. An early custom-handler implementation bypassed Laravel's mock pipeline and exposed public fetch attempts; it was corrected to `setHandler`, and subsequent tests retain fakes. No private-network/metadata live probes or provider publications were performed.

## Fix and evidence matrix

Priority here is implementation urgency, not a formal CVSS score. Each automated result asserts an expected outcome; it is not real-provider evidence.

| ID / priority | Steps and expected result | Actual / evidence classification |
| --- | --- | --- |
| SEC-01 / P1 | Exhaust the admin login bucket, then submit correct credentials; login stays blocked. | Pass — automated, `AuthenticationBoundaryTest`. Rate check now precedes both guard attempts. |
| SEC-02 / P1 | Log into a confirmed-MFA account by password, magic link or Firebase; no authenticated session before a valid second factor. Expired challenge fails; recovery code cannot be reused. | Pass — automated/mocked. Shared browser completion also covers Socialite by code review; existing Google sign-in regressions pass, but live Google/MFA callback was not tested. |
| SEC-03 / P1 | Mobile confirmed-MFA login without code returns 202/no token; wrong/reused code fails, valid recovery code returns one token. Non-MFA login keeps original response. | Pass — automated. Native client code-input behavior and real device are not tested. |
| SEC-04 / P1 | Verify synthetically RS256-signed tokens with exact Firebase project; reject wrong/substr audience, wrong issuer, invalid/unverified email, invalid subject, expiry/future auth, HS256 and wrong RSA key. | Pass — automated/mocked, `FirebaseTokenVerificationTest`. Fixed certificate endpoint; no generic token-info authorization. Real Firebase identity flow not tested. |
| SEC-05 / P1 | Deactivate a user/client after authentication; browser/API access fails. User tokens/database sessions/remember token are revoked; generic user serialization omits MFA secrets. | Pass — automated, `AuthenticationBoundaryTest`; queued subscription/account eligibility changes code-reviewed. Non-database session-driver deployment behavior not live tested. |
| SEC-06 / P1 | Use `contacts:read` token to mint/list tokens, access mobile or authorize broadcasts; reject while retaining authorized contact reads. First-party session mutation without CSRF fails; matching CSRF succeeds. | Pass — automated. Full-agent mobile fixtures explicitly use `*`, matching real login tokens. Token-management HTTP/session contract remains compatible. |
| SEC-07 / P1 | Lower-tier administrator attempts to grant Super Admin; reject. Super Admin retains management authority. | Pass — automated for escalation; update/delete/status target authority code-reviewed and existing admin regressions pass. `view_clients` exception deliberately unchanged. |
| SEC-08 / P1 | Supply a victim's stable external customer ID as unsigned widget `logged_in` metadata; do not restore victim transcript. Existing HMAC identity tests continue to pass. | Pass — automated, `VisitorAndUploadBoundaryTest`; signed mode additionally requires a nonempty secret. Anonymous/session tokens remain bearer secrets. |
| SEC-09 / P1 | Upload text with HTML filename, JPEG with HTML filename, active HTML with TXT filename; storage extension follows contents (`.txt`, image extension, `.bin`), not supplied filename. | Pass — automated for media/attachments. Branding call sites use the same helper by code review. Existing media/mobile-upload regressions pass; legacy objects were not scanned or renamed. |
| SEC-10 / P1 | Request loopback/private/metadata/shared/documentation/mapped/transition destinations, numeric address variants or mixed DNS; no dispatch. Follow a public→private redirect; no second dispatch. Preserve public custom port and TLS/hostname/pinned IP. Bound body size; never follow a POST redirect. | Pass — automated/mocked, `PublicHttpBoundaryTest`. Applied to indexer, webhooks and WooCommerce by code review plus automation regressions. Real DNS rebinding/production egress not tested. |
| SEC-11 / P1 | Repeat sitemap expansion; enqueue child only once. Nested sitemap shares 200-child root budget. Depth-three expansion makes no request; XML entities/private child URLs fail closed. | Pass — automated/mocked, `SitemapBoundaryTest`. Real multi-worker locking and production MySQL remain untested. |
| SEC-12 / P1 | Call Woo callback with public store UUID, expired capability, inactive actor or consumed capability; reject without replacing working credentials. A failed reconnect must retain persisted working credentials/status. | Pass — automated/mocked, `StoreCallbackBoundaryTest`. One-time capability bound to actor/workspace/store; real Woo authorization and Shopify actor/expiry behavior are code-reviewed/not live tested. |
| SEC-13 / P1 | Render production-policy root with Meta/OneSignal configured; every inline script including their bootstraps and Ziggy has matching nonce, and scripts have no unsafe-inline/eval. | Pass — automated, `SecurityHeadersTest`. Production TLS verification enforcement is code-reviewed. Local Chrome admin root visibly rendered with compiled assets — live observed only; provider SDK execution/OAuth/payment/push not browser-verified. |
| SEC-14 / P1 | Supply shell-like host/password in a mocked dump; keep literal argument array/environment password. Nonzero dump cannot become gzip-success. Keep no-upload and failed-upload files private; delete only after successful private upload; reject public disk. | Pass — automated/mocked, `BackupBoundaryTest`. No real database dump/upload/restore performed. |
| SEC-15 / P1 | Update only affected dependencies and necessary compatible transitive packages; audit both lockfiles. | Composer security audit and npm audit report zero advisories — tooling-observed. Not proof against unknown vulnerabilities. |

## Verification record

- Final backend suite: **1,008 tests, 4,209 assertions pass**.
- Targeted security suite: **64 tests, 173 assertions pass**, including the expanded Meta/OneSignal script assertion.
- Frontend suite: **183 tests across 33 files pass**; production Vite build passes. Build uses `--ignore-scripts` to avoid the unrelated postbuild application-version bump. Existing large-chunk warning remains.
- Focused formatting of all changed/new PHP files passes; diff whitespace check passes.
- Focused PHPStan passes for active-account middleware, login/MFA/Firebase services, safe upload naming, public URL/HTTP clients, Woo client and backup service/command. Repository-wide `composer analyse` remains non-green: **585 findings** on the recorded run. No new baseline/suppression was added; this is not a global static-analysis clean bill.
- `composer audit --locked --abandoned=ignore`: no security advisories. Standard audit additionally reports the pre-existing abandoned Larastan alias. `npm audit`: zero vulnerabilities. Composer strict schema validation reports the pre-existing exact Flysystem version warning; it is not a passing strict-schema gate.
- Route/configuration caches were built and cleared successfully. No real database migration, release metadata/version edit or provider send performed for this implementation. Temporary local QA server was stopped.

## Compatibility and release requirements

1. Deploy only after separate approval and canonical branch validation. Run `2026_09_15_170000_add_security_ingestion_boundaries` on staging/production MySQL with verified backup/restore preparation. Existing document roots default to depth zero; existing store credentials remain unchanged. Old pending Woo callbacks must restart.
2. Native mobile clients must implement the documented 202/code/resubmit contract before release to confirmed-MFA users. Accounts without MFA and existing full-access tokens keep their original operation. Restricted developer tokens intentionally lose unintended full-agent/token-management access.
3. Designated tests must verify password/magic/Google/Firebase MFA, Meta onboarding, OneSignal/native push, checkout redirects, Woo OAuth/sync and knowledge indexing through actual providers. A passing preview, mock or DOM render is not delivery evidence.
4. Inspect production proxy forwarding/trusted-proxy configuration and egress restrictions using the actual topology. No environment change was made; avoid blindly changing proxy trust because it affects HTTPS/session behavior and rate-limit identity.
5. Inventory legacy public uploads and stored knowledge file references. This change prevents new filename/path abuse; it does not scan for malware, quarantine old files, make all media private, remove every possible stored-XSS sink or establish that the deployment is uncompromised. Anonymous visitor/session identifiers must stay secret and high-entropy.
6. Perform a designated real MySQL dump, integrity check and restore rehearsal; verify storage bucket policies are genuinely private. Mocked process/upload tests do not prove recoverability. Retained failed/no-upload artifacts need deliberate retention cleanup.
7. Revisit the explicit **`view_clients` impersonation and plan-assignment exception** before a least-privilege release. No changes to that policy were made.
8. This repository hardening does not establish why reputation vendors flagged the domain or automatically remove their listings. No live penetration test or complete infrastructure/malware audit was performed in this pass.

Outcome: the implemented local boundaries pass regression checks. Release/provider/infrastructure checks above remain open; do not label the total system "unhackable" or fully security-certified.
