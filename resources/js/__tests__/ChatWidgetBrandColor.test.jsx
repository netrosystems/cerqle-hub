import { useState } from 'react'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import ChatWidgetForm from '@/Pages/Chat/Widgets/Partials/ChatWidgetForm'

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, set] = useState(initial)
        return {
            data,
            setData: (key, value) => set((current) => (typeof key === 'object' ? key : { ...current, [key]: value })),
            errors: {},
            clearErrors: vi.fn(),
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
            transform: vi.fn(),
        }
    },
    usePage: () => ({ props: { errors: {}, defaultPrimaryColor: '#8F5FA7' } }),
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
}))

afterEach(cleanup)

it('survives the brand colour being cleared character by character', () => {
    render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)

    const hex = screen.getByLabelText('Brand color hex')

    // A client selecting the field and deleting it, then removing just the hash.
    fireEvent.change(hex, { target: { value: '' } })
    fireEvent.change(hex, { target: { value: '8F5FA7' } })
    fireEvent.change(hex, { target: { value: 'nonsense' } })

    expect(screen.getByLabelText('Brand color hex').value).toBe('nonsense')
})

it('adds a missing hash when the field loses focus', () => {
    render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)
    const hex = screen.getByLabelText('Brand color hex')

    fireEvent.change(hex, { target: { value: '112233' } })
    fireEvent.blur(hex)

    expect(screen.getByLabelText('Brand color hex').value).toBe('#112233')
})

it('falls back to the brand colour when the field is left empty', () => {
    render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)
    const hex = screen.getByLabelText('Brand color hex')

    fireEvent.change(hex, { target: { value: '' } })
    fireEvent.blur(hex)

    expect(screen.getByLabelText('Brand color hex')).toBeTruthy()
})

it('puts AI answering first, marked as an AI surface', () => {
    const { container } = render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)

    const headings = [...container.querySelectorAll('h3')].map((h) => h.textContent.trim())
    expect(headings[0]).toBe('AI answering')
    expect(headings).toContain('Appearance')

    const aiCard = screen.getByText('AI answering').closest('div.rounded-2xl')
    expect(aiCard.className).toMatch(/border-brand-200/)
})

it('leaves availability switches out of the create form', () => {
    render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)

    expect(screen.queryByRole('switch', { name: 'Widget enabled' })).toBeNull()
    expect(screen.queryByRole('switch', { name: 'SDK enabled' })).toBeNull()
})

it('keeps website and SDK switches as draft state until Save changes', () => {
    const onSubmit = vi.fn()
    render(
        <ChatWidgetForm
            widget={{ enabled: true, sdk_enabled: false }}
            chatbots={[]}
            aiTimezone="UTC"
            submitLabel="Save changes"
            onSubmit={onSubmit}
        />,
    )

    const visitorCard = screen.getByText('Visitor experience').closest('div.rounded-2xl')
    const widgetSwitch = within(visitorCard).getByRole('switch', { name: 'Widget enabled' })
    const sdkSwitch = within(visitorCard).getByRole('switch', { name: 'SDK enabled' })

    expect(widgetSwitch.getAttribute('aria-checked')).toBe('true')
    expect(sdkSwitch.getAttribute('aria-checked')).toBe('false')
    fireEvent.click(widgetSwitch)
    fireEvent.click(sdkSwitch)
    expect(onSubmit).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(onSubmit).toHaveBeenCalledTimes(1)
    expect(onSubmit.mock.calls[0][0]).toMatchObject({ enabled: false, sdk_enabled: true })
})
