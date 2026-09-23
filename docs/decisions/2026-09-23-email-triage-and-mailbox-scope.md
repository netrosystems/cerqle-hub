# Decision: which emails the bot answers, and on which mailboxes

- Date: 2026-09-23
- Status: Accepted

## Context

AI automatic replies for email were all-or-nothing per workspace. Turning them
on meant every connected mailbox, so a client who wanted the bot on `support@`
but not on `billing@` or `careers@` could not have it, and the setting was
therefore left off.

A shared mailbox also receives far more than customer questions: newsletters,
receipts, delivery reports, alerts from other systems, and mail from addresses
that do not accept replies at all. `AiReplyEligibility::suppressed()` already
refused the clearly-declared cases — `List-Id`, `Precedence: bulk`,
`Auto-Submitted`, no-reply senders — but it returned a bare boolean. Nothing
could say *why* a message went unanswered, and the checks sat in the middle of
an unrelated class.

## Decision

**A mailbox scope on the existing setting, where NULL means all.** The column
is `workspace_ai_automation_settings.mailbox_ids`. NULL is exactly what the
setting already meant, so every workspace using the feature keeps its behaviour
with no backfill and no migration of intent. An empty array is a different
thing — deliberately none — and is rejected while the mode is on, because
"enabled, for nothing" looks enabled and silently never replies.

Mailbox ids are re-checked against the workspace on save rather than trusted
from the request: they decide whose mail a bot may answer.

**Triage is one service with a verdict, not a boolean.** `EmailTriage` returns
a category, whether to reply, whether it is confident, the signals it saw, and
a sentence an operator can read. Routing acts on it and the inbox displays it,
so the two can never disagree about why a message was skipped.

**Every signal is free and deterministic.** Headers the sending system set
about itself, the shape of the sender's address, and structural properties of
the body — link count, an unsubscribe link, a 1×1 tracking image. No model is
consulted, so a verdict costs nothing, never differs between two identical
messages, and can be explained in full.

**Prose is deliberately not judged.** Deciding "this reads like marketing" from
wording means a word list, which works in English and fails in every other
language a customer might write in — the same reasoning as the 2026-09-20
decision against shipped phrase tables. Counting links and reading headers
holds in any language.

**When the signals do not decide, the mail is answered.** `uncertain` replies
and is flagged in the inbox. Chosen with the product owner: a real customer
left waiting is worse than an occasional reply to a notification, and the
flagged row gives a human the chance to notice. The opposite bias — silence
unless confident — fails invisibly, which is the worse failure.

## Consequences

- Two new routing reasons, `mailbox_not_selected` and `email_not_an_inquiry`,
  so the inbox distinguishes "not switched on here" from "not a question".
- The verdict is written onto the message at ingestion. Messages synced before
  this have none and show no badge; a resync backfills them.
- Well-formed marketing that sets no list headers, has few links and no
  unsubscribe will still be answered. That is the accepted cost of refusing to
  read the words, and it lands on the safe side of the chosen bias.
- `suppressed()` keeps its signature and its non-email behaviour, so the seven
  callers and their tests were untouched; only the email branch now delegates.
