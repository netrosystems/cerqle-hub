import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectWhatsAppForm } from '@/Pages/Inbox/Setup';

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => children }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: () => null, router: { reload: vi.fn() }, usePage: () => ({ props: {} }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key) => key }) }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn() } }));

afterEach(() => { cleanup(); vi.unstubAllGlobals(); delete window.__fbSdkReady; });

describe('WhatsApp connection-only form', () => {
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
