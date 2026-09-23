import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'

const patch = vi.fn()
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { post: vi.fn(), delete: vi.fn(), patch: (...a) => patch(...a) },
}))
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }))
vi.mock('@/Pages/Chat/Widgets/Partials/ChatWidgetForm', () => ({ default: () => <div>CONFIGURATION FORM</div> }))
// Renders children: identity setup now nests inside the install card.
vi.mock('@/Pages/Chat/Widgets/Partials/InstallCard', () => ({ default: ({ children }) => <div>INSTALL CARD{children}</div> }))
vi.mock('@/Pages/Chat/Widgets/Partials/IdentityCard', () => ({ default: () => <div>IDENTITY CARD</div> }))
vi.mock('@/Pages/Chat/Widgets/Partials/MobileSdkCard', () => ({ default: ({ widgetKey }) => <div>MOBILE SDK {widgetKey}</div> }))

global.route = () => '/app/inbox/chat-widgets/1'

const { default: ChatWidgetEdit } = await import('@/Pages/Chat/Widgets/Edit')

const widget = { id: 1, name: 'Website chat', widget_key: 'abc', identity_verification: false }

afterEach(cleanup)

it('opens on Configuration', () => {
    render(<ChatWidgetEdit widget={widget} />)

    expect(screen.getByRole('tab', { name: 'Configuration' }).getAttribute('aria-selected')).toBe('true')
    expect(screen.getByText('CONFIGURATION FORM').closest('[role="tabpanel"]').hidden).toBe(false)
    expect(screen.getByText(/INSTALL CARD/).closest('[role="tabpanel"]').hidden).toBe(true)
})

it('shows the install and identity sections under Setup', () => {
    render(<ChatWidgetEdit widget={widget} />)

    fireEvent.click(screen.getByRole('tab', { name: 'Setup' }))

    const installCard = screen.getByText(/INSTALL CARD/)
    const setupPanel = installCard.closest('[role="tabpanel"]')
    expect(setupPanel.hidden).toBe(false)
    // Identity setup lives inside the install card, not beside it.
    expect(installCard.contains(screen.getByText('IDENTITY CARD'))).toBe(true)
    expect(screen.getByText('CONFIGURATION FORM').closest('[role="tabpanel"]').hidden).toBe(true)
})

it('keeps the configuration form mounted so switching tabs never discards edits', () => {
    render(<ChatWidgetEdit widget={widget} />)

    fireEvent.click(screen.getByRole('tab', { name: 'Setup' }))

    expect(screen.getByText('CONFIGURATION FORM')).toBeTruthy()
})

it('puts the widget on/off switch in the title row and saves it on its own', () => {
    render(<ChatWidgetEdit widget={{ ...widget, enabled: true }} />)

    const toggle = screen.getByRole('switch', { name: 'Widget' })
    expect(toggle.getAttribute('aria-checked')).toBe('true')

    fireEvent.click(toggle)

    expect(patch).toHaveBeenCalledTimes(1)
    expect(patch.mock.calls[0][1]).toEqual({ enabled: false })
    // preserveState so an unsaved form and the open tab both survive the flip.
    expect(patch.mock.calls[0][2]).toMatchObject({ preserveState: true })
})

it('reflects a widget that is currently off', () => {
    render(<ChatWidgetEdit widget={{ ...widget, enabled: false }} />)

    expect(screen.getByRole('switch', { name: 'Widget' }).getAttribute('aria-checked')).toBe('false')
})

it('no longer offers delete from the detail page', () => {
    render(<ChatWidgetEdit widget={widget} />)

    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull()
})

it('groups Setup into a website path and a mobile path', () => {
    render(<ChatWidgetEdit widget={widget} />)

    fireEvent.click(screen.getByRole('tab', { name: 'Setup' }))
    const panel = screen.getByText(/INSTALL CARD/).closest('[role="tabpanel"]')

    expect(panel.textContent).toContain('Website')
    expect(panel.textContent).toContain('Mobile app')
    expect(panel.contains(screen.getByText(/MOBILE SDK/))).toBe(true)
})

it('hands the widget key to the mobile SDK card', () => {
    render(<ChatWidgetEdit widget={widget} />)

    fireEvent.click(screen.getByRole('tab', { name: 'Setup' }))

    expect(screen.getByText(`MOBILE SDK ${widget.widget_key}`)).toBeTruthy()
})
