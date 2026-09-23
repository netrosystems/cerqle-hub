import { useState } from 'react'
import { DialogTitle } from '@headlessui/react'
import { Link, useForm } from '@inertiajs/react'
import Modal from '@/Components/ui/Modal'
import Button from '@/Components/ui/Button'

const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const inputClass =
    'w-full rounded-soft border-neutral-300 bg-white text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'

export default function AiAutomationCard({ settings, group }) {
    const [open, setOpen] = useState(false)
    const [hoursOpen, setHoursOpen] = useState(false)
    const form = useForm({
        mode: settings.mode,
        chatbot_id: settings.chatbot_id ?? '',
        timezone: settings.timezone,
        weekly_hours: settings.weekly_hours,
        revision: settings.revision,
        // null means every mailbox, which is what this setting meant before it
        // could be narrowed. Only email has mailboxes to choose between.
        mailbox_ids: Array.isArray(settings.mailbox_ids) ? settings.mailbox_ids : null,
    })
    const begin = () => {
        form.setData({
            mode: settings.mode,
            chatbot_id: settings.chatbot_id ?? '',
            timezone: settings.timezone,
            weekly_hours: settings.weekly_hours.map((day) => ({ ...day })),
            revision: settings.revision,
            mailbox_ids: Array.isArray(settings.mailbox_ids) ? [...settings.mailbox_ids] : null,
        })
        form.clearErrors()
        setHoursOpen(false)
        setOpen(true)
    }
    const updateDay = (index, key, value) =>
        form.setData(
            'weekly_hours',
            form.data.weekly_hours.map((day, i) => (i === index ? { ...day, [key]: value } : day)),
        )
    const close = () => {
        if (!form.processing) setOpen(false)
    }
    const submit = (event) => {
        event.preventDefault()
        form.patch(route('client.inbox.ai-automation.update', { group }), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        })
    }
    const summary = settings.legacy ? 'Existing setup' : { off: 'Off', on: 'On', scheduled: 'Scheduled' }[settings.mode]
    return (
        <>
            <section
                aria-label="AI Automation"
                className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-soft-lg border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <div>
                    <h2 className="text-sm font-semibold">
                        AI Automation{' '}
                        <span className="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium dark:bg-neutral-800">
                            {summary}
                        </span>
                    </h2>
                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        {settings.bot_unavailable && <span className="text-amber-700 dark:text-amber-300">Chatbot unavailable · </span>}
                        {settings.chatbot_name && `${settings.chatbot_name} · `}
                        {settings.mode === 'scheduled'
                            ? `${settings.available ? 'Active now' : 'Outside hours'} · ${settings.timezone}`
                            : group === 'email'
                              ? 'One setting for every connected mailbox'
                              : 'One setting for WhatsApp, Instagram and Messenger'}
                    </p>
                </div>
                <Button variant="outline" onClick={begin}>
                    Manage AI Automation
                </Button>
            </section>
            <Modal show={open} onClose={close} maxWidth="lg" closeable={!form.processing}>
                <form onSubmit={submit}>
                    <DialogTitle className="sr-only">AI Automation</DialogTitle>
                    <Modal.Header title="AI Automation" onClose={close} />
                    <Modal.Body className="max-h-[65vh] overflow-y-auto space-y-4">
                        <fieldset>
                            <legend className="mb-2 text-xs text-neutral-500">When should the chatbot reply?</legend>
                            <div className="grid grid-cols-3 gap-2">
                                {[
                                    ['off', 'Off'],
                                    ['on', 'On'],
                                    ['scheduled', 'Scheduled'],
                                ].map(([value, label]) => (
                                    <label
                                        key={value}
                                        className={`cursor-pointer rounded-soft border p-2 text-center text-sm ${form.data.mode === value ? 'border-brand-500 bg-brand-50 dark:bg-brand-950' : 'border-neutral-200 dark:border-neutral-700'}`}
                                    >
                                        <input
                                            className="sr-only"
                                            type="radio"
                                            name="ai-mode"
                                            value={value}
                                            checked={form.data.mode === value}
                                            onChange={() => form.setData('mode', value)}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        {form.data.mode !== 'off' && (
                            <div>
                                <label htmlFor="ai-chatbot" className="mb-1 block text-sm font-medium">
                                    Chatbot
                                </label>
                                <select
                                    id="ai-chatbot"
                                    className={inputClass}
                                    value={form.data.chatbot_id}
                                    onChange={(e) => form.setData('chatbot_id', e.target.value)}
                                >
                                    <option value="">Select a chatbot</option>
                                    {settings.chatbots.map((bot) => (
                                        <option key={bot.id} value={bot.id}>
                                            {bot.name}
                                        </option>
                                    ))}
                                </select>
                                <Link
                                    href={route('client.ai.chatbots.index')}
                                    className="mt-1 inline-block text-xs text-brand-600 underline"
                                >
                                    Manage Chatbots
                                </Link>
                                {settings.chatbots.length === 0 && (
                                    <p className="mt-1 text-xs text-amber-700">
                                        Create and enable a chatbot before turning AI on.
                                    </p>
                                )}
                            </div>
                        )}
                        {form.data.mode !== 'off' && group === 'email' && (settings.mailboxes ?? []).length > 0 && (
                            <fieldset>
                                <legend className="mb-1 block text-sm font-medium">Which mailboxes</legend>
                                <p className="mb-2 text-xs text-neutral-500 dark:text-neutral-400">
                                    Mail arriving at a mailbox you do not choose is left for your team.
                                </p>
                                <label className="flex cursor-pointer items-start gap-2 rounded-soft border border-neutral-200 p-2 text-sm dark:border-neutral-700">
                                    <input
                                        type="radio"
                                        name="mailbox-scope"
                                        className="mt-0.5"
                                        checked={form.data.mailbox_ids === null}
                                        onChange={() => form.setData('mailbox_ids', null)}
                                    />
                                    <span>
                                        <span className="font-medium">All mailboxes</span>
                                        <span className="block text-xs text-neutral-500 dark:text-neutral-400">
                                            Includes any mailbox connected later.
                                        </span>
                                    </span>
                                </label>
                                <label className="mt-1.5 flex cursor-pointer items-start gap-2 rounded-soft border border-neutral-200 p-2 text-sm dark:border-neutral-700">
                                    <input
                                        type="radio"
                                        name="mailbox-scope"
                                        className="mt-0.5"
                                        checked={form.data.mailbox_ids !== null}
                                        onChange={() =>
                                            form.setData('mailbox_ids', settings.mailboxes.map((box) => box.id))
                                        }
                                    />
                                    <span className="font-medium">Only the mailboxes I choose</span>
                                </label>
                                {form.data.mailbox_ids !== null && (
                                    <div className="mt-1.5 space-y-1 rounded-soft border border-neutral-200 p-2 dark:border-neutral-700">
                                        {settings.mailboxes.map((box) => (
                                            <label key={box.id} className="flex cursor-pointer items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.mailbox_ids.includes(box.id)}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'mailbox_ids',
                                                            e.target.checked
                                                                ? [...form.data.mailbox_ids, box.id]
                                                                : form.data.mailbox_ids.filter((id) => id !== box.id),
                                                        )
                                                    }
                                                />
                                                <span className="min-w-0 flex-1 truncate">
                                                    {box.name}
                                                    {box.email && (
                                                        <span className="text-neutral-500 dark:text-neutral-400"> · {box.email}</span>
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                                {form.errors.mailbox_ids && (
                                    <p className="mt-1 text-xs text-coral-600">{form.errors.mailbox_ids}</p>
                                )}
                            </fieldset>
                        )}
                        {form.data.mode === 'scheduled' && (
                            <div>
                                <button
                                    type="button"
                                    className="flex w-full items-center justify-between rounded-soft border border-neutral-200 p-3 text-sm dark:border-neutral-700"
                                    aria-expanded={hoursOpen}
                                    onClick={() => setHoursOpen(!hoursOpen)}
                                >
                                    <span>Weekly hours · {form.data.timezone}</span>
                                    <span className="text-brand-600">{hoursOpen ? 'Hide' : 'Edit hours'}</span>
                                </button>
                                {hoursOpen && (
                                    <div className="mt-3 space-y-3">
                                        <label className="block text-xs">
                                            Timezone
                                            <input
                                                list="ai-timezones"
                                                className={`${inputClass} mt-1`}
                                                value={form.data.timezone}
                                                onChange={(e) => form.setData('timezone', e.target.value)}
                                            />
                                        </label>
                                        <datalist id="ai-timezones">
                                            {(Intl.supportedValuesOf?.('timeZone') ?? ['UTC', 'Asia/Dhaka']).map(
                                                (zone) => (
                                                    <option key={zone} value={zone} />
                                                ),
                                            )}
                                        </datalist>
                                        {form.data.weekly_hours.map((day, index) => (
                                            <div
                                                key={days[index]}
                                                className="rounded-soft border border-neutral-200 p-2 dark:border-neutral-700"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <label className="flex items-center gap-2 text-xs">
                                                        <input
                                                            type="checkbox"
                                                            checked={day.enabled}
                                                            onChange={(e) =>
                                                                updateDay(index, 'enabled', e.target.checked)
                                                            }
                                                        />
                                                        {days[index]}
                                                    </label>
                                                    {day.enabled && (
                                                        <label className="flex items-center gap-1 text-xs">
                                                            <input
                                                                type="checkbox"
                                                                checked={day.all_day}
                                                                onChange={(e) =>
                                                                    updateDay(index, 'all_day', e.target.checked)
                                                                }
                                                            />
                                                            All day
                                                        </label>
                                                    )}
                                                </div>
                                                {day.enabled && !day.all_day && (
                                                    <div className="mt-2 grid grid-cols-2 gap-2">
                                                        <label className="text-xs">
                                                            Start
                                                            <input
                                                                aria-label={`${days[index]} start`}
                                                                type="time"
                                                                className={`${inputClass} mt-1`}
                                                                value={day.start}
                                                                onChange={(e) =>
                                                                    updateDay(index, 'start', e.target.value)
                                                                }
                                                            />
                                                        </label>
                                                        <label className="text-xs">
                                                            End
                                                            <input
                                                                aria-label={`${days[index]} end`}
                                                                type="time"
                                                                className={`${inputClass} mt-1`}
                                                                value={day.end}
                                                                onChange={(e) =>
                                                                    updateDay(index, 'end', e.target.value)
                                                                }
                                                            />
                                                        </label>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                        <button
                                            type="button"
                                            className="text-xs text-brand-600 underline"
                                            onClick={() =>
                                                form.setData(
                                                    'weekly_hours',
                                                    form.data.weekly_hours.map((day, index) =>
                                                        index < 5 ? { ...form.data.weekly_hours[0] } : day,
                                                    ),
                                                )
                                            }
                                        >
                                            Copy Monday to weekdays
                                        </button>
                                        <p className="text-xs text-neutral-500">
                                            An earlier end time continues into the next day. Outside hours, messages
                                            stay with your team; AI does not reply later.
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}
                        {Object.keys(form.errors).length > 0 && (
                            <div role="alert" className="rounded-soft bg-coral-50 p-2 text-xs text-coral-700">
                                {Object.entries(form.errors).map(([key, error]) => (
                                    <p key={key}>{error}</p>
                                ))}
                            </div>
                        )}
                        <details className="text-xs text-neutral-500">
                            <summary className="cursor-pointer">How this works</summary>
                            <p className="mt-2">
                                Includes future accounts in this group. Human takeover stops AI. Workflows and reply
                                rules run first. Off only disables AI, not those rules.
                            </p>
                            {settings.legacy && (
                                <p className="mt-2">
                                    Existing per-account chatbot assignments remain until you save this grouped setting.
                                </p>
                            )}
                        </details>
                    </Modal.Body>
                    <Modal.Footer>
                        <Button variant="ghost" onClick={close} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || (form.data.mode !== 'off' && !form.data.chatbot_id)}
                        >
                            Save changes
                        </Button>
                    </Modal.Footer>
                </form>
            </Modal>
        </>
    )
}
