# Mobile inbox action contract

Deployed and route registration verified on 2026-09-13 in release `v1.0.90` (production code commit `a621ce5631e4`). Focused backend inbox/mobile regressions passed with local MAMP PHP. Live customer chats were not resolved or deleted for verification; the mobile team should run its authenticated end-to-end checks with designated test data.

These endpoints require a Sanctum bearer token and `Accept: application/json`. JSON requests also send `Content-Type: application/json`. Scope is the user's mobile-selected workspace, persisted via `POST /api/v1/mobile/workspaces/{workspace}/select`. Subscription and demo write restrictions still apply.

## Conversation activity compatibility (local change, 2026-09-22; not deployed)

The backend adds `POST /api/v1/mobile/conversations/{uuid}/{join|leave|takeover}` for future staff clients. Join/leave are idempotent; an active join by another agent returns 409, and takeover of an active join requires a client administrator. Existing assignment and status routes retain their request/response shapes and now record staff activity. Mobile conversation and email-thread detail/history include `direction=system`, `type=event` activity records; conversation detail also exposes `joined_at` plus `joined_user`. Inbox preview/order/unread still use only content messages. The native Cerqle Agent repository remains unchanged, so mobile UI adoption of activity rows and join controls is a separate app release; do not claim it has shipped.

## Login hardening contract (2026-09-15, not yet deployed)

Mobile login tokens retain full-agent `*` access. Restricted developer API tokens cannot use `/api/v1/mobile/*`, `/api/v1/auth/{me,profile,logout}`, broadcasting authorization, notification availability, or token management; those return 403 for insufficient scope. Existing full-access mobile tokens continue to work. Inactive users/clients are rejected even with an existing token.

`POST /api/v1/auth/login` keeps the existing 200 token/user response for accounts without confirmed 2FA. With confirmed 2FA and no `two_factor_code`, it returns HTTP 202 without a token:

```json
{"code":"two_factor_required","message":"Enter your authenticator or recovery code."}
```

The native client must show a code input, then repeat the original email/password/device/push fields with `two_factor_code` (authenticator OTP or recovery code). Only a successful 200 response permits storing a token or entering the app. Invalid codes return 422 and count toward the existing login throttle. Recovery codes are single-use. Do not automatically retry missing codes or treat HTTP 202 as authenticated success. The backend is tested; native settings/login UI and real-device end-to-end verification remain release requirements for 2FA-enabled users.

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

Authenticated Sanctum `GET/PATCH /api/v1/notification-availability` uses the token user's selected `workspace_id`; no arbitrary member/workspace parameter. GET returns `workspace_id`, `mode` (`always|scheduled|paused`), `timezone`, `weekly_hours` (seven Monday-first entries with `enabled`, `all_day`, `start`, `end`), `revision`, `active`, nullable ISO `next_boundary` and `can_edit`. Staff are read-only: PATCH returns 403; hide native availability editing unless `can_edit` is true. Only client administrators may PATCH their own schedule. PATCH requires `mode` and `revision`; optional timezone/hours preserve stored values when omitted. Revisions start at zero with no row; stale saves return 422 `errors.revision`. Invalid schedule returns 422; inaccessible/inactive context is rejected. Browser equivalent uses session/CSRF at `/app/settings/notification-availability`. Client administrators manage assigned teammates through Team, not arbitrary parameters on this personal API.

Work notification `data.silent` is additive; keep unread/history while suppressing foreground alerts when true. Availability does not change assignments, live message updates or AI. Never replay silent history as catch-up alerts. Native mobile setup UI is not included; provider delivery remains separately verified.
