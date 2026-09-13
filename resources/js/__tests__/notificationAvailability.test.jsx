import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import axios from 'axios'
import TeamIndex from '@/Pages/client/Team/Index'
import { router } from '@inertiajs/react'
import useNotificationAvailability, {
    allowsNotificationAlert,
    notifyAvailabilityChanged,
} from '@/hooks/useNotificationAvailability'
import NotificationAvailabilityCard, {
    defaultNotificationHours,
    NotificationAvailabilitySummary,
} from '@/Components/NotificationAvailabilityCard'

vi.mock('axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
const page = vi.hoisted(() => ({ props: { auth: { user: { client_role: 'administrator' } } } }))
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn() },
    usePage: () => page,
    useForm: (data) => ({ data, errors: {}, processing: false }),
}))
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }))
vi.mock('@/Components/ui', async () => ({
    Button: (await import('@/Components/ui/Button')).default,
    Modal: (await import('@/Components/ui/Modal')).default,
    PasswordInput: () => null,
}))
vi.mock('@headlessui/react', () => ({ DialogTitle: ({ children, ...props }) => <h2 {...props}>{children}</h2> }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) =>
    (options?.defaultValue ?? key).replace(/\{\{(\w+)\}\}/g, (_, name) => options?.[name] ?? name),
}) }))
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
    can_edit: true,
    ...extra,
})
afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
    page.props.auth.user.client_role = 'administrator'
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

it.each([false, undefined])('hides staff management and renders no schedule inputs (can_edit=%s)', async (can_edit) => {
    axios.get.mockResolvedValue({ data: settings({ can_edit, mode: 'scheduled' }) })
    render(<NotificationAvailabilityCard workspaceId={1} autoOpen />)
    await screen.findByText('Your administrator sets your notification schedule.')
    expect(screen.queryByRole('button', { name: 'Manage availability' })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio')).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(axios.patch).not.toHaveBeenCalled()
})

const teamProps = {
    users: [{ id: 8, name: 'Member', email: 'member@example.test', status: 'active', workspace_assignments: [
        { workspace_id: 1, name: 'First', role: 'staff', availability: settings() },
        { workspace_id: 2, name: 'Second', role: 'staff', availability: settings({ workspace_id: 2 }) },
    ] }],
    workspaces: [{ id: 1, name: 'First' }, { id: 2, name: 'Second' }],
}

it('fetches only the selected team schedule, auto-opens it and reloads only users after saving', async () => {
    axios.get.mockResolvedValue({ data: settings({ workspace_id: 2, member_id: 8, revision: 12 }) })
    axios.patch.mockResolvedValue({ data: settings({ workspace_id: 2, member_id: 8, revision: 13 }) })
    render(<TeamIndex {...teamProps} />)
    expect(axios.get).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Manage availability for Member in Second' })).toHaveTextContent('Always')
    expect(screen.queryByText(/Receiving alerts/)).not.toBeInTheDocument()
    expect(screen.queryByText(/UTC/)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Manage availability for Member in Second' }))
    await screen.findByRole('dialog')
    expect(axios.get).toHaveBeenCalledOnce()
    expect(axios.get.mock.calls[0][0]).toBe('/app/team/8/workspaces/2/notification-availability')
    fireEvent.click(screen.getByLabelText('Paused'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['users'], preserveScroll: true }))
    expect(axios.patch.mock.calls[0][0]).toBe('/app/team/8/workspaces/2/notification-availability')
    expect(axios.patch.mock.calls[0][1]).toMatchObject({ revision: 12, workspace_id: 2, mode: 'paused' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('does not offer team schedule management to staff', () => {
    page.props.auth.user.client_role = 'staff'
    render(<TeamIndex {...teamProps} />)
    expect(screen.queryByRole('button', { name: /Manage availability for/ })).not.toBeInTheDocument()
    expect(screen.getAllByText('Availability')).toHaveLength(2)
    expect(screen.getAllByText('Availability')[0].closest('details')).not.toHaveAttribute('open')
    expect(axios.get).not.toHaveBeenCalled()
})

it('rejects a selected member response for another member without enabling the editor', async () => {
    axios.get.mockResolvedValue({ data: settings({ member_id: 9 }) })
    render(<NotificationAvailabilityCard workspaceId={1} memberId={8} getUrl="/app/team/8/workspaces/1/notification-availability" autoOpen />)
    await screen.findByRole('alert')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Manage availability' })).not.toBeInTheDocument()
})

it('disables an open schedule if refresh revokes editing permission', async () => {
    axios.get.mockResolvedValueOnce({ data: settings() }).mockResolvedValue({ data: settings({ can_edit: false }) })
    render(<NotificationAvailabilityCard workspaceId={1} autoOpen />)
    await screen.findByRole('dialog')
    act(() => window.dispatchEvent(new Event('focus')))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled())
    expect(screen.getByLabelText('Always')).toBeDisabled()
    expect(screen.getByLabelText('Scheduled')).toBeDisabled()
    expect(axios.patch).not.toHaveBeenCalled()
})
