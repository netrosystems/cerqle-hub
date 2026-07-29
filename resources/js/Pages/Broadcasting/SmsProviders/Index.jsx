import { Head, useForm, usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, EyeOff, CheckCircle, Trash2, BookOpen, ChevronDown, ChevronUp } from 'lucide-react';

const SETUP_GUIDES = {
    twilio: {
        steps: [
            'Sign up at twilio.com and verify your account.',
            'From the Console Dashboard, copy your Account SID and Auth Token.',
            'Go to Phone Numbers → Buy a Number, choose one with SMS capability.',
            'Enter the Account SID, Auth Token, and the Twilio phone number below.',
        ],
        link: 'https://console.twilio.com',
        linkLabel: 'Open Twilio Console',
    },
    nexmo: {
        steps: [
            'Sign up at vonage.com (formerly Nexmo).',
            'From the API Dashboard, copy your API Key and API Secret.',
            'Purchase a virtual number under Numbers → Buy Numbers.',
            'Enter your API Key and API Secret below.',
        ],
        link: 'https://dashboard.nexmo.com',
        linkLabel: 'Open Vonage Dashboard',
    },
    messagebird: {
        steps: [
            'Sign up at messagebird.com.',
            'Go to Developers → API access keys → Add access key.',
            'Copy the live API key.',
            'Optionally, buy a virtual number under Numbers.',
            'Paste the API key below.',
        ],
        link: 'https://dashboard.messagebird.com/en/developers/access',
        linkLabel: 'Open MessageBird Dashboard',
    },
    smsbd: {
        steps: [
            'Register at smsbd.com.bd.',
            'After approval, log in and go to your profile to find your API credentials.',
            'Copy the API Token / User credential.',
            'Enter the credentials below.',
        ],
    },
    reve: {
        steps: [
            'Register at revesms.com or contact their sales team.',
            'Log in and navigate to your account settings to get API credentials.',
            'Copy the API key or username/password pair.',
            'Enter the credentials below.',
        ],
    },
    alaris: {
        steps: [
            'Request your HTTPS API base URL, username, password, and approved Sender ID (ANI) from PROSMS.',
            'Use the HTTPS hostname supplied by PROSMS, not its numeric IP address, so certificate verification succeeds.',
            'Cerqle uses the verified PROSMS GET API contract with command=submit and URL-encoded request fields.',
            'Use E.164 destination numbers. Cerqle sends one contact at a time, which is compatible with long SMS handling.',
            'Ask PROSMS to configure delivery reports to your Cerqle callback URL after saving this gateway.',
        ],
    },
    bulksmsbd: {
        steps: [
            'Register at bulksmsbd.com.',
            'After account activation, go to My Account → API credentials.',
            'Copy the API key.',
            'Enter the API key and your sender ID below.',
        ],
    },
    sms_dot_bd: {
        steps: [
            'Register at sms.com.bd.',
            'After account activation, find your API credentials in the account settings.',
            'Copy the API key or access token.',
            'Enter the credentials below.',
        ],
    },
    mimsms: {
        steps: [
            'Register at mimsms.com.',
            'After approval, log in and go to API Settings.',
            'Copy your API key.',
            'Enter the API key and sender ID below.',
        ],
    },
    fast2sms: {
        steps: [
            'Register at fast2sms.com (Indian mobile numbers only).',
            'After KYC approval, go to Dev API → Authorization to copy your API key.',
            'Choose route: "q" for Quick/Transactional or "dlt" for DLT promotional messages.',
            'For DLT route, register your sender ID and templates on the DLT portal first.',
            'Enter the API key, sender ID, and route below.',
        ],
        link: 'https://www.fast2sms.com/dashboard/api',
        linkLabel: 'Open Fast2SMS Dashboard',
    },
    amazon_sns: {
        steps: [
            'Log in to the AWS Console and navigate to IAM → Users → Add user.',
            'Attach the "AmazonSNSFullAccess" policy (or a scoped SNS SMS policy).',
            'Under Security Credentials, create an Access Key and copy the Access Key ID and Secret.',
            'Choose the AWS Region closest to your recipients (e.g. ap-south-1 for India, us-east-1 for global).',
            'Optionally set a Sender ID (alphanumeric, supported in select countries).',
            'For production, request an SNS SMS spending limit increase in AWS Support.',
            'Enter the Access Key, Secret, and Region below.',
        ],
        link: 'https://console.aws.amazon.com/sns/v3/home#/mobile/text-messaging',
        linkLabel: 'Open AWS SNS Console',
    },
};

function SetupGuide({ providerKey }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const guide = SETUP_GUIDES[providerKey];
    if (!guide) return null;
    return (
        <div className="pt-2 border-t border-neutral-100 dark:border-neutral-800">
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="flex items-center gap-1.5 text-xs text-brand-600 dark:text-brand-400 hover:text-brand-700 font-medium transition w-full text-left"
            >
                <BookOpen className="h-3.5 w-3.5 shrink-0" />
                {t('sms.setup_guide')}
                {open ? <ChevronUp className="h-3 w-3 ml-auto" /> : <ChevronDown className="h-3 w-3 ml-auto" />}
            </button>
            {open && (
                <div className="mt-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-3 py-2.5 space-y-2">
                    <ol className="space-y-1">
                        {guide.steps.map((step, i) => (
                            <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                <span>{step}</span>
                            </li>
                        ))}
                    </ol>
                    {guide.link && (
                        <a href={guide.link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                            {guide.linkLabel} →
                        </a>
                    )}
                </div>
            )}
        </div>
    );
}

const BRAND = {
    twilio: {
        color: '#F22F46',
        logo: 'twilio',
    },
    nexmo: {
        color: '#6E3697',
        logo: 'vonage',
    },
    messagebird: {
        color: '#2481CC',
        logo: 'messagebird',
    },
    smsbd: {
        color: '#0d9488',
        logo: 'smsbd',
    },
    reve: {
        color: '#4f46e5',
        logo: 'revesms',
    },
    alaris: {
        color: '#1f4f8f',
        logo: null,
    },
    bulksmsbd: {
        color: '#16a34a',
        logo: 'bulksmsbd',
    },
    sms_dot_bd: {
        color: '#0284c7',
        logo: 'smsdotbd',
    },
    mimsms: {
        color: '#7c3aed',
        logo: 'mimsms',
    },
    fast2sms: {
        color: '#e65c00',
        logo: 'fast2sms',
    },
    amazon_sns: {
        color: '#FF9900',
        logo: 'amazonsns',
    },
};

function ProviderBadge({ provider }) {
    const brand = BRAND[provider] ?? { color: '#6b7280', logo: null };
    return (
        <div
            className="flex items-center justify-center w-9 h-9 rounded-xl shrink-0"
            style={{ backgroundColor: brand.color }}
        >
            {brand.logo ? (
                <img
                    src={`/images/integrations/${brand.logo}.svg`}
                    alt={provider}
                    className="h-5 w-5"
                    style={{ filter: 'brightness(0) invert(1)' }}
                />
            ) : (
                <span className="text-[11px] font-bold tracking-tight text-white">AL</span>
            )}
        </div>
    );
}

function ProviderCard({ provider }) {
    const { t } = useTranslation();
    const [showSecrets, setShowSecrets] = useState({});
    const [testingConnection, setTestingConnection] = useState(false);

    const initialCredentials = {};
    (provider.fields ?? []).forEach(f => {
        initialCredentials[f.key] = provider.masked?.[f.key] ?? '';
    });

    const { data, setData, put, processing, errors } = useForm({
        credentials: initialCredentials,
        sender_id: provider.sender_id ?? '',
        default: provider.default ?? false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('client.sms-gateways.update', provider.provider), { preserveScroll: true });
    };

    const handleDelete = () => {
        if (!confirm(t('sms.remove_confirm', { label: provider.label }))) return;
        router.delete(route('client.sms-gateways.destroy', provider.provider), { preserveScroll: true });
    };

    const handleTestConnection = () => {
        setTestingConnection(true);
        router.post(
            route('client.sms-gateways.test', provider.provider),
            {},
            {
                preserveScroll: true,
                onFinish: () => setTestingConnection(false),
            },
        );
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2.5">
                    <ProviderBadge provider={provider.provider} />
                    <h3 className="font-semibold text-sm text-neutral-900 dark:text-neutral-100 leading-tight">
                        {provider.label}
                    </h3>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    {provider.configured && (
                        <span className="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                            <CheckCircle className="h-3.5 w-3.5" /> {t('sms.configured')}
                        </span>
                    )}
                    {provider.default && (
                        <span className="text-xs px-2 py-0.5 rounded-full bg-brand-100 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 font-medium">
                            {t('sms.default')}
                        </span>
                    )}
                </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-3">
                {(provider.fields ?? []).map(field => (
                    <div key={field.key}>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            {field.label}{field.required && ' *'}
                        </label>
                        <div className="relative mt-1">
                            {field.type === 'select' ? (
                                <select
                                    value={data.credentials[field.key] || 'split'}
                                    onChange={e => setData('credentials', { ...data.credentials, [field.key]: e.target.value })}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                >
                                    {(field.options ?? []).map(option => (
                                        <option key={option} value={option}>{option}</option>
                                    ))}
                                </select>
                            ) : (
                                <input
                                    type={field.type === 'password' && !showSecrets[field.key] ? 'password' : 'text'}
                                    value={data.credentials[field.key] ?? ''}
                                    onChange={e => setData('credentials', { ...data.credentials, [field.key]: e.target.value })}
                                    placeholder={provider.configured ? t('sms.encrypted_placeholder') : ''}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm"
                                />
                            )}
                            {field.type === 'password' && (
                                <button
                                    type="button"
                                    onClick={() => setShowSecrets(s => ({ ...s, [field.key]: !s[field.key] }))}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600"
                                >
                                    {showSecrets[field.key] ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                            )}
                        </div>
                        {errors[`credentials.${field.key}`] && (
                            <p className="mt-1 text-xs text-red-500">{errors[`credentials.${field.key}`]}</p>
                        )}
                    </div>
                ))}

                {provider.provider !== 'alaris' && <div>
                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('sms.sender_id')}</label>
                    <input
                        type="text"
                        value={data.sender_id}
                        onChange={e => setData('sender_id', e.target.value)}
                        placeholder={t('sms.sender_id_placeholder')}
                        className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                    />
                </div>}

                <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                        type="checkbox"
                        checked={data.default}
                        onChange={e => setData('default', e.target.checked)}
                        className="rounded"
                    />
                    {t('sms.set_default')}
                </label>

                <div className="flex gap-2 pt-1">
                    <button
                        type="submit"
                        disabled={processing}
                        className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                    >
                        {processing ? t('sms.saving') : t('common.save')}
                    </button>
                    {provider.configured && provider.provider === 'alaris' && (
                        <button
                            type="button"
                            onClick={handleTestConnection}
                            disabled={testingConnection}
                            className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm text-neutral-600 dark:text-neutral-300 hover:border-brand-300 hover:text-brand-600 disabled:opacity-60 transition"
                        >
                            {testingConnection ? 'Testing…' : 'Test connection'}
                        </button>
                    )}
                    {provider.configured && (
                        <button
                            type="button"
                            onClick={handleDelete}
                            className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-neutral-500 hover:text-red-500 hover:border-red-300 transition"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    )}
                </div>
            </form>
            <SetupGuide providerKey={provider.provider} />
        </div>
    );
}

export default function SmsProvidersIndex({ providers }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    return (
        <ClientLayout title={t('sms.title')}>
            <Head title={t('sms.title')} />
            <div className="space-y-5">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('sms.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('sms.subtitle')}
                    </p>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">
                        {flash.error}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {providers.map(p => (
                        <ProviderCard key={p.provider} provider={p} />
                    ))}
                </div>
            </div>
        </ClientLayout>
    );
}
