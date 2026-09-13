# Personal notification availability QA — 2026-09-13

Environment: local PHP 8.5.7, Vite/Chrome, development checkout on `spiderman`; no production deployment or real push/email. Implementation baseline `126fd7d`; final commit recorded in handoff.

| ID | Steps and expected result | Actual / evidence | Severity |
| --- | --- | --- | --- |
| NA-01 | GET legacy settings; save schedule; reopen; switch Paused; stale revision | Automated/mocked: Always default, retained hours and revision conflict passed | None |
| NA-02 | Separate users/workspaces; inaccessible selection and inactive user | Automated/mocked isolation and inactive checks passed. Existing middleware repairs stale browser workspace to an accessible fallback, never writes foreign settings | None |
| NA-03 | Invalid timezone/equal/incomplete/disabled hours and overlap | Automated/mocked rejection passed | None |
| NA-04 | 09:00 start, 17:00 end, weekends, overnight and DST repeated hours | Automated/mocked boundary checks passed | None |
| NA-05 | Paused/outside-hours event then resume; queued alert crosses boundary | Automated/mocked: silent history, no catch-up and worker recheck passed | None |
| NA-06 | All eight retained work events, preferences, revoked membership, direct push/SMTP | Automated/mocked policy regression passed; billing/security untouched | None |
| UI-01 | Open compact dialog, choose Scheduled, edit Monday/copy weekdays/save/reopen | Live observed local Chrome: 08:00 copied and persisted, Asia/Dhaka suggestion | None |
| UI-02 | Submit Invalid/Zone | Live observed local Chrome: inline error, draft retained | None |
| UI-03 | 390×844 light/dark layout; open schedule; Escape to cancel | Live observed local Chrome: card/fields/buttons fit, scrollable hours and fixed actions usable. Escape closes; viewport/theme restored | None |

Local QA settings restored to Always, retaining test hours; no notification preference changes or real messages. API supports Sanctum authentication and source workspace isolation. Migration and route-cache rebuilding verified locally. Source/account/workspace policy, optimistic locking and billing bypass code-reviewed.

Automated regression checkpoint: 891 backend tests/3692 assertions, 149 frontend tests/30 files; production build passed with existing chunk-size warnings. Focused new service/controller/policy/broadcast PHPStan and Pint passed; global analysis remains failing (634 findings reported). Final commit recorded in handoff.

## Outstanding verification

### Admin-only ownership correction

Production checkpoint: approved deployment on 2026-09-13, `v1.0.96`, main `9c00ef05347f`, tree-equivalent to validated dev `3ab287b`. Deployment script exited 0 without manual recovery. Live observed: Team administrator Manage controls opened a teammate's Always/Scheduled/Paused editor; scheduled timezone and weekday 09:00–17:00 controls loaded. Cancelled without saving. Six browser/API routes present; database availability rows zero; four Supervisor workers RUNNING. No schema changes, customer schedule changes or real alerts. Staff production browser verification remains not tested. Checkpoint retained locally, outside the deployed commit.

The user superseded personal editing: only client administrators manage schedules. Automated/mocked authorization tests verify admin save, staff read-only browser/Sanctum access, staff write 403, foreign/unassigned target 404, independent workspaces and revision conflicts. Frontend tests verify hidden staff Manage controls, read-only defaults, selected admin editor and summary refresh. Regression: 895 backend tests/3720 assertions, 155 frontend tests/30 files; build, focused Pint/controller PHPStan and route-cache rebuilding passed.

Live observed local Chrome: Team admin selected a teammate, saved Paused, saw row update and reopened persisted mode; restored Always. No alerts sent; impersonation ended. Staff UI was tested with mocks, not a real staff browser session. Existing production checkpoints below describe the earlier self-edit release, not this correction.

Approved production deployment observed on 2026-09-13: `v1.0.95`, main `668fac4aef14`, tree-equivalent to validated `3c31108`. Fresh private database backup passed gzip integrity; migration marked Ran. Script stopped at worker startup (exit 1), restored the site, and workers stabilized; remaining broadcast refresh/release/webhook finalization completed (exit 0). Final four workers RUNNING for over one minute. Production card showed Always; three-mode dialog and default weekly hours inspected then cancelled without saving. Database availability rows: zero. No test alerts sent. This checkpoint is local documentation, not included in the deployed commit.

Designated real OneSignal/native push and SMTP recipient tests, including provider failure/retry behavior, remain not tested. Parallel workers, interrupt/recovery and exhaustive accessibility are not signed off. Existing global static-analysis backlog is not resolved by this feature.
