import { useState } from 'react'
import { DialogTitle } from '@headlessui/react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import Modal from '@/Components/ui/Modal'
import Button from '@/Components/ui/Button'
import useNotificationAvailability, {
    AVAILABILITY_URL,
    availabilityRequestOptions,
    notifyAvailabilityChanged,
} from '@/hooks/useNotificationAvailability'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const inputClass =
    'w-full rounded-soft border-neutral-300 bg-white text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'
export const defaultNotificationHours = () =>
    DAYS.map((_, index) => ({ enabled: index < 5, all_day: false, start: '09:00', end: '17:00' }))

export function NotificationAvailabilitySummary({ availability, showHours = false }) {
    const { t } = useTranslation()
    const text = (label) => t(`settings.availability.${label}`, { defaultValue: label })
    if (!availability) return null
    return (
        <span className="text-xs text-neutral-500 dark:text-neutral-400">
            {text({ always: 'Always', scheduled: 'Scheduled', paused: 'Paused' }[availability.mode] ?? 'Unavailable')}
            {' · '}
            {text(availability.active ? 'Receiving alerts' : 'Alerts silenced')}
            {availability.timezone && ` · ${availability.timezone}`}
            {showHours && availability.mode === 'scheduled' && (
                <span className="block mt-1">{availability.weekly_hours?.map((day, index) => day.enabled
                    ? `${text(DAYS[index])}: ${day.all_day ? text('All day') : `${day.start}–${day.end}`}` : null).filter(Boolean).join(' · ')}</span>
            )}
        </span>
    )
}

export default function NotificationAvailabilityCard({ workspaceId, workspaceName }) {
    const { t } = useTranslation()
    const text = (label) => t(`settings.availability.${label}`, { defaultValue: label })
    const { availability, loading, error, refresh } = useNotificationAvailability(workspaceId)
    const [draft, setDraft] = useState(null)
    const [errors, setErrors] = useState([])
    const [saving, setSaving] = useState(false)
    const [hoursOpen, setHoursOpen] = useState(false)
    const begin = () => {
        setDraft({
            mode: availability.mode,
            timezone: availability.revision === 0 ? (Intl.DateTimeFormat().resolvedOptions().timeZone || availability.timezone) : availability.timezone,
            weekly_hours: (availability.weekly_hours?.length === 7
                ? availability.weekly_hours
                : defaultNotificationHours()
            ).map((day) => ({ ...day })),
            revision: availability.revision,
            workspace_id: workspaceId,
        })
        setErrors([])
        setHoursOpen(false)
    }
    const update = (key, value) => setDraft((previous) => ({ ...previous, [key]: value }))
    const updateDay = (index, patch) =>
        update(
            'weekly_hours',
            draft.weekly_hours.map((day, i) => (i === index ? { ...day, ...patch } : day)),
        )
    const close = () => {
        if (!saving) setDraft(null)
    }
    const submit = async (event) => {
        event.preventDefault()
        setErrors([])
        setSaving(true)
        try {
            const { data } = await axios.patch(AVAILABILITY_URL, draft, availabilityRequestOptions())
            if (Number(data.workspace_id) !== Number(workspaceId))
                throw new Error(text('Workspace changed. Reload availability.'))
            notifyAvailabilityChanged(workspaceId)
            setDraft(null)
        } catch (failure) {
            const conflict = failure.response?.status === 409 || !!failure.response?.data?.errors?.revision
            setErrors(
                conflict
                    ? [text('Availability changed elsewhere. Cancel and reopen to review the latest settings.')]
                    : Object.values(failure.response?.data?.errors ?? {}).flat().length
                      ? Object.values(failure.response.data.errors).flat()
                      : [failure.response?.data?.message ?? text('Could not save availability. Please try again.')],
            )
            if (conflict) refresh()
        } finally {
            setSaving(false)
        }
    }
    // A workspace switch unmounts this keyed card, discarding the old workspace draft.
    return (
        <>
            <section
                aria-label={text('My availability')}
                className="flex flex-wrap items-center justify-between gap-3 rounded-soft-lg border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        {text('My availability')}
                    </h2>
                    {workspaceName && <p className="text-xs text-neutral-500">{workspaceName}</p>}
                    {availability ? (
                        <NotificationAvailabilitySummary availability={availability} />
                    ) : (
                        <p className="text-xs text-neutral-500">
                            {text(loading ? 'Loading availability…' : 'Availability unavailable')}
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-xs text-coral-600">
                            {text('Could not refresh availability.')}{' '}
                            <button type="button" className="underline" onClick={refresh}>
                                {text('Retry')}
                            </button>
                        </p>
                    )}
                    <p className="mt-1 text-xs text-neutral-500">{text('Controls your notifications, not conversation assignments or team online status.')}</p>
                </div>
                <Button variant="outline" disabled={!availability || saving} onClick={begin}>
                    {text('Manage availability')}
                </Button>
            </section>
            <Modal show={!!draft} onClose={close} closeable={!saving} maxWidth="lg">
                {draft && (
                    <form onSubmit={submit}>
                        <DialogTitle className="sr-only">{text('My availability')}</DialogTitle>
                        <Modal.Header title={text('My availability')} onClose={close} showClose={!saving} />
                        <Modal.Body className="max-h-[65vh] overflow-y-auto space-y-4">
                            <fieldset disabled={saving}>
                                <legend className="mb-2 text-xs text-neutral-500">
                                    {text('When should work notifications alert you?')}
                                </legend>
                                <div className="grid grid-cols-3 gap-2">
                                    {[
                                        ['always', 'Always'],
                                        ['scheduled', 'Scheduled'],
                                        ['paused', 'Paused'],
                                    ].map(([mode, label]) => (
                                        <label
                                            key={mode}
                                            className={`flex items-center justify-center gap-1 rounded-soft border p-2 text-sm focus-within:ring-2 focus-within:ring-brand-500/30 ${draft.mode === mode ? 'border-brand-500 bg-brand-50 dark:bg-brand-950' : 'border-neutral-200 dark:border-neutral-700'}`}
                                        >
                                            <input
                                                type="radio"
                                                name="notification-availability-mode"
                                                value={mode}
                                                checked={draft.mode === mode}
                                                onChange={() => update('mode', mode)}
                                            />
                                            {text(label)}
                                        </label>
                                    ))}
                                </div>
                                {draft.mode === 'scheduled' && (
                                    <div className="mt-4">
                                        <button
                                            type="button"
                                            aria-expanded={hoursOpen}
                                            onClick={() => setHoursOpen(!hoursOpen)}
                                            className="w-full rounded-soft border border-neutral-200 p-2 text-left text-sm dark:border-neutral-700"
                                        >
                                            {text('Weekly hours')} · {draft.timezone}
                                        </button>
                                        {hoursOpen && (
                                            <div className="mt-3 space-y-3">
                                                <label className="block text-xs">
                                                    {text('Timezone')}
                                                    <input
                                                        list="notification-timezones"
                                                        className={`${inputClass} mt-1`}
                                                        value={draft.timezone}
                                                        onChange={(e) => update('timezone', e.target.value)}
                                                        required
                                                    />
                                                </label>
                                                <datalist id="notification-timezones">
                                                    {[
                                                        'UTC',
                                                        ...(Intl.supportedValuesOf?.('timeZone') ?? ['Asia/Dhaka']),
                                                    ].map((zone) => (
                                                        <option key={zone} value={zone} />
                                                    ))}
                                                </datalist>
                                                {draft.weekly_hours.map((day, index) => (
                                                    <div
                                                        key={DAYS[index]}
                                                        className="rounded-soft border border-neutral-200 p-2 dark:border-neutral-700"
                                                    >
                                                        <div className="flex justify-between gap-2 text-xs">
                                                            <label className="flex items-center gap-2">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={day.enabled}
                                                                    onChange={(e) =>
                                                                        updateDay(index, { enabled: e.target.checked })
                                                                    }
                                                                />
                                                                {text(DAYS[index])}
                                                            </label>
                                                            {day.enabled && (
                                                                <label className="flex items-center gap-1">
                                                                    <input
                                                                        type="checkbox"
                                                                        aria-label={`${text(DAYS[index])} ${text('All day')}`}
                                                                        checked={day.all_day}
                                                                        onChange={(e) =>
                                                                            updateDay(index, {
                                                                                all_day: e.target.checked,
                                                                            })
                                                                        }
                                                                    />
                                                                    {text('All day')}
                                                                </label>
                                                            )}
                                                        </div>
                                                        {day.enabled && !day.all_day && (
                                                            <div className="mt-2 grid min-w-0 grid-cols-2 gap-2">
                                                                {['start', 'end'].map((key) => (
                                                                    <label key={key} className="min-w-0 text-xs">
                                                                        {text(key === 'start' ? 'Start' : 'End')}
                                                                        <input
                                                                            type="time"
                                                                            required
                                                                            aria-label={`${text(DAYS[index])} ${text(key)}`}
                                                                            className={`${inputClass} mt-1 min-w-0`}
                                                                            value={day[key]}
                                                                            onChange={(e) =>
                                                                                updateDay(index, {
                                                                                    [key]: e.target.value,
                                                                                })
                                                                            }
                                                                        />
                                                                    </label>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                                <button
                                                    type="button"
                                                    className="text-xs text-brand-600 underline"
                                                    onClick={() =>
                                                        update(
                                                            'weekly_hours',
                                                            draft.weekly_hours.map((day, index) =>
                                                                index < 5 ? { ...draft.weekly_hours[0] } : day,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {text('Copy Monday to weekdays')}
                                                </button>
                                                <p className="text-xs text-neutral-500">
                                                    {text(
                                                        'An earlier end time continues into the next day. Use All day for 24 hours.',
                                                    )}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </fieldset>
                            {errors.length > 0 && (
                                <div
                                    role="alert"
                                    className="rounded-soft bg-coral-50 p-2 text-xs text-coral-700 dark:bg-coral-950/40 dark:text-coral-300"
                                >
                                    {errors.map((message, index) => (
                                        <p key={index}>{message}</p>
                                    ))}
                                </div>
                            )}
                            <details className="text-xs text-neutral-500">
                                <summary className="cursor-pointer">{text('How this works')}</summary>
                                <p className="mt-2">
                                    {text(
                                        'Only your work alerts are paused. Notifications remain unread in your inbox. Billing alerts are unaffected. This does not change team presence, assignments or chatbot hours.',
                                    )}
                                </p>
                            </details>
                        </Modal.Body>
                        <Modal.Footer>
                            <Button variant="ghost" disabled={saving} onClick={close}>
                                {text('Cancel')}
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {text(saving ? 'Saving…' : 'Save changes')}
                            </Button>
                        </Modal.Footer>
                    </form>
                )}
            </Modal>
        </>
    )
}
