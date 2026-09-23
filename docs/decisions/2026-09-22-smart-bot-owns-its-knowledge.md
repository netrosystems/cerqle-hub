# A Smart Bot owns its knowledge

Date: 2026-09-22. Status: Accepted.

Extends `2026-09-16-grounded-smart-bot-answering.md` and `2026-09-20-language-agnostic-smart-bot.md`. Both still stand: scopes, generations, the credit model and flag discipline are unchanged. This record covers how a client reaches those capabilities, not how they behave.

## Context

Getting a Smart Bot to answer anything took five destinations across two mental models, in an order nothing stated:

```
AI → Knowledge Bases → create (name only)
   → KB detail → business profile → add sources → wait for indexing
AI → Chatbots → create (name only)
   → chatbot → pick a KB from a dropdown → answer scope → prompt
Inbox → channel or widget → choose the bot → set a mode
```

A client who began at Chatbots — the obvious place to begin — met **an empty knowledge dropdown with no explanation**. Nothing said a knowledge base was a separate object that had to exist first.

The business profile was worse than hidden. `business_name`, `business_purpose` and `target_audience` live on `ai_knowledge_bases` and were editable only through an "Edit profile" button on the knowledge base page, inside the same modal as renaming it. The card displayed `business_name · target_audience`; **`business_purpose` was collected and then never shown back at all.** Meanwhile the bot page warned "Add the business profile to this knowledge base" when the scope needed it — and gave no link to where that was. The owner of this product entered those details, could not find them afterwards, and reasonably concluded they had not been saved.

The root cause is an information-architecture error, not a missing tutorial: **the knowledge base is an implementation detail that was promoted to a first-class client concept.** `ai_chatbots.ai_kb_id` being a nullable FK is a useful engineering property — several bots may share one knowledge base — but almost no client wants two bots sharing knowledge. They want a bot, and the knowledge is what it knows.

## Decision

**The Smart Bot is the destination. Knowledge is one of its tabs.**

1. **Creating a bot creates the knowledge it answers from**, in one request and one transaction. `AiChatbotController::store()` accepts the identity fields and the business profile, creates the `AiKnowledgeBase`, and links it. A client never creates a knowledge base by hand.

2. **Sharing knowledge is opt-in, not the default path.** The create flow offers "Use knowledge from an existing bot", which states plainly that editing it then affects both. A knowledge base id belonging to another workspace is ignored rather than attached; tenancy is enforced on the server, never by the absence of a UI control.

3. **The business profile lives on the bot**, under Knowledge, with all three fields displayed back and editable in place. Renaming the knowledge base and editing the business profile are separate intents with separate controls.

4. **Every incomplete state names its own fix and links to it.** The bot page carries a readiness strip — bot is on, knowledge indexed, business profile — where each failing item states the specific reason and opens the tab that resolves it. This exists because in production a broken provider key, an inaccessible model and an empty knowledge base all presented identically: the bot answered with its fallback line.

5. **The storage model does not change.** `ai_chatbots` and `ai_knowledge_bases` keep their tables, columns and relationship. Existing bots and knowledge bases keep working untouched. This is navigation and information architecture, not a migration.

6. **Channel assignment stays where it is.** Choosing which bot answers WhatsApp, email or a widget remains in Channel Setup, Email Setup and the widget's own page. The bot page may show where a bot is in use, but it is not a place to configure channels. Establishing a channel — OAuth, webhooks, provider state — is a separate job from choosing who answers on it.

## Consequences

- A bot always has a knowledge base, so `ai_chatbots.ai_kb_id` is null only for bots created before this change. Code reading it must still tolerate null.
- One knowledge base may still serve several bots. Where that is true the UI must say so, because an edit affects every bot sharing it.
- `workspace_ai_automation_settings` keeps `unique(workspace_id, group)`: any number of bots may exist, but one is *selected* per channel group at a time. WhatsApp, Instagram and Messenger cannot currently run different bots. That constraint is unchanged here and should be revisited on its own merits, not assumed away by this UI.
- **`ai_chatbots.channels` is written but never read.** It is fillable, cast to `array`, validated in `AiChatbotController`, and holds plausible values on live records that control nothing. It must either become the real backing for channel assignment or be removed. A column that looks authoritative and is not is the same defect as a feature flag with nothing behind it.
- Still outstanding at the time of writing: the old create modal on the bots list is unreachable and should be deleted; bot rows still open inline editing rather than the bot page; the Sources form accepts URL and sitemap but not yet file or text.

## What this deliberately does not do

- It does not rename or merge any table, and it does not migrate existing data.
- It does not auto-split knowledge bases already shared between bots.
- It does not add a tutorial, coach marks or a product tour. A flow that needs explaining has a structure problem; the fix is the order of the steps, not a narrator over the old order.
