# Smart Bot behaviour specification for Cerqle

> **Status: input document, partially adopted.** This is the specification as
> received, kept for the production observations in Part A and the algorithms in
> Part B. It is *not* the record of what Cerqle built.
>
> Read [`decisions/2026-09-20-language-agnostic-smart-bot.md`](decisions/2026-09-20-language-agnostic-smart-bot.md)
> first — it states which parts were adopted and which were rejected. In
> particular the Bangla framing running through Part B was rejected outright in
> favour of script properties and embeddings, §11 (video) and §14 (source
> review) were dropped, and the temperature values in §4 and §6 are inert on
> managed models. The branch instruction in the scope rule below is stale; the
> current workflow is in [`../AGENTS.md`](../AGENTS.md).

**Purpose.** Bring Cerqle's Smart Bot to the behaviour running in production at wisperbot.com: grounded answers that never invent facts, tappable choices, client-written starter answers, tutorial links, and deterministic zero-credit shortcuts. Self-contained — decisions, algorithms and parameters are stated here.

**Part A** is what I observed in the live production account on 20 Sep 2026 (v1.3.89), including two failures worth learning from. **Part B** is the implementation spec for Cerqle.

**Scope rule.** Cerqle already has the foundations. **Extend them; do not build parallel structures.** Keep `app/Modules/AI/Services/ChatbotRunner.php`, `EmbeddingStore`, `LlmGateway`, `AiCreditService`, `IndexDocumentJob`, the `AiKbGeneration` publishing model, `ChatbotAnswer`, and `AutoReplyListener` → `GenerateGroupedAiReply`.

**Repo rules apply** (`AGENTS.md`): update the authoritative doc in the same commit; strict `workspace_id` scoping everywhere; jobs take IDs and re-verify ownership; never expose credentials; commit on `spiderman`; run `php artisan test`, `npm test -- --run`, `npm run build`, `./vendor/bin/pint --test`, `composer analyse`.

---

# Part A — Production observations

## A1. How a Knowledge Base actually looks

Live account, two Knowledge Bases: *Telzen Knowledgebase* (1 document) and *Netro Systems* (**36 documents, 83,539 tokens, 36 indexed, readiness 100%**).

The Netro base is one **sitemap source that fanned out into 35 indexed URL documents** — about pages, service pages, portfolio case studies, privacy, terms, careers — each 300–4,100 tokens. Two facts to copy:

- The UI is a **5-step setup** ("STEP 2 OF 5 — Knowledge sources"), not a file dump, and it states: *"Manage the information WisperBot can use. Changes remain in draft until you publish."* Guarded publishing is **on in production**.
- Roughly **20 of 36 documents carry "Review N findings"** (4–6 each) from automated quality review, with filters *All sources / Ready / Needs review / Blocked / Failed*. Ingestion is not "fetch and trust": every source is scored and a human can be asked to look.

## A2. What a client can configure per bot

The production Smart Bot panel (Netro Systems Bot, tone Professional) exposes exactly this:

- **Answer scope** (marked *Staged rollout*): **Business only** (recommended), **Verified sources only**, **General assistant** — with the honest note *"Complete the Knowledge Base purpose, brand, and audience. Until then, this bot safely uses Verified sources only."*
- **When no verified answer exists:** *Ask for a relevant detail, then offer human help* | *Offer human help immediately*.
- **Research approved sources:** *"check only website and sitemap domains already added to this Knowledge Base"*.
- **Use Knowledge Base wording exactly:** *"Off: replies are written naturally while facts stay exact. On: replies keep your approved wording, for regulated or scripted answers."*
- **Live product prices** — staged by the operator, *"0 products detected"*.
- **Starter questions:** *"Shown at the top of website chats. Tapping one sends your saved answer instantly — no AI credits used."*
- Retrieval tuning is deliberately **not** exposed: *"Answer quality, context selection, confidence, and relevant video matching are optimized automatically."*

## A3. The credit model, stated to the client

The playground prints it plainly: **"Generated answers use 1 credit · greetings are free · 34 remaining."** Observed:

| Turn (production playground) | Result | Credits |
|---|---|---|
| "What does your MVP audit include and how long does it take?" | Grounded answer: security holes, broken flows, UX/accessibility, performance & SEO, ~60 seconds, prioritised findings, free, no card | 34 → **33** |
| "apnara ki mobile app development koren? price koto?" | Fallback: *"I could not answer properly."* | 33 → **33** |
| "apnara ki mobile app banan?" | Fallback | 33 → **33** |

**A failed answer is not charged.** That is the rule to copy: the client pays for answers, not for attempts.

## A4. Two production failures worth designing around

**1. The public widget answers nothing, by design.** wisperbot.com's own widget opens, greets, and accepts messages — but **"Let a Smart Bot answer first" is off** on that widget, so visitor messages go to human agents. A bot can be fully configured and still be silent because a *channel-level* switch is off. Cerqle already has this shape (`chat_widgets.ai_mode`, availability windows); keep the switch visible and make silence explainable.

**2. Romanised Bengali falls back in production.** The same style of question that a local build answers in Roman Bangla ("eSIM install korar video ache?") returned the fallback on **both** production bots, unpaid. The cross-language feature is deployed — the settings panel shows the newest options — so the difference is configuration (retrieval flags) or Knowledge Base content, not missing code.

The lesson for Cerqle: **cross-language answering is a flag-and-content property, not a code property.** Ship the translation step, then verify per environment with a real non-English question, because a silent fallback looks identical to "we don't support that language".

## A5. What the target behaviour looks like when everything is on

Verified on a full build of the same product, same conversation, one Knowledge Base (2 sources — a public sitemap crawl plus an uploaded document marked authoritative, priority 75 — 17 chunks):

| Customer said | Bot answered | Origin | Cost |
|---|---|---|---|
| *(taps)* "How do I install my eSIM?" | The saved answer, verbatim | `starter_question` | 0 tokens, instant |
| "Do you sell physical SIM cards for Thailand?" | "Yes… delivered to your doorstep" | `knowledge_base` | score 0.579, 1 passage, 53 tokens |
| "eSIM install korar video ache?" | Answered in Roman Bangla **with a tutorial link** | `knowledge_base` | video attached |
| "I need a data plan" | "Which country will you be traveling to?" + **Thailand / USA / Europe** | `clarification` | `citations: []` to the customer |
| *(taps)* "Thailand" | Thailand guidance + its own follow-ups | `business_guidance` | 77 tokens |
| "thanks!" | "You're welcome! Is there anything else I can help with?" | `conversation` | **0 tokens, no credit** |

The authoritative uploaded document beat the public page that still says "eSIM only" — that is §9 working.

---

# Part B — Implementation spec

## 1. What Cerqle already has

| Area | State |
|---|---|
| Ingestion (url/sitemap/file/text), chunking, embeddings, Qdrant + MySQL fallback | Done (`IndexDocumentJob`, `EmbeddingStore`) |
| Immutable published generations | Done — **stronger than the source product's revisions; keep it** |
| Credits: reserve → complete/refund, idempotency, rate limits, concurrency | Done |
| Answer scopes, confidence/clarification thresholds, citations, handoff | Done |
| Greeting shortcut, account-specific handoff, legacy exact FAQ | Done |
| Grouped inbox AI, widget availability windows, single-owner routing | Done |

## 2. Gaps, in build order

1. Structured JSON replies + dynamic quick replies (today: free text, two hardcoded choice sets).
2. Post-generation grounding validation (today: prompt + threshold only).
3. Reply style — facts strict, wording free.
4. Starter questions.
5. Conversational turns (closing, decline, acceptance, thanks).
6. Retrieval quality — authority, duplicates, cross-language.
7. Answer and semantic caches.
8. Video resources.
9. Diagnostics.
10. Source quality review (§A1) — the "Review N findings" pass.

## 3. The answering ladder (`ChatbotRunner::generate()`)

Return at the first step that answers. Steps 1–6 cost **zero credits**.

1. **Starter question** (§7) — exact saved question → saved answer, verbatim.
2. **Offer reply** — yes/no to the bot's own previous question (§8).
3. **Conversational turn** (§8) — greeting, thanks, goodbye, decline, acceptance.
4. **Account-specific guard** — existing: hand off, never send to the model.
5. **Exact FAQ** — existing.
6. **Answer cache**, then **semantic cache** (§10).
7. **Retrieval** (§9).
8. **No evidence** → scope rules: business guidance, one clarification, or handoff.
9. **Generation** through `LlmGateway` with a strict JSON schema (§4).
10. **Validation** (§5): parse, sanitise choices, reject ungrounded figures. One retry, then fallback — **and never charge for the fallback** (§A3).
11. **Decoration**: video link (§11), citations server-side only (§12), diagnostics row (§13).

## 4. Structured output and the reply contract

The model returns JSON, never prose. Free text is what lets Knowledge Base copy-paste and invented choices through.

Request `response_format` as a strict schema `smart_bot_reply`; on HTTP 400 for an unsupported schema retry once in plain JSON mode; if that fails extract the first balanced JSON object.

```json
{ "reply": "string", "quick_replies": ["string"], "response_type": "answer | clarification | fallback", "grounded": true }
```

`max_tokens: 320`, `temperature: 0.4` (0.2 with exact wording, §6).

**Choice rules** (sanitise server-side; never trust the model):
- 0, 2 or 3 choices — one is never useful.
- ≤ 60 characters, plain text, customer's language.
- Reject `< > [ ] { }`, control characters, `http:`, `https:`, `javascript:`, `data:`, `www.`.
- Deduplicate case-insensitively; ids assigned server-side (`qr_1`…).
- Choices send text only — never book, buy, cancel, pay or summon a human.
- Reply ≤ 70 words.
- **A yes/no question must carry choices**; recover them from the question sentence ("Would you like…", "Shall I…") and add Yes/No in the customer's language if the model forgets.
- Keep a numbered text fallback in `body` for channels without buttons: `"Which country will you be traveling to?\n\n1. Thailand\n2. USA\n3. Europe"`.

## 5. Grounding validation (after generation)

`grounded: true` is not trusted. Reject, retry once inside the same reservation, then fall back:

1. **Unsupported figures** — any price, currency amount, data size, percentage, duration or 2+ digit number in the reply **or a choice** that is absent from the evidence. Normalise first: Bengali/Arabic digits → Latin, `1,000` → `1000`, `1.20` → `1.2`. Units must match for sizes and percentages; currency and time may match a bare number ("৳128" vs "128tk"). Ignore single-digit step numbers.
2. **General guidance naming specifics** — with no evidence, no plans, package sizes, amounts, prices or product names.
3. **Empty or ungrounded factual claims.**

**Accept** a reply that only asks one short question even with `grounded: false` — it states no facts, and rejecting it turns follow-ups into handoffs. In clarification mode the model may answer instead of asking when passages clearly cover it; accept only with `grounded: true`.

## 6. Reply style: facts strict, wording free

- Prices, sizes, durations, menu paths, policies and countries come from evidence, the business profile, or the customer's own message. **History is never evidence.**
- Lead with what was asked; steps in the order the customer performs them; keep it short.
- Passages written as scripted dialogue show facts and flow, not text to copy — follow the flow in natural wording.
- Answer in the customer's language, including romanised forms.
- **Never shown:** editing notes and script markers (`[Shows two CTAs]`, `***`, `AI:`/`Customer:`), raw links pasted from passages, sources.
- **Add `kb_exact_wording`** (default false): approved wording verbatim at temperature 0.2. Production wording to reuse: *"Off: replies are written naturally while facts stay exact. On: replies keep your approved wording, for regulated or scripted answers."*

## 7. Starter questions

Up to **5** client-written questions with fixed answers, plus a switch. Shown at the top of the chat; tapping or typing one returns the saved answer instantly — no model call, no credits. Production label: *"Shown at the top of website chats. Tapping one sends your saved answer instantly — no AI credits used."*

**Schema** (additive on `ai_chatbots`): `starter_questions_enabled` boolean default false; `starter_questions` json — `{id: "sq_xxxxxxxx", question, answer}`.

**Service** `StarterQuestions`:
- `normalize()` — NFC, lowercase, replace `[^\p{L}\p{M}\p{N}]+` with a space, trim. **Keep combining marks** so Bengali words differing only by a vowel sign never collide. Do not reuse a hash helper that strips vowel signs.
- `match(bot, message)` — an empty normalised message never matches.
- `publicLabels(bot)` — `[{id, label}]`; answers never leave the server.
- `prepareForStorage()` — trim, normalise newlines, keep existing ids, mint `sq_` + 8 lowercase chars.

**Save validation:** max 5; question ≤ 80 chars with the choice character rules; answer ≤ 1000 chars, no control characters except newline/tab; reject questions normalising to empty, duplicates after normalising, and any containing a handover phrase (reuse `AutoReplyListener::HANDOVER_PHRASES`).

**Delivery:** labels in the widget config only while the bot is answering (AI on and inside its window — see §A4). Pin them under the welcome message; unlike quick replies they never go stale. Disable while sending, during pre-chat, and once a human owns the chat. Answer **synchronously** in the send request; everything else stays on the `ai` queue.

**Precedence:** paused/assigned/joined/handed-over chats skip the bot; keyword auto-replies and handover phrases first; starter answers only while the bot is answering.

## 8. Conversational turns, offers and closings

Deterministic, zero-credit, multilingual: greeting, thanks, closing, decline, acceptance. Track the bot's last question so a bare "yes"/"no" is understood and retrieval is skipped. A closing must work without goodbyes existing in the Knowledge Base. Match declines against **any** last question the bot asked, not only the standard "anything else?" offer — otherwise a decline after a content question silently costs a credit.

## 9. Retrieval quality

- **Score shaping:** `rank = semantic + min(0.18, lexical*0.12 + fuzzy*0.06)`.
- **Source authority:** add `ai_kb_documents.authoritative` (bool) and `priority` (0–100, default 50); weight `= (authoritative ? 0.06 : 0) + ((priority − 50)/50) * 0.04`. This is what makes an uploaded current document beat a stale public page (§A5).
- **Near-duplicate removal:** drop a passage whose word-set Jaccard overlap with a selected one is ≥ 0.75. With a 36-page sitemap crawl (§A1) this matters: service and portfolio pages repeat boilerplate.
- **Evidence labels:** prefix passages `Authoritative source: <title>` / `Source: <title>`; never shown to customers.
- **Cross-language search:** when the question is probably not English, translate to English **for searching only**, cache it, search both ways, answer in the customer's language. **Zero credits.** Then verify per environment (§A4) — this is the feature most likely to be silently off.
- **Clarification band:** clarification threshold = `max(0.38, answer_threshold − 0.20)`. Observed live scores ranged 0.43–0.75, so the band is doing real work.

## 10. Caches

- **Exact answer cache** — normalised question → answer, scoped to the active generation, 24 hours; never cache dialogue-specific choices.
- **Semantic cache** — cosine ≥ 0.92 against cached question embeddings, same generation.
- **Query embedding cache** — 7 days.
- Skip caching when evidence is time-sensitive (prices, availability, "today").

## 11. Video resources

Show a matched tutorial as a link — "▶ See Tutorial →" opening the provider page in a new tab — not an embedded player: no frame/media CSP demands on customer sites, and it works in the SDK.

- Detect video URLs while indexing; resolve titles via oEmbed; never use the document name as a title.
- Attach **only to the reply that delivers the solution**: never on a question-only turn, never in clarification mode, never on a reply whose text after a question mark carries choices.
- Any evidence passage may supply the video.
- Strip raw video links from the reply text; the platform renders the link.

## 12. What customers never receive

Sources/citations (kept on the stored message for staff and the API; sent as `citations: []`), confidence scores, internal ids, prompts, provider errors. `answer_origin` is allow-listed before leaving the server: `conversation`, `knowledge_base`, `business_guidance`, `trusted_research`, `live_product`, `starter_question`, `fallback`.

## 13. Diagnostics

Add `ai_kb_retrieval_diagnostics`: workspace, kb, chatbot, generation, decision (`answer|clarify|handoff`), cache source, intent, `answer_origin`, best score, passages used, completion tokens, credit result — one row per turn, including zero-credit paths. Without it, §A4's two failures are indistinguishable from "the AI is bad". Also record unanswered questions as knowledge gaps with counts; that list tells a client which page to write next.

## 14. Source quality review

Mirror §A1: after extraction, score each source and attach findings ("Review 5 findings"), with statuses *Ready / Needs review / Blocked / Failed* and a filter in the UI. A 36-page crawl will contain thin pages, navigation-only pages and contradictions; surfacing that is what keeps retrieval honest.

## 15. Tests (sqlite in-memory, `createWorkspaceContext()`, mirroring `tests/Feature/AI/GroundedAiAnsweringTest.php`)

- Choices: 2–3 only, ≤60 chars, HTML/URL/control rejected, ids server-side, yes/no question carries choices, reply ≤70 words, numbered fallback in `body`.
- Grounding: unseen price rejected; present price accepted; Bengali digits and `1,000` normalise; question-only reply accepted with `grounded:false`; general guidance naming a plan rejected.
- **Fallback costs nothing** — reservation refunded (matches §A3).
- Starter questions: validation (5 max, lengths, duplicates after normalising, emoji-only, handover phrase), id stability, tap and typed match return the answer with `answer_origin=starter_question` and **no provider call**, Bengali vowel-sign difference does not match, disabled → normal AI, labels only in config, answered without queueing.
- Turns: decline after the bot's own question ends warmly at zero credits; bare "yes" answers the last question.
- Retrieval: authoritative source outranks a contradicting one; duplicates collapse; non-English question retrieves via translation at zero credits and is answered in the customer's language.
- Caches: identical question served without a provider call; time-sensitive evidence not cached.
- Payload: no citations or confidence to customers; `answer_origin` allow-list enforced.
- Channel switch: with widget AI off, an inbound message produces **no** bot reply and is visible to agents (§A4).
- Tenancy: every new table and endpoint scoped by `workspace_id`.

## 16. Flags, config, rollout

Add to `config/ai.php` under `smart_bot`, defaulting **false**: `structured_output`, `grounding_validation`, `facts_strict_wording_free`, `starter_questions`, `conversational_turns`, `retrieval_authority`, `answer_cache`, `video_resources`.

Credits: one credit per finished generated answer; **embedding, search translation and provider tests are zero-credit**; the per-minute limiter applies only to features that cost credits, or indexing a large base locks a client out of their own bot; retries inside a reservation never double-charge; **fallbacks refund**.

Rollout: enable per flag on `spiderman`; verify against a real Knowledge Base **in a browser**, using §A5 as the script and §A4 as the trap list; then promote. Restart `ai` workers and refresh config cache. After each production deploy, re-run one non-English question — a flag left off looks exactly like an unsupported language.

## 17. Hard-won details

- A page contradicting the client's current facts gets quoted confidently. Authority weighting plus a visible "authoritative" flag fixes it; prompt wording does not.
- Repeated passages from a crawl crowd out the answer; deduplicate before spending the token budget.
- A customer's "no" after the bot's own offer is a conversation move, not an unsupported question.
- A video on every final answer feels spammy and is often wrong; attach it to the solution turn only.
- Showing sources "for trust" backfires — customers read a link list as the bot dodging.
- Quick replies that are not clickable are worse than none: render server-provided labels generically, never match hardcoded text.
- Indexing that quietly consumes credits exhausts the allowance and silently turns the bot into a handoff machine; keep indexing free and diagnose from diagnostics.
- Per-bot tuning columns drift from managed defaults (a live bot still carried 3 passages / 1200 tokens after the platform default moved to 5 / 1600). Decide which wins, in one place.
- A configured bot can still be silent because a channel switch is off (§A4). Make that state visible in the UI and in diagnostics, or support will chase a phantom AI bug.
