import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * Reproduces what production does after the Google round trip.
 *
 * The callback redirects to /register or /login with `errors.oauth` set. That
 * redirect is a full page load, so the page's useForm() is brand new and its
 * errors are empty — the message exists only in the page props. Reading it
 * from the form alone rendered nothing, and a failed Google signup looked like
 * a button that did nothing.
 */

const pageProps = { errors: {} };

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...rest }) => <a {...rest}>{children}</a>,
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: pageProps }),
    // A fresh form on a fresh page load: no errors of its own.
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        setError: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key, options) => options?.defaultValue ?? key }),
}));

vi.mock('@/Layouts/AuthLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

beforeAll(() => {
    globalThis.route = () => '/';
});

afterEach(() => {
    cleanup();
    pageProps.errors = {};
});

describe('an OAuth error that arrives by redirect', () => {
    it('is shown on the Register page', async () => {
        pageProps.errors = { oauth: 'An account already exists. Sign in instead.' };
        const { default: Register } = await import('@/Pages/Auth/Register');

        render(<Register googleSignupEnabled />);

        expect(screen.getByRole('alert')).toHaveTextContent('An account already exists. Sign in instead.');
    });

    it('is shown on the Login page', async () => {
        pageProps.errors = { oauth: 'Social login failed. Please try again.' };
        const { default: Login } = await import('@/Pages/Auth/Login');

        render(<Login socialProviders={['google']} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Social login failed. Please try again.');
    });

    it('shows nothing when there is no OAuth error', async () => {
        const { default: Register } = await import('@/Pages/Auth/Register');

        render(<Register googleSignupEnabled />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
