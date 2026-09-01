import { Button, Input } from '@/Components/ui';
import { useTranslation } from 'react-i18next';

const LIMIT_KEYS = [
    'users',
    'workspaces',
    'storage',
    'whatsapp_accounts',
    'whatsapp_templates',
    'whatsapp_messages_per_month',
    'campaigns_per_month',
    'sms_per_month',
    'emails_per_month',
    'inbox_agents',
    'ai_credits_per_month',
    'knowledge_bases',
    'chatbots',
    'social_accounts',
    'social_posts_per_month',
    'automations',
];

const LABELS = {
    users: 'Users',
    workspaces: 'Workspaces',
    storage: 'Storage (MB)',
    whatsapp_accounts: 'WhatsApp Accounts',
    whatsapp_templates: 'WhatsApp Templates',
    whatsapp_messages_per_month: 'WhatsApp Messages / mo',
    campaigns_per_month: 'Campaigns / mo',
    sms_per_month: 'SMS Messages / mo',
    emails_per_month: 'Emails / mo',
    inbox_agents: 'Inbox Agents',
    ai_credits_per_month: 'Cerqle AI Credits / month',
    knowledge_bases: 'Knowledge Bases',
    chatbots: 'Chatbots',
    social_accounts: 'Social Accounts',
    social_posts_per_month: 'Social Posts / mo',
    automations: 'Automations',
};

const DEFAULT_LIMITS = Object.fromEntries(LIMIT_KEYS.map((k) => [k, null]));

export default function PlanLimits({ limits = {}, onChange }) {
    const { t } = useTranslation();
    const value = { ...DEFAULT_LIMITS, ...limits };

    const update = (key, v) => {
        const next = { ...value, [key]: v === '' || v === undefined ? null : Number(v) };
        onChange(next);
    };

    const setAllUnlimited = () => {
        onChange(Object.fromEntries(LIMIT_KEYS.map((k) => [k, k === 'ai_credits_per_month' ? 0 : null])));
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.plan_limits')}</span>
                <Button type="button" variant="outline" size="sm" onClick={setAllUnlimited}>
                    {t('admin.unlimited')}
                </Button>
            </div>
            <div className="grid grid-cols-2 gap-4">
                {LIMIT_KEYS.map((key) => (
                    <Input
                        key={key}
                        type="number"
                        min={0}
                        label={LABELS[key]}
                        value={key === 'ai_credits_per_month' ? (value[key] ?? 0) : (value[key] ?? '')}
                        onChange={(e) => update(key, e.target.value ? e.target.value : (key === 'ai_credits_per_month' ? 0 : null))}
                        placeholder={key === 'ai_credits_per_month' ? '0 (no managed credits)' : t('admin.unlimited_placeholder')}
                    />
                ))}
            </div>
        </div>
    );
}
