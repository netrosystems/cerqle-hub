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

Designated real OneSignal/native push and SMTP recipient tests, including provider failure/retry behavior, remain not tested. Parallel workers, interrupt/recovery and exhaustive accessibility are not signed off. Existing global static-analysis backlog is not resolved by this feature.
