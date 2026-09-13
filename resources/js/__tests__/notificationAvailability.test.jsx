import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import axios from 'axios'
import useNotificationAvailability, {
    allowsNotificationAlert,
    notifyAvailabilityChanged,
} from '@/hooks/useNotificationAvailability'
import NotificationAvailabilityCard, {
    defaultNotificationHours,
    NotificationAvailabilitySummary,
} from '@/Components/NotificationAvailabilityCard'

vi.mock('axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('@headlessui/react', () => ({ DialogTitle: ({ children, ...props }) => <h2 {...props}>{children}</h2> }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) => options?.defaultValue ?? key }) }))
vi.mock('@/Components/ui/Modal', () => {
    const Modal = ({ show, children }) => (show ? <div role="dialog">{children}</div> : null)
    Modal.Header = ({ title }) => <h3>{title}</h3>
    Modal.Body = Modal.Footer = ({ children }) => <div>{children}</div>
    return { default: Modal }
})
const settings = (extra = {}) => ({
    mode: 'always',
    timezone: 'UTC',
    weekly_hours: defaultNotificationHours(),
    revision: 3,
    active: true,
    next_boundary: null,
    workspace_id: 1,
    ...extra,
})
afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
})

it('suppresses only scoped work alerts and strict silent flags, including expired or cross-workspace state', () => {
    expect(allowsNotificationAlert({ type: 'new_message' }, settings(), 1)).toBe(true)
    expect(allowsNotificationAlert({ type: 'mention' }, settings({ active: false }), 1)).toBe(false)
    expect(allowsNotificationAlert({ type: 'conversation_assigned' }, settings(), 2)).toBe(false)
    expect(
        allowsNotificationAlert({ type: 'automation_failed' }, settings({ next_boundary: '2020-01-01T00:00:00Z' }), 1),
    ).toBe(false)
    expect(allowsNotificationAlert({ type: 'billing_failed' }, null, 1)).toBe(true)
    expect(allowsNotificationAlert({ type: 'billing_failed', silent: true }, settings(), 1)).toBe(false)
    expect(allowsNotificationAlert({ type: 'new_message', silent: 'true' }, settings(), 1)).toBe(true)
    for (const type of ['handover', 'pending_customer_reply', 'workspace_export_ready']) {
        expect(allowsNotificationAlert({ type }, settings({ active: false }), 1)).toBe(false)
    }
})

it('refreshes on focus, saves and workspace switches, ignoring late old-workspace responses', async () => {
    axios.get.mockResolvedValue({ data: settings() })
    const { result, rerender } = renderHook(({ id }) => useNotificationAvailability(id), { initialProps: { id: 1 } })
    await waitFor(() => expect(result.current.availability?.workspace_id).toBe(1))
    act(() => window.dispatchEvent(new Event('focus')))
    await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2))
    act(() => notifyAvailabilityChanged(1))
    await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(3))
    let resolveOld
    axios.get.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                resolveOld = resolve
            }),
    )
    act(() => window.dispatchEvent(new Event('focus')))
    axios.get.mockResolvedValue({ data: settings({ workspace_id: 2, active: false }) })
    rerender({ id: 2 })
    await waitFor(() => expect(result.current.availability?.workspace_id).toBe(2))
    await act(async () => resolveOld({ data: settings() }))
    expect(result.current.availability.workspace_id).toBe(2)
    expect(result.current.shouldAlert({ type: 'new_message' })).toBe(false)
})

it('refreshes at the next schedule boundary', async () => {
    vi.useFakeTimers()
    axios.get
        .mockResolvedValueOnce({ data: settings({ next_boundary: new Date(Date.now() + 2000).toISOString() }) })
        .mockResolvedValue({ data: settings({ active: false }) })
    const { result } = renderHook(() => useNotificationAvailability(1))
    await act(async () => {})
    await act(async () => vi.advanceTimersByTimeAsync(2100))
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(result.current.shouldAlert({ type: 'new_message' })).toBe(false)
})

it('polls without a boundary and fails closed after refresh errors', async () => {
    vi.useFakeTimers()
    axios.get.mockResolvedValueOnce({ data: settings() }).mockRejectedValue(new Error('Offline'))
    const { result } = renderHook(() => useNotificationAvailability(1))
    await act(async () => {})
    expect(result.current.shouldAlert({ type: 'new_message' })).toBe(true)
    await act(async () => vi.advanceTimersByTimeAsync(60000))
    expect(result.current.shouldAlert({ type: 'new_message' })).toBe(false)
    expect(result.current.shouldAlert({ type: 'billing_failed' })).toBe(true)
    expect(result.current.error).toBeTruthy()
})

it('refreshes cross-tab changes only for the current workspace', async () => {
    axios.get.mockResolvedValue({ data: settings() })
    renderHook(() => useNotificationAvailability(1))
    await waitFor(() => expect(axios.get).toHaveBeenCalledOnce())
    act(() =>
        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'cerqle:notification-availability',
                newValue: JSON.stringify({ workspaceId: 2 }),
            }),
        ),
    )
    expect(axios.get).toHaveBeenCalledOnce()
    act(() =>
        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'cerqle:notification-availability',
                newValue: JSON.stringify({ workspaceId: 1 }),
            }),
        ),
    )
    await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2))
})

it('edits one-window overnight/all-day schedules, copies weekdays and saves JSON revision with CSRF', async () => {
    axios.get.mockResolvedValue({ data: settings() })
    axios.patch.mockResolvedValue({ data: settings({ revision: 4 }) })
    const meta = document.createElement('meta')
    meta.name = 'csrf-token'
    meta.content = 'test-csrf'
    document.head.append(meta)
    render(<NotificationAvailabilityCard workspaceId={1} />)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Manage availability' })).toBeEnabled())
    fireEvent.click(screen.getByRole('button', { name: 'Manage availability' }))
    fireEvent.click(screen.getByLabelText('Scheduled'))
    expect(screen.queryByLabelText('Monday start')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Weekly hours/ }))
    fireEvent.change(screen.getByLabelText('Monday start'), { target: { value: '22:00' } })
    fireEvent.change(screen.getByLabelText('Monday end'), { target: { value: '06:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Copy Monday to weekdays' }))
    expect(screen.getByLabelText('Friday start')).toHaveValue('22:00')
    expect(screen.getByLabelText('Saturday')).not.toBeChecked()
    fireEvent.click(screen.getByLabelText('Tuesday All day'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(axios.patch).toHaveBeenCalledOnce())
    const [, payload, options] = axios.patch.mock.calls[0]
    expect(payload).toMatchObject({ mode: 'scheduled', revision: 3, workspace_id: 1 })
    expect(payload.weekly_hours[1].all_day).toBe(true)
    expect(payload.weekly_hours[4]).toMatchObject({ start: '22:00', end: '06:00' })
    expect(options.headers['X-CSRF-TOKEN']).toBe('test-csrf')
    meta.remove()
})

it.each([
    { status: 409 },
    { status: 422, data: { errors: { revision: ['Availability changed. Reload and try again.'] } } },
])('keeps revision conflict errors inline without overwriting the draft (%j)', async (response) => {
    axios.get.mockResolvedValue({ data: settings() })
    axios.patch.mockRejectedValue({ response })
    render(<NotificationAvailabilityCard workspaceId={1} />)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Manage availability' })).toBeEnabled())
    fireEvent.click(screen.getByRole('button', { name: 'Manage availability' }))
    fireEvent.click(screen.getByLabelText('Paused'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere'))
    expect(screen.getByLabelText('Paused')).toBeChecked()
})

it('renders optional team availability as read-only', () => {
    render(<NotificationAvailabilitySummary availability={settings({ mode: 'paused', active: false })} />)
    expect(screen.getByText(/Paused · Alerts silenced · UTC/)).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
})
