import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import SocialPlatformOverrides from '@/Components/SocialPlatformOverrides';

describe('SocialPlatformOverrides', () => {
    it.each([false, true])('shows generic X field errors with customization %s', customize => {
        render(<SocialPlatformOverrides networks={['twitter']} accounts={[]} value={{ twitter: { customize } }} errors={{ body: 'X text is invalid.', media_ids: 'X uploads are invalid.', media_urls: 'External X media is not allowed.', 'platform_payloads.twitter.body': 'X override is invalid.' }} />);
        expect(screen.getAllByRole('alert')).toHaveLength(3);
        expect(screen.getByText('X text is invalid.')).toBeVisible();
        expect(screen.getByText('X uploads are invalid.')).toBeVisible();
        expect(screen.getByText('External X media is not allowed.')).toBeVisible();
        expect(screen.getByText('X override is invalid.')).toBeVisible();
    });

    it('does not show generic X errors on another platform tab', () => {
        render(<SocialPlatformOverrides networks={['twitter', 'instagram']} accounts={[]} errors={{ body: 'X text is invalid.' }} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Instagram' }));
        expect(screen.queryByText('X text is invalid.')).not.toBeInTheDocument();
    });

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
