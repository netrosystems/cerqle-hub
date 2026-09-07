import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import HeaderAiCredits from '@/Components/HeaderAiCredits';
let credits;
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { headerAiCredits: credits } }), Link: ({ children, ...props }) => <a {...props}>{children}</a> }));
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
it('shows available credits and opens provider settings', () => {
    vi.stubGlobal('route', vi.fn(() => '/app/ai/providers'));
    credits = { mode: 'managed', remaining: 80, used: 15, reserved: 5, allowance: 100 };
    render(<HeaderAiCredits />);
    expect(screen.getByText('80 AI credits')).toBeInTheDocument();
    expect(screen.getByRole('link')).toHaveAttribute('href', '/app/ai/providers');
    expect(route).toHaveBeenCalledWith('client.ai.providers.index');
    expect(screen.getByRole('link')).toHaveAttribute('title', expect.stringContaining('5 reserved'));
});
it('does not show a false credit exhaustion warning in BYOK mode', () => {
    vi.stubGlobal('route', () => '/app/subscription');
    credits = { mode: 'byok', remaining: 0, exhausted: true };
    render(<HeaderAiCredits />);
    expect(screen.getByText('Own API')).toBeInTheDocument();
    expect(screen.getByRole('link')).not.toHaveClass('text-red-600');
});
