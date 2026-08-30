import { describe, expect, it } from 'vitest';
import { formatInboxTimestamp } from '@/Utils/datetime';

const now = new Date('2026-08-30T18:30:00Z'); // Aug 31, 12:30 AM in Dhaka
const options = { now, locale: 'en-US' };

describe('formatInboxTimestamp', () => {
    it('shows only the time for a conversation from today in the user timezone', () => {
        expect(formatInboxTimestamp('2026-08-30T18:05:00Z', 'Asia/Dhaka', options)).toBe('12:05 AM');
    });

    it('shows Yesterday for the previous calendar day in the user timezone', () => {
        expect(formatInboxTimestamp('2026-08-30T17:59:00Z', 'Asia/Dhaka', options)).toBe('Yesterday');
    });

    it('shows a compact date for an older conversation in the current year', () => {
        expect(formatInboxTimestamp('2026-08-28T10:00:00Z', 'Asia/Dhaka', options)).toBe('Aug 28');
    });

    it('includes the year when the conversation is from another year', () => {
        expect(formatInboxTimestamp('2025-12-31T10:00:00Z', 'Asia/Dhaka', options)).toBe('Dec 31, 2025');
    });

    it('uses the configured timezone instead of the browser timezone', () => {
        expect(formatInboxTimestamp('2026-08-30T18:05:00Z', 'America/New_York', options)).toBe('02:05 PM');
    });

    it('returns an empty label for missing or invalid values', () => {
        expect(formatInboxTimestamp(null, 'Asia/Dhaka', options)).toBe('');
        expect(formatInboxTimestamp('not-a-date', 'Asia/Dhaka', options)).toBe('');
    });
});
