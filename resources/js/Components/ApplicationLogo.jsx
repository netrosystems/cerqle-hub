import { usePage } from '@inertiajs/react';

export default function ApplicationLogo({ className, style, alt }) {
    let logoUrl = null;
    try {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        logoUrl = usePage().props.branding?.logo_url ?? null;
    } catch {
        logoUrl = null;
    }

    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt={alt ?? 'App Logo'}
                className={className}
                style={style}
            />
        );
    }

    // Default brand mark supplied by the current Cerqle identity.
    return (
        <img
            src="/cerqle-icon.svg"
            alt={alt ?? 'App Logo'}
            className={className}
            style={{ objectFit: 'contain', ...style }}
        />
    );
}
