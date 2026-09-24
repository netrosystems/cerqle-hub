# X under standard social publishing limits

Date: 2026-09-15. Status: Accepted.

## Context

The owner selected client-owned X accounts with one admin-funded app, text/images/video,
and no published text links. A separate X allowance was rejected in favor of the
existing social plan model. Provider costs still include operations beyond create.

## Decision

- Display X, retain database-compatible `twitter` and system `oauth_twitter`.
- Use existing social account/post limits; no X-specific allowance or billing UI.
- OAuth uses confidential-client PKCE, offline access and exact sender identity.
- Reject links, enforce weighted text and conservative inspected media limits.
- Persist resumable media stages and pin destination payloads. Ambiguous creates
  require explicit review, not automatic replay. Review verifies author ownership
  or acknowledges duplicate risk and retains prior attempt evidence encrypted.
- Do not add engagement, analytics, threads, Premium long posts or remote edit/delete.

## Consequences

The shared app owner must fund X credits and set a provider spending cap. API
exhaustion may stop all connected clients; existing Cerqle limits are not a dollar
budget. Video requires ffprobe on workers. Unit/mocked success does not establish
provider access or actual posting. Production deployment/submission is separate.

## Amendment (2026-09-24): media caps

X bills API use per request and each attachment is its own upload, so Cerqle
caps X posts below what X allows: text, up to **three** JPEG/PNG images, or
**one** video or animated GIF on its own. GIFs were previously refused; they
now upload as `tweet_gif` (15 MB). The caps are constants on
`XContentValidator` (`MAX_IMAGES`, `MAX_MOTION`) with a mirror in
`resources/js/Components/Social/xText.js`. The composer warns when shared media
would break them, before the post is saved. Posts already scheduled with four
images fail at publish time with a message saying what to change. X's 402
(no API credits) is now reported as a credits problem rather than a bare status.

