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
