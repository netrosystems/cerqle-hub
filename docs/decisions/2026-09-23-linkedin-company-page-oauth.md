# Decision: LinkedIn company pages are a separate network

- Date: 2026-09-23
- Status: Accepted

## Context

Clients could connect a LinkedIn **member profile** only. `LinkedInDriver`
posted with a hardcoded `urn:li:person:` author, and the OAuth flow requested
`w_member_social`, which authorises posting as a person and nothing else.

LinkedIn does not expose company-page posting through those scopes. Posting as
an organisation needs `w_organization_social` and, to discover which pages a
member may post to, `r_organization_admin`. Both belong to the **Community
Management API**, a product LinkedIn approves by manual review and normally
against an app that has been verified against a company page.

Two shapes were possible: one `linkedin` network carrying a type flag, or two
networks.

## Decision

**A company page is its own network, `linkedin_page`, with its own operator
credentials under `oauth_linkedin_page`.**

The deciding constraint is credentials, not modelling taste.
`CredentialResolver::oauth()` resolves `oauth_{network}`, so one network can
only ever have one client id. Because the member product and the Community
Management API are usually approved against different apps, a single network
would have forced a special case in the resolver to pick a second credential
set by inspecting a flag on the account — the same branch, in a worse place.
Two networks make the operator's two configuration cards fall out of the
existing design rather than being bolted onto it.

**One authorisation connects every page that member administers.** The callback
calls `organizationAcls?q=roleAssignee&role=ADMINISTRATOR` and upserts one
`SocialAccount` per page, mirroring how a Meta login yields several Facebook
Pages. The unique key is already `(workspace_id, network, account_id)`, so a
profile and any number of pages coexist without schema change beyond widening
the `network` enum.

**The two drivers share the media pipeline.** `LinkedInPageDriver` differs from
`LinkedInDriver` in one field — the author URN — so the register/upload/poll
asset sequence is reused via `LinkedInDriver::uploadAssetFor()` rather than
copied. Two copies of asset-status polling would drift.

**The member description was corrected.** It claimed "profile or company page",
which was never true and is what made the gap invisible.

## Consequences

- Operators configure **two** LinkedIn cards. They may hold the same client id
  and secret when a single app carries both products; nothing forces two apps.
- A missing Community Management API approval is now a named error at connect
  time rather than a generic failure, and "you administer no pages" — the most
  common cause — is reported before anything about setup.
- Every place mapping a network key needed the new value: char limits, labels,
  previews, icons, refreshable networks, the composer and the posts filter. A
  future network will need the same sweep; that list is the real cost of the
  string-keyed network map, and it is not addressed here.
- `down()` deletes `linkedin_page` rows rather than relabelling them, because a
  member-profile token cannot post as an organisation and a silently
  relabelled row would be a destination that fails only at publish time.
