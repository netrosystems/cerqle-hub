import { useState } from 'react';

export function instagramAttachments(message) {
    if (message.channel !== 'instagram') return [];
    const attachments = message.payload?.message?.attachments;
    return Array.isArray(attachments) ? attachments : [];
}

function Attachment({ attachment }) {
    const [failed, setFailed] = useState(false);
    const rawUrl = attachment?.payload?.url;
    let url = null;
    try {
        const parsed = new URL(rawUrl);
        if (parsed.protocol === 'https:') url = parsed.href;
    } catch { /* Missing or unsupported provider URL. */ }

    if (!url || failed) {
        return <p role="status" className="text-xs opacity-75">Instagram media unavailable. It may have expired or been removed.</p>;
    }

    const type = attachment?.type;
    if (type === 'image' || type === 'animated_image') {
        return <a href={url} target="_blank" rel="noopener noreferrer">
            <img src={url} alt="Instagram attachment" loading="lazy" referrerPolicy="no-referrer"
                onError={() => setFailed(true)} className="max-w-full max-h-80 rounded-lg object-contain" />
        </a>;
    }
    if (type === 'video') {
        return <video src={url} controls preload="metadata" onError={() => setFailed(true)} className="max-w-full max-h-80 rounded-lg" aria-label="Instagram video" />;
    }
    if (type === 'audio') {
        return <audio src={url} controls preload="metadata" onError={() => setFailed(true)} className="max-w-full" aria-label="Instagram audio" />;
    }

    // Shares/story links are not necessarily direct image/video files.
    return <a href={url} target="_blank" rel="noopener noreferrer" className="underline text-sm">Open Instagram attachment</a>;
}

export default function InstagramAttachments({ attachments }) {
    return <div className="space-y-2">{attachments.map((attachment, index) => <Attachment key={`${index}:${attachment?.payload?.url ?? ''}`} attachment={attachment} />)}</div>;
}
