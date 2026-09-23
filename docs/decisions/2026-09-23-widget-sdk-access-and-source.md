# Website Widget And Customer SDK Access

Date: 2026-09-23

## Decision

Keep website chat and the customer mobile SDK on the existing `webchat` channel and shared `ChatWidget`, but give each surface its own public key and enable switch. Website traffic uses `widget_key` and `enabled`; customer SDK traffic uses `sdk_widget_key` and `sdk_enabled`. The SDK key is accepted only by `/widget/v1/*` and never by the website script loader.

Native SDK requests skip the website Origin/Referer allowlist because native applications do not provide a stable browser origin. All other visitor-token, identity-HMAC, workspace, conversation, handoff, realtime, push and AI checks remain in force.

New conversations store an additive nullable `started_from` value: `web_widget` or `customer_sdk`. A restored conversation keeps its original value. Historical conversations remain null, retain the existing webchat icon and do not display a guessed source.

## Compatibility

- Existing `widget_key` values are not changed.
- Existing widgets receive a generated SDK key and default `sdk_enabled=true`.
- Old SDK builds that still use the website key continue to follow the website enable switch until their configuration is updated.
- A valid token issued with the same widget's website key is temporarily accepted on its SDK key and replaced by an SDK-key-bound token.
- Staff/agent mobile APIs remain compatible; `started_from` is an additive nullable response field.

## App Release

The customer app or `cerqle_chat` package must replace the website key with the dashboard SDK key. No new header or request payload is required. Staff/agent apps may optionally render the additive `started_from=customer_sdk` value with a mobile icon; ignoring it remains valid.
