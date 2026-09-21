# Language-agnostic Smart Bot behaviour

Date: 2026-09-20. Status: Accepted.

Partially supersedes nothing; it extends `2026-09-16-grounded-smart-bot-answering.md`, whose scopes, generations, credit model and flag discipline all still stand.

## Context

A behaviour specification from the sibling product (WisperBot) described grounded answers, tappable choices, client-written starter answers and deterministic zero-credit shortcuts. Its worked examples name Bangla and romanised Bangla throughout, as if those were the product's languages.

They are not. Cerqle's customers may write in anything, so a design that ships phrase tables for a chosen set of languages is wrong for every customer outside that set — and silently wrong, because a missing language looks exactly like a bot that cannot answer.

Inspection of the running code found the same parochialism already present and worse than the specification assumed:

- Every deterministic, zero-credit path emitted hardcoded English, and the greeting matcher was an English regex.
- **A customer could only reach a human by typing English.** The handover list was eleven English substrings and the widget decided a button meant "handoff" by testing for the word `person`.
- Quick replies were silently dropped on WhatsApp, Messenger, Instagram and email, so those customers saw questions with no options at all.
- After any handover the bot fell permanently silent, including outside working hours when nobody would answer for hours.
- Five distinct reasons for a silent bot collapsed into one boolean, one of them a swallowed exception, and all seven routing skips recorded a null reason that nothing read.

## Decision

**No shipped phrase tables and no language list anywhere.** Conversational intent is recognised by comparing the message embedding against a small set of *English exemplars*. The embedding model is multilingual, so a seed such as "thank you" recalls its equivalents in scripts nobody enumerated. These are semantic seeds, not translations.

**Phrases are learned once, then free.** A fixed set of short English strings is translated on first use in a given language and cached for 90 days. The first customer writing in a language pays a few hundred milliseconds; everyone after them, in any workspace, is served from cache.

**That phrase cache is global; the caches holding customer text are not.** A cached phrase is a translation of a Cerqle-authored constant keyed by a language tag, derived from no tenant's data, so sharing it leaks nothing and is exactly what makes the second tenant's turn free. Query embeddings, search translations and choice-label vectors *are* derived from customer text and remain workspace-scoped.

**The language is named by the model, not by a detector.** The structured reply contract carries a BCP-47 tag, which is stored on the conversation so later deterministic turns are cache hits. Before any model reply the tag is simply unknown, which is a normal state: the phrase generator is shown the customer's own message instead, so even the first turn comes out in the right language. A Unicode *script* property is used only as a cache key, never to choose a reply language.

**Everything degrades instead of failing.** No embedding provider means intent classification stands down and the existing English matching applies. A failed or suspicious translation yields the English seed. Neither ever costs a customer their reply, and both are recorded.

**Zero-credit work is explicit.** Translating a question for search and rendering a UI phrase go through `LlmGateway::chatUnmetered()`, which writes an `AiRun` and no credit reservation — the same pattern `embed()` already used. Two independent guards (an allow-list and a zero rate) plus a token cap and its own rate limiter prevent it becoming a free general-purpose model.

**A customer can reach a human in any language**, by three means: the existing English phrases, picking the handoff choice the bot itself offered (which carries a server-assigned role rather than English wording), or asking in their own words. The role also fixes the widget, which no longer inspects label text.

**Choices appear on every channel.** Channels without buttons receive a numbered list in the message body, capped at three, and a reply of "2" — in any digit system — selects the same option.

**A handover into an empty room is acknowledged.** When nobody is available the handover still happens and still notifies, and the customer additionally receives one zero-credit message in their language saying the team has it and when they are back. The client's own `offline_message` wins verbatim where set. Availability is read from configured business hours and active membership only: **notification availability is still not used as presence**, per the 2026-09-16 decision.

**The bot keeps helping until a person actually replies.** Behind a flag, a pending handover with no human reply and nobody available no longer silences the bot; it stands down the moment a human sends the first message.

**Every silent turn can explain itself.** Routing skips carry a machine-readable `reason_code`, widget availability reports which of six causes applied, and `available()` is derived from that reason so the two cannot drift. One `ai_answer_diagnostics` row is written per turn including free ones, and unanswered questions accumulate as knowledge gaps.

All switches default false.

## Consequences

Cross-lingual embedding alignment is the main technical risk, and romanised text — Latin script, non-English language — is its weakest case, which is precisely the failure observed in the sibling product's production account. Mitigations are the similarity margin, length and punctuation guards, and an English-matching floor; a misclassification degrades to asking the model, which is correct but paid rather than wrong. Thresholds must be tuned from the recorded distribution, and no accuracy figure is claimed.

The phrase mechanism costs one short provider call per language per phrase, platform-wide, and nothing thereafter. Callers must accept the English seed as a legitimate answer.

`ai_answer_diagnostics` grows with traffic and needs `ai:prune-diagnostics` scheduled before volume.

Deliberately excluded: video tutorial links and source quality review from the specification, and widget chrome translation — the embedded widget has no i18n and its public config has no conversation from which to derive a language. None of these block reaching a human.

**Added 2026-09-21 — grounded structured answers.** The model is now asked for a fixed JSON object rather than prose, because free text is what let Knowledge Base wording be pasted back verbatim and let the model invent its own buttons. The request degrades schema → JSON mode → plain text inside a single credit reservation, so a provider that cannot enforce a schema still answers and is still charged once. Every figure in the reply or in a choice is checked against the evidence after normalising digit systems, thousands separators and trailing zeros; units must match for sizes and percentages, while currency and time may match a bare number. A reply that only asks one short question is accepted even when the model reports it as ungrounded, because rejecting those turns legitimate follow-ups into handoffs. A rejected reply is retried once inside the same reservation and, if it fails again, the credit is handed back and the customer receives the fallback — **a client pays for answers, not for attempts**. The contract also carries the customer's language, which is what makes later deterministic turns free.

Still outstanding at the time of writing: starter questions, exact wording and answer caches. `ai_kb_chunks` still carries no `workspace_id`; retrieval now verifies knowledge-base ownership at the boundary instead, and the column remains separate work.
