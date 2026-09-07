import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import BillingUsageOverview from '@/Components/BillingUsageOverview';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { channel_plan_usage: {
    a: { key: 'a', label: 'Channels', used: 4, limit: 5, remaining: 1 },
    b: { key: 'b', label: 'Messages', used: 39, unlimited: true },
    c: { key: 'c', label: 'Widgets', used: 0, limit: 0, remaining: 0 },
} } }) }));
afterEach(cleanup);
it('distinguishes capped, unlimited, and excluded allowances', () => {
    render(<BillingUsageOverview />);
    expect(screen.getByRole('progressbar', { name: 'Channels' })).toHaveAttribute('aria-valuenow', '80');
    expect(screen.queryByRole('progressbar', { name: 'Messages' })).toBeNull();
    expect(screen.getByText('No plan cap')).toBeInTheDocument();
    expect(screen.getByText('Not included')).toBeInTheDocument();
});
