import { useTranslation } from 'react-i18next';

const STATUS_STYLES = {
    open: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    resolved: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    snoozed: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
};

export default function ConversationStatusBadge({ status }) {
    const { t } = useTranslation();
    const value = status || 'open';

    return (
        <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold leading-none ${STATUS_STYLES[value] ?? STATUS_STYLES.open}`}>
            {t(`inbox.status_${value}`, { defaultValue: value.charAt(0).toUpperCase() + value.slice(1) })}
        </span>
    );
}
