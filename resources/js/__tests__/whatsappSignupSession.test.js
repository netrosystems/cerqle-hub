import { afterEach, describe, expect, it, vi } from 'vitest';
import { createWhatsappSignupSession } from '../Utils/whatsappSignupSession';

describe('WhatsApp signup session', () => {
    const sessions = [];
    const start = (options) => {
        const session = createWhatsappSignupSession(options);
        sessions.push(session);
        return session;
    };
    const emit = (event, data = { waba_id: 'DEMO_WABA' }, origin = 'https://www.facebook.com') => {
        window.dispatchEvent(new MessageEvent('message', {
            origin, data: JSON.stringify({ type: 'WA_EMBEDDED_SIGNUP', event, data }),
        }));
    };
    afterEach(() => {
        sessions.splice(0).forEach(session => session.dispose());
        vi.useRealTimers();
    });
    it('captures completion even when a user spends minutes inside Meta', async () => {
        vi.useFakeTimers();
        const session = start();
        vi.advanceTimersByTime(180000);
        emit('FINISH');
        await expect(session.wait()).resolves.toEqual({ waba_id: 'DEMO_WABA' });
    });
    it('accepts completion after the OAuth callback', async () => {
        const session = start();
        const result = session.wait();
        emit('FINISH');
        await expect(result).resolves.toEqual({ waba_id: 'DEMO_WABA' });
    });
    it('does not convert cancellation into successful standard signup', async () => {
        const session = start();
        emit('CANCEL');
        await expect(session.wait()).rejects.toThrow('not completed');
    });
    it('preserves the standard no-session fallback after the callback grace period', async () => {
        vi.useFakeTimers();
        const session = start();
        const result = session.wait();
        vi.advanceTimersByTime(15000);
        await expect(result).resolves.toEqual({});
    });
    it('never falls back to standard registration for coexistence', async () => {
        vi.useFakeTimers();
        const session = start({ mode: 'coexistence' });
        const result = expect(session.wait()).rejects.toThrow('timed out');
        vi.advanceTimersByTime(15000);
        await result;
    });
    it('accepts the documented WABA-only coexistence completion', async () => {
        const session = start({ mode: 'coexistence' });
        emit('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING');
        await expect(session.wait()).resolves.toEqual({ waba_id: 'DEMO_WABA' });
    });
    it('rejects an unexpected connection mode', async () => {
        const session = start();
        emit('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING');
        await expect(session.wait()).rejects.toThrow('different WhatsApp connection mode');
    });
    it('ignores spoofed origins and unrelated progress events', async () => {
        const session = start();
        emit('FINISH', { waba_id: 'BAD' }, 'https://facebook.com.attacker.test');
        emit('FINISH', { waba_id: 'BAD' }, 'http://facebook.com');
        emit('START', { waba_id: 'BAD' });
        emit('FINISH');
        await expect(session.wait()).resolves.toEqual({ waba_id: 'DEMO_WABA' });
    });
    it('removes the listener on disposal', async () => {
        const session = start();
        session.dispose();
        emit('FINISH');
        await expect(session.wait()).rejects.toThrow('cancelled');
    });
});
