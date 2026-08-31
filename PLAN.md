# Cerqle Hub — Implementation Plan & Feature Specifications

## 1. Purpose & Product Vision

**Cerqle Hub** is an enterprise-grade, omni-channel customer messaging, AI automation, and engagement SaaS platform. It centralizes customer conversations across WhatsApp, Meta Messenger, Instagram DMs, Website Chat, Telegram, and Email into a single unified agent workspace, augmented with autonomous AI knowledge bots, visual workflow automations, and targeted SMS broadcasting.

---

## 2. Core User Journeys

```mermaid
journey
    title Core User Journeys in Cerqle Hub
    section Setup & Channels
      Log in to Workspace: 5: User
      Connect WhatsApp WABA / Meta Pages: 5: User
      Configure Embeddable Website Widget: 4: User
    section AI & Automation
      Upload knowledge documents & URLs: 5: User
      Build XYFlow visual auto-response logic: 5: User
      Test Smart Bot responses in Playground: 4: User
    section Live Engagement
      Receive incoming multi-channel messages: 5: Agent
      Inspect customer context & order history: 5: Agent
      Assign conversation or trigger AI handoff: 5: Agent
    section Campaigns & Growth
      Draft SMS broadcast campaign: 5: Marketer
      Schedule multi-platform social media posts: 4: Marketer
      Inspect delivery rates & analytics: 5: Marketer
```

---

## 3. Feature Modules & Detailed Specifications

### Feature 1: Multi-Tenancy, Auth & Team Management (`app/Http/Controllers/Client/*`)

#### Capabilities
- **Authentication & Security**: Email/password authentication, Magic Link login, Google/Socialite OAuth, and Google Authenticator 2FA (`TwoFactorController`).
- **Workspace Switching**: Seamless switching between client-owned workspaces (`WorkspaceController`) with strict scoped sessions.
- **Workspace Management**: Authorized owners and client administrators can rename a workspace or permanently delete it after typing its exact name. Deletion is atomic, removes workspace-scoped records, selects a safe fallback workspace, and cannot remove the client's only workspace.
- **Role-Based Team Access**: Client administrators can invite team members, assign granular roles (Admin, Agent, Viewer), and inspect audit logs (`TeamController`, `ClientAuditLogController`).
- **Session Management**: View and revoke active browser sessions remotely (`SessionController`).
- **Resilient Verification Email**: Account creation succeeds even when SMTP or fallback notification delivery is rejected; failures are logged for follow-up and transactional messages include branded HTML plus a readable plain-text part.

#### Verification Criteria
- [x] Unauthenticated requests redirect to `/login`.
- [x] Workspace switching immediately re-scopes all active queries, broadcast channels, and settings.
- [x] Same-client users cannot access workspace broadcasts without ownership or explicit membership.
- [x] Permanent deletion requires exact-name confirmation, preserves a fallback workspace, and removes dependent workspace data.
- [x] Deactivating a team member terminates their active sessions immediately.

---

### Feature 2: Omni-Channel Agent Inbox & Master Email Inbox (`app/Modules/Inbox`, `app/Modules/Shared`)

#### Capabilities
- **Unified Conversation Stream (`/app/inbox`)**: Real-time conversation list filtered by folders (`All`, `Mine`, `Unassigned`, `Resolved`, `Snoozed`) and channels (WhatsApp, Instagram, Messenger, Webchat).
- **Interactive Chat Interface**:
  - Rich message formatting with image, video, audio, and document attachment previews.
  - Canned replies (`/quick-reply`) for fast repetitive response delivery.
  - Internal agent private notes and conversation tagging.
  - Real-time agent typing indicators and live presence detection.
- **Master Email Inbox (`/app/inbox/email`)**: Dedicated multi-mailbox email client synchronizing Gmail, Microsoft 365, and IMAP/SMTP accounts with folder organization and threaded conversations.

#### UI State Machine
```text
[Incoming Message] ──► [Unassigned Folder] ──(Assign Agent)──► [Mine Folder]
                              │                                      │
                              ├────────(Trigger AI Handback)─────────┤
                              ▼                                      ▼
                      [AI Handling Turn] ◄────(2 Fails/Turn)───► [Human Agent]
                              │                                      │
                              └───────(Mark Resolved)────────────────► [Resolved]
```

---

### Feature 3: Embeddable Website Chatbot Widget (`/widget/v1/*`, `public/widgets/chat/*`)

#### Capabilities
- **Lightweight Script Loader**: Embed script (`/widgets/chat/{widgetKey}.js`) dynamically injects the Cerqle Chat Launcher onto client websites.
- **Visitor Session Isolation**: Each visitor is assigned a secure cryptographic session token. Unauthenticated visitors stay anonymous; authenticated user profiles are verified via server-side HMAC validation.
- **AI-to-Human Handoff**: Auto-engages visitors with knowledge base answers, offering a smooth handoff to live agents after 2 failed turns or explicit user request.
- **Custom Branding**: Configurable colors, greeting messages, avatar launcher icon, and pre-chat capture forms.

---

### Feature 4: WhatsApp Cloud API & Template Manager (`app/Modules/Whatsapp`)

#### Capabilities
- **Meta Embedded Signup**: Direct WABA account onboarding and phone number registration via Meta Embedded Signup flow.
- **Template Lifecycle Management**: Create, submit for Meta approval, and synchronize WhatsApp Message Templates (Header, Body, Buttons, Parameters).
- **Keyword Auto-Replies**: Rule-based automated responses triggered by inbound keyword patterns.
- **Media Messaging**: Inbound and outbound support for interactive buttons, list messages, documents, location pins, and voice notes.

---

### Feature 5: Social Media Publisher & Scheduler (`app/Modules/Social`)

#### Capabilities
- **Connected Accounts**: Connect Facebook Pages, Instagram Business Accounts, and LinkedIn profiles via OAuth 2.0.
- **Multi-Platform Post Composer (`/app/social/composer`)**: Compose copy, attach media, preview platform-specific layouts, and publish immediately or schedule for future delivery.
- **Platform-Specific Payloads**: A shared base post can be overridden per selected network. Provider-only fields (YouTube metadata and TikTok creator privacy/interaction consent) are validated independently without conflating capabilities.
- **Temporary Publishing Media**: Social uploads count against plan storage while active or retryable, are released from quota after all destinations publish, and are purged after a 24-hour safety window.
- **Large Social Video Uploads**: Social videos accept up to 500 MB, while thumbnails retain their stricter provider limit. Deployment templates align PHP-FPM and Nginx with a 520 MB multipart request ceiling.
- **Storage Visibility**: The Subscription page and subscription APIs report organization-wide used, remaining, percentage, unlimited, and full storage states.
- **Interactive Visual Calendar (`/app/social/calendar`)**: Month/Week/Day calendar view for managing scheduled and past social media campaigns.
- **Capability-Driven Deletion**: Safely distinguishes remote platform deletion capabilities between Facebook (supported) and Instagram (API limited).

---

### Feature 6: AI Knowledge Bases & Autonomous Smart Bots (`app/Modules/AI`)

#### Capabilities
- **Multi-Source Knowledge Ingestion**: Ingest raw text, PDF/Word documents, website URL crawlers, and XML sitemaps into vectorized embeddings (`IndexKnowledgeDocumentJob`).
- **Hybrid Vector Retrieval**: Built-in MySQL vector-like similarity fallback with high-performance Qdrant vector database support.
- **LLM Provider Agnostic**: Native support for OpenAI (GPT-4o), Anthropic (Claude 3.5), Google (Gemini 1.5/2.0), and DeepSeek.
- **Smart Bot Configuration**: Define persona prompt instructions, confidence thresholds, temperature, and fallback behaviors.

---

### Feature 7: Visual Workflow Automation Builder (`app/Modules/Automation`)

#### Capabilities
- **Drag-and-Drop Node Canvas**: Interactive node-graph builder powered by **XYFlow / React Flow** (`/app/automations/builder/{id}`).
- **Comprehensive Node Catalog**:
  - **Triggers**: `contact.created`, `message.received`, `tag.added`, `order.placed`, `cart.abandoned`, `webhook.received`.
  - **Send**: Send WhatsApp message, send template, send email, push notification.
  - **Logic**: Condition branch (If/Else), Time delay (Wait X hours/days), Random A/B split.
  - **Contact**: Add tag, remove tag, update custom field, assign agent.
  - **Integrations**: Fire external webhook, execute AI prompt, sync CRM lead.
- **Execution Engine**: Asynchronous step execution via `ExecuteAutomationStepJob` with run logs and telemetry.

---

### Feature 8: SMS Broadcasting & Gateway Connector (`app/Modules/Broadcasting`)

#### Capabilities
- **Pluggable SMS Gateways**: Pre-integrated drivers for Twilio, MessageBird, SMSBD, REVE SMS, BulkSMS BD, ProSMS (Alaris), and Amazon SNS.
- **Segmented Campaigns**: Dispatch targeted SMS broadcasts to dynamic contact segments or CSV uploads.
- **Rate Limiting & Queue Batching**: Throttled chunk dispatching on the `broadcast` queue to comply with carrier rate limits.
- **Delivery Callbacks**: Real-time SMS status tracking (Queued, Sent, Delivered, Failed) with cost metering.

---

### Feature 9: E-Commerce Store Integration (`app/Modules/Ecommerce`)

#### Capabilities
- **Store Connectors**: OAuth connectors for Shopify, WooCommerce, and BigCommerce stores.
- **Contextual In-Chat Customer Widget**: When an agent chats with a customer, their active cart, recent order numbers, fulfillment status, and total lifetime spend display in the side panel.
- **Automated Abandoned Cart Recovery**: Ingests store events to trigger automated recovery messages via WhatsApp.

---

### Feature 10: Subscriptions, Add-ons & Developer Platform (`app/Http/Controllers/Client/*`)

#### Capabilities
- **Tiered Plans & Usage Metering**: Automated enforcement of contact limits, monthly message quotas, AI vector tokens, and team member capacity.
- **Self-Service Checkout**: Automated billing and invoicing via Stripe, PayPal, and Paddle.
- **Developer Tools Add-on**:
  - API Token generator with scoped permissions for REST APIs (`/api/v1/*`).
  - Outbound Webhook Endpoints with secret rotation and delivery logs (`/app/webhooks`).
  - Interactive OpenAPI/Swagger documentation (`/app/api-docs`).

---

## 4. Testing & Verification Matrix

| Module / Area | Test Type | File Reference | Coverage Target |
| :--- | :--- | :--- | :--- |
| **Workspace Scoping** | Feature | `tests/Feature/WorkspaceScopingTest.php` | 100% tenant isolation across models. |
| **WhatsApp Ingestion** | Feature | `tests/Feature/WhatsAppWebhookTest.php` | Signature validation, message storage & Echo broadcast. |
| **Widget HMAC Auth** | Feature | `tests/Feature/WidgetSessionTest.php` | Anonymous vs HMAC signed visitor flows. |
| **Automation Runner** | Unit / Feature | `tests/Feature/AutomationExecutionTest.php` | Branch evaluation, delays & step transitions. |
| **Navigation & Layout** | Component | `resources/js/__tests__/useClientNav.test.jsx` | Sidebar permission filtering & route active states. |
| **Contact Operations** | Unit | `resources/js/__tests__/contactListOperations.test.js` | Filtering, segment selection & bulk mutations. |
| **Stripe / Billing** | Feature | `tests/Feature/BillingWebhookTest.php` | Subscription renewals, plan changes & cancellations. |
