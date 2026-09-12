# Mobile inbox action contract

Implementation exists on `spiderman`; do not assume deployed availability. Backend regression checks remain pending until a PHP test runtime is available.

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
