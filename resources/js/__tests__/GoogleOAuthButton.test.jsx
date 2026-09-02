import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import GoogleOAuthButton from '@/Components/Auth/GoogleOAuthButton';

describe('GoogleOAuthButton', () => {
    it('uses the same visual treatment for login links and registration buttons', () => {
        const { rerender } = render(
            <GoogleOAuthButton href="/auth/google/redirect" label="Continue with Google" />,
        );
        const loginClassName = screen.getByRole('link', { name: 'Continue with Google' }).className;

        rerender(<GoogleOAuthButton onClick={() => {}} label="Continue with Google" />);

        expect(screen.getByRole('button', { name: 'Continue with Google' }).className).toBe(loginClassName);
    });

    it('runs the terms-gated registration handler when used as a button', () => {
        const onClick = vi.fn();
        render(<GoogleOAuthButton onClick={onClick} label="Continue with Google" />);

        fireEvent.click(screen.getByRole('button', { name: 'Continue with Google' }));

        expect(onClick).toHaveBeenCalledOnce();
    });
});
