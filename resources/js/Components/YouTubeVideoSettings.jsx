import MediaUpload from '@/Components/MediaUpload';
import Tooltip from '@/Components/ui/Tooltip';
import { Info, Youtube } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

const CATEGORIES = [
    ['1', 'Film & Animation'], ['2', 'Autos & Vehicles'], ['10', 'Music'],
    ['15', 'Pets & Animals'], ['17', 'Sports'], ['20', 'Gaming'],
    ['22', 'People & Blogs'], ['23', 'Comedy'], ['24', 'Entertainment'],
    ['25', 'News & Politics'], ['26', 'Howto & Style'], ['27', 'Education'],
    ['28', 'Science & Technology'], ['29', 'Nonprofits & Activism'],
];

export const DEFAULT_YOUTUBE_OPTIONS = {
    thumbnail_url: '',
    privacy_status: 'private',
    tags: [],
    category_id: '22',
    playlist_id: '',
    made_for_kids: false,
    contains_synthetic_media: false,
    notify_subscribers: true,
    default_language: '',
    thumbnail_media_id: null,
};

const parseTags = (value) => value
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean);

export function YouTubeTagsInput({ tags = [], onChange, className = '', ...props }) {
    const canonicalTags = Array.isArray(tags) ? tags.join(', ') : '';
    const [draft, setDraft] = useState(canonicalTags);
    const lastEmittedTags = useRef(canonicalTags);

    useEffect(() => {
        // Parent echoes from this input must not remove a trailing comma while
        // the user is still typing. Genuine external changes still sync in.
        if (canonicalTags !== lastEmittedTags.current) {
            setDraft(canonicalTags);
            lastEmittedTags.current = canonicalTags;
        }
    }, [canonicalTags]);

    const handleChange = (event) => {
        const nextDraft = event.target.value;
        const nextTags = parseTags(nextDraft);

        setDraft(nextDraft);
        lastEmittedTags.current = nextTags.join(', ');
        onChange?.(nextTags);
    };

    return (
        <input
            {...props}
            value={draft}
            onChange={handleChange}
            maxLength={500}
            className={className}
        />
    );
}

export default function YouTubeVideoSettings({ value, onChange, errors = {}, remainingBytes = null, onStorageChange }) {
    const { t } = useTranslation();
    const options = { ...DEFAULT_YOUTUBE_OPTIONS, ...(value ?? {}) };
    const update = (key, next) => onChange?.({ ...options, [key]: next });

    return (
        <section className="rounded-xl border border-red-200 bg-red-50/40 p-4 space-y-4 dark:border-red-900/60 dark:bg-red-950/10">
            <div className="flex items-start gap-2">
                <Youtube className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                <div>
                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('social.youtube_settings')}</h3>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('social.youtube_settings_help')}</p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_visibility')}</label>
                    <select value={options.privacy_status} onChange={e => update('privacy_status', e.target.value)} className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                        <option value="private">{t('social.youtube_private')}</option>
                        <option value="unlisted">{t('social.youtube_unlisted')}</option>
                        <option value="public">{t('social.youtube_public')}</option>
                    </select>
                </div>
                <div>
                    <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_category')}</label>
                    <select value={String(options.category_id)} onChange={e => update('category_id', e.target.value)} className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                        {CATEGORIES.map(([id, label]) => <option key={id} value={id}>{label}</option>)}
                    </select>
                </div>
            </div>

            <div>
                <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_tags')}</label>
                <YouTubeTagsInput
                    tags={options.tags}
                    onChange={(tags) => update('tags', tags)}
                    placeholder="travel, esim, tutorial"
                    className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                />
                <p className="mt-1 text-xs text-neutral-400">{t('social.youtube_tags_help')}</p>
                {errors['youtube_options.tags'] && <p className="mt-1 text-xs text-red-500">{errors['youtube_options.tags']}</p>}
            </div>

            <div>
                <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_thumbnail')}</label>
                <MediaUpload
                    value={options.thumbnail_url}
                    onChange={next => onChange?.({
                        ...options,
                        thumbnail_url: next,
                        thumbnail_media_id: next ? options.thumbnail_media_id : null,
                    })}
                    onUploaded={upload => {
                        onChange?.({ ...options, thumbnail_url: upload.url, thumbnail_media_id: upload.media_id });
                        onStorageChange?.(upload.storage);
                    }}
                    accept="image/jpeg,image/png"
                    maxSizeMb={2}
                    collection="social-thumbnail"
                    placeholder="https://cdn.example.com/thumbnail.jpg"
                    urlHelp={t('social.youtube_thumbnail_help')}
                    remainingBytes={remainingBytes}
                />
                {errors['youtube_options.thumbnail_url'] && <p className="mt-1 text-xs text-red-500">{errors['youtube_options.thumbnail_url']}</p>}
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <div className="mb-1 flex items-center gap-1.5">
                        <label className="text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_playlist')}</label>
                        <Tooltip content={t('social.youtube_playlist_help')} position="top" wrap>
                            <button
                                type="button"
                                aria-label={`${t('social.youtube_playlist')} instructions`}
                                className="rounded-full text-neutral-400 transition hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:text-brand-300"
                            >
                                <Info className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </Tooltip>
                    </div>
                    <input value={options.playlist_id} onChange={e => update('playlist_id', e.target.value.trim())} placeholder="PLxxxxxxxxxxxxxxxx" className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('social.youtube_language')}</label>
                    <input value={options.default_language} onChange={e => update('default_language', e.target.value.trim())} placeholder="en, bn, ur" className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                </div>
            </div>

            <div className="space-y-2 text-sm text-neutral-700 dark:text-neutral-300">
                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.made_for_kids)} onChange={e => update('made_for_kids', e.target.checked)} /> {t('social.youtube_made_for_kids')}</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.contains_synthetic_media)} onChange={e => update('contains_synthetic_media', e.target.checked)} /> {t('social.youtube_synthetic_media')}</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(options.notify_subscribers)} onChange={e => update('notify_subscribers', e.target.checked)} /> {t('social.youtube_notify_subscribers')}</label>
            </div>

        </section>
    );
}
