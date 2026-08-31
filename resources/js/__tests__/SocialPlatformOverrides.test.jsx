import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import SocialPlatformOverrides from '@/Components/SocialPlatformOverrides';

describe('SocialPlatformOverrides', () => {
    it('keeps platform guidance behind an accessible info tooltip', () => {
        render(
            <SocialPlatformOverrides
                networks={['instagram']}
                accounts={[]}
                value={{}}
                onChange={vi.fn()}
            />,
        );

        const guidance = 'Images publish to the feed, videos publish as Reels, and 2–10 compatible items publish as a carousel. Available capabilities depend on the connected Instagram account and API.';
        expect(screen.queryByText(guidance)).not.toBeInTheDocument();

        fireEvent.focus(screen.getByRole('button', { name: 'Instagram publishing instructions' }));
        expect(screen.getByRole('tooltip')).toHaveTextContent(guidance);
    });
});
