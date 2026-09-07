import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/** A compact, persistent allowance display shared by setup, creation and inbox pages. */
export default function ChannelPlanUsage() {
    const { t } = useTranslation();
    const { channel_plan_usage: usage = {}, mailboxUsage, errors = {} } = usePage().props;
    const current = route().current() || '';
    let keys = [];
    if (current.startsWith('client.inbox.chat-widgets.')) keys = ['website_widgets'];
    else if (current.startsWith('client.whatsapp.widget.')) keys = ['whatsapp_chatbots'];
    else if (current.startsWith('client.social.')) keys = ['social_accounts'];
    else if (['client.inbox.index', 'client.inbox.show'].includes(current) || current.startsWith('client.inbox.setup') || current.startsWith('client.whatsapp.')) keys = ['messaging_channels', 'messaging_messages_per_month'];
    const items = keys.map(key => usage[key]).filter(Boolean);
    if (current === 'client.inbox.email.index' && mailboxUsage) {
        items.push({
            ...mailboxUsage,
            key: 'email_accounts',
            label: t('limits.labels.email_accounts', { defaultValue: 'Connected email accounts' }),
            is_full: !mailboxUsage.can_connect,
        });
    }
    if (!items.length && !errors.plan_limit) return null;

    return (
        <div className="shrink-0 border-b border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 px-4 py-2" role="status" aria-live="polite">
            <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-neutral-600 dark:text-neutral-300">
                {items.map(item => (
                    <span key={item.key} className={item.is_full ? 'text-amber-700 dark:text-amber-300' : ''}>
                        {item.label}: <strong>{item.used}/{item.unlimited ? '∞' : item.limit}</strong>
                        {' · '}{item.unlimited ? t('limits.unlimited', { defaultValue: 'Unlimited' }) : t('limits.remaining', { defaultValue: '{{count}} left', count: item.remaining })}
                    </span>
                ))}
                <span>{t('limits.organization', { defaultValue: 'Across all workspaces' })}</span>
            </div>
            {errors.plan_limit && <p role="alert" className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.plan_limit}</p>}
        </div>
    );
}
