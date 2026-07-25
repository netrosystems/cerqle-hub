/**
 * Copy for the license/activation UI, adapted to the configured verify_type.
 * Marketplace purchase codes and direct license codes are both presented with
 * neutral customer-facing wording.
 */

/** Short label for a verify type, used in the code-type chooser. */
export function typeLabel(type) {
    if (type === 'envato') return 'Purchase code';
    if (type === 'gumroad') return 'Gumroad license';
    return 'License code';
}

export function licenseCopy(verifyType) {
    const envato = verifyType === 'envato';

    return {
        envato,
        label: envato ? 'Purchase code' : 'License code',
        placeholder: envato
            ? 'e.g. 8f9c1e2a-4b6d-4f3a-9c7e-1a2b3c4d5e6f'
            : 'e.g. XXXX-XXXX-XXXX-XXXX',
        hint: envato
            ? 'Your purchase code was sent with your purchase. Activation registers this installation with the license server.'
            : 'Your license code was sent with your purchase. Activation registers this installation with the license server.',
        helpUrl: null,
        helpText: 'Where do I find my purchase code?',
        // Buyer/name field.
        nameLabel: envato ? 'Buyer name' : 'Your name (optional)',
        namePlaceholder: envato
            ? 'The username or name the item was purchased with'
            : 'The name your license was issued to',
        nameRequired: envato,
        activateLabel: envato ? 'Verify & activate' : 'Activate license',
        activatingLabel: envato ? 'Verifying…' : 'Activating…',
        stepLabel: 'License',
        stepDesc: envato ? 'Verify your purchase' : 'Activate your license',
        stepTitle: envato ? 'Verify your purchase' : 'License activation',
        stepSubtitle: envato
            ? 'Enter your purchase code to activate this installation.'
            : 'Enter your license code to activate this installation.',
    };
}
