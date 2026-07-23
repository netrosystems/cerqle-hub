import { useState } from 'react';
import { Check, ChevronDown, Copy, UserCheck, ShieldCheck } from 'lucide-react';

function CopyBox({ code, label }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        const done = () => { setCopied(true); setTimeout(() => setCopied(false), 2000); };
        if (navigator.clipboard?.writeText) navigator.clipboard.writeText(code).then(done, done);
        else done();
    };
    return (
        <div className="relative">
            {label && <p className="mb-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</p>}
            <pre className="overflow-x-auto rounded-lg bg-neutral-950 p-3.5 text-[12px] leading-relaxed font-mono text-neutral-200 whitespace-pre">{code}</pre>
            <button type="button" onClick={copy} className="absolute right-2 top-2 flex items-center gap-1 rounded-md bg-white/15 px-2.5 py-1.5 text-[11px] font-semibold text-white hover:bg-white/25 transition">
                {copied ? <><Check className="h-3 w-3" /> Copied</> : <><Copy className="h-3 w-3" /> Copy code</>}
            </button>
        </div>
    );
}

/**
 * Identity passthrough setup: shows the client how to hand the widget a logged-in
 * customer's details so agents see who they're talking to — with the optional
 * HMAC signing snippet when verification is on.
 */
export default function IdentityCard({ embedBase, widgetKey, identitySecret, verification }) {
    const [expanded, setExpanded] = useState(false);
    const basic =
`<!-- Before the widget script, set your logged-in user (skip fields you don't have) -->
<script>
  window.CerqleSettings = {
    name: "Jane Doe",
    email: "jane@example.com",
    avatar: "https://your-site.com/avatars/jane.jpg",
    external_id: "USER_123"${verification ? ',\n    user_hash: "GENERATED_ON_YOUR_SERVER"' : ''}
  };
</script>
<script src="${embedBase}/widgets/chat/${widgetKey}.js" async></script>`;

    const phpSign =
`// On YOUR server, per request (never expose the secret in the browser):
$user_hash = hash_hmac('sha256', (string) $user->id, '${identitySecret}');`;

    const nodeSign =
`// Node.js
const crypto = require('crypto');
const userHash = crypto
  .createHmac('sha256', '${identitySecret}')
  .update(String(userId))
  .digest('hex');`;

    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-5">
            <h3 className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                <UserCheck className="h-4 w-4 text-brand-500" /> Show logged-in customers to your agents
            </h3>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Use this only if your website has logged-in users and you want your agents to see their name, email or photo automatically. Otherwise, you can leave it closed.
            </p>

            <button
                type="button"
                onClick={() => setExpanded((value) => !value)}
                className="mt-4 inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                aria-expanded={expanded}
            >
                {expanded ? 'Hide developer setup' : 'Set up logged-in visitor details'}
                <ChevronDown className={`h-4 w-4 transition-transform ${expanded ? 'rotate-180' : ''}`} />
            </button>

            {expanded && (
                <div className="mt-4">
                    <CopyBox code={basic} label="1. Set the visitor identity" />

                    {verification && (
                        <div className="mt-4 space-y-3">
                            <div className="flex items-start gap-2 rounded-lg bg-brand-500/10 border border-brand-500/20 px-3 py-2 text-xs text-brand-700 dark:text-brand-300">
                                <ShieldCheck className="h-4 w-4 flex-shrink-0" />
                                <span>Verification is <b>on</b>: generate <code>user_hash</code> on your server with your secret and include it above. Unsigned identities are treated as anonymous.</span>
                            </div>
                            <CopyBox code={phpSign} label="2a. Sign the user id (PHP)" />
                            <CopyBox code={nodeSign} label="2b. Sign the user id (Node.js)" />
                            <div>
                                <p className="mb-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">Your widget secret (keep it server-side)</p>
                                <CopyBox code={identitySecret} />
                            </div>
                        </div>
                    )}

                    {!verification && (
                        <p className="mt-3 text-xs text-neutral-400">
                            Tip: turn on <b>identity verification</b> below so visitors can't impersonate other customers — you'll then sign the id with your secret.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
