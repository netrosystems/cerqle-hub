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
            'nav.home',
            'nav.group_inbox',
            'nav.group_contacts',
            'nav.group_setup',
            'nav.group_campaigns',
            'nav.group_social_media',
            'nav.group_automations',
            'nav.group_reports',
            'Assets',
            'nav.group_support',
            'nav.group_billing',
            'nav.group_account',
        ]);
        expect(groups.some((group) => group.label === 'nav.group_ecommerce')).toBe(false);
    });

    it('prioritizes inboxes and moves technical connections into Setup', () => {
        const { result } = renderHook(() => useClientNav());
        const landing = result.current.find((group) => group.label === 'nav.home');
        const inbox = result.current.find((group) => group.label === 'nav.group_inbox');
        const setup = result.current.find((group) => group.label === 'nav.group_setup');
        const campaigns = result.current.find((group) => group.label === 'nav.group_campaigns');
        const reports = result.current.find((group) => group.label === 'nav.group_reports');
        const support = result.current.find((group) => group.label === 'nav.group_support');

        expect(landing.items.map((item) => item.label)).toEqual([
            'nav.dashboard',
            'nav.tutorials',
        ]);
        expect(inbox.items.map((item) => item.label)).toEqual([
            'inBOX',
            'Email inBOX',
        ]);
        expect(setup.items.map((item) => item.label)).toEqual([
            'nav.channel_setup',
            'nav.email_setup',
            'nav.website_widget',
            'nav.chat_widget',
            'nav.social_accounts',
            'nav.sms_gateways',
            'nav.ai_providers',
        ]);
        expect(campaigns.items.map((item) => item.label)).toEqual([
            'nav.sms_campaigns',
            'nav.templates',
            'nav.auto_replies',
        ]);
        expect(reports.items.map((item) => item.label)).toEqual([
            'nav.reports_inbox',
            'nav.automations',
            'nav.ai_usage',
            'nav.social',
        ]);
        expect(inbox.defaultOpen).toBe(true);
        expect(setup.defaultOpen).toBe(false);
        expect(support.items.map((item) => item.label)).toEqual([
            'nav.support_tickets',
        ]);
    });

    it('keeps security and session management in the Account group', () => {
        const { result } = renderHook(() => useClientNav());
        const account = result.current.find((group) => group.label === 'nav.group_account');

        expect(account.items.slice(0, 4).map((item) => item.label)).toEqual([
            'nav.settings',
            'nav.twoFactor',
            'nav.sessions',
            'nav.workspaces',
        ]);
        expect(account.items.find((item) => item.label === 'nav.twoFactor').activePattern).toBe('client.profile.2fa*');
        expect(account.items.find((item) => item.label === 'nav.sessions').activePattern).toBe('client.profile.sessions*');
    });
});
