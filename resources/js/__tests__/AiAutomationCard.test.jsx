import { useState } from 'react'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import AiAutomationCard from '@/Components/Inbox/AiAutomationCard'

const patch = vi.fn()
vi.mock('@headlessui/react', () => ({ DialogTitle: ({ children, ...props }) => <h2 {...props}>{children}</h2> }))
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    useForm: (initial) => {
        const [data, set] = useState(initial)
        return {
            data,
            setData: (key, value) => set((current) => (typeof key === 'object' ? key : { ...current, [key]: value })),
            errors: {},
            clearErrors: vi.fn(),
            processing: false,
            patch,
        }
    },
}))
vi.mock('@/Components/ui/Modal', () => {
    const Modal = ({ show, children }) => (show ? <div role="dialog">{children}</div> : null)
    Modal.Header = ({ title }) => <h3>{title}</h3>
    Modal.Body = ({ children }) => <div>{children}</div>
    Modal.Footer = ({ children }) => <div>{children}</div>
    return { default: Modal }
})
const settings = {
    configured: false,
    legacy: true,
    mode: 'off',
    chatbot_id: null,
    timezone: 'Asia/Dhaka',
    revision: 0,
    chatbots: [{ id: 1, name: 'Support Bot' }],
    weekly_hours: Array.from({ length: 7 }, (_, i) => ({
        enabled: i < 5,
        all_day: false,
        start: '09:00',
        end: '17:00',
    })),
}
afterEach(() => {
    cleanup()
    vi.clearAllMocks()
})

it('keeps setup compact and preserves existing setup until saved', () => {
    render(<AiAutomationCard settings={settings} group="channels" />)
    expect(screen.getByText('Existing setup')).toBeInTheDocument()
    expect(screen.queryByLabelText('Chatbot')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    fireEvent.click(screen.getByLabelText('On', { exact: true }))
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Chatbot'), { target: { value: '1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(patch).toHaveBeenCalledOnce()
    expect(screen.getByText('How this works').closest('details')).not.toHaveAttribute('open')
})

it('shows weekly hours only when needed and supports copying weekdays', () => {
    render(<AiAutomationCard settings={settings} group="email" />)
    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    fireEvent.click(screen.getByLabelText('Scheduled'))
    expect(screen.queryByLabelText('Monday start')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Weekly hours/ }))
    fireEvent.change(screen.getByLabelText('Monday start'), { target: { value: '22:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Copy Monday to weekdays' }))
    expect(screen.getByLabelText('Friday start')).toHaveValue('22:00')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    expect(screen.getByLabelText('Off', { exact: true })).toBeChecked()
    expect(patch).not.toHaveBeenCalled()
})

const emailSettings = {
    ...settings,
    legacy: false,
    mailbox_ids: null,
    mailboxes: [
        { id: 7, name: 'Sales', email: 'sales@example.test', status: 'active' },
        { id: 8, name: 'Billing', email: 'billing@example.test', status: 'active' },
    ],
}

it('offers every mailbox by default and lets the client narrow it', () => {
    render(<AiAutomationCard settings={emailSettings} group="email" />)
    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    fireEvent.click(screen.getByLabelText('On', { exact: true }))

    // "All mailboxes" is the existing behaviour and has to stay the default,
    // or turning AI on would quietly stop answering somewhere.
    expect(screen.getByRole('radio', { name: /All mailboxes/ })).toBeChecked()
    expect(screen.queryByRole('checkbox', { name: /Sales/ })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('radio', { name: /Only the mailboxes I choose/ }))
    expect(screen.getByRole('checkbox', { name: /Sales/ })).toBeChecked()

    fireEvent.click(screen.getByRole('checkbox', { name: /Sales/ }))
    expect(screen.getByRole('checkbox', { name: /Sales/ })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: /Billing/ })).toBeChecked()
})

it('does not offer mailboxes to channel groups that have none', () => {
    render(<AiAutomationCard settings={settings} group="channels" />)
    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    fireEvent.click(screen.getByLabelText('On', { exact: true }))

    expect(screen.queryByText('Which mailboxes')).not.toBeInTheDocument()
})

it('says what the scope actually is rather than claiming every mailbox', () => {
    // The card's one summary line is where a client checks the scope at a
    // glance, so it must not keep saying "every mailbox" after narrowing.
    const { rerender } = render(<AiAutomationCard settings={emailSettings} group="email" />)
    expect(screen.getByText('All 2 connected mailboxes')).toBeInTheDocument()

    rerender(<AiAutomationCard settings={{ ...emailSettings, mailbox_ids: [7] }} group="email" />)
    expect(screen.getByText('1 of 2 mailboxes')).toBeInTheDocument()
})

it('explains the empty case instead of hiding the control', () => {
    // With no mailboxes there is nothing to pick, but rendering nothing left a
    // client wondering where the feature went.
    render(<AiAutomationCard settings={{ ...emailSettings, mailboxes: [] }} group="email" />)
    expect(screen.getByText('No mailboxes connected yet')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Manage AI Automation' }))
    fireEvent.click(screen.getByLabelText('On', { exact: true }))

    expect(screen.getByText(/No mailboxes are connected yet/)).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /All mailboxes/ })).not.toBeInTheDocument()
})
