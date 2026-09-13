# Mobile inbox action contract

Deployed and route registration verified on 2026-09-13 in release `v1.0.90` (production code commit `a621ce5631e4`). Focused backend inbox/mobile regressions passed with local MAMP PHP. Live customer chats were not resolved or deleted for verification; the mobile team should run its authenticated end-to-end checks with designated test data.

These endpoints require a Sanctum bearer token and `Accept: application/json`. JSON requests also send `Content-Type: application/json`. Scope is the user's mobile-selected workspace, persisted via `POST /api/v1/mobile/workspaces/{workspace}/select`. Subscription and demo write restrictions still apply.

## Resolve all open email threads

`POST /api/v1/mobile/email/resolve-open`

```json
{"account_id": 123}
```

Use the selected email mailbox ID. Omit `account_id`, or send null, to target all email mailboxes in the selected workspace.

Success: HTTP 200, including when nothing is open.

```json
{"resolved_count": 12, "account_id": 123}
```

All-mailbox responses return `account_id: null`. Only `open` threads change; pending, snoozed and already-resolved threads remain unchanged. Includes all pages and ignores search/folder filters. No email is sent or deleted; existing resolution timestamps are retained. Repeat requests are safe and report only newly affected threads.

Errors: 401 without authentication; 422 for invalid `account_id`; 404 for a missing, foreign-workspace or non-email account. On errors, leave local thread status unchanged.

UI: Show “Resolve all open” with `counts.open` from `GET /api/v1/mobile/email/threads`, disable when zero or submitting, and confirm the mailbox/all-workspace scope plus inclusion of hidden pages/search results. After success refresh threads and counts; do not use the inbox count as the open count (it includes pending/snoozed).

## Delete a whole chat

`DELETE /api/v1/mobile/conversations/{conversation_uuid}` — no body. Success: HTTP 204 with no response body. Missing, foreign or already-deleted chat: 404.

Require permanent-deletion confirmation. Removes Cerqle conversation/messages/notes and related chat records, but keeps the contact, shared media assets, AI billing history and original-provider messages. New inbound activity may create a new chat. After success close the chat and refresh the inbox. This is not individual-message deletion.
# Personal notification availability (2026-09-13)

Authenticated Sanctum `GET/PATCH /api/v1/notification-availability` uses the token user's selected `workspace_id`; no arbitrary member/workspace parameter. GET returns `workspace_id`, `mode` (`always|scheduled|paused`), `timezone`, `weekly_hours` (seven Monday-first entries with `enabled`, `all_day`, `start`, `end`), `revision`, `active` and nullable ISO `next_boundary`. PATCH requires `mode` and `revision`; optional timezone/hours preserve stored values when omitted. Revisions start at zero with no row; stale saves return 422 `errors.revision`. Invalid schedule returns 422; inaccessible/inactive context is rejected. Browser equivalent uses session/CSRF at `/app/settings/notification-availability`.

Work notification `data.silent` is additive; keep unread/history while suppressing foreground alerts when true. Availability does not change assignments, live message updates or AI. Never replay silent history as catch-up alerts. Native mobile setup UI is not included; provider delivery remains separately verified.
