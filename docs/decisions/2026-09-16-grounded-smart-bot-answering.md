# Grounded Smart Bot answering

Date: 2026-09-16. Status: Accepted.

## Context

Cerqle's existing Smart Bot used semantic retrieval but could answer from broad general knowledge, indexed documents destructively, and injected recent ecommerce order details into prompts. A proposed Wisperbot behavior document supplied useful product concepts but referenced a different architecture and incorrectly treated notification availability as human presence.

## Decision

Keep Cerqle's enabled-bot safety gate, queue ownership, AI availability schedules, credit ledger and workspace boundaries. Add Business-only, Verified-only and General scopes. Existing bots migrate General; new bots default Business-only. Business facts require published Knowledge Base evidence in every scope. An incomplete Business-only profile saves successfully but behaves as Verified-only and shows a warning. Unsupported questions either clarify once then offer human help, or offer help immediately. Account/order-specific questions are human-only and ecommerce records are not injected into AI prompts.

Knowledge retrieval uses complete published generations. A failed pending build never replaces the active generation. Optional hybrid retrieval blends semantic, lexical and typo-tolerant evidence. URL, Sitemap, File and Text remain authoring sources; legacy FAQ data remains readable and can provide deterministic exact answers. Deterministic greetings and exact FAQ answers use no generation credit. Structured answer metadata is additive to existing string/API contracts and is sanitized before reaching a public widget.

`SMART_BOT_BUSINESS_AWARE_ROUTING` and `KB_HYBRID_RETRIEVAL_ENABLED` default false for controlled rollout. No live external research is performed. Human identity/presence is a separate future feature; notification availability must not be used as presence.

## Consequences

Production needs the additive migration and a full Knowledge Base reindex before both flags are enabled. Existing active content is backfilled as a legacy generation. Qdrant may fall back to MySQL until new generation-aware vectors are built. Real provider responses, multilingual fallbacks, large Knowledge Bases and concurrent Redis workers still require designated release verification.
