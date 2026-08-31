import { useEffect, useMemo, useState } from 'react';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import MediaUpload from '@/Components/MediaUpload';
import YouTubeVideoSettings, { DEFAULT_YOUTUBE_OPTIONS } from '@/Components/YouTubeVideoSettings';

const LABELS = { youtube: 'YouTube', tiktok: 'TikTok', instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn' };
const LIMITS = { youtube: 5000, tiktok: 2200, instagram: 2200, facebook: 63206, linkedin: 3000 };

const defaultTikTokOptions = {
    privacy_level: '', allow_comment: false, allow_duet: false, allow_stitch: false,
    video_cover_timestamp_ms: 0, brand_content: false, brand_organic: false,
    is_aigc: false, consent: false,
};

function initialPayload(network) {
    return {
        customize: false,
        title: '',
        body: '',
        media_urls: [''],
        media_ids: [],
        account_options: {},
        options: network === 'youtube' ? { ...DEFAULT_YOUTUBE_OPTIONS } : network === 'tiktok' ? { ...defaultTikTokOptions } : {},
    };
}

export default function SocialPlatformOverrides({ networks, accounts, value = {}, onChange, errors = {}, storageUsage, onStorageChange }) {
    const [active, setActive] = useState(networks[0] ?? null);
    const [creatorOptions, setCreatorOptions] = useState({});

    useEffect(() => {
        // Keep the selected tab valid when the user removes a destination.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        if (!networks.includes(active)) setActive(networks[0] ?? null);
    }, [active, networks]);

    useEffect(() => {
        const tiktokAccounts = accounts.filter(account => account.network === 'tiktok');
        tiktokAccounts.forEach(async account => {
            if (creatorOptions[account.id]) return;
            try {
                const response = await fetch(route('client.social.accounts.creator-options', account.id), { headers: { Accept: 'application/json' } });
                const json = await response.json();
                setCreatorOptions(current => ({ ...current, [account.id]: json.data ?? { error: json.error } }));
            } catch {
                setCreatorOptions(current => ({ ...current, [account.id]: { error: 'TikTok options could not be loaded.' } }));
            }
        });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [accounts.map(account => account.id).join(',')]);

    const payload = useMemo(() => ({ ...initialPayload(active), ...(value[active] ?? {}) }), [active, value]);
    if (!active || networks.length === 0) return null;

    const updatePayload = next => onChange?.({ ...value, [active]: { ...payload, ...next } });
    const updateOption = (key, next) => updatePayload({ options: { ...(payload.options ?? {}), [key]: next } });
    const updateTikTokOption = (accountId, key, next) => {
        if (activeAccounts.length <= 1) {
            updateOption(key, next);
            return;
        }
        const current = payload.account_options?.[accountId] ?? { ...defaultTikTokOptions };
        updatePayload({
            account_options: {
                ...(payload.account_options ?? {}),
                [accountId]: { ...current, [key]: next },
            },
        });
    };
    const isVideo = ['youtube', 'tiktok'].includes(active);
    const activeAccounts = accounts.filter(account => account.network === active);

    return (
        <section className="space-y-4 rounded-soft-lg border border-neutral-200 bg-neutral-50/60 p-4 dark:border-neutral-700 dark:bg-neutral-800/40">
            <div>
                <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Platform settings</h3>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">Use shared content or customize the fields that differ for each destination.</p>
            </div>
            <div className="flex gap-1 overflow-x-auto border-b border-neutral-200 dark:border-neutral-700" role="tablist">
                {networks.map(network => (
                    <button key={network} type="button" role="tab" aria-selected={active === network} onClick={() => setActive(network)} className={`flex items-center gap-1.5 border-b-2 px-3 py-2 text-xs font-medium ${active === network ? 'border-brand-500 text-brand-700 dark:text-brand-300' : 'border-transparent text-neutral-500'}`}>
                        <SocialBrandIcon network={network} className="h-4 w-4" /> {LABELS[network] ?? network}
                    </button>
                ))}
            </div>

            <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                <input type="checkbox" checked={Boolean(payload.customize)} onChange={event => updatePayload({ customize: event.target.checked })} />
                Customize content for {LABELS[active]}
            </label>

            {payload.customize && (
                <div className="space-y-3 rounded-soft border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{active === 'youtube' ? 'Video title' : 'Platform title (optional)'}</label>
                        <input value={payload.title ?? ''} maxLength={active === 'youtube' ? 100 : 256} onChange={event => updatePayload({ title: event.target.value })} className="w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </div>
                    <div>
                        <div className="mb-1 flex justify-between"><label className="text-xs font-medium text-neutral-600 dark:text-neutral-300">{active === 'youtube' ? 'Description' : 'Caption'}</label><span className="text-xs text-neutral-400">{(payload.body ?? '').length} / {LIMITS[active]}</span></div>
                        <textarea value={payload.body ?? ''} maxLength={LIMITS[active]} rows={4} onChange={event => updatePayload({ body: event.target.value })} className="w-full resize-none rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </div>
                    <MediaUpload
                        value={payload.media_urls?.[0] ?? ''}
                        onChange={url => updatePayload({ media_urls: url ? [url] : [], media_ids: url ? payload.media_ids : [] })}
                        onUploaded={upload => {
                            updatePayload({ media_urls: [upload.url], media_ids: [upload.media_id] });
                            onStorageChange?.(upload.storage);
                        }}
                        accept={isVideo ? 'video/mp4,video/webm,video/quicktime' : 'image/*,video/*'}
                        collection={isVideo ? 'social-video' : 'social'}
                        maxSizeMb={isVideo ? 500 : 200}
                        videoMaxSizeMb={500}
                        limitType={isVideo ? 'youtubeVideoMb' : 'mediaMb'}
                        remainingBytes={storageUsage?.remaining_bytes ?? null}
                    />
                </div>
            )}

            {active === 'youtube' && <YouTubeVideoSettings value={payload.options} onChange={options => updatePayload({ options })} errors={errors} remainingBytes={storageUsage?.remaining_bytes ?? null} onStorageChange={onStorageChange} />}

            {active === 'tiktok' && (
                <div className="space-y-3">
                    {activeAccounts.map(account => {
                        const capability = creatorOptions[account.id] ?? {};
                        const options = activeAccounts.length > 1 ? (payload.account_options?.[account.id] ?? defaultTikTokOptions) : (payload.options ?? defaultTikTokOptions);
                        const privacyOptions = capability.privacy_level_options ?? ['SELF_ONLY'];
                        const setOption = (key, next) => updateTikTokOption(account.id, key, next);
                        return <div key={account.id} className="space-y-3 rounded-soft border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            {activeAccounts.length > 1 && <p className="font-semibold text-neutral-800 dark:text-neutral-200">{account.name}</p>}
                            {capability.error && <p className="text-xs text-coral-600">{capability.error}</p>}
                            <div><label className="mb-1 block text-xs font-medium">Privacy</label><select value={options.privacy_level ?? ''} onChange={event => setOption('privacy_level', event.target.value)} className="w-full rounded-soft border border-neutral-300 px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800"><option value="">Choose privacy</option>{privacyOptions.map(option => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}</select></div>
                            <div className="grid gap-2 sm:grid-cols-3">
                                {[['allow_comment', 'Allow comments', capability.comment_disabled], ['allow_duet', 'Allow Duet', capability.duet_disabled], ['allow_stitch', 'Allow Stitch', capability.stitch_disabled]].map(([key, label, disabled]) => <label key={key} className={`flex items-center gap-2 ${disabled ? 'opacity-50' : ''}`}><input type="checkbox" disabled={disabled} checked={Boolean(options[key])} onChange={event => setOption(key, event.target.checked)} />{label}</label>)}
                            </div>
                            <div><label className="mb-1 block text-xs font-medium">Cover frame (milliseconds)</label><input type="number" min="0" value={options.video_cover_timestamp_ms ?? 0} onChange={event => setOption('video_cover_timestamp_ms', Number(event.target.value))} className="w-full rounded-soft border border-neutral-300 px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800" /></div>
                            <div className="space-y-2">
                                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.brand_content)} onChange={event => setOption('brand_content', event.target.checked)} />Paid partnership / branded content</label>
                                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.brand_organic)} onChange={event => setOption('brand_organic', event.target.checked)} />Promotes my own business</label>
                                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.is_aigc)} onChange={event => setOption('is_aigc', event.target.checked)} />AI-generated content</label>
                                <label className="flex items-start gap-2 rounded-soft border border-amber-200 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200"><input className="mt-0.5" type="checkbox" checked={Boolean(options.consent)} onChange={event => setOption('consent', event.target.checked)} /><span>I consent to Cerqle publishing this content to {account.name}.</span></label>
                            </div>
                        </div>;
                    })}
                    {activeAccounts.length === 0 && <p className="text-xs text-coral-600">Select a TikTok account to load its publishing settings.</p>}
                </div>
            )}

            {['facebook', 'instagram', 'linkedin'].includes(active) && (
                <div className="space-y-3 rounded-soft border border-neutral-200 bg-white p-3 text-xs text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                    {(active === 'facebook' || active === 'linkedin') && <div>
                        <label className="mb-1 block font-medium text-neutral-700 dark:text-neutral-300">Link URL (optional)</label>
                        <input type="url" value={payload.options?.link_url ?? ''} onChange={event => updateOption('link_url', event.target.value)} placeholder="https://example.com/article" className="w-full rounded-soft border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                        <p className="mt-1">Used when this platform override does not include uploaded media.</p>
                    </div>}
                    {active === 'instagram' && <p>Images publish to the feed, videos publish as Reels, and 2–10 compatible items publish as a carousel.</p>}
                    <p>Cerqle will apply the publishing capabilities currently supported by the connected {LABELS[active]} account and API.</p>
                </div>
            )}

            {Object.entries(errors).filter(([key]) => key.startsWith(`platform_payloads.${active}`)).map(([key, message]) => <p key={key} className="text-xs text-coral-600">{message}</p>)}
        </section>
    );
}
