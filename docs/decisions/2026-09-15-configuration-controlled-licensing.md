# Configuration-controlled licensing

Date: 2026-09-15
Status: Accepted

## Context

The owner requested restoration of the previous system behavior. The recent local-only opt-out caused production to require activation despite its existing `LICENSE_VERIFY=false` configuration and absent activation file.

## Decision

Respect `LICENSE_VERIFY` in every environment. Default remains true; explicit false disables license verification. Preserve activation files, provider configuration and all unrelated access controls. This supersedes the local-only enforcement introduced on 2026-09-15.

## Consequences

Operators control whether an installation verifies licensing. When true and configured, missing activation still blocks access. Configuration changes require cache rebuilding; deployments must preserve the private environment file. No credentials or activation files are committed.
