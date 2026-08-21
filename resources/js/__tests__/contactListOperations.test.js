import { describe, expect, it } from 'vitest';
import {
    OPERATION_VISIBILITY,
    isLiveOperation,
} from '@/lib/contactListOperations';

const now = Date.parse('2026-08-07T03:10:00Z');

describe('OPERATION_VISIBILITY windows', () => {
    it('keeps queued rows visible until a worker handles them', () => {
        expect(OPERATION_VISIBILITY.queued).toBe(Number.POSITIVE_INFINITY);
    });

    it('keeps processing rows visible for long-running imports', () => {
        expect(OPERATION_VISIBILITY.processing).toBe(Number.POSITIVE_INFINITY);
    });

    it('keeps completed rows visible for five minutes', () => {
        expect(OPERATION_VISIBILITY.completed).toBe(5 * 60 * 1000);
    });

    it('keeps failed rows visible for one day', () => {
        expect(OPERATION_VISIBILITY.failed).toBe(24 * 60 * 60 * 1000);
    });
});

describe('isLiveOperation', () => {
    it('keeps a freshly queued operation visible', () => {
        expect(isLiveOperation({ status: 'queued', created_at: '2026-08-07T03:09:30Z' }, now)).toBe(true);
    });

    it('keeps a stale queued operation visible so stopped workers are diagnosable', () => {
        expect(isLiveOperation({ status: 'queued', created_at: '2026-08-01T03:00:00Z' }, now)).toBe(true);
    });

    it('keeps a processing operation that is still inside the thirty minute window', () => {
        expect(isLiveOperation({ status: 'processing', created_at: '2026-08-07T02:50:00Z' }, now)).toBe(true);
    });

    it('keeps a long-running processing operation visible', () => {
        expect(isLiveOperation({ status: 'processing', created_at: '2026-08-01T02:30:00Z' }, now)).toBe(true);
    });

    it('keeps a completed operation visible inside the five minute window', () => {
        expect(isLiveOperation({ status: 'completed', created_at: '2026-08-07T03:08:00Z' }, now)).toBe(true);
    });

    it('hides a completed operation that finished more than five minutes ago', () => {
        expect(isLiveOperation({ status: 'completed', created_at: '2026-08-07T03:04:00Z' }, now)).toBe(false);
    });

    it('keeps a recently failed operation visible', () => {
        expect(isLiveOperation({ status: 'failed', finished_at: '2026-08-07T03:08:00Z' }, now)).toBe(true);
    });

    it('keeps a failed operation visible inside the one-day window', () => {
        expect(isLiveOperation({ status: 'failed', finished_at: '2026-08-07T03:04:00Z' }, now)).toBe(true);
    });

    it('keeps completed CSV validation visible until it is confirmed', () => {
        expect(isLiveOperation({
            type: 'csv_validation',
            status: 'completed',
            finished_at: '2026-07-01T03:04:00Z',
        }, now)).toBe(true);
    });

    it('uses completion time instead of creation time for terminal operations', () => {
        expect(isLiveOperation({
            status: 'failed',
            created_at: '2026-08-01T00:00:00Z',
            finished_at: '2026-08-07T03:09:00Z',
        }, now)).toBe(true);
    });

    it('hides a queued operation with no timestamp at all', () => {
        expect(isLiveOperation({ status: 'queued' }, now)).toBe(false);
    });

    it('hides an operation with an unparseable timestamp', () => {
        expect(isLiveOperation({ status: 'processing', created_at: 'not-a-date' }, now)).toBe(false);
    });

    it('returns false for a missing operation record', () => {
        expect(isLiveOperation(null, now)).toBe(false);
    });

    it('hides an operation whose status is not recognised by the visibility map', () => {
        expect(isLiveOperation({ status: 'mystery', created_at: '2026-08-07T03:09:00Z' }, now)).toBe(false);
    });
});
