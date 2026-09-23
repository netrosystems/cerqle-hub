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

it('leaves the on/off switch out of the settings form', () => {
    // It lives in the page header now and saves on its own, so a form save must
    // not carry an `enabled` value at all — sending one would fight the switch.
    const { container } = render(<ChatWidgetForm chatbots={[]} aiTimezone="UTC" />)

    expect(container.querySelectorAll('[role="switch"]').length).toBe(0)
})
