/** Capture Meta session events before OAuth returns; start the grace period only afterwards. */
export function createWhatsappSignupSession({ mode = 'cloud_api', target = window } = {}) {
    let outcome;
    let waiter;
    let timer;
    const cleanup = () => {
        clearTimeout(timer);
        target.removeEventListener('message', handler);
    };
    const settle = (value, error) => {
        if (outcome) return;
        outcome = { value, error };
        cleanup();
        if (waiter) error ? waiter.reject(error) : waiter.resolve(value);
    };
    function handler(event) {
        let origin;
        let parsed;
        try {
            origin = new URL(event.origin);
            parsed = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        } catch {
            return;
        }
        if (origin.protocol !== 'https:' ||
            (origin.hostname !== 'facebook.com' && !origin.hostname.endsWith('.facebook.com')) ||
            parsed?.type !== 'WA_EMBEDDED_SIGNUP') return;

        if (['CANCEL', 'ERROR'].includes(parsed.event)) {
            settle(null, new Error('WhatsApp authorization was not completed. Please try again.'));
            return;
        }
        if (!['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'].includes(parsed.event)) return;
        const isCoexistence = parsed.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
        if (isCoexistence !== (mode === 'coexistence')) {
            settle(null, new Error('Meta returned a different WhatsApp connection mode. Please restart setup.'));
            return;
        }
        if (isCoexistence && !parsed.data?.waba_id) {
            settle(null, new Error('Meta did not return the WhatsApp Business Account. Please restart setup.'));
            return;
        }
        settle(parsed.data ?? {});
    }
    target.addEventListener('message', handler);
    return {
        wait(timeout = 15000) {
            if (outcome) return outcome.error ? Promise.reject(outcome.error) : Promise.resolve(outcome.value);
            if (waiter) return waiter.promise;
            let resolve;
            let reject;
            const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
            waiter = { promise, resolve, reject };
            timer = setTimeout(() => {
                // Standard signup can discover a unique granted WABA server-side.
                // Coexistence must never fall back to standard phone registration.
                if (mode === 'coexistence') settle(null, new Error('Meta signup confirmation timed out. Please restart setup.'));
                else settle({});
            }, timeout);
            return promise;
        },
        dispose() {
            settle(null, new Error('WhatsApp authorization was cancelled.'));
        },
    };
}
