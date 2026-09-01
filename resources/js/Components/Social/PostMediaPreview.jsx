import { ImageOff, Play } from 'lucide-react';
import { useState } from 'react';

const IMAGE_EXTENSION = /\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#].*)?$/i;
const VIDEO_EXTENSION = /\.(?:m4v|mov|mp4|webm)(?:[?#].*)?$/i;

export function resolveMediaKind(url, mimeType, preferVideo = false) {
    if (mimeType?.startsWith('video/')) return 'video';
    if (mimeType?.startsWith('image/')) return 'image';
    if (VIDEO_EXTENSION.test(url ?? '')) return 'video';
    if (IMAGE_EXTENSION.test(url ?? '')) return 'image';

    return preferVideo ? 'video' : 'image';
}

export default function PostMediaPreview({
    url,
    mimeType,
    preferVideo = false,
    controls = false,
    className = '',
}) {
    const kind = resolveMediaKind(url, mimeType, preferVideo);
    const mediaKey = `${kind}:${url}`;
    const [failedMedia, setFailedMedia] = useState(null);
    const failed = failedMedia === mediaKey;

    if (failed) {
        return (
            <div className={`flex items-center justify-center bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500 ${className}`}>
                <ImageOff className="h-7 w-7" aria-hidden="true" />
                <span className="sr-only">Media preview unavailable</span>
            </div>
        );
    }

    if (kind === 'video') {
        return (
            <div className={`relative overflow-hidden bg-neutral-950 ${className}`}>
                <video
                    src={url}
                    muted
                    playsInline
                    preload="metadata"
                    controls={controls}
                    onError={() => setFailedMedia(mediaKey)}
                    className="h-full w-full object-cover"
                />
                {!controls && (
                    <span className="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
                        <span className="flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-white shadow-sm backdrop-blur-sm">
                            <Play className="ml-0.5 h-4 w-4 fill-current" />
                        </span>
                    </span>
                )}
            </div>
        );
    }

    return <img src={url} alt="" onError={() => setFailedMedia(mediaKey)} className={className} />;
}
