# Client-wide WhatsApp connection modes — QA

Date: 2026-09-15. Base commit: `b6dc3da`; implementation is the commit containing
this document. Development macOS/PHP, isolated SQLite :memory:, Vitest and Vite.

## Results

| ID | Scenario / expected | Actual / evidence type |
| --- | --- | --- |
| CW-01 | Two unrelated subscribed clients begin without allowlisting; stale/empty lists do not deny access. Setup returns global availability true. | Pass, automated/mocked: `test_all_clients_can_begin_without_workspace_allowlisting`. |
| CW-02 | Global switch off denies begin/store before provider calls and setup returns false. | Pass, automated/mocked: `test_disabled_rollout_rejects_before_provider_calls`. |
| CW-03 | Both connection radios remain visible with switch off; Business App disabled, WABA usable; disabled handler cannot begin onboarding. | Pass, component test: `WhatsappConnectionMode.test.jsx`. |
| CW-04 | Select Business App, prepare once, then explicitly Continue; Meta receives coexistence feature flag. No duplicate number field. | Pass, component tests: `WhatsappConnectionMode.test.jsx`, `ConnectWhatsAppForm.test.jsx`. |
| CW-05 | Reject actor/workspace switch, replay, wrong app/scopes, ambiguous or foreign phone/WABA; never register/deregister coexistence phone. | Pass, automated/mocked onboarding regressions. |
| CW-06 | Preserve channel quotas, tenant separation, echoes/history safeguards and human takeover. | Pass, automated/mocked shared limit and coexistence suites. |

- `php artisan test --filter='Coexistence|WhatsappEmbeddedSignup|WorkspaceDefaultWhatsapp|ChannelPlanLimit'`: **53 passed, 219 assertions**.
- `npm test -- --run`: **183 passed, 33 files**. Updated obsolete label expectations in the connection-mode tests because the selector is directly in this scope.
- `npm --ignore-scripts run build`: passed; no release-version hook run.
- Focused Pint and CoexistenceRollout PHPStan: passed. Whitespace checks passed.
- Local config cache rebuilt and cleared successfully. One test attempt overlapped
  the config-cache check and was safely blocked by the isolated-database guard;
  after clearing cache the complete focused suite above passed. Do not rebuild
  config caches concurrently with database tests.

## Boundaries and release

No database migration required. `WHATSAPP_COEXISTENCE_ENABLED` remains the global
onboarding switch, default false. Existing enabled production installations will
allow every client after deployment; stale `WHATSAPP_COEXISTENCE_WORKSPACES` is
ignored without editing environment files. If Meta configuration is missing, the
drawer retains its existing configuration-required notice rather than offering an
unusable connection. Existing accounts and post-onboarding ingress are unchanged.

History/contact import stays off. Subscription limits, workspace ownership,
single-use sessions, Graph phone verification and signed webhooks are unchanged.
Documentation supersedes the pilot availability decision, not its safety policy.

**Not tested live:** real number connection, Meta customer eligibility, provider
delivery, Business-app echo, browser dark/mobile/keyboard end-to-end signoff. No
production setting, provider subscription, connection or customer message changed.
No deployment or main promotion performed for this change. Production remains
v1.0.103 until separate release approval.
