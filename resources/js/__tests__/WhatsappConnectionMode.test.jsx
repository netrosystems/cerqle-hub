import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ConnectWhatsAppForm } from '@/Pages/Inbox/Setup';

let enabled = true;
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { whatsappCoexistenceEnabled: enabled } }),
    router: { reload: vi.fn() }, Head: () => null, Link: () => null,
}));
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => children }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, fallback) => fallback || key }) }));

beforeEach(() => {
    enabled = true;
    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf">';
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

it('keeps the legacy flow when coexistence rollout is disabled', () => {
    enabled = false;
    render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
    expect(screen.queryByText('Keep WhatsApp Business app')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeEnabled();
});

it('prepares coexistence on mode selection without duplicate number entry', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => ({ attempt_id: 'demo-attempt' }) });
    render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
    fireEvent.click(screen.getByLabelText('Keep WhatsApp Business app'));
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeEnabled());
    expect(fetch).toHaveBeenCalledWith('/client.whatsapp.setup.coexistence.begin', expect.objectContaining({
        body: JSON.stringify({}),
    }));
    expect(screen.getByText('Keep the mobile app. New messages only.')).toBeInTheDocument();
});

it('does not open authorization when the server rejects the attempt', async () => {
    fetch.mockResolvedValue({ ok: false, json: async () => ({ message: 'Setup unavailable' }) });
    render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
    fireEvent.click(screen.getByLabelText('Keep WhatsApp Business app'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Setup unavailable');
    expect(screen.queryByRole('button', { name: 'inbox.continue_meta_whatsapp' })).not.toBeInTheDocument();
    fetch.mockResolvedValue({ ok: true, json: async () => ({ attempt_id: 'retry-attempt' }) });
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeEnabled());
});
