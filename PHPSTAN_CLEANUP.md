# PHPStan cleanup checkpoint — 2026-09-07

## Status

Local analysis at level 6 has decreased from **1,671** findings to **578**. This is not yet a passing analysis run. No new baseline suppressions, analysis exclusions, or lower severity levels were introduced. Production has not been deployed and `main` has not been merged as part of this cleanup.

The original count included incomplete Laravel schema/type information as well as actionable code issues; it did not represent 1,671 confirmed runtime defects.

## Completed

- Added module migration discovery alongside the main migrations.
- Enabled analysis of Laravel `casts()` methods, so JSON and date fields use their actual runtime types.
- Added related-model/declaring-model contracts to 112 direct relationship declarations, plus previously untyped automation, social, and conversation relations.
- Removed 581 baseline entries confirmed absent after correcting schema/type inference. Existing count mismatches remain visible for investigation.
- Documented heterogeneous automation node data, context, and output contracts without changing execution behavior.
- Fixed missing Messenger user-token availability during the second, Page-selection request. The token stays in the server session; missing legacy credentials fail closed.
- Added validated reconstruction of stored AI results. Ledger IDs are authoritative; malformed stored results fail explicitly.
- Fixed the AI rate limiter's invalid eager-loading of `Client::activePlan()` as a relationship.
- Replaced late-static calls to private helpers with class-bound calls.
- Added regression tests for saved AI results, Messenger selection, and plan-derived AI rate limits.

## Remaining findings

| Category | Count | Meaning / approach |
| --- | ---: | --- |
| Missing array element types | 286 | Specify real payload/list shapes, especially provider responses and billing contracts. |
| Unresolved generic arguments | 72 | Trace collection inputs and model/query result types. |
| Unrecognized properties | 37 | Inspect aggregate aliases, model resources, and pivot metadata. |
| Redundant nullsafe operations | 33 | Verify declared nullability before removing defensive branches. |
| Redundant null-coalescing offsets | 30 | Check array contracts against runtime inputs before simplifying. |
| Baseline occurrence-count mismatches | 16 | Reconcile only confirmed obsolete allowances; do not increase counts to hide new findings. |
| Missing generic model/collection types | 15 | Specify the actual model and collection key/value types. |
| Argument type mismatches | 12 | Inspect calls and external library contracts, adding regression tests for runtime changes. |
| Other findings | 77 | Return contracts, unused state, configuration access, and control-flow checks. |

## Remaining work order

1. Billing/provider return contracts and webhook payload types; preserve signatures, idempotency, and subscription transitions.
2. Aggregated report rows, resources, and workspace/pivot collections.
3. Stream request bodies and integration credential return types.
4. Remaining control-flow/nullability findings after contracts are trustworthy.
5. Reconcile baseline count mismatches, then rerun the entire backend suite, frontend suite, production build, changed-file formatting, and PHPStan.

Do not merge to `main` or deploy on the strength of unit tests alone while the required analysis check still fails. Provider-side behavior requires separate live verification; mocked Meta tests do not prove live OAuth works.

## Reproduction

```bash
./vendor/bin/phpstan analyse --memory-limit=1G --error-format=json --no-progress
php artisan test --compact
./vendor/bin/pint --dirty --test
```

The JSON report for this local checkpoint is `/tmp/cerqle-phpstan-checkpoint.json`; temporary files may be removed by the operating system. No credentials or customer data are included in this document.
