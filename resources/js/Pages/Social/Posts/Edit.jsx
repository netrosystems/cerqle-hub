import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import MediaUpload from '@/Components/MediaUpload';
import { DEFAULT_YOUTUBE_OPTIONS } from '@/Components/YouTubeVideoSettings';
import SocialPlatformOverrides from '@/Components/SocialPlatformOverrides';
import TimezonePicker from '@/Components/TimezonePicker';
import { DatePicker, Tooltip } from '@/Components/ui';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import { ArrowLeft, Clock, Trash2, Plus, Send, Calendar, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useState } from 'react';
import { browserTz, tzLocalToUtcIso, formatInTz } from '@/Utils/datetime';

const CHAR_LIMITS = { tiktok: 2200, linkedin: 3000, facebook: 63206, instagram: 2200, youtube: 5000 };

/** Convert a UTC datetime string to a `datetime-local` value in the given timezone. */
function toLocalDatetime(utcStr, tz) {
    if (!utcStr) return '';
    try {
        const d = new Date(utcStr);
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: tz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hour12: false,
        }).formatToParts(d);
        const get = (type) => parts.find(p => p.type === type)?.value ?? '00';
        return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
    } catch {
        return '';
    }
}

export default function EditPost({ post, accounts, storageUsage }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';
    const postTz = post.timezone || userTz;
    const [liveStorage, setLiveStorage] = useState(storageUsage);

    const { data, setData, put, processing, errors, clearErrors, transform } = useForm({
        title:           post.title ?? '',
        body:            post.body ?? '',
        media_urls:      (post.media_urls ?? []).filter(Boolean).length
                            ? post.media_urls.filter(Boolean)
                            : [''],
        media_ids:       post.media_ids ?? [],
        target_accounts: (post.target_accounts ?? []).map(String),
        scheduled_at:    toLocalDatetime(post.scheduled_at, postTz),
        timezone:        postTz,
        youtube_options: { ...DEFAULT_YOUTUBE_OPTIONS, ...(post.youtube_options ?? {}) },
        platform_payloads: post.platform_payloads ?? {},
    });

    const toggleAccount = (id) => {
        const sid = id.toString();
        setData('target_accounts', data.target_accounts.includes(sid)
            ? data.target_accounts.filter(a => a !== sid)
            : [...data.target_accounts, sid]);
    };

    const addMediaSlot = () => {
        clearErrors('media_urls');
        setData('media_urls', [...(data.media_urls ?? []), '']);
    };

    const removeMediaSlot = (index) => {
        clearErrors('media_urls', `media_urls.${index}`);
        setData('media_urls', (data.media_urls ?? []).filter((_, itemIndex) => itemIndex !== index));
        setData('media_ids', (data.media_ids ?? []).filter((_, itemIndex) => itemIndex !== index));
    };

    const selectedAccounts = accounts
        .filter(a => data.target_accounts.includes(a.id.toString()));
    const selectedNetworks = [...new Set(selectedAccounts.map(a => a.network))];
    const requiresDirectVideo = selectedNetworks.some(network => ['youtube', 'tiktok'].includes(network));
    const hasYoutube = selectedNetworks.includes('youtube');
    const isYoutubeOnly = selectedNetworks.length === 1 && hasYoutube;
    const minCharLimit = selectedNetworks.length > 0
        ? Math.min(...selectedNetworks.map(n => CHAR_LIMITS[n] ?? 5000))
        : 5000;

    const handleSubmit = (e) => {
        e.preventDefault();
        transform(current => ({
            ...current,
            scheduled_at: current.scheduled_at ? tzLocalToUtcIso(current.scheduled_at, current.timezone || 'UTC') : null,
            media_urls: (current.media_urls ?? []).filter(Boolean),
            media_ids: (current.media_ids ?? []).filter(Boolean),
            platform_payloads: Object.fromEntries(Object.entries(current.platform_payloads ?? {}).filter(([network]) => selectedNetworks.includes(network))),
            target_accounts: current.target_accounts.map(Number),
        }));
        put(route('client.social.posts.update', post.id), { preserveScroll: true });
    };

    const statusColor = {
        draft: 'bg-neutral-100 text-neutral-600',
        scheduled: 'bg-blue-100 text-blue-700',
        failed: 'bg-red-100 text-red-700',
    }[post.status] ?? 'bg-neutral-100 text-neutral-500';

    return (
        <ClientLayout title={t('social.edit_post_title')}>
            <Head title={t('social.edit_post_head')} />
            <div className="max-w-2xl space-y-6">

                {/* Header */}
                <div className="flex items-center gap-3">
                    <Link href={route('client.social.posts.index')}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.edit_post_title')}</h2>
                        <p className="text-sm text-neutral-400 mt-0.5">
                            {t('social.edit_post_subtitle')}
                        </p>
                    </div>
                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${statusColor}`}>
                        {t(`social.status_${post.status}`, post.status)}
                    </span>
                </div>

                <form onSubmit={handleSubmit} className="space-y-5">

                    {/* Account selector */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.post_to')}</h3>
                        <div className="flex flex-wrap gap-2">
                            {accounts.map(acct => {
                                const selected = data.target_accounts.includes(acct.id.toString());
                                return (
                                    <button key={acct.id} type="button" onClick={() => toggleAccount(acct.id)}
                                        className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm border transition ${
                                            selected
                                                ? 'bg-brand-600 border-brand-600 text-white'
                                                : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-400'
                                        }`}>
                                        <div className="relative shrink-0">
                                            {acct.picture_url
                                                ? <img src={acct.picture_url} alt={acct.name} className="h-5 w-5 rounded-full object-cover" />
                                                : <SocialBrandIcon network={acct.network} className="h-4 w-4" />
                                            }
                                            {acct.picture_url && (
                                                <span className="absolute -bottom-0.5 -right-0.5 flex h-3 w-3 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                                                    <SocialBrandIcon network={acct.network} className="h-2.5 w-2.5" />
                                                </span>
                                            )}
                                        </div>
                                        <span className="truncate max-w-[120px]">{acct.name}</span>
                                    </button>
                                );
                            })}
                            {accounts.length === 0 && (
                                <p className="text-sm text-neutral-400">
                                    {t('social.no_accounts_connected')}{' '}
                                    <Link href={route('client.social.accounts.index')} className="text-brand-600 hover:underline">{t('social.add_one')}</Link>
                                </p>
                            )}
                        </div>
                        {errors.target_accounts && <p className="text-xs text-red-500">{errors.target_accounts}</p>}
                    </div>

                    {/* Content */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.content')}</h3>

                        {/* Title */}
                        <div>
                            <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">
                                {hasYoutube ? t('social.youtube_video_title') : t('social.title_label')}{' '}
                                {!hasYoutube && <span className="text-neutral-400">({t('common.optional')})</span>}
                            </label>
                            <input
                                type="text"
                                value={data.title}
                                onChange={e => setData('title', e.target.value)}
                                placeholder={t('social.edit_title_placeholder')}
                                maxLength={hasYoutube ? 100 : 256}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                            {errors.title && <p className="mt-1 text-xs text-red-500">{errors.title}</p>}
                        </div>

                        {/* Body */}
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{isYoutubeOnly ? t('social.youtube_description') : t('social.post_content')}</label>
                                <span className={`text-xs ${data.body.length > minCharLimit ? 'text-red-500 font-medium' : 'text-neutral-400'}`}>
                                    {data.body.length} / {minCharLimit}
                                </span>
                            </div>
                            <textarea
                                value={data.body}
                                onChange={e => setData('body', e.target.value)}
                                rows={8}
                                placeholder={isYoutubeOnly ? t('social.youtube_description_placeholder') : t('social.body_placeholder')}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                            {errors.body && <p className="mt-1 text-xs text-red-500">{errors.body}</p>}
                        </div>
                    </div>

                    {/* Media */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-1.5">
                                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.media')}</h3>
                                {requiresDirectVideo && (
                                    <Tooltip content={t('social.direct_video_url_help')} position="right" wrap>
                                        <button
                                            type="button"
                                            aria-label={t('social.video_media_help_label')}
                                            className="rounded-full text-neutral-400 transition-colors hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:text-brand-300"
                                        >
                                            <Info className="h-4 w-4" />
                                        </button>
                                    </Tooltip>
                                )}
                            </div>
                            {(!requiresDirectVideo || (data.media_urls ?? []).length === 0) && <button type="button"
                                onClick={addMediaSlot}
                                className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                                <Plus className="h-3.5 w-3.5" /> {t('social.add_media')}
                            </button>}
                        </div>
                        {(data.media_urls ?? []).map((url, i) => (
                            <div key={i} className="flex items-start gap-2">
                                <div className="flex-1 min-w-0">
                                    <MediaUpload
                                        value={url}
                                        onChange={v => {
                                            clearErrors('media_urls', `media_urls.${i}`);
                                            const next = [...(data.media_urls ?? [])];
                                            next[i] = v;
                                            setData('media_urls', next);
                                            if (!v) {
                                                const ids = [...(data.media_ids ?? [])];
                                                ids[i] = null;
                                                setData('media_ids', ids);
                                            }
                                        }}
                                        onUploaded={upload => {
                                            const ids = [...(data.media_ids ?? [])];
                                            ids[i] = upload.media_id;
                                            setData('media_ids', ids);
                                            setLiveStorage(upload.storage);
                                        }}
                                        accept={requiresDirectVideo ? 'video/mp4,video/webm,video/quicktime' : 'image/*,video/*'}
                                        collection={requiresDirectVideo ? 'social-video' : 'social'}
                                        maxSizeMb={25}
                                        videoMaxSizeMb={500}
                                        limitType="socialImageMb"
                                        remainingBytes={liveStorage?.remaining_bytes ?? null}
                                        placeholder={requiresDirectVideo ? 'https://cdn.example.com/video.mp4' : 'https://cdn.example.com/image.jpg'}
                                    />
                                </div>
                                <button type="button"
                                    onClick={() => removeMediaSlot(i)}
                                    className="mt-7 shrink-0 text-neutral-400 hover:text-red-500 transition">
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                        {(data.media_urls ?? []).filter(Boolean).length === 0 && (
                            <p className="text-xs text-neutral-400">{t('social.no_media_attached')}</p>
                        )}
                        {errors.media_urls && <p className="text-xs text-red-500">{errors.media_urls}</p>}
                    </div>

                    <SocialPlatformOverrides networks={selectedNetworks} accounts={selectedAccounts} value={data.platform_payloads} onChange={payloads => setData('platform_payloads', payloads)} errors={errors} storageUsage={liveStorage} onStorageChange={setLiveStorage} />

                    {/* Schedule */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200 flex items-center gap-1.5">
                            <Calendar className="h-4 w-4 text-neutral-400" /> {t('social.schedule')}
                            <span className="text-xs font-normal text-neutral-400">{t('social.schedule_draft_hint')}</span>
                        </h3>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">{t('social.date_time')}</label>
                                <DatePicker
                                    mode="datetime"
                                    value={data.scheduled_at}
                                    onChange={v => setData('scheduled_at', v)}
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">{t('social.timezone')}</label>
                                <TimezonePicker
                                    value={data.timezone}
                                    onChange={tz => setData('timezone', tz)}
                                />
                            </div>
                        </div>
                        {data.scheduled_at && (
                            <p className="text-xs text-neutral-400 flex items-center gap-1">
                                <Clock className="h-3 w-3" />
                                {t('social.publishes_at', { time: formatInTz(tzLocalToUtcIso(data.scheduled_at, data.timezone), data.timezone) })}
                            </p>
                        )}
                        {errors.scheduled_at && <p className="text-xs text-red-500">{errors.scheduled_at}</p>}
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing || (isYoutubeOnly ? !data.title.trim() : !data.body.trim()) || data.target_accounts.length === 0}
                            className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                        >
                            <Send className="h-4 w-4" />
                            {processing ? t('social.saving') : data.scheduled_at ? t('social.save_and_schedule') : t('social.save_as_draft')}
                        </button>
                        <Link
                            href={route('client.social.posts.index')}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-5 py-2.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            {t('common.cancel')}
                        </Link>
                    </div>

                </form>
            </div>
        </ClientLayout>
    );
}
