# WhatsApp coexistence implementation checkpoint

Status: 2026-09-09. New-messages-only pilot implemented on `spiderman`; not enabled
or deployed. The current checkpoint below supersedes the earlier preparation notes.

## Current pilot checkpoint

- Separate begin/store routes and a compact two-mode connection drawer are wired.
  Number entry occurs only in Meta. Selecting coexistence prepares a bound session;
  the explicit Continue click opens Meta. The server checks any returned phone ID
  against Graph's WABA phone list and coexistence mode; without an ID, multiple
  eligible phones fail closed instead of selecting the first. Limitations remain
  expandable and history/contact import stays off.
- `WHATSAPP_COEXISTENCE_ENABLED` defaults false. Set the comma-separated
  `WHATSAPP_COEXISTENCE_WORKSPACES` allowlist to the pilot workspace before enabling;
  an empty list allows all workspaces and must not be used for this pilot.
- History/contact import remains off and is not requested. Those webhook fields
  are acknowledged without importing their contents. The isolated history store
  is not a complete or exposed import feature.
- Signed mobile-app echoes are persisted as encrypted, phone/workspace-scoped
  receipts before acknowledgement. ID-only jobs run on `whatsapp`, recheck tenant
  ownership, and process transactionally/idempotently. Raw receipts are cleared
  after processing. The scheduler retries pending receipts every five minutes,
  expires pending payloads after 24 hours, and prunes metadata after 30 days.
- Echoes create outbound Business app-origin records, not inbound events, consent,
  unread counts, or API sends. Inbox bubbles identify Business app origin. Bot sends
  recheck current human assignment; an in-flight provider request cannot be recalled.
- Partner removal/offboarding events disable only matching coexistence channels.
  They never delete history; reconnect requires verified onboarding. Standard
  reauthorization and manual phone sync are blocked for coexistence WABAs to prevent
  registration or pruning of existing numbers.
- Webhook registration preserves existing fields and adds the three coexistence
  fields when rollout is enabled or coexistence phones exist. New-onboarding disable
  does not disable processing for existing connections.
- Full backend suite passed 791 tests (3127 assertions). Frontend suite passed
  96 tests in 22 files; Vite builds. PHPStan remains
  non-green at 578 findings, with none reported in new coexistence classes.

### Remaining release steps

1. Final checks; commit on spiderman, reconcile origin/dev, fast-forward dev, then
   approved merge to main. No suppression or force push.
2. Confirm a production database backup, deploy GitHub main with the production
   script, verify migrations and WhatsApp workers before activation.
3. Enable only the approved workspace; rebuild config, register and verify Meta
   subscriptions. Keep import disabled and decline sharing during the live flow.
4. User approves the new dedicated Business app number on their phone. Verify its
   exact Graph API mode and test customer inbound, Cerqle reply, Business app echo,
   duplicate handling, human takeover and quota in both Cerqle and WhatsApp.
5. Do not expose the pilot globally until provider-side checks are complete.

Meta documents Embedded Signup v2 deprecation on 2026-10-08. Verify the app's v4
configuration/SDK compatibility before that deadline. Do not claim historical
import or unsupported companion-device functionality as part of this pilot.

## Earlier preparation notes (superseded by current checkpoint)

## Verified provider contract

Source: [Meta: Onboard WhatsApp Business app users](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users), viewed in Chrome on 2026-09-09 (page updated 2026-06-26).

- Set `extras.featureType=whatsapp_business_app_onboarding`; use session logging.
- Completion event is `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`; the documented
  example includes only `data.waba_id`. Do not require a browser phone ID.
- Skip `/register` for coexistence. Verify `is_on_biz_app=true` and
  `platform_type=CLOUD_API` on the provider phone object, and its WABA membership.
- Subscribe to `history`, `smb_app_state_sync`, and `smb_message_echoes` only after
  their handlers are deployed. Preserve existing event subscriptions.
- `POST /{phone_id}/smb_app_data` accepts `messaging_product=whatsapp` and
  `sync_type=smb_app_state_sync` or `history`. These are one-time sync requests,
  within 24 hours of onboarding; store returned request IDs. An accepted request
  does not prove history consent or import completion. Error 2593109 indicates
  history sharing was declined. Never automatically offboard to retry.
- History may arrive out of order in phases/chunks, including separate media
  hydration events under the `history` field with a `messages` array.
- History and mobile-app sends do not open or extend the Cloud API service window.
- Mobile-app disconnect emits `account_update/PARTNER_REMOVED`; offboarding and
  reconnection also use account events. Do not call `/deregister` for coexistence.
- Meta documents changes to Business app features, including companion-device
  unlinking and unsupported Windows/WearOS companions. Show current limitations
  before consent; do not promise unchanged/full app functionality.

## Initial local changes

- OAuth session listener now survives time spent inside the Meta dialog.
- Explicit cancellation cannot fall through into server-side standard signup.
- Coexistence event recognition is isolated in a tested utility; not exposed.
- Non-live webhook fields cannot fall through into live message ingestion.
- Additive migration adds phone mode/metadata and message origin. Service windows
  now exclude imported history and messages from a different channel account.
- An unrouted onboarding controller verifies single-use actor/workspace-bound
  attempts, token app/scopes, and the exact confirmed number. It preserves other
  phones on reconnect, applies existing model quota enforcement, and never invokes
  `/register` or `/deregister`. Network errors are sanitized; ambiguous exchanges
  require a fresh attempt.
- A persistence service (not connected to ingress yet) tests isolated history and
  mobile-app echoes, replay handling, late-media metadata enrichment, and human
  takeover. Imported contact creation grants no marketing consent and does not
  resurrect deleted contacts. It does not fetch imported media yet.
- `WHATSAPP_COEXISTENCE_ENABLED` and `WHATSAPP_COEXISTENCE_IMPORT_ENABLED` default
  false. They are preparation flags, not a supported activation switch yet.

## Validation checkpoint

Focused backend tests and the nine signup-session frontend tests pass; Vite builds
successfully. Repository-wide PHPStan currently fails (579 findings before fixing
one newly introduced nullsafe-call finding); do not describe the full quality gate
as green. No commit, promotion, migration, or production deployment was performed.

Read-only Chrome inspection found that the user-designated demo number is already
an active Cerqle inbox channel with a bot assigned. Whether it also currently works
in the WhatsApp Business mobile app needs confirmation before any live onboarding.
Do not disconnect the existing API channel to try to make it eligible.

Later verification on 2026-09-09: a read-only production Graph API request returned
HTTP 200, `platform_type=CLOUD_API`, and `is_on_biz_app=false` for that existing
channel. The user supplied and approved a different dedicated Business app number
for the pilot. Preserve existing channels; leave history import off. No phone
numbers or customer identifiers are recorded in this document.

The send boundary now blocks API resending of history/mobile-app-origin records
and reloads human assignment before bot sends. Focused onboarding, persistence,
and chatbot regression tests pass (22 tests at this checkpoint). This is not an
atomic guarantee against an echo arriving after the final check or against a send
already in flight. Coexistence remains unrouted, disabled, and undeployed.

## Remaining implementation gates

1. Server-side onboarding attempt bound to actor/workspace/mode, verified phone
   discovery and registration policy, safe reconnect without pruning unrelated
   phones, and transaction-safe asset exclusivity/quota handling.
2. Dedicated idempotent, tenant-scoped echo/history/contact persistence; history
   never triggers bots, consent, unread counts, or live message quota. Origin-aware
   display and human takeover prevent AI replies after mobile-app agent replies.
3. Durable, bounded import jobs on the `whatsapp` queue, explicit import consent,
   request/progress tracking, late-media merge, storage allowance, and retention.
4. Compact two-mode UI, capability/health display, and separate disabled-by-default
   onboarding/import rollout flags. Existing connections continue processing when
   new onboarding is disabled.
5. Make automatic webhook registration preserve required coexistence fields and
   validate the provider version contract before changing live subscriptions.
6. Run backend/frontend tests and build; inspect plan limits, duplicate/reordered
   events, cross-workspace access, reconnect, cancellation, and AI takeover races.
7. Confirm dedicated demo workspace/Business app number, eligible provider access,
   data-handling disclosures, and user acceptance of Meta app-side limitations.
8. Validate both sides with real demo sends/echoes and consented import. No customer
   assets, permanent deletion, or number migration without specific confirmation.
9. Commit on spiderman; incorporate origin/dev; validate; fast-forward dev; obtain
   release approval before main/deployment. Deploy with the production script only.

## Scope and safety

Both modes are intended for all plans under existing channel limits. History and
contact import is optional; support/automation precedes commerce/advertising.
Do not add permissions for unimplemented product features. No production token,
phone registration, webhook toggle, or deployment has been changed by this work.
The existing untracked Meta review guide is unrelated user work and is preserved.
