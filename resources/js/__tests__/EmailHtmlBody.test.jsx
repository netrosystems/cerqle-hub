import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it } from 'vitest'
import EmailHtmlBody from '@/Components/Inbox/EmailHtmlBody'

afterEach(cleanup)

const frame = () => document.querySelector('iframe[title="Email message"]')

it('never grants the message permission to run scripts', () => {
    // The server sanitises too, but this is the layer that has to hold if
    // anything gets past it: without allow-scripts nothing in the mail can
    // execute, whatever markup survived.
    render(<EmailHtmlBody html="<p>Hello</p>" />)

    const sandbox = frame().getAttribute('sandbox')
    expect(sandbox).not.toContain('allow-scripts')
    expect(sandbox).toContain('allow-same-origin')
})

it('blocks remote images until the reader asks for them', () => {
    render(<EmailHtmlBody html={'<p>Hi</p><img src="https://tracker.example.com/pixel.gif">'} />)

    // A tracking pixel reports that this person opened this mail at this
    // moment, so the policy has to withhold it rather than the markup.
    expect(frame().getAttribute('srcdoc')).not.toMatch(/img-src[^;]*https:/)
    expect(screen.getByText(/Images are blocked/i)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /show images/i }))

    expect(frame().getAttribute('srcdoc')).toMatch(/img-src[^;]*https:/)
    expect(screen.queryByText(/Images are blocked/i)).not.toBeInTheDocument()
})

it('does not offer the images control when nothing is being withheld', () => {
    render(<EmailHtmlBody html="<p>Just words and a <b>table</b>.</p>" />)

    expect(screen.queryByText(/Images are blocked/i)).not.toBeInTheDocument()
})

it('treats an inline data image as safe to show', () => {
    render(<EmailHtmlBody html={'<img src="data:image/gif;base64,R0lGOD">'} />)

    // A data: image carries no request, so it reveals nothing and needs no
    // banner.
    expect(screen.queryByText(/Images are blocked/i)).not.toBeInTheDocument()
    expect(frame().getAttribute('srcdoc')).toMatch(/img-src data:/)
})

it('sends links out of the frame rather than navigating it', () => {
    render(<EmailHtmlBody html={'<a href="https://example.com">go</a>'} />)

    const srcdoc = frame().getAttribute('srcdoc')
    expect(srcdoc).toContain('<base target="_blank">')
    expect(srcdoc).toContain("form-action 'none'")
})

it('renders an empty body without crashing', () => {
    render(<EmailHtmlBody html="" />)

    expect(frame()).toBeTruthy()
})
