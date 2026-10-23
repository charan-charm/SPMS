import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

vi.mock('../../services/api/session', () => ({
    loadServerSession: vi.fn(async () => ({ authenticated: true, account: {} })),
}))

vi.mock('../../services/api/bills', () => ({
    listBills: vi.fn(),
    getBill: vi.fn(),
    uploadBill: vi.fn(),
    processBill: vi.fn(),
}))

import { getBill, listBills, processBill, uploadBill } from '../../services/api/bills'
import { BillsPage } from './BillsPage'

/**
 * BillsPage container (B4 §20/§21/§30): the upload → processing →
 * review orchestration over the ratified endpoints. The backend stays
 * authoritative throughout — the transition to review only ever comes
 * from a real read after the real process call, never from a local
 * assumption that OCR finished.
 */

function billRow(status) {
    return {
        bill_id: 'bill-1',
        status,
        billing_period_start: status === 'PROCESSING' ? null : '2026-08-01',
        billing_period_end: status === 'PROCESSING' ? null : '2026-09-01',
        consumption_kwh: null,
        solar_units_kwh: null,
        net_units_kwh: null,
        bill_amount: null,
        total_due: null,
        verified_at: null,
        updated_at: '2026-09-10 09:05:00',
    }
}

function emptyValues() {
    return Object.fromEntries(
        [
            'billing_period_start',
            'billing_period_end',
            'consumption_kwh',
            'previous_meter_reading',
            'present_meter_reading',
            'previous_solar_reading',
            'present_solar_reading',
            'solar_units_kwh',
            'net_units_kwh',
            'bill_amount',
            'arrears',
            'total_due',
            'energy_charges',
            'fixed_charges',
            'customer_service_charges',
            'other_charges',
            'taxes_adjustments',
            'utility_provider',
            'tariff_category',
            'meter_type',
            'contracted_load',
        ].map((field) => [field, null]),
    )
}

function processingDetail() {
    return {
        bill_id: 'bill-1',
        status: 'PROCESSING',
        verified_at: null,
        created_at: '2026-09-10 09:00:00',
        updated_at: '2026-09-10 09:00:00',
        values: emptyValues(),
        provenance: {},
        extraction: null,
        validations: [],
        quality_flags: [],
    }
}

function extractedDetail() {
    const values = emptyValues()
    values.billing_period_start = '2026-08-01'
    values.billing_period_end = '2026-09-01'
    values.consumption_kwh = '145.000'

    return {
        bill_id: 'bill-1',
        status: 'EXTRACTED',
        verified_at: null,
        created_at: '2026-09-10 09:00:00',
        updated_at: '2026-09-10 09:04:00',
        values,
        provenance: { consumption_kwh: { source: 'AI_EXTRACTION', confidence: '0.9800' } },
        extraction: {
            assessment: 'VALID',
            assessment_reason: 'clear bill',
            document_confidence: '0.9900',
            extracted_at: '2026-09-10 09:04:00',
        },
        validations: [],
        quality_flags: [],
    }
}

describe('BillsPage container', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        listBills.mockResolvedValue({ data: { bills: [billRow('PROCESSING')] } })
        getBill.mockResolvedValue({ data: { bill: processingDetail() } })
    })

    it('walks upload → processing → review strictly on backend answers', async () => {
        const user = userEvent.setup()
        // Small real delays so the intermediate stages are observable —
        // the whole flow would otherwise resolve within microtasks.
        uploadBill.mockImplementation(async () => {
            await sleep(25)
            return { data: { bill_id: 'bill-1', status: 'PROCESSING', document: { type: 'image/png', size_bytes: 1024 } } }
        })
        processBill.mockImplementation(async () => {
            await sleep(160)
            return { data: { bill_id: 'bill-1', status: 'EXTRACTED' } }
        })
        getBill.mockResolvedValueOnce({ data: { bill: processingDetail() } }).mockResolvedValue({ data: { bill: extractedDetail() } })

        render(<BillsPage />)

        // Signed-in workspace loads from the list read.
        await waitFor(() => expect(listBills).toHaveBeenCalled())
        expect(screen.getByRole('heading', { level: 1, name: /your electricity bills/i })).toBeInTheDocument()

        // Open the upload panel and pick a file.
        await user.click(screen.getByRole('button', { name: /upload bill/i }))
        await user.upload(screen.getByLabelText(/choose a bill file to upload/i), new File(['abc'], 'sept-bill.png'))
        const submitButtons = screen.getAllByRole('button', { name: /^upload bill$/i })
        await user.click(submitButtons[submitButtons.length - 1])

        // Processing state is visible while the real work runs…
        await waitFor(() => expect(uploadBill).toHaveBeenCalledTimes(1))
        await waitFor(() => expect(processBill).toHaveBeenCalledWith('bill-1', expect.anything()))
        expect(screen.getByRole('status')).toHaveTextContent(/analyzing your bill/i)

        // …and review only appears after the follow-up READ says EXTRACTED.
        await waitFor(() =>
            expect(screen.getByRole('heading', { name: /bill information extracted/i })).toBeInTheDocument(),
        )
        expect(screen.getAllByText('145 kWh').length).toBeGreaterThanOrEqual(1)
        expect(screen.queryByText('Verified')).not.toBeInTheDocument()
        // 3 reads: initial, post-upload, post-process.
        expect(getBill.mock.calls.map((call) => call[0])).toEqual(['bill-1', 'bill-1', 'bill-1'])
    })

    it('a failed process call keeps the honest retryable state, not a fake review', async () => {
        const user = userEvent.setup()
        const { ApiError } = await import('../../services/api/client')
        uploadBill.mockRejectedValue(new ApiError({ code: 'TIMEOUT', message: 'x', status: 0 }))

        render(<BillsPage />)
        await waitFor(() => expect(listBills).toHaveBeenCalled())

        await user.click(screen.getByRole('button', { name: /upload bill/i }))
        await user.upload(screen.getByLabelText(/choose a bill file to upload/i), new File(['abc'], 'bill.png'))
        const submitButtons = screen.getAllByRole('button', { name: /^upload bill$/i })
        await user.click(submitButtons[submitButtons.length - 1])

        expect(await screen.findByRole('alert')).toHaveTextContent(/could not reach spms/i)
        expect(screen.queryByRole('heading', { name: /bill information extracted/i })).not.toBeInTheDocument()
        expect(processBill).not.toHaveBeenCalled()
    })

    it('shows the signed-out workspace when the session is not there', async () => {
        const session = await import('../../services/api/session')
        session.loadServerSession.mockResolvedValueOnce({ authenticated: false })

        render(<BillsPage />)

        expect(await screen.findByText(/sign in to upload electricity bills/i)).toBeInTheDocument()
        expect(listBills).not.toHaveBeenCalled()
    })

    it('synchronizes selected statement from history and updates URL deep link', async () => {
        const user = userEvent.setup()
        const rows = [
            billRow('VERIFIED'),
            {
                ...billRow('VERIFIED'),
                bill_id: 'bill-2',
                billing_period_start: '2026-07-01',
                billing_period_end: '2026-08-01',
            },
        ]
        listBills.mockResolvedValue({ data: { bills: rows } })
        getBill
            .mockResolvedValueOnce({ data: { bill: { ...extractedDetail(), bill_id: 'bill-1' } } })
            .mockResolvedValue({ data: { bill: { ...extractedDetail(), bill_id: 'bill-2', values: { ...extractedDetail().values, consumption_kwh: '200.000' } } } })

        render(<BillsPage />)
        await waitFor(() => expect(listBills).toHaveBeenCalled())
        expect(await screen.findByRole('heading', { name: /bill information extracted/i })).toBeInTheDocument()

        // Click second statement row in history table
        const historyRows = screen.getAllByRole('button', { name: /verified/i })
        await user.click(historyRows[1])

        await waitFor(() => expect(getBill).toHaveBeenCalledWith('bill-2', expect.anything()))
        expect(window.location.search).toContain('billId=bill-2')
    })

    it('restores specified bill when opening deep link with billId query param', async () => {
        window.history.replaceState({}, '', '/bills?billId=bill-2')
        const rows = [
            billRow('VERIFIED'),
            {
                ...billRow('VERIFIED'),
                bill_id: 'bill-2',
                billing_period_start: '2026-07-01',
                billing_period_end: '2026-08-01',
            },
        ]
        listBills.mockResolvedValue({ data: { bills: rows } })
        getBill.mockResolvedValue({ data: { bill: { ...extractedDetail(), bill_id: 'bill-2' } } })

        render(<BillsPage />)
        await waitFor(() => expect(listBills).toHaveBeenCalled())
        await waitFor(() => expect(getBill).toHaveBeenCalledWith('bill-2', expect.anything()))
    })
})

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms))
}
