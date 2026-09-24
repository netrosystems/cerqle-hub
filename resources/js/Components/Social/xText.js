// Advisory counter; server/provider validation remains authoritative.
// NFC, Unicode weights and emoji graphemes. Links are blocked by this plan.
export function xWeightedLength(text = '') {
    const normalized = text.normalize('NFC');
    const segments = new Intl.Segmenter('en', { granularity: 'grapheme' });
    const count = value => [...segments.segment(value)].reduce((total, { segment }) => {
        if (/\p{Emoji_Presentation}|\p{Extended_Pictographic}\ufe0f|[0-9#*]\ufe0f?\u20e3/u.test(segment)) return total + 2;
        return total + [...segment].reduce((sum, char) => {
            const cp = char.codePointAt(0);
            return sum + (cp <= 0x10ff || (cp >= 0x2000 && cp <= 0x200d) || (cp >= 0x2010 && cp <= 0x201f) || (cp >= 0x2032 && cp <= 0x2037) ? 1 : 2);
        }, 0);
    }, 0);
    return count(normalized);
}

// Cerqle's caps for X (X itself allows four images): up to three images, or
// one video or GIF on its own. Mirrors XContentValidator on the server.
export const X_MAX_IMAGES = 3;
export const X_ACCEPT = { images: ['image/jpeg', 'image/png'], video: ['video/mp4', 'image/gif'] };
const X_LIMITS_MB = { 'image/jpeg': 5, 'image/png': 5, 'image/gif': 15, 'video/mp4': 500 };

/** Why these files cannot go on an X post of this type: 'count', 'type', or null. */
export function xFilesError(type, files) {
    if (files.length > (type === 'video' ? 1 : X_MAX_IMAGES)) return 'count';
    return files.some(file => !X_ACCEPT[type]?.includes(file.type) || file.size > X_LIMITS_MB[file.type] * 1024 * 1024) ? 'type' : null;
}

/**
 * The composer's shared media, judged by file extension, against X's caps.
 * Advisory only: the server checks the real file types when publishing.
 */
export function xSharedMediaProblem(urls = []) {
    const items = urls.filter(Boolean);
    const motion = items.filter(url => /\.(mp4|mov|webm|gif)(\?|#|$)/i.test(url));
    if (motion.length && items.length > 1) return 'motion_alone';
    if (items.length > X_MAX_IMAGES) return 'too_many_images';
    return null;
}
