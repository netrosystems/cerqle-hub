import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectWhatsAppForm } from '@/Pages/Inbox/Setup';

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => children }));
const page = vi.hoisted(() => ({ props: {} }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: () => null, router: { reload: vi.fn() }, usePage: () => page }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key) => key }) }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn() } }));

afterEach(() => { cleanup(); vi.unstubAllGlobals(); delete window.__fbSdkReady; page.props = {}; });

describe('WhatsApp connection-only form', () => {
    it('prepares coexistence without a phone field and opens Meta on the next click', async () => {
        page.props = { whatsappCoexistenceEnabled: true };
        document.head.innerHTML = '<meta name="csrf-token" content="test" />';
        vi.stubGlobal('route', (name) => name);
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ attempt_id: 'attempt' }) });
        vi.stubGlobal('fetch', fetchMock);
        window.__fbSdkReady = true;
        const login = vi.fn((callback) => callback({ status: 'unknown' }));
        vi.stubGlobal('FB', { login });
        render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
        fireEvent.click(screen.getByRole('radio', { name: 'inbox.keep_business_app' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({});
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        await waitFor(() => expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' }));
        await waitFor(() => expect(login).toHaveBeenCalledOnce());
        expect(login.mock.calls[0][1].extras.featureType).toBe('whatsapp_business_app_onboarding');
    });
    it('shows only connection actions, without account inventory or a repeated card heading', () => {
        const onClose = vi.fn();
        render(<ConnectWhatsAppForm onClose={onClose} metaConfigIdWhatsapp="config" metaAppId="app" />);
        expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeInTheDocument();
        expect(screen.queryByText('inbox.whatsapp_business')).not.toBeInTheDocument();
        expect(screen.queryByText('inbox.no_whatsapp_accounts')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'common.cancel' }));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('keeps cancellation available when Meta is not configured', () => {
        render(<ConnectWhatsAppForm onClose={vi.fn()} />);
        expect(screen.getByText('inbox.meta_app_not_configured')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'inbox.continue_meta_whatsapp' })).not.toBeInTheDocument();
    });

    it('keeps the SDK consent options and displays cancellation without closing the drawer', async () => {
        const onClose = vi.fn();
        window.__fbSdkReady = true;
        const login = vi.fn((callback) => callback({ status: 'unknown' }));
        vi.stubGlobal('FB', { login });
        render(<ConnectWhatsAppForm onClose={onClose} metaConfigIdWhatsapp="config" metaAppId="app" />);
        fireEvent.click(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' }));
        await waitFor(() => expect(login).toHaveBeenCalledOnce());
        expect(login.mock.calls[0][1]).toMatchObject({ config_id: 'config', response_type: 'code', override_default_response_type: true });
        expect(screen.getByText('inbox.authorization_cancelled')).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });
});
