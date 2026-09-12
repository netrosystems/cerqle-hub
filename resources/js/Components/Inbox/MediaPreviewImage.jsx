import { useState } from 'react';

// Retry a broken saved URL through the authenticated media endpoint once.
export default function MediaPreviewImage({ src, fallbackSrc, onError, ...props }) {
    const [failedSource, setFailedSource] = useState(null);
    const source = failedSource === src ? fallbackSrc : (src || fallbackSrc);

    return <img {...props} src={source} onError={event => {
        if (src && source !== fallbackSrc) setFailedSource(src);
        else onError?.(event);
    }} />;
}
