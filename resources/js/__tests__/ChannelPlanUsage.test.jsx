import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChannelPlanUsage from '@/Components/ChannelPlanUsage';

let current = 'client.inbox.setup';
let props = {};
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, opts) => opts.defaultValue.replace('{{count}}', opts.count) }) }));
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

function show(routeName, key, overrides = {}, extraProps = {}) {
    current = routeName;
    vi.stubGlobal('route', () => ({ current: () => current }));
    props = { channel_plan_usage: { [key]: { key, label: 'Allowance', used: 4, limit: 5, remaining: 1, ...overrides } }, ...extraProps };
    return render(<ChannelPlanUsage />);
}

describe('channel plan usage', () => {
    it('shows used, total, remaining and organization scope', () => {
        show('client.inbox.setup', 'messaging_channels');
        expect(screen.getByRole('status')).toHaveTextContent('4/5');
        expect(screen.getByRole('status')).toHaveTextContent('1 left');
        expect(screen.getByText('Across all workspaces')).toBeInTheDocument();
    });
    it('shows unlimited without a false remaining value', () => {
        show('client.social.accounts.index', 'social_accounts', { unlimited: true, limit: null, remaining: null });
        expect(screen.getByRole('status')).toHaveTextContent('4/∞');
        expect(screen.getByRole('status')).toHaveTextContent('Unlimited');
    });
    it('keeps widget limits on create and edit routes', () => {
        show('client.inbox.chat-widgets.create', 'website_widgets');
        expect(screen.getByRole('status')).toHaveTextContent('4/5');
    });
    it('shows a full zero-allowance plan accurately', () => {
        show('client.whatsapp.widget.edit', 'whatsapp_chatbots', { used: 0, limit: 0, remaining: 0, is_full: true });
        expect(screen.getByRole('status')).toHaveTextContent('0/0');
        expect(screen.getByRole('status')).toHaveTextContent('0 left');
    });
    it('does not put messaging usage in the email inbox', () => {
        show('client.inbox.email-inbox', 'messaging_channels');
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
    it('uses the same strip for email setup with its independent mailbox allowance', () => {
        show('client.inbox.email.index', 'messaging_channels', {}, {
            mailboxUsage: { used: 2, limit: 5, remaining: 3, unlimited: false, can_connect: true },
        });
        expect(screen.getByRole('status')).toHaveTextContent('Connected email accounts: 2/5 · 3 left');
        expect(screen.getByRole('status')).not.toHaveTextContent('4/5');
    });
    it('marks full mailbox allowance in amber', () => {
        show('client.inbox.email.index', 'messaging_channels', {}, {
            mailboxUsage: { used: 5, limit: 5, remaining: 0, unlimited: false, can_connect: false },
        });
        expect(screen.getByText('5/5').parentElement).toHaveClass('text-amber-700');
        expect(screen.getByRole('status')).toHaveTextContent('0 left');
    });
    it('renders unlimited mailboxes consistently', () => {
        show('client.inbox.email.index', 'messaging_channels', {}, {
            mailboxUsage: { used: 2, limit: null, remaining: null, unlimited: true, can_connect: true },
        });
        expect(screen.getByRole('status')).toHaveTextContent('2/∞ · Unlimited');
    });
});
