import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import {
    LayoutDashboard, CreditCard, Package, FileText, Users, Settings,
    Layers, Webhook, Key, BookOpen, Image, Radio, Inbox, Bot, Database,
    Zap, Share2, Tag, LifeBuoy, MessageSquare,
    MessageCircle, Mail, Monitor, ShieldCheck,
} from 'lucide-react';

const iconClass = 'h-4 w-4';
const whatsappNavIcon = <ChannelBrandIcon channel="whatsapp" className={iconClass} />;

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return '#'; }
}

/**
 * Single source of truth for the client-panel sidebar navigation.
 *
 * Both ClientLayout and InboxLayout consume this so the sidebar is identical on
 * every client page. Previously each layout kept its own copy and they drifted —
 * the inbox sidebar was missing whole groups (Social Media and Automations)
 * and items. Keep all nav changes here only.
 */
export default function useClientNav() {
    const { auth, branding, entitlements } = usePage().props;
    const { t } = useTranslation();
    const user = auth?.user;
    const docsUrl = branding?.docs_url;
    const isClientAdmin = user?.client_role === 'administrator';

    const landingItems = [
        { label: t('nav.dashboard'), href: safeRoute('client.dashboard'), icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.dashboard' },
        {
            label: t('nav.tutorials'),
            href: docsUrl || safeRoute('client.onboarding.show'),
            icon: <BookOpen className={iconClass} />,
            activePattern: docsUrl ? null : 'client.onboarding.*',
            external: Boolean(docsUrl),
        },
    ];

    const accountSettingsItems = [
        { label: t('nav.settings'),  href: safeRoute('client.settings.index'),    icon: <Settings className={iconClass} />,    activePattern: 'client.settings.*' },
        { label: t('nav.twoFactor'), href: safeRoute('client.profile.2fa'),       icon: <ShieldCheck className={iconClass} />, activePattern: 'client.profile.2fa*' },
        { label: t('nav.sessions'),  href: safeRoute('client.profile.sessions'),  icon: <Monitor className={iconClass} />,     activePattern: 'client.profile.sessions*' },
        { label: t('nav.workspaces'), href: safeRoute('client.workspaces.index'), icon: <Layers className={iconClass} />,   activePattern: 'client.workspaces.*' },
    ];

    if (isClientAdmin) {
        accountSettingsItems.push(
            { label: t('nav.team'),      href: safeRoute('client.team.index'),      icon: <Users className={iconClass} />,    activePattern: 'client.team.*' },
            { label: t('nav.audit_log'), href: safeRoute('client.audit-log.index'), icon: <FileText className={iconClass} />, activePattern: 'client.audit-log.*' },
        );
    }

    const billingItems = [
        { label: t('nav.subscription'), href: safeRoute('client.subscription.show'), icon: <CreditCard className={iconClass} />, activePattern: 'client.subscription.*' },
        { label: t('nav.billing'),      href: safeRoute('client.billing.index'),     icon: <CreditCard className={iconClass} />, activePattern: 'client.billing.*' },
        { label: t('nav.plans'),        href: safeRoute('client.pricing'),            icon: <Package className={iconClass} />,    activePattern: 'client.pricing' },
        { label: t('nav.addons', { defaultValue: 'Add-ons' }), href: safeRoute('client.addons.index'), icon: <Package className={iconClass} />, activePattern: 'client.addons.*' },
    ];

    const developerItems = [
        { label: t('nav.api_tokens'),    href: safeRoute('client.api-tokens.index'), icon: <Key className={iconClass} />,     activePattern: 'client.api-tokens.*' },
        { label: t('nav.webhooks'),      href: safeRoute('client.webhooks.index'),    icon: <Webhook className={iconClass} />,  activePattern: 'client.webhooks.*' },
        { label: t('nav.api_docs'),      href: safeRoute('client.api-docs'),          icon: <BookOpen className={iconClass} />, activePattern: 'client.api-docs' },
    ];

    const assetItems = [
        { label: t('nav.media_library'), href: safeRoute('client.media.index'), icon: <Image className={iconClass} />, activePattern: 'client.media.*' },
    ];

    const supportItems = [
        { label: t('nav.support_tickets'), href: safeRoute('client.support.index'), icon: <LifeBuoy className={iconClass} />,   activePattern: 'client.support.*' },
    ];

    const contactsItems = [
        { label: t('nav.contacts'),  href: safeRoute('client.contacts.index'),  icon: <Users className={iconClass} />,  activePattern: 'client.contacts.*' },
        { label: t('nav.segments'),  href: safeRoute('client.segments.index'),  icon: <Tag className={iconClass} />,    activePattern: 'client.segments.*' },
    ];

    const messagingItems = [
        { label: t('nav.templates'),     href: safeRoute('client.whatsapp.templates.index'),     icon: whatsappNavIcon, activePattern: 'client.whatsapp.templates.*' },
        { label: t('nav.auto_replies'),  href: safeRoute('client.whatsapp.auto-replies.index'),  icon: whatsappNavIcon, activePattern: 'client.whatsapp.auto-replies.*' },
    ];

    const inboxItems = [
        { label: 'inBOX', href: safeRoute('client.inbox.index'), icon: <Inbox className={iconClass} />, activePattern: 'client.inbox.index' },
        { label: 'Email inBOX', href: safeRoute('client.inbox.email-inbox'), icon: <Mail className={iconClass} />, activePattern: 'client.inbox.email-inbox' },
    ];

    const inboxSetupItems = [
        { label: t('nav.channel_setup'),  href: safeRoute('client.inbox.setup'),               icon: <Inbox className={iconClass} />,         activePattern: 'client.inbox.setup' },
        { label: t('nav.email_setup', { defaultValue: 'Email Setup' }), href: safeRoute('client.inbox.email.index'), icon: <Mail className={iconClass} />, activePattern: 'client.inbox.email.*' },
    ];

    const chatbotSetupItems = [
        { label: t('nav.website_widget', { defaultValue: 'Website Widget' }), href: safeRoute('client.inbox.chat-widgets.index'), icon: <MessageCircle className={iconClass} />, activePattern: 'client.inbox.chat-widgets.*' },
        { label: t('nav.chat_widget'), href: safeRoute('client.whatsapp.widget.index'), icon: whatsappNavIcon, activePattern: 'client.whatsapp.widget.*' },
    ];

    const automationToolsItems = [
        { label: t('nav.automations'), href: safeRoute('client.automations.index'), icon: <Zap className={iconClass} />, activePattern: 'client.automations.*' },
        { label: t('nav.chatbots'),        href: safeRoute('client.ai.chatbots.index'),        icon: <Bot className={iconClass} />,      activePattern: 'client.ai.chatbots.*' },
        { label: t('nav.knowledge_bases'), href: safeRoute('client.ai.knowledge-bases.index'), icon: <Database className={iconClass} />, activePattern: 'client.ai.knowledge-bases.*' },
    ];

    const socialPublishingItems = [
        { label: t('nav.post_composer'),   href: safeRoute('client.social.composer'),        icon: <FileText className={iconClass} />,       activePattern: 'client.social.composer' },
        { label: t('nav.posts'),           href: safeRoute('client.social.posts.index'),     icon: <Radio className={iconClass} />,           activePattern: 'client.social.posts.*' },
        { label: t('nav.calendar'),        href: safeRoute('client.social.calendar'),         icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.social.calendar' },
    ];

    const campaignItems = [
        { label: t('nav.sms_campaigns'), href: safeRoute('client.campaigns.index'), icon: <Radio className={iconClass} />, activePattern: 'client.campaigns.*' },
        ...messagingItems,
    ];

    const setupItems = [
        ...inboxSetupItems,
        ...chatbotSetupItems,
        { label: t('nav.social_accounts'), href: safeRoute('client.social.accounts.index'), icon: <Share2 className={iconClass} />, activePattern: 'client.social.accounts.*' },
        { label: t('nav.sms_gateways'), href: safeRoute('client.sms-gateways.index'), icon: <MessageSquare className={iconClass} />, activePattern: 'client.sms-gateways.*' },
        { label: t('nav.ai_providers'), href: safeRoute('client.ai.providers.index'), icon: <Bot className={iconClass} />, activePattern: 'client.ai.providers.*' },
    ];

    const reportsItems = [
        { label: t('nav.reports_inbox'),       href: safeRoute('client.reports.inbox.index'),       icon: <Inbox className={iconClass} />,  activePattern: 'client.reports.inbox.*' },
        { label: t('nav.automations'),         href: safeRoute('client.reports.automations.index'), icon: <Zap className={iconClass} />,    activePattern: 'client.reports.automations.*' },
        { label: t('nav.ai_usage'),            href: safeRoute('client.reports.ai.index'),          icon: <Bot className={iconClass} />,    activePattern: 'client.reports.ai.*' },
        { label: t('nav.social'),              href: safeRoute('client.reports.social.index'),      icon: <Share2 className={iconClass} />, activePattern: 'client.reports.social.*' },
    ];

    // E-Commerce remains available at route level but is intentionally omitted
    // from the client sidebar until the product area is ready to be promoted.
    return [
        { type: 'group', label: t('nav.home'), items: landingItems, defaultOpen: true },
        { type: 'group', label: t('nav.group_inbox'), items: inboxItems, defaultOpen: true },
        { type: 'group', label: t('nav.group_contacts'), items: contactsItems, defaultOpen: true },
        { type: 'group', label: t('nav.group_setup', { defaultValue: 'Setup' }), items: setupItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_campaigns', { defaultValue: 'Campaigns' }), items: campaignItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_social_media'), items: socialPublishingItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_automations'), items: automationToolsItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_reports'), items: reportsItems, defaultOpen: false },
        { type: 'group', label: 'Assets', items: assetItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_support'), items: supportItems, defaultOpen: false },
        { type: 'group', label: t('nav.group_billing'), items: billingItems, defaultOpen: false },
        ...(entitlements?.developer_tools
            ? [{ type: 'group', label: t('nav.group_developer'), items: developerItems, defaultOpen: false }]
            : []),
        { type: 'group', label: t('nav.group_account'), items: accountSettingsItems, defaultOpen: false },
    ];
}
