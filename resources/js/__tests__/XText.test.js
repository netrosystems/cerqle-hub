import { describe, expect, it } from 'vitest';
import { xWeightedLength } from '@/Components/Social/xText';

describe('X advisory weighted counter', () => {
    it.each([
        ['a'.repeat(280), 280],
        ['漢'.repeat(140), 280],
        ['👨‍👩‍👧‍👦', 2],
        ['🙋🏽', 2],
        ['🇧🇩', 2],
        ['1️⃣', 2],
        ['cafe\u0301', 4],
        ['©', 1],
        ['©️', 2],
        ['e\u0301', 1],
    ])('counts %s as %i', (text, expected) => {
        expect(xWeightedLength(text)).toBe(expected);
    });
});
