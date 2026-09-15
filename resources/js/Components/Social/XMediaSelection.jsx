import axios from 'axios';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function XMediaSelection({ payload, onChange, onStorageChange }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const type = payload.options?.media_type ?? 'text';
    const upload = async event => {
        const files = [...event.target.files];
        event.target.value = '';
        const video = type === 'video';
        if (files.length > (video ? 1 : 4) || files.some(file => !(video ? ['video/mp4'] : ['image/jpeg', 'image/png']).includes(file.type) || file.size > (video ? 500 : 5) * 1024 * 1024)) {
            setError(t('social.x_upload_limits'));
            return;
        }
        setBusy(true);
        setError('');
        const urls = [], ids = [];
        try {
            for (const file of files) {
                const form = new FormData();
                form.append('file', file);
                form.append('collection', video ? 'social-video' : 'social');
                const { data } = await axios.post(route('client.media.store'), form);
                urls.push(data.url); ids.push(data.media_id);
                onStorageChange?.(data.storage);
            }
        } catch (err) {
            setError(err.response?.data?.message ?? t('social.x_upload_failed'));
        } finally {
            if (urls.length) onChange({ media_urls: urls, media_ids: ids });
            setBusy(false);
        }
    };
    return <div className="space-y-2">
        <label className="block text-xs font-medium">{t('social.x_media_type')}
            <select disabled={busy} value={type} onChange={event => onChange({ options: { ...payload.options, media_type: event.target.value }, media_urls: [], media_ids: [] })} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800">
                {['text', 'images', 'video'].map(value => <option key={value} value={value}>{t(`social.x_media_${value}`)}</option>)}
            </select>
        </label>
        {type !== 'text' && <label className="block text-xs">{t('social.x_upload_limits')}<input type="file" disabled={busy} multiple={type === 'images'} accept={type === 'video' ? 'video/mp4' : 'image/jpeg,image/png'} onChange={upload} className="mt-2 block w-full text-xs" /></label>}
        {(payload.media_urls ?? []).filter(Boolean).map((url, index) => <div key={url} className="flex items-center justify-between gap-2 text-xs"><span className="truncate">{t('social.media')} {index + 1}</span><button type="button" disabled={busy} onClick={() => onChange({ media_urls: payload.media_urls.filter((_, i) => i !== index), media_ids: (payload.media_ids ?? []).filter((_, i) => i !== index) })}>{t('common.remove', { defaultValue: 'Remove' })}</button></div>)}
        {error && <p role="alert" className="text-xs text-coral-600">{error}</p>}
    </div>;
}
