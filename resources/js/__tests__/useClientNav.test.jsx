import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import useClientNav from '@/Layouts/useClientNav';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
}));

describe('client navigation organization', () => {
    it('uses the requested group order and keeps ecommerce out of the sidebar', () => {
        const { result } = renderHook(() => useClientNav());
        const groups = result.current;

        expect(groups.map((group) => group.label)).toEqual([
            'nav.group_landing',
            'nav.group_inbox',
            'nav.group_chatbot_setup',
            'nav.group_social_media',
            'nav.group_messaging',
            'nav.group_contacts',
            'nav.group_broadcasting',
            'nav.group_automations',
            'nav.group_ai',
            'nav.group_reports',
            'nav.group_assets',
            'nav.group_support',
            'nav.group_billing',
            'nav.group_account',
        ]);
        expect(groups.some((group) => group.label === 'nav.group_ecommerce')).toBe(false);
    });

    it('places website and WhatsApp chatbot setup in their own group', () => {
        const { result } = renderHook(() => useClientNav());
        const landing = result.current.find((group) => group.label === 'nav.group_landing');
        const chatbotSetup = result.current.find((group) => group.label === 'nav.group_chatbot_setup');
        const messaging = result.current.find((group) => group.label === 'nav.group_messaging');
        const support = result.current.find((group) => group.label === 'nav.group_support');

        expect(landing.items.map((item) => item.label)).toEqual([
            'nav.dashboard',
            'nav.tutorials',
        ]);
        expect(chatbotSetup.items.map((item) => item.label)).toEqual([
            'nav.website_widget',
            'nav.chat_widget',
        ]);
        expect(messaging.items.map((item) => item.label)).toEqual([
            'nav.templates',
            'nav.auto_replies',
        ]);
        expect(support.items.map((item) => item.label)).toEqual([
            'nav.support_tickets',
        ]);
    });
});
