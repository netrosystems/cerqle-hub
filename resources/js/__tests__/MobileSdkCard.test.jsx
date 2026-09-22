import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import MobileSdkCard from '@/Pages/Chat/Widgets/Partials/MobileSdkCard'

const KEY = 'vjtvefTu02ELq9oxGOA5B8iCqpEg2Ifw'
const writeText = vi.fn(() => Promise.resolve())

beforeEach(() => {
    writeText.mockClear()
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})
afterEach(cleanup)

it('shows the widget key as the SDK key', () => {
    render(<MobileSdkCard widgetKey={KEY} />)

    expect(screen.getByLabelText('SDK key').value).toBe(KEY)
})

it('copies the key, not the whole snippet', () => {
    render(<MobileSdkCard widgetKey={KEY} />)

    fireEvent.click(screen.getByRole('button', { name: /copy/i }))

    expect(writeText).toHaveBeenCalledWith(KEY)
})

it('offers Flutter and marks the rest as coming soon', () => {
    render(<MobileSdkCard widgetKey={KEY} />)

    const flutter = screen.getByRole('link', { name: /get it/i })
    expect(flutter.getAttribute('href')).toBe('https://pub.dev/packages/cerqle_chat')
    expect(flutter.getAttribute('rel')).toContain('noopener')

    expect(screen.getAllByText('Coming soon')).toHaveLength(3)
    for (const name of ['Kotlin', 'React Native', 'Swift']) {
        expect(screen.getByText(name)).toBeTruthy()
    }
})

it('does not offer a download for a platform that is not ready', () => {
    render(<MobileSdkCard widgetKey={KEY} />)

    // One link only: a "coming soon" platform must not be clickable, or it
    // reads as a broken download rather than an unreleased one.
    expect(screen.getAllByRole('link')).toHaveLength(1)
})
