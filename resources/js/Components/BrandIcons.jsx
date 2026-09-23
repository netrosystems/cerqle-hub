/**
 * Brand SVG paths are from the Simple Icons project (CC0 / public domain).
 * https://github.com/simple-icons/simple-icons — follow each vendor’s brand guidelines in production.
 */
import brandIconData from './brandIconData.json';
import { Smartphone } from 'lucide-react';

/** Human-readable labels for inbox / campaign channel keys */
export const CHANNEL_LABELS = {
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    messenger: 'Messenger',
    sms: 'SMS',
    email: 'Email',
    webchat: 'Website',
};

function SvgBrand({ name, className }) {
    const data = brandIconData[name];
    if (!data) return null;
    const useCurrentColor = name === 'twitter' || name === 'tiktok';
    const fill = useCurrentColor ? 'currentColor' : `#${data.hex}`;
    return (
        <svg
            role="img"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            className={[
                className ?? 'h-4 w-4',
                useCurrentColor ? 'text-neutral-900 dark:text-neutral-100' : '',
            ]
                .filter(Boolean)
                .join(' ')}
            aria-hidden
        >
            <path fill={fill} d={data.path} />
        </svg>
    );
}

/** WhatsApp, Instagram, Messenger, SMS (Twilio mark), Email (envelope icon), Website (chat bubble) */
export function ChannelBrandIcon({ channel, className }) {
    // The website live-chat channel has no vendor logo — render a brand-tinted
    // chat bubble so it reads as "Website" in the inbox filters and headers.
    if (channel === 'webchat') {
        return (
            <svg viewBox="0 0 24 24" className={className ?? 'h-4 w-4'} fill="#ff762e" aria-hidden>
                <path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.2 1 4.3 2.6 5.8-.1 1-.5 2.4-1.4 3.4 1.5-.2 3.2-.8 4.3-1.6 1.4.6 2.9.9 4.5.9 5.5 0 10-3.9 10-8.7S17.5 3 12 3z" />
            </svg>
        );
    }

    const key =
        channel === 'whatsapp'
            ? 'whatsapp'
            : channel === 'instagram'
              ? 'instagram'
              : channel === 'messenger'
                ? 'messenger'
                : channel === 'sms'
                  ? 'sms'
                  : channel === 'email'
                    ? 'email'
                    : null;
    if (!key) return null;
    return <SvgBrand name={key} className={className} />;
}

export function ConversationChannelIcon({ conversation, className }) {
    const channel = conversation?.channel_account?.channel ?? conversation?.channel ?? 'webchat';
    if (channel === 'webchat' && conversation?.started_from === 'customer_sdk') {
        return <Smartphone className={className ?? 'h-4 w-4'} aria-hidden />;
    }

    return <ChannelBrandIcon channel={channel} className={className} />;
}

/** Facebook, Instagram, LinkedIn, X, YouTube, TikTok */
export function SocialBrandIcon({ network, className }) {
    const map = {
        facebook: 'facebook',
        instagram: 'instagram',
        linkedin: 'linkedin',
        linkedin_page: 'linkedin',
        twitter: 'twitter',
        youtube: 'youtube',
        tiktok: 'tiktok',
    };
    const name = map[network];
    if (!name) return null;
    return <SvgBrand name={name} className={className} />;
}

/**
 * Resolve a free-text integration name (e.g. "WhatsApp Business", "Google Sheets")
 * to a brand key in brandIconData.json, or null when no official logo exists.
 */
const BRAND_ALIASES = {
    whatsappbusiness: 'whatsapp',
    facebookmessenger: 'messenger',
    instagramdirect: 'instagram',
    instagramdm: 'instagram',
    smscampaigns: 'sms',
    anthropicclaude: 'anthropic',
    claude: 'anthropic',
    gemini: 'googlegemini',
    googlesheet: 'googlesheets',
    x: 'twitter',
    xtwitter: 'twitter',
    twitterx: 'twitter',
};

export function resolveBrandKey(name) {
    const norm = (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    const key = BRAND_ALIASES[norm] || norm;
    return brandIconData[key] ? key : null;
}

/**
 * Logo tile for an integration. Renders the official brand SVG (on a white tile)
 * when one is available, otherwise a clean monogram fallback. Use for integration
 * grids / logo strips.
 */
export function BrandMark({ name, tileClassName = 'h-10 w-10 rounded-xl', glyphClassName = 'h-6 w-6', monogramClassName = 'text-sm' }) {
    const key = resolveBrandKey(name);
    const tile = `inline-flex items-center justify-center bg-white ring-1 ring-neutral-200 dark:ring-neutral-700 ${tileClassName}`;
    if (key) {
        return (
            <span className={tile} aria-label={name}>
                <SvgBrand name={key} className={glyphClassName} />
            </span>
        );
    }
    const letter = (name || '?').trim().charAt(0).toUpperCase();
    return (
        <span className={`${tile} font-bold text-neutral-700 ${monogramClassName}`} aria-label={name}>
            {letter}
        </span>
    );
}
