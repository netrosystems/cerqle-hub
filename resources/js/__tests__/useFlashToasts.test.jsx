import { render, cleanup } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

const success = vi.fn()
const error = vi.fn()
let pageProps = { flash: { success: null, error: null } }

vi.mock('sonner', () => ({ toast: { success: (...a) => success(...a), error: (...a) => error(...a) } }))
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: pageProps }) }))

const { default: useFlashToasts } = await import('@/hooks/useFlashToasts')

function Harness() {
    useFlashToasts()
    return null
}

beforeEach(() => {
    success.mockClear()
    error.mockClear()
})
afterEach(cleanup)

it('raises a toast for a flashed success message', () => {
    pageProps = { flash: { success: 'Widget updated.', error: null } }
    render(<Harness />)

    expect(success).toHaveBeenCalledWith('Widget updated.')
})

it('raises a toast for a flashed error message', () => {
    pageProps = { flash: { success: null, error: 'Could not save.' } }
    render(<Harness />)

    expect(error).toHaveBeenCalledWith('Could not save.')
})

it('does not repeat the same message when the page re-renders', () => {
    pageProps = { flash: { success: 'Widget updated.', error: null } }
    const view = render(<Harness />)
    view.rerender(<Harness />)
    view.rerender(<Harness />)

    expect(success).toHaveBeenCalledTimes(1)
})

it('stays silent when nothing was flashed', () => {
    pageProps = { flash: { success: null, error: null } }
    render(<Harness />)

    expect(success).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
})
