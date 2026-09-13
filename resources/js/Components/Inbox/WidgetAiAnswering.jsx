import { useTranslation } from 'react-i18next';

export const defaultWidgetHours = () => Array.from({ length: 7 }, (_, day) => ({ enabled: day < 5, all_day: false, windows: [{ start: '09:00', end: '17:00' }] }));
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const input = 'w-full rounded-soft border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:ring-2 focus:ring-brand-500/30';

export default function WidgetAiAnswering({ data, setData, chatbots, errors = {} }) {
    const { t } = useTranslation();
    const text = (label) => t(`widget_ai.${label.toLowerCase().replaceAll(' ', '_')}`, { defaultValue: label });
    const hours = data.ai_weekly_hours;
    const changeDay = (day, patch) => setData('ai_weekly_hours', hours.map((entry, index) => index === day ? { ...entry, ...patch } : entry));
    const changeWindow = (day, index, key, value) => changeDay(day, { windows: hours[day].windows.map((window, i) => i === index ? { ...window, [key]: value } : window) });
    return <div className="space-y-3">
        <fieldset>
            <legend className="sr-only">{text('AI answering mode')}</legend>
            <div className="grid grid-cols-3 gap-2">
                {['off', 'permanent', 'scheduled'].map((mode) => <label key={mode} className={`flex cursor-pointer items-center justify-center gap-2 rounded-soft border px-2 py-2 text-sm ${data.ai_mode === mode ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-200' : 'border-neutral-300 dark:border-neutral-700'}`}>
                    <input type="radio" name="widget-ai-mode" value={mode} checked={data.ai_mode === mode} onChange={() => setData((previous) => ({ ...previous, ai_mode: mode, ai_enabled: mode !== 'off' }))} className="h-3 w-3 text-brand-600 focus:ring-brand-500" />
                    {text(mode[0].toUpperCase() + mode.slice(1))}
                </label>)}
            </div>
        </fieldset>
        {data.ai_mode !== 'off' && <label className="block text-sm">
            <span className="mb-1 block">{text('Chatbot')}</span>
            <select className={input} value={data.ai_chatbot_id || ''} onChange={(event) => setData('ai_chatbot_id', event.target.value)}>
                <option value="">{text('Select a chatbot')}</option>
                {chatbots.map((bot) => <option key={bot.id} value={bot.id}>{bot.name}</option>)}
            </select>
        </label>}
        {data.ai_mode !== 'off' && !chatbots.length && <p className="text-xs text-amber-700 dark:text-amber-300">{text('No enabled chatbots available.')}</p>}
        {data.ai_mode !== 'off' && <a href={route('client.ai.chatbots.index')} className="text-xs text-brand-600 underline">{text('Manage Chatbots')}</a>}
        {data.ai_mode === 'scheduled' && <details className="rounded-soft-lg border border-neutral-200 p-3 dark:border-neutral-700">
            <summary className="cursor-pointer text-sm">{text('Edit hours')} · {data.ai_timezone}</summary>
            <div className="mt-3 max-h-[420px] space-y-3 overflow-y-auto pr-1">
                <label className="block text-sm">{text('Timezone')}<input className={input} value={data.ai_timezone} onChange={(event) => setData('ai_timezone', event.target.value)} placeholder="Asia/Dhaka" /></label>
                {hours.map((entry, day) => <div key={day} className="space-y-2 rounded-soft border border-neutral-200 p-2 dark:border-neutral-700">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <label><input type="checkbox" checked={entry.enabled} onChange={(event) => changeDay(day, { enabled: event.target.checked })} className="mr-2 rounded text-brand-600" />{text(days[day])}</label>
                        {entry.enabled && <label className="text-xs"><input type="checkbox" checked={entry.all_day} onChange={(event) => changeDay(day, { all_day: event.target.checked })} className="mr-2 rounded text-brand-600" />{text('All day')}</label>}
                    </div>
                    {entry.enabled && !entry.all_day && <>
                        {entry.windows.map((window, index) => <div key={index} className="flex items-center gap-2">
                            <input type="time" aria-label={`${text(days[day])} ${text('start')} ${index + 1}`} className={`${input} min-w-0`} value={window.start} onChange={(event) => changeWindow(day, index, 'start', event.target.value)} />
                            <span className="text-xs">–</span>
                            <input type="time" aria-label={`${text(days[day])} ${text('end')} ${index + 1}`} className={`${input} min-w-0`} value={window.end} onChange={(event) => changeWindow(day, index, 'end', event.target.value)} />
                            <button type="button" aria-label={`${text('Remove hours')} ${text(days[day])} ${index + 1}`} onClick={() => changeDay(day, { windows: entry.windows.filter((_, i) => i !== index) })} className="rounded p-2 text-neutral-500 focus:ring-2 focus:ring-brand-500">×</button>
                        </div>)}
                        <button type="button" disabled={entry.windows.length >= 5} onClick={() => changeDay(day, { windows: [...entry.windows, { start: '', end: '' }] })} className="text-xs text-brand-600 disabled:opacity-40">{text('Add hours')}</button>
                    </>}
                    {day === 0 && <button type="button" onClick={() => setData('ai_weekly_hours', hours.map((item, i) => i < 5 ? structuredClone(entry) : item))} className="text-xs text-brand-600">{text('Copy Monday to weekdays')}</button>}
                </div>)}
                <p className="text-xs text-neutral-500">{text('An earlier end continues into the next day.')}</p>
            </div>
        </details>}
        {Object.entries(errors).filter(([key]) => key.startsWith('ai_')).map(([key, error]) => <p key={key} role="alert" className="text-xs text-red-600 dark:text-red-400">{error}</p>)}
        <details className="text-xs text-neutral-500"><summary className="cursor-pointer">{text('How this works')}</summary><p className="mt-2">{text('Permanent answers at any time. Scheduled answers only within your hours. Off and outside hours leave messages for your team; AI will not reply later.')}</p></details>
    </div>;
}
