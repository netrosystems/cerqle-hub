import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import ConfirmProvider, { useConfirm } from '@/Components/ui/ConfirmProvider'

afterEach(cleanup)

function Harness({ onResult }) {
    const confirm = useConfirm()
    return <button onClick={async () => onResult(await confirm('Delete this bot?'))}>Delete</button>
}

it('resolves true when the action is confirmed', async () => {
    const onResult = vi.fn()
    render(<ConfirmProvider><Harness onResult={onResult} /></ConfirmProvider>)

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(await screen.findByRole('dialog')).toBeTruthy()

    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[1])
    await vi.waitFor(() => expect(onResult).toHaveBeenCalledWith(true))
})

it('resolves false when cancelled, and never calls the native dialog', async () => {
    const native = vi.spyOn(window, 'confirm')
    const onResult = vi.fn()
    render(<ConfirmProvider><Harness onResult={onResult} /></ConfirmProvider>)

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }))

    await vi.waitFor(() => expect(onResult).toHaveBeenCalledWith(false))
    // The whole point: a suppressed native dialog must never decide this.
    expect(native).not.toHaveBeenCalled()
})

it('still works outside the provider rather than throwing', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const onResult = vi.fn()
    render(<Harness onResult={onResult} />)

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))

    await vi.waitFor(() => expect(onResult).toHaveBeenCalledWith(true))
})
