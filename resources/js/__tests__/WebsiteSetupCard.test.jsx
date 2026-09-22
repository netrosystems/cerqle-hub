import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, expect, it } from 'vitest'
import InstallCard from '@/Pages/Chat/Widgets/Partials/InstallCard'
import IdentityCard from '@/Pages/Chat/Widgets/Partials/IdentityCard'

const props = { embedBase: 'https://cerqle.ai', widgetKey: 'abc123', identitySecret: 'sek', verification: false }

afterEach(cleanup)

it('keeps the install steps — they are how a client learns to integrate', () => {
    render(<InstallCard embedBase={props.embedBase} widgetKey={props.widgetKey} />)

    expect(screen.getByText('Copy the snippet.')).toBeTruthy()
    expect(screen.getByText('Paste it into your site.')).toBeTruthy()
    expect(screen.getByText('Reply from your inbox.')).toBeTruthy()
})

it('drops the steps only in the compact listing variant', () => {
    render(<InstallCard embedBase={props.embedBase} widgetKey={props.widgetKey} compact />)

    expect(screen.queryByText('Copy the snippet.')).toBeNull()
})

it('renders identity setup inside the install card, not as a second card', () => {
    const { container } = render(
        <InstallCard embedBase={props.embedBase} widgetKey={props.widgetKey} title="Website snippet">
            <IdentityCard embedded {...props} />
        </InstallCard>,
    )

    // One card wrapper for both sections.
    expect(container.querySelectorAll('div.rounded-2xl.border').length).toBe(1)
    expect(screen.getByText(/Optional: show logged-in customers/)).toBeTruthy()
    expect(screen.getByText('Website snippet')).toBeTruthy()
})

it('still stands alone when not embedded', () => {
    const { container } = render(<IdentityCard {...props} />)

    expect(container.querySelectorAll('div.rounded-2xl.border').length).toBe(1)
    expect(screen.getByText('Show logged-in customers to your agents')).toBeTruthy()
})
