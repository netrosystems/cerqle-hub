# Admin-managed notification availability

Date: 2026-09-13
Status: Accepted

## Context

The user corrected schedule ownership after the initial personal-settings release: only administrators should set availability; teammates must not edit it.

## Decision

Client administrators manage each assigned teammate's availability independently for each workspace from Team. Teammates can read their own schedule only. Personal browser/Sanctum PATCH endpoints require client-administrator status; admin Team endpoints require matching client identity and target membership/ownership. Workspace staff/admin role alone does not grant organization-wide Team management. Administrators can set their own schedule too.

Preserve all stored schedules, revisions and delivery behavior. Existing notification preference controls are unaffected; only availability editing changes. No new database migration or production schedule changes.

## Consequences

Supersedes the self-edit ownership rule in the previous personal availability decision. Read APIs expose `can_edit`; unauthorized writes return 403, foreign or unassigned targets return 404. Production deployment requires separate approval.
