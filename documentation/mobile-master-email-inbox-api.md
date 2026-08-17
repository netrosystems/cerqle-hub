# Mobile Master Email Inbox API

This API exposes Cerqle's Master Email Inbox to the agent mobile app. Email remains separate from the Omni Channel Inbox.

## Base URL and authentication

Production base URL:

```text
https://cerqle.ai/api/v1
```

Create a mobile token:

```http
POST /auth/login
Content-Type: application/json

{
  "email": "agent@example.com",
  "password": "password",
  "device_name": "Cerqle iOS"
}
```

Send the returned token on every protected request:

```http
Authorization: Bearer <token>
Accept: application/json
```

The login and `/auth/me` responses also contain the native push bootstrap data:

```json
{
  "push": {
    "provider": "onesignal",
    "enabled": true,
    "app_id": "ONE_SIGNAL_APP_ID",
    "external_id": "user:123"
  }
}
```

## Workspace selection

Email accounts and threads are isolated by workspace. List accessible workspaces:

```http
GET /workspaces
```

Select the workspace used by subsequent mobile requests:

```http
POST /mobile/workspaces/{workspace_id}/select
```

The server returns `403` if the agent is not a member or owner of that workspace. The selected workspace is also returned as `workspace_id` and `current_workspace_id` by `/auth/me`.

## Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/mobile/email/accounts` | List active connected email accounts |
| `GET` | `/mobile/email/threads` | List and filter email threads |
| `GET` | `/mobile/email/threads/{uuid}` | Read one thread and its messages |
| `GET` | `/mobile/email/threads/{uuid}/messages` | Poll for messages newer than a known message ID |
| `POST` | `/mobile/email/threads/{uuid}/reply` | Send a text email reply |
| `PATCH` | `/mobile/email/threads/{uuid}/status` | Resolve or reopen a thread |
| `POST` | `/mobile/email/accounts/{account_id}/sync` | Request an immediate mailbox sync |

### List connected accounts

```http
GET /mobile/email/accounts
```

```json
{
  "data": [
    {
      "id": 12,
      "provider": "imap_smtp",
      "display_name": "Support",
      "email": "support@example.com",
      "last_synced_at": "2026-08-17T08:30:00+00:00",
      "last_sync_error": null
    }
  ]
}
```

Credentials, passwords, OAuth tokens, and server secrets are never returned.

### List threads

```http
GET /mobile/email/threads?folder=inbox&account_id=12&search=invoice&per_page=30&page=1
```

Query parameters:

| Parameter | Values |
| --- | --- |
| `folder` | `inbox`, `unread`, `sent`, `resolved`, `all`; default `inbox` |
| `account_id` | Optional connected account ID |
| `search` | Sender name, sender email, subject, or message text |
| `per_page` | `1` to `100`; default `30` |
| `page` | Pagination page |

The response contains `data`, pagination `meta`, folder `counts`, and sync guidance:

```json
{
  "data": [
    {
      "id": 42,
      "uuid": "019c...",
      "status": "open",
      "subject": "Help with my account",
      "preview": "I cannot sign in...",
      "unread_count": 1,
      "last_message_at": "2026-08-17T08:35:00+00:00",
      "resolved_at": null,
      "contact": {
        "id": 91,
        "name": "Customer Name",
        "email": "customer@example.com",
        "avatar": null
      },
      "account": {
        "id": 12,
        "provider": "imap_smtp",
        "display_name": "Support",
        "email": "support@example.com",
        "last_synced_at": "2026-08-17T08:30:00+00:00",
        "last_sync_error": null
      },
      "assigned_user": null
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 30,
    "total": 1
  },
  "counts": {
    "inbox": 1,
    "unread": 1,
    "sent": 0,
    "resolved": 0,
    "all": 1
  },
  "sync": {
    "queued": true,
    "queued_accounts": 1,
    "poll_after_seconds": 5
  }
}
```

### Read a thread

```http
GET /mobile/email/threads/{uuid}?per_page=50&page=1
```

Opening a thread clears its unread count. Messages are returned oldest-to-newest within the requested page. Each message includes direction, body, subject, an attachment indicator, delivery status, sender attribution, and timestamps. When attachment metadata is available it is returned in `attachments`; otherwise use `has_attachments` to tell the agent that the source email contains an attachment.

### Poll for new messages

```http
GET /mobile/email/threads/{uuid}/messages?after_id=450&limit=100
```

```json
{
  "data": [],
  "meta": {
    "last_id": 450,
    "poll_after_seconds": 3
  }
}
```

Store `meta.last_id` locally and pass it as the next `after_id`. This avoids downloading the complete thread repeatedly.

### Reply

```http
POST /mobile/email/threads/{uuid}/reply
Content-Type: application/json

{
  "body": "Thanks for contacting us. We are checking this now."
}
```

The current API supports text replies up to 20,000 characters. Mobile attachment upload is not supported yet. A provider rejection is returned in `error`, while the saved message has `status: "failed"` so the app can show a retry state.

### Resolve or reopen

```http
PATCH /mobile/email/threads/{uuid}/status
Content-Type: application/json

{
  "status": "resolved"
}
```

Use `open` to reopen the thread.

### Request mailbox sync

```http
POST /mobile/email/accounts/{account_id}/sync
```

A successful request returns HTTP `202`. Sync runs asynchronously; refresh the thread list after the delay returned by the list endpoint.

## Recommended app synchronization

- Refresh the thread list every 5 seconds only while the inbox screen is visible.
- Poll the open thread every 3 seconds with `after_id`.
- Stop polling when the app is backgrounded.
- Treat push notifications as a wake-up hint; the API remains the source of truth.
- Do not start overlapping requests for the same screen.
- The server throttles automatic provider sync requests per account, so several app clients cannot continuously reconnect to IMAP, Google, or Microsoft.
- The production queue worker and scheduler must remain active for provider synchronization.

## Native push notifications

New inbound email messages use the same OneSignal installation as other client-team notifications. Super Admin accounts are not push recipients.

After a successful Cerqle login:

1. Initialize the OneSignal Android/iOS SDK with `user.push.app_id`.
2. Request notification permission using the native SDK.
3. Call OneSignal `login(user.push.external_id)` after permission/registration succeeds. Do not use the email address as the external ID.
4. Register a notification-click handler and route the payload shown below to the Master Email Inbox.
5. Call OneSignal `logout()` when the Cerqle user logs out or before changing accounts on the same device.

Email notification data:

```json
{
  "screen": "master_email_inbox",
  "channel": "email",
  "workspace_id": 7,
  "conversation_id": 42,
  "conversation_uuid": "019c...",
  "account_id": 12,
  "url": "https://cerqle.ai/app/inbox/email?conversation=019c..."
}
```

When `screen` is `master_email_inbox`, first select `workspace_id` if it differs from the app's active workspace, then open the mobile Master Email Inbox and request `/mobile/email/threads/{conversation_uuid}`. If the thread is not yet in local state, fetch it directly by UUID. Foreground pushes should update the inbox badge/list without forcing navigation; a user tap should open the thread.

The Cerqle server targets the OneSignal alias `external_id = user:{Cerqle user ID}`. APNs credentials for iOS and FCM credentials for Android must also be configured in the same OneSignal application before native delivery can work.

## Common responses

| Status | Meaning |
| --- | --- |
| `200` | Request completed |
| `202` | Mailbox sync queued |
| `401` | Missing or invalid token |
| `403` | Workspace is not accessible or demo mode blocks a write |
| `404` | Account or thread is not in the selected workspace |
| `422` | Invalid request data |
| `429` | API rate limit reached |

All thread and account lookups are constrained to both the authenticated agent's selected workspace and the `email` channel.
