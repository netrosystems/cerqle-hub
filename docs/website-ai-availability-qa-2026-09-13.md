# Website AI availability — QA checkpoint

Date: 2026-09-13. Environment: local Laravel/SQLite, PHP 8.5, Vite/React and Chrome. Base: `5513a04` on spiderman; implementation is the commit containing this report. No production deployment or production settings changed.

## Operation

Website widget → AI Answering → Off/Permanent/Scheduled. Select an enabled chatbot for Permanent/Scheduled. Scheduled → Edit hours supports enabled days, all-day, five windows/day, overnight and weekday copying. Save the widget to activate configuration. Outside hours AI does not answer or catch up later; visitors can still leave messages and request human support. Off retains bot/hours. Messaging/email group settings are separate.

## Results

Full backend: **872 passed, 3538 assertions**. Focused new `WidgetAiAvailabilityTest`: **14 passed, 63 assertions**. Frontend: **140 passed across 29 files**, including three new answering-editor tests. Vite production build, dirty-file Pint and route-cache rebuild/clear passed. Focused PHPStan for schedule, widget availability and queued AI job: no errors. Global analysis backlog is not claimed resolved. Only the new migration was applied to the local application database.

| ID | Steps / expected outcome | Actual / evidence |
| --- | --- | --- |
| WA-01 | Save Scheduled, reopen, switch Off; stale revision rejected; bot/hours retained | Passed — automated/mocked HTTP; local Chrome save/reopen and database check |
| WA-02 | Foreign/missing bot, invalid timezone, equal/empty hours and foreign workspace | Rejected — automated/mocked |
| WA-03 | Split windows, boundary minutes, timezone and overnight | Start-inclusive/end-exclusive; break inactive — automated/mocked |
| WA-04 | Sunday wrap overlap, all-day overriding incomplete windows, six windows | Overlap/limit rejected; all-day accepted — automated/mocked |
| WA-05 | DST spring/fall, all-day and disabled days | Passed — automated/mocked |
| WA-06 | Legacy migration enabled/disabled widgets | Permanent/Off, selected bot preserved — automated/mocked migration |
| WA-07 | Duplicate inbound/jobs and widget bot without channel metadata | One queued job/one mocked outbound — automated/mocked |
| WA-08 | Outside-hours inbound replayed later, then Off | No AI jobs/catch-up — automated/mocked |
| WA-09 | Revision change and old unpinned webchat jobs | Skipped before generation — automated/mocked |
| WA-10 | Hours end during generation | No outbound send — automated/mocked |
| WA-11 | Takeover, Off or sender disconnect during generation | No outbound send — automated/mocked |
| WA-12 | Independent widget modes, disabled widget | Isolated availability — automated/mocked |
| WA-13 | Public session then outside-hours poll | Mode/active refreshed, private schedule not added — automated/mocked HTTP |
| WA-14 | AI Off then visitor message/human request | Human request works — automated/mocked HTTP |
| UI-01 | Create local QA widget, select bot, add 18:00–22:00, copy weekdays, save/reopen | Correct bot and both windows persisted — live observed local Chrome |
| UI-02 | Submit missing bot and incomplete added hours | Inline errors visible; no widget created until valid — live observed local Chrome |
| UI-03 | Mobile 390×844 and dark/light | Horizontal overflow found and fixed; document width equals 390; controls visually inspected — live observed local Chrome |
| UI-04 | Off retention, add/remove/copy, five-window limit, all-day, error rendering | Passed — automated/mocked component |

The local QA widget was left disabled and AI Off, retaining saved hours/bot; no visitor messages or external AI calls were made through Chrome. Display settings restored and local impersonation ended. Test-only suite messages use mocked providers.

## Code-reviewed safeguards

Tenant/account/widget binding, enabled bot checks, revisioned transactional updates, durable ownership, shared overlap and attempt guards, workflow/rule precedence, subscription/credit guards and before-generation/send eligibility. Existing grouped AI regressions passed, covering credit exhaustion, workflow precedence, takeover, provider ambiguity and no duplicate retries. These are not real provider or concurrent worker proofs. Public responses expose only mode/active and polling refreshes availability; no manual typing simulation when AI is inactive.

## Not tested / release checks remaining

- Real embedded-site AI delivery with a designated widget and usable chatbot/provider credits. Local account reports zero managed credits; no external request attempted. Test available hours → reply, outside hours/Off → no AI reply, human handoff, and observe message evidence.
- Real Redis concurrency, worker termination/recovery and settings races while workers run. Sequential duplicate tests do not establish parallel execution.
- Exhaustive screen-reader and keyboard traversal; semantic radios/disclosures and labelled fields are code-reviewed, not full accessibility signoff.
- Live production migration, cache, worker timing and old-job recovery. Requires separate release approval; old unpinned webchat jobs intentionally skip.

Verdict: implemented and locally regression-tested; **not real-provider or production signed off**.
