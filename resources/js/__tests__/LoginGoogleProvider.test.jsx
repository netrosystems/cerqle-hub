import { describe, expect, it } from 'vitest';
import { shouldUseFirebaseGoogle } from '@/Pages/Auth/Login';

describe('Google login provider precedence', () => {
    const firebase = {
        enabled: true,
        apiKey: 'firebase-api-key',
    };

    it('uses the dedicated Google OAuth client instead of rendering duplicate Firebase login', () => {
        expect(shouldUseFirebaseGoogle(firebase, ['google'])).toBe(false);
    });

    it('keeps Firebase as a fallback when dedicated Google OAuth is unavailable', () => {
        expect(shouldUseFirebaseGoogle(firebase, [])).toBe(true);
    });

    it('does not render an incomplete Firebase configuration', () => {
        expect(shouldUseFirebaseGoogle({ enabled: true, apiKey: '' }, [])).toBe(false);
    });
});
