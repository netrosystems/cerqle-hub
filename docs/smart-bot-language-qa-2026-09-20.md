# Smart Bot language and continuity QA

Date: 2026-09-20 (work continued 2026-09-21)
Environment: local working tree on branch `oris`, SQLite. Not committed, not promoted, not deployed.

## Implemented scope

Steps 1–5 and 7 of the approved plan. Steps 6 (structured output, grounding validation, refunds), 8 (starter questions, exact wording) and 9 (answer caches) are **not** built.

- Shared zero-credit query embedding; evidence ranking with source authority, near-duplicate removal and a context budget; knowledge-base ownership verified at the retrieval boundary.
- Machine-readable reason codes for all seven routing skips; six distinct widget availability reasons including the previously swallowed schedule error; `ai_answer_diagnostics`, `ai_knowledge_gaps` and a retention command.
- Language-agnostic intent from English exemplars in a multilingual embedding space; phrases translated once per language and cached globally; conversation language stored from a model-reported tag; `LlmGateway::chatUnmetered()` for zero-credit infrastructure calls.
- Handover reachable in any language by three tiers; choices carrying server-assigned roles; the widget's English `person` test removed.
- Numbered choice lists on WhatsApp, Messenger, Instagram and email; ordinal replies read in any digit system.
- Holding reply when a handover lands with nobody available; the bot keeps answering until a human actually replies.

## Evidence

| ID | Result type | Check | Result |
| --- | --- | --- | --- |
| SBL-01 | Automated | Full backend regression **before** any behaviour was added, proving the foundation step is inert | Pass: 1,027 passed, matching the branch baseline exactly |
| SBL-02 | Automated | Full backend regression after steps 1–5 and 7, every new flag false | Pass: 1,069 passed / 4,389 assertions |
| SBL-03 | Automated | Language mechanism: intent from vectors with no word list, long/questioning/numeric messages never chit-chat, yes-no only after a question, degradation to English when no embedding provider, phrase learned once then cached, English seed on failure, markup-like translations rejected, English needs no call, billable feature cannot use the free path | Pass: `SmartBotLanguageTest`, 14 tests |
| SBL-04 | Automated | Reaching a human: English phrases still work, a role-tagged choice works in any language, a numbered reply works on messaging channels, other digit systems resolve, ordinary messages are not mistaken for choices, stale offers expire, models cannot mint a handoff choice | Pass: `SmartBotHandoverTest`, 11 tests |
| SBL-05 | Automated | No-agent experience: acknowledgement at zero credits, client `offline_message` verbatim, nothing sent while open, bot continues until a human replies, explicit assignment always silences it, flag off restores today's behaviour | Pass: `NoAgentAvailableTest`, 6 tests |
| SBL-06 | Automated | Explainability: handover and human-owned reasons recorded, missing widget distinguished from a disabled switch, each availability failure named, `available()` derived from `reason()` | Pass: `AiRoutingReasonTest`, 5 tests |
| SBL-07 | Automated | Diagnostics: one row per zero-credit turn, workspace scoping, knowledge gaps counted, conversational turns excluded, flag-off writes nothing, pruning works | Pass: `SmartBotDiagnosticsTest`, 6 tests |
| SBL-08 | Automated | Frontend regression | Pass: 183 tests / 33 files |
| SBL-09 | Build | Production asset build; widget JavaScript parses | Pass |
| SBL-10 | Static analysis | PHPStan over every new service | Pass: no findings. Repository-wide analysis has a large pre-existing backlog and is **not** claimed clean. |
| SBL-11 | Style | Pint on changed files | Pass. Repository-wide Pint backlog unchanged and not claimed clean. |
| SBL-12 | Migration | Four additive migrations applied locally on SQLite | Pass |
| SBL-13 | Not tested | Real provider behaviour: whether a genuine multilingual embedding separates these intents at the chosen threshold, and whether generated phrases read naturally to a native speaker | **Release gate** |
| SBL-14 | Not tested | Browser verification against a real Knowledge Base with the flags enabled | **Release gate** |
| SBL-15 | Not tested | Concurrent Redis workers, Redis cache store, large Knowledge Base | **Release gate** |

## Honest limitations

The automated tests use controlled vectors, so they prove the **mechanism** — that no word list is consulted, that guards hold, that a phrase is learned once and then free, that every path degrades safely. They do **not** prove that a real embedding model separates these intents well in any particular language. That needs real traffic, which is why `intent`, `intent_score` and `intent_method` are recorded on every turn: the threshold is meant to be tuned from that distribution, and no accuracy figure is claimed here.

Romanised text — Latin script, non-English language — is the weakest case for cross-lingual embeddings and was the observed production failure in the sibling product. Expect to tune it first.

One test fails in this working tree: `Tests\Feature\Campaign\WhatsappWebhookTest > failed status records provider error`, with a messaging-channel plan-limit error from `ChannelPlanLimitService`. It fails identically on unmodified `main` and is unrelated to this work.

## Release gate

Apply the four migrations, leave every flag false, then enable one at a time and verify in a browser against a real Knowledge Base: a message in a language nobody planned for answered in that language, with the phrase cache warm on the second turn; the same customer reaching a human; a numbered list arriving on WhatsApp and "2" selecting the right option; a 3am handover acknowledged and the bot still helping afterwards; each availability failure showing its own reason in the inbox. Restart `ai` workers and refresh the configuration cache on deploy. Schedule `ai:prune-diagnostics` before this runs at volume.
