import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import OAuthErrorAlert from '@/Components/Auth/OAuthErrorAlert';

describe('OAuthErrorAlert', () => {
    it('keeps OAuth callback failures visible and actionable', () => {
        render(
            <OAuthErrorAlert message="No Cerqle account matches this Google account.">
                <a href="/register">Create an account</a>
            </OAuthErrorAlert>,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('No Cerqle account matches this Google account.');
        expect(screen.getByRole('link', { name: 'Create an account' })).toHaveAttribute('href', '/register');
    });

    it('renders nothing when there is no OAuth failure', () => {
        const { container } = render(<OAuthErrorAlert message="" />);

        expect(container).toBeEmptyDOMElement();
    });
});
