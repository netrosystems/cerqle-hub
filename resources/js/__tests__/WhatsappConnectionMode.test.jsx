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

it('requires an exact number and acknowledgement before preparing coexistence', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => ({ attempt_id: 'demo-attempt' }) });
    render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
    fireEvent.click(screen.getByLabelText('Keep WhatsApp Business app'));
    expect(screen.getByRole('button', { name: 'Prepare connection' })).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '+1 555 000 0000' } });
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Prepare connection' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'inbox.continue_meta_whatsapp' })).toBeEnabled());
    expect(fetch).toHaveBeenCalledWith('/client.whatsapp.setup.coexistence.begin', expect.objectContaining({
        body: JSON.stringify({ phone: '+15550000000', acknowledge_limitations: true }),
    }));
    expect(screen.getByRole('textbox')).toBeDisabled();
    expect(screen.getByText('New messages only. Existing chats and contacts will not be imported.')).toBeInTheDocument();
});

it('does not open authorization when the server rejects the attempt', async () => {
    fetch.mockResolvedValue({ ok: false, json: async () => ({ message: 'Setup unavailable' }) });
    render(<ConnectWhatsAppForm onClose={vi.fn()} metaConfigIdWhatsapp="config" metaAppId="app" />);
    fireEvent.click(screen.getByLabelText('Keep WhatsApp Business app'));
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '+15550000000' } });
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Prepare connection' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Setup unavailable');
    expect(screen.queryByRole('button', { name: 'inbox.continue_meta_whatsapp' })).not.toBeInTheDocument();
});
