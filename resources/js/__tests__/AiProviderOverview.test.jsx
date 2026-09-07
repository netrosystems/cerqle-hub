import { cleanup, render, screen, fireEvent } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import AiProviderOverview from '@/Components/AiProviderOverview';
vi.mock('@inertiajs/react', () => ({ router: { put: vi.fn() } }));
afterEach(cleanup);
it('uses configured costs and keeps the explanation expandable', () => {
    render(<AiProviderOverview mode="auto_fallback" rates={{ rag_reply: 1, social_single_generate: 3 }} enforced={false} />);
    expect(screen.getByText('3 credits')).toBeInTheDocument();
    expect(screen.getByText('Single social post')).toBeInTheDocument();
    const summary = screen.getByText('Credit costs');
    expect(summary.closest('details')).not.toHaveAttribute('open');
    fireEvent.click(summary);
    expect(summary.closest('details')).toHaveAttribute('open');
    expect(screen.getByRole('button', { name: /Cerqle credits, then my provider/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('Tracking only')).toBeInTheDocument();
});
