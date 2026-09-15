# X integration QA — 2026-09-15

## Environment and verdict

Development implementation on `spiderman`, based on `279f2ac85666`; the implementing
commit is the commit containing this document. macOS, PHP/Homebrew, Node 24,
Laravel tests with isolated SQLite, Vitest, Vite, local Chrome at 127.0.0.1:8000.
No production settings, customer posts, credentials or provider applications changed.

**Development verification passes; provider delivery is not yet verified.** Promotion
to `dev` is authorized; production release and X application submission are separate.
The additive migration adds publishing attempts and disconnected-account tombstones.
Video requires server `ffprobe`; actual media metadata is validated, not trusted from
browser uploads. No transcoding is performed.

## Reproducible checks

- `php artisan test --filter='Social|IntegrationConfig'`: 105 tests / 488 assertions passed, including final reconnect/dispatch regressions.
- `npm test -- --run resources/js/__tests__/XText.test.js resources/js/__tests__/XMediaSelection.test.jsx resources/js/__tests__/SocialPlatformOverrides.test.jsx`: 26 passed.
- `npm --ignore-scripts run build`: passed. Postbuild release-version mutation deliberately excluded.
- Full frontend suite: 181 passed, 2 failed. Both failures are the untouched WhatsApp connection tests expecting the previous “Keep WhatsApp Business app” label; current label is “WhatsApp Business App.” Not an X failure, and not silently fixed in this scope.
- Focused PHPStan for X validator, destination publisher, uploader, exception, driver, OAuth manager, shared publisher, review controller and attempt model: passed.
- Repository-wide static analysis: 584 errors, compared with 592 on the original base. Full analysis is not claimed green. Controller typing cleanup does not add baseline suppressions.
- Focused Pint, route cache rebuild/clear and whitespace checks passed. No production caches were changed.

## Test records

Actual results below are **automated/mocked** unless explicitly marked otherwise.
Named test methods in `tests/Feature/Social/X*Test.php` are executable evidence;
frontend evidence is in the three test files listed above.

| ID | Steps / expected result | Actual / evidence |
| --- | --- | --- |
| X-OAUTH-01 | Start two connections; use first callback twice. Distinct PKCE challenges; only first use accepted; second tab remains valid. | Pass: `XOAuthConnectionTest::test_pkce_parallel_attempts_and_single_use_callback`. |
| X-OAUTH-02 | Expire/cancel attempt, change app, remove workspace membership or return insufficient scopes. Reject without linking an account. | Pass: OAuth expiry, application, scope and membership tests. |
| X-OAUTH-03 | Reauthorize at capacity or reconnect disconnected identity. Reuse the same ID; connected reauthorization consumes no extra slot. | Pass: final OAuth reconnect/capacity regressions. |
| X-TOKEN-01 | Refresh expiring access and rotate refresh token. Persist atomically without deleting account. | Pass: safety refresh test plus existing token-service transient-failure regressions. |
| X-TEXT-01 | Exercise Unicode, emoji, normalization and weighted 280-character boundaries. Browser/server agree. | Pass: `XContentValidatorTest` and `XText.test.js`. |
| X-TEXT-02 | Submit scheme, www, bare/Unicode domain and shortened links, including overrides. Reject rather than remove. | Pass: URL and effective-override validator tests. Validation also applies to generated content when scheduled/published. |
| X-MEDIA-01 | Select mixed, excessive, oversized, wrong-owner or externally addressed media. Reject; accept permitted real images. | Pass: actual-image validator and selection tests. |
| X-MEDIA-02 | Submit spoofed metadata, wrong container/codec/duration or absent probe. Fail closed; accept generated H.264 MP4. | Pass: metadata tests, including real local ffmpeg fixture. No real X upload. |
| X-MEDIA-03 | Advance initialize/chunks/finalize/status, failure and expiry. Persist offset/identity; use refreshed credentials; never insert source URL in text. | Pass: seven native-provider contract tests. HTTP mocked. |
| X-MEDIA-04 | Use permanent Media Library IDs through browser/API. Link to post without releasing or purging the library asset. | Pass: library and lifecycle tests. |
| X-API-01 | Submit invalid immediate/scheduled content via browser/API; edit incomplete draft. Block send but allow draft edit. | Pass: validator controller/API tests. Existing account and plan guards remain in use. |
| X-QUEUE-01 | Inspect immediate browser/API post status during dispatch. Worker must see persisted `publishing`, never draft. | Pass: dispatch-time regression assertions. |
| X-SEND-01 | Execute duplicate jobs and simulate crash after dispatch. Successful destination sends once; abandoned create is unknown, not retried. | Pass: duplicate, ambiguous and crashed-create safety tests. |
| X-SEND-02 | Return 429. Persist bounded provider-aware retry and do not send before retry time. | Pass: safety and native rate-reset tests. |
| X-SEND-03 | Disconnect account, change app or expire subscription. No provider create; retain original sender. | Pass: safety eligibility tests. |
| X-SEND-04 | Disconnect during uncertain create/upload. Preserve evidence, free inventory, and end waiting safely. | Pass: tombstone/inventory and upload-disconnect regressions. |
| X-SEND-05 | Remove failed destination from editable post. Historical receipt remains but current successful target determines result. | Pass: removed-destination regression. |
| X-REVIEW-01 | Review from wrong workspace or supply another author's post ID. Reject. Record verified own ID without create, or explicitly retry edited payload with warning/history. | Pass: four review tests. Verification endpoint mocked. |
| X-LIMIT-01 | Run existing social account/post plan regressions; inspect X entry points. No extra X meter or allowance. | Pass existing shared-limit tests; **code-reviewed** browser/API account and subscription enforcement. No X quota/billing fields added. |
| X-UI-01 | Open admin X card/configuration and expandable guide. Show exact callback, enable/credential controls and configuration-only testing. | **Live observed locally** in Chrome. Rebuilt page displays “Test configuration”; no save or external call performed. |
| X-UI-02 | Select X media mode, change modes, reject files, preserve uploaded IDs, remove assets and display server errors. | Pass: mocked component tests. |

## Code-reviewed behavior and boundaries

- UI displays X; storage and APIs retain `twitter`. Admin credentials are encrypted;
  blank secrets retain stored values. Disabling app prevents connections and sends.
- Existing social-account and social-post limits are unchanged, including multi-account
  counting. Provider credits/spending caps belong to Admin, not a client X quota.
- Per-destination payloads and attempts are encrypted and pinned once work begins.
  Per-post and destination locks protect duplicate/competing execution. Partial
  successes are retained; definite failures require correction/retry, uncertain creates
  require explicit review. Review is authenticated, workspace-scoped and CSRF-protected.
- No threads, quotes, replies, GIFs, Premium long posts, analytics polling, inbox,
  engagement automation or remote edit/delete. Max four JPEG/PNG at 5 MB each, or
  one MP4 at 500 MB / 140 seconds with H.264 and optional AAC.
- Editable drafts may be incomplete. Existing create UX publishes now or schedules;
  no new API draft-create mode is invented. Queued jobs revalidate before provider calls.
- New locale keys use English fallback outside English; translation review remains.

## Remaining release gate — not tested

1. Configure an approved shared X Web App, exact callback, five requested scopes,
   prepaid credits and spending cap; perform real client authorization and reconnect.
2. With a designated account, publish text, four-image and MP4 media-only posts,
   plus scheduled posts. Record provider IDs and verify visible X permalinks.
3. Exercise actual worker restart, processing latency, rate/credit exhaustion and
   deliberate uncertain-outcome reconciliation. Mocked HTTP does not establish
   live endpoint entitlement or exactly-once provider delivery.
4. Complete authenticated-client browser save/reopen, Media Library selection,
   scheduling/results, keyboard, dark mode and narrow-screen checks. Only the local
   Admin form was inspected live; component tests are not end-to-end browser proof.
5. Resolve/document repository-wide static-analysis and existing WhatsApp-test
   backlog before claiming an unrestricted green repository release.

Provider references checked during implementation: [OAuth PKCE](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code),
[character counting](https://docs.x.com/fundamentals/counting-characters),
[native media](https://docs.x.com/x-api/media/initialize-media-upload),
and [pricing](https://docs.x.com/x-api/getting-started/pricing).
