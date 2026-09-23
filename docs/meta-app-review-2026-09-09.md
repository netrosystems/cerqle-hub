# Cerqle Meta review preparation

> **Status: point-in-time audit record.** Evidence gathered 2026-09-08–09 for
> the Meta app review, covering implemented features only. Several
> prerequisites were deliberately left for the owner — demo assets,
> data-handling declarations, allowed-usage agreements, reviewer access and the
> screencasts. Treat the outstanding items below as still outstanding unless a
> newer record says otherwise, and do not read a listed permission as proof
> that a production token authorizes it.

Audit dates: 2026-09-08–2026-09-09. Scope: implemented features only; dedicated demo assets; no submission or screencast upload. This is a review-evidence document, not a claim of live integration success.

## Status and evidence

- Meta's live dashboard identifies Cerqle as the published app. Production credential/app-ID matching remains unverified in this audit.
- The existing draft now contains Business Asset User Profile Access, instagram_manage_contents, and whatsapp_business_management. All eleven previously approved permissions remain listed for renewal.
- Both rejected requests received “Screencast Not Aligned with Use Case Details”; Meta said the described use cases were allowed.
- The existing WhatsApp management description is consistent with code and was preserved. Meta marks its API-test prerequisite completed; this is prior dashboard evidence, not a fresh rehearsal.
- Updated Instagram deletion and business-profile descriptions were saved in the Meta draft. Meta also marks the Instagram deletion API-test prerequisite completed. Neither recording nor allowed-usage agreement was supplied.
- Renewal presents eleven certification checkboxes, not editable per-permission descriptions. They were inspected and left unchecked for the owner.
- Corrected the reviewer form's Facebook Login integration answer from No to Yes: Cerqle uses Facebook Login for Business for asset connection after its own dashboard login. Appended route-based testing instructions without changing the credentials already stored in Meta.
- Demo workspace, Page, Instagram account, WABA, and test recipient have not been identified. No customer asset is an acceptable implicit substitute.
- Data-handling declarations, allowed-usage agreements, reviewer access, and recordings must be completed before submission. Do not accept agreements on the owner's behalf.

## Permission matrix

Graph paths are relative to the configured Graph API version. Endpoint presence in code does not prove production tokens authorize it.

| Permission / feature | Cerqle screen | Implemented API/data usage | Client benefit / recording |
| --- | --- | --- | --- |
| whatsapp_business_management | /app/inbox/setup; /app/whatsapp/templates | GET /WABA_ID/phone_numbers; message_templates CRUD; /WABA_ID/subscribed_apps | Connect the chosen WABA, import phones, manage templates, receive account events. Record A. |
| whatsapp_business_messaging | /app/inbox | /PHONE_NUMBER_ID/messages and media operations; inbound message/status webhooks | Two-way support and permitted template messaging. Record B. |
| Business Asset User Profile Access | /app/inbox | Messenger sender-profile lookup; Page conversations participants fallback | Identify the person contacting the authorized business. Record C. Exact feature-specific field dependency must be confirmed with the demo token; do not imply this feature grants access to arbitrary Facebook profiles. |
| pages_messaging | /app/inbox/setup; /app/inbox | Page message sends, conversation reconciliation, scoped sender lookup | Receive and answer Page messages. Record C. |
| pages_manage_metadata | /app/inbox/setup | Page subscribed_apps/webhook configuration | Subscribe the connected Page so inbound messages reach the correct workspace. Record C. |
| instagram_manage_messages | /app/inbox/setup; /app/inbox | Instagram messaging and scoped profile endpoints; message webhooks | Receive and answer Instagram DMs. Record D. |
| instagram_manage_contents | /app/social/posts | DELETE /IG_MEDIA_ID through client.social.posts.instagram.destroy | Remove the client's own published Instagram post. Record E. |
| instagram_content_publish | /app/social/composer | POST /IG_USER_ID/media and /media_publish | Publish image, video, or carousel content. Record E. |
| pages_manage_posts | /app/social/composer; /app/social/posts | Page feed/photos/videos publishing; post update/delete | Manage the client's Facebook Page posts. Record F. |
| pages_read_engagement | /app/social/accounts; /app/inbox/setup | Page metadata and linked Instagram account discovery | Identify and display the authorized Page and related account. Record G. Do not claim an implemented insights dashboard. |
| pages_show_list | /app/social/accounts; /app/inbox/setup | GET /me/accounts | Present the authorized Pages for selection. Record G. |
| business_management | Connection flow | GET /me/businesses and /BUSINESS_ID/owned_pages, /client_pages | Discover portfolio-assigned Pages that direct Page listing may omit. Record G. |
| instagram_basic | /app/social/accounts | Linked instagram_business_account identity, profile and media information | Identify the connected Instagram professional account. Records G/E. |
| public_profile | Meta authorization | Login identity/default profile supplied by Meta | Identify the person authorizing their business assets; not a replacement for customer-profile access. Record G. |

The current social OAuth flow uses Facebook Login permissions. Do not substitute instagram_business_* permissions from the separate Instagram Login product without implementing and validating that authentication path.

Not requested: ads_read, ads_management, catalog_management, comment moderation, likes management, hashtag/public-content access, insights, or Human Agent. No demonstrable matching client flow was established in this audit. Ordinary human replies in the inbox do not establish support for the special extended-window Human Agent tag.

## Description drafts

### Instagram content management

Cerqle lets an authenticated business administrator manage content published to their connected Instagram professional account. The administrator connects the account through Facebook Login for Business and selects the Facebook Page linked to that Instagram account. In Social Posts, an authorized workspace user can select a published Instagram post and explicitly choose to delete it from Instagram. Cerqle sends a deletion request for that account's media ID and updates the local publishing record only after Meta confirms success. This permission is necessary to let clients remove their own outdated or incorrect Instagram posts from the same workspace where they publish them. It is not used to delete comments, other users' content, or unrelated accounts' posts. The recording will show authorization, a disposable post visible on Instagram, deletion from Cerqle, and the resulting removal on Instagram.

### Business asset user profile access

Cerqle is a workspace-based customer-support inbox. After a business administrator authorizes and connects their Facebook Page, Cerqle displays supported identifying information for people who contact that business, alongside their conversation. This helps authorized agents distinguish customers and respond in the correct conversation instead of relying only on an opaque sender ID. Cerqle requests profile information in the context of the connected Page and its customer conversation, using the Page's authorized access; it does not offer arbitrary personal-profile search. The recording will show the business connection flow, a demo person messaging the connected Page, and the corresponding identity information displayed in that workspace's inbox. The feature-specific API dependency and returned fields must be verified on the demo asset before submission; Messenger's existing pages_messaging permission also participates in this flow.

## Recording runbook

Use the English UI. Begin each permission-specific video with a title naming its permission and purpose. Show readable actions and results with narration or captions. Never expose passwords, OTPs, tokens, app secrets, customer messages, or unrelated business assets. Do not cut out the authorization or final result. A missing result is a failed rehearsal, not something to explain away in captions.

### A — WhatsApp account management

1. Sign into the dedicated demo workspace; open /app/inbox/setup.
2. Click Connect WhatsApp and show the complete Meta login, consent, and selection of the designated demo business/WABA/phone. Hide credential entry.
3. Return to Cerqle and show that the same phone is listed. A WABA badge with zero phones is not a passing result.
4. Use Sync from Meta; show its successful result and matching number in Meta's WhatsApp Manager.
5. Open /app/whatsapp/templates. Synchronize templates and show a template. Create a clearly labeled demo template through Cerqle and show its returned pending/approved status accurately; do not claim immediate approval.
6. Narration: “The business grants access to its selected WhatsApp assets. Cerqle uses this permission to list its numbers, manage templates, and configure the account's webhook subscription.”

### B — WhatsApp messaging

1. Show connection/consent or include an unambiguous reference to the same recording's onboarding segment.
2. From the designated test recipient, send a harmless message to the demo business number.
3. Show it arriving in /app/inbox. Reply manually from Cerqle and show delivery on the recipient's WhatsApp.
4. If demonstrating templates, use an approved template and an opted-in test recipient only. Avoid bulk campaigns and production contacts.
5. Narration: “A customer initiates this conversation. The agent receives and answers it in Cerqle; the customer receives the reply in WhatsApp.”

### C — Messenger and customer identity

1. Open /app/inbox/setup; connect the demo Page through the full Meta flow with only designated assets selected.
2. From the demo person's Messenger, message that Page.
3. Show the message, supported customer name/profile information, and selected Page in /app/inbox. Do not prefill contact names manually to simulate API results.
4. Reply from Cerqle and show receipt in Messenger.
5. Narration: “The Page owner authorizes Cerqle. When a person contacts this Page, the agent sees their supported identity information in the same conversation and can respond.”
6. For pages_manage_metadata, explain that the Page subscription delivers the inbound event. API status is supplementary; it does not replace the visible message arriving.

### D — Instagram direct messages

1. Connect the demo Instagram professional account from /app/inbox/setup, showing the actual Facebook-based authorization used by Cerqle.
2. Send a DM from the test identity. Show it arriving in Cerqle under the matching Instagram channel.
3. Reply in Cerqle and show the reply on Instagram.
4. Narration: “The business authorizes its Instagram account so its agents can read and answer customer DMs from Cerqle.”

### E — Instagram publishing and deletion

1. Open /app/social/accounts; connect the demo Instagram account and show authorization and Page/account selection.
2. Open /app/social/composer; publish a harmless demo image with a unique caption.
3. Show the successful post in /app/social/posts and open it on Instagram.
4. Obtain explicit confirmation before permanently deleting even the demo post. In Cerqle choose the Instagram platform deletion action, not local-record removal.
5. Verify success in Cerqle and refresh Instagram to show removal.
6. Narration: “Publishing uses instagram_content_publish. The separate instagram_manage_contents permission lets the owner remove this published Instagram post from Cerqle.”

### F — Facebook Page publishing

1. Show connection to the demo Page in /app/social/accounts.
2. Publish a demo post from /app/social/composer; show it on the actual Page.
3. If the submitted description includes editing, edit its text through Cerqle and show the updated Page post. Deletion is optional and requires confirmation.
4. Narration: “The Page administrator authorizes Cerqle to publish and manage their business's Page content.”

### G — Shared authorization/discovery

1. Start at /app/social/accounts or /app/inbox/setup and show Meta login, permissions, and designated asset selection.
2. Show the authorized Page list and the linked Instagram account returning to Cerqle.
3. If demonstrating business_management, use a designated portfolio-assigned demo Page and show the matching business/Page, without exposing unrelated assets.
4. Narration: “Cerqle identifies the authorizing user and lists only accessible business assets, allowing the administrator to select which Page or Instagram account to connect.”

## Test evidence to collect before recording

For each test, retain only timestamp, permission, sanitized endpoint shape, HTTP status/error code, expected result, actual result, and pass/fail. Never copy bearer tokens or raw customer responses into this document.

1. Match production's configured public app ID to the app open in Meta; verify login configuration IDs correspond to the intended channel flow.
2. Validate demo token type, expiry and granted scopes server-side; never expose credentials in the browser. Check that asset IDs match the demo workspace.
3. Read demo phone list/templates, Page list, linked Instagram identity, and webhook subscription. Check the feature-specific profile API with a demo contact who actually messaged the Page.
4. Rehearse A–G only with the explicitly named demo assets. Capture both Cerqle and provider-side results. Existing Meta API-call counters do not prove these tests passed.
5. Confirm an ordinary reviewer account can log in, is verified, and has sufficient channel/social/AI allowances without an admin impersonation bypass.

## Data handling and unresolved prerequisites

Code findings: messages/contact data are persisted; MessengerDriver logs some sender names/profile responses; LlmGateway forwards message arrays to the selected managed/BYOK provider; deletion services exist but do not by themselves establish backup retention or a complete Meta deletion compliance policy. Do not certify “no third parties,” “metadata only,” or a specific deletion interval from these facts.

Owner must confirm: legal data-controller name/address/contact; actual production hosting and storage providers/regions; AI and other subprocessors receiving Meta-derived data; provider contracts and processing purposes; retention/deletion schedules including logs/backups; government-request history and organizational response policies. Reviewer credentials must be provided securely in Meta, not committed here.

The current prefilled processor list contains Firebase, Pusher, and OneSignal. Do not treat this as an audited complete inventory: hosting/storage and actual AI processing need confirmation. Existing controller/country and authority-request declarations were left unchanged. The owner explicitly requested preservation on 2026-09-09; this does not establish a newly verified processor inventory.

Blocked until identified: dedicated demo assets and reviewer account. Production credential/configuration matching and new end-to-end tests remain pending. Screenshots and code inspection are not substitutes.

## Sources and implementation references

- Meta's live App Review feedback and permission catalog, inspected 2026-09-08. Both rejected use cases were allowed but recording evidence was insufficient.
- https://developers.facebook.com/docs/app-review/submission-guide/screen-recordings/
- https://developers.facebook.com/docs/permissions/reference/
- https://developers.facebook.com/docs/features-reference/
- Code: app/Modules/Whatsapp/Http/Controllers; app/Modules/Inbox/Services/MessengerDriver.php; app/Modules/Inbox/Services/InstagramDriver.php; app/Modules/Integrations/Services/MetaPageDiscoveryService.php; app/Modules/Social/Services/Drivers; app/Modules/AI/Services/LlmGateway.php.

No application behavior, API schema, database schema, or production configuration changed by this document. No release or deployment is required.
