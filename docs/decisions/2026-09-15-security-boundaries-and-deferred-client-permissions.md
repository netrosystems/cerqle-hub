# Unified security boundaries and deferred client permissions

Date: 2026-09-15

Status: Accepted

## Context

The owner requested fixes for the repository security concerns without breaking valid product workflows. The owner explicitly excluded the finding that `view_clients` also permits client impersonation and plan assignment during pre-release. Implementation does not authorize production deployment or provider testing.

## Decision

Centralize active-account and confirmed-MFA login safeguards. Require explicit full-agent token/session authority for token management and unscoped agent operations while retaining developer resource scopes. Verify Firebase signatures and exact project claims, keep MFA secrets out of generic serialization, and prevent lower-tier admin role escalation.

Treat unsigned widget profile metadata as untrusted display information, not customer authentication. Derive stored upload extensions from file contents. Protect caller-controlled outbound HTTP with public-destination checks, connection pinning, TLS verification, redirect/body limits and bounded sitemap expansion. Preserve legitimate public custom ports and credential-free page redirects.

Use expiring actor/workspace/store-bound single-use capabilities for Woo server callbacks, and validate reconnect credentials before replacing working credentials. Create private backups without shell interpolation and retain local artifacts unless an upload succeeds.

Keep `view_clients` impersonation and plan-assignment authority unchanged until separately approved. Preserve the owner's configuration-controlled licensing decision; no license flag, environment or subscription enforcement changes are authorized here.

## Consequences

Native mobile clients must handle the MFA-required 202 response before this change is released to 2FA-enabled users. Old pending Woo callbacks must restart onboarding; connected stores remain intact. Private/unsafe callback URLs and caller-selected knowledge-base file paths are intentionally rejected, not compatibility promises.

The additive migration must be validated on the production MySQL version before approved deployment. Real OAuth/provider, proxy/egress, legacy uploads and backup restore checks remain necessary. The deferred client permission is a known pre-release risk, not a resolved finding. Regression tests establish tested behavior, not a guarantee against every vulnerability or future regression.
