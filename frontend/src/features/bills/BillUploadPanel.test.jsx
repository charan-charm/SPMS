import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BillUploadPanel } from './BillUploadPanel'
import { clientSideUploadCheck } from './presentation'

/**
 * Upload panel (B4 §18/§19/§30): early UX rejection of obvious problems,
 * honest "the server decides" framing, filename+size shown locally, and
 * the busy/loading states around the centralized upload.
 */

const bigFile = () => {
    const file = new File(['x'], 'huge.pdf', { type: 'application/pdf' })
    Object.defineProperty(file, 'size', { value: 26 * 1024 * 1024 })
    return file
}

describe('BillUploadPanel', () => {
    it('renders the calm entry point with the accepted formats', () => {
        render(<BillUploadPanel onUpload={vi.fn()} />)

        expect(screen.getByRole('heading', { name: /upload your electricity bill/i })).toBeInTheDocument()
        expect(screen.getByText(/pdf, jpg or png up to 25 mb/i)).toBeInTheDocument()
        const input = screen.getByLabelText(/choose a bill file to upload/i)
        expect(input).toHaveAttribute('accept', '.jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf')
        expect(screen.getByRole('button', { name: /^upload bill$/i })).toBeDisabled()
    })

    it('rejects an obviously wrong file type before any upload', async () => {
        // The native picker filters by accept=, so the drop path is the
        // route a wrong type actually takes — it must still be refused.
        render(<BillUploadPanel onUpload={vi.fn()} />)

        fireEvent.drop(screen.getByText(/drag your bill here/i).closest('div'), {
            dataTransfer: { files: [new File(['x'], 'virus.exe')] },
        })

        expect(await screen.findByRole('alert')).toHaveTextContent(/pdf, jpg or png/i)
        expect(screen.getByRole('button', { name: /^upload bill$/i })).toBeDisabled()
    })

    it('rejects an obviously oversized file before any upload', async () => {
        const user = userEvent.setup()
        const onUpload = vi.fn()
        render(<BillUploadPanel onUpload={onUpload} />)

        await user.upload(screen.getByLabelText(/choose a bill file to upload/i), bigFile())

        expect(await screen.findByRole('alert')).toHaveTextContent(/larger than 25 mb/i)
        expect(onUpload).not.toHaveBeenCalled()
    })

    it('shows only the name and size locally, then hands the file to the API', async () => {
        const user = userEvent.setup()
        // A manual gate (not a timer) keeps the in-flight assertion
        // deterministic regardless of how long userEvent takes internally.
        let releaseUpload
        const gate = new Promise((resolve) => {
            releaseUpload = resolve
        })
        const onUpload = vi.fn(() => gate)
        render(<BillUploadPanel onUpload={onUpload} />)

        await user.upload(
            screen.getByLabelText(/choose a bill file to upload/i),
            new File(['abc'], 'september-bill.png', { type: 'image/png' }),
        )

        expect(screen.getByText('september-bill.png')).toBeInTheDocument()
        expect(screen.getByText('3 B')).toBeInTheDocument()

        const uploadButton = screen.getByRole('button', { name: /^upload bill$/i })
        expect(uploadButton).toBeEnabled()
        await user.click(uploadButton)

        // Loading while in flight…
        expect(uploadButton).toHaveAttribute('aria-busy', 'true')
        expect(onUpload).toHaveBeenCalledTimes(1)
        expect(onUpload.mock.calls[0][0].name).toBe('september-bill.png')

        // …and the selection is gone once the flow completed.
        releaseUpload({ uploaded: true })
        await waitFor(() => expect(screen.queryByText('september-bill.png')).not.toBeInTheDocument())
    })

    it('keeps the selection and shows the server rejection when intake says no', async () => {
        const user = userEvent.setup()
        const onUpload = vi.fn(async () => ({ uploaded: false, error: 'The uploaded file content does not match its claimed type.' }))
        render(<BillUploadPanel onUpload={onUpload} />)

        await user.upload(
            screen.getByLabelText(/choose a bill file to upload/i),
            new File(['abc'], 'forged.jpg', { type: 'image/jpeg' }),
        )
        await user.click(screen.getByRole('button', { name: /^upload bill$/i }))

        expect(await screen.findByRole('alert')).toHaveTextContent(/does not match its claimed type/i)
        expect(screen.getByText('forged.jpg')).toBeInTheDocument()
    })

    it('client-side checks mirror the ratified bounds', () => {
        expect(clientSideUploadCheck(new File(['x'], 'bill.pdf'))).toBeNull()
        expect(clientSideUploadCheck(new File(['x'], 'bill.JPEG'))).toBeNull()
        expect(clientSideUploadCheck(new File(['x'], 'notes.txt'))).toMatch(/pdf/i)
        expect(clientSideUploadCheck(bigFile())).toMatch(/25 mb/i)
    })
})

