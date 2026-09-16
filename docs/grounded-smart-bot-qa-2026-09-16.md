# Grounded Smart Bot and Knowledge Base QA

Date: 2026-09-16
Environment: local `spiderman` working tree; not promoted or deployed.

## Implemented scope

- Business-only, Verified-only and General answer scopes, with existing-bot General migration and new-bot Business-only default.
- Safe downgrade for incomplete business profiles; configurable clarify-once or immediate-handoff fallback.
- Account/order-specific requests offer human help and no longer receive ecommerce data in model context.
- Structured answer origin, response mode, citations, quick replies and handoff metadata while preserving plain-text callers.
- Hybrid semantic/lexical/fuzzy retrieval, generation-aware MySQL/Qdrant filtering and atomic Knowledge Base publication.
- URL, Sitemap, File and Text authoring; legacy FAQ reading and deterministic exact answers.
- Compact Smart Bot and Knowledge Base profile UI plus public-widget quick-reply controls.
- Rollout flags default off; approved-source live research and human presence/identity are excluded.

## Evidence

| ID | Result type | Check | Result |
| --- | --- | --- | --- |
| GAI-01 | Automated/mocked | Deterministic greeting does not reserve customer credit | Pass |
| GAI-02 | Automated/mocked | Account/order request returns handoff without provider generation | Pass |
| GAI-03 | Automated/mocked | Incomplete Business-only profile downgrades; one clarification then handoff | Pass |
| GAI-04 | Automated/mocked | Exact legacy FAQ returns grounded answer, citation and zero generation credit | Pass |
| GAI-05 | Automated/mocked | Failed reindex preserves active generation and clears pending state | Pass |
| GAI-06 | Automated/mocked | Public widget payload includes only sanitized answer controls | Pass |
| GAI-07 | Automated/mocked | Full backend regression, including AI, grouped automation, widget availability, tenancy and Qdrant | Pass: 1,015 tests / 4,234 assertions |
| GAI-08 | Build | Production frontend compilation | Pass |
| GAI-08A | Automated | Frontend regression | Pass: 183 tests / 33 files |
| GAI-08B | Static analysis | Changed backend surface | Pass: no findings; repository-wide historical backlog not claimed clean |
| GAI-08C | Cache | Route and configuration cache build/clear | Pass |
| GAI-09 | Not tested | Real model answer quality, multilingual clarification and provider failure behavior | Release gate |
| GAI-10 | Not tested | Large Knowledge Base/Qdrant reindex and concurrent Redis workers | Release gate |
| GAI-11 | Not tested | Designated website, messaging and email recipient delivery | Release gate |

## Release gate

Apply the migration, leave both rollout flags false, reindex designated Knowledge Bases, verify generation counts and provider responses, then enable hybrid retrieval before business-aware routing. Do not claim real delivery from mocked tests. Production promotion and deployment require separate approval.
