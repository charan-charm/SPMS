import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'

vi.mock('../../services/api/reports', () => ({
    reportsApi: {
        getSummary: vi.fn(),
        downloadBills: vi.fn(),
        downloadSolar: vi.fn(),
    },
}))

import { reportsApi } from '../../services/api/reports'
import { ReportsPage } from './ReportsPage'

/**
 * F8 — ReportsPage container tests.
 * Verifies: page rendering, loading state, error state, empty state,
 * download actions, feedback on success, and download errors.
 */

function summaryData(billsCount = 5, solarCount = 120) {
    return {
        reports: {
            bills: {
                count: billsCount,
                first_date: billsCount > 0 ? '2024-01-01' : null,
                last_date: billsCount > 0 ? '2026-08-31' : null,
            },
            solar: {
                count: solarCount,
                first_date: solarCount > 0 ? '2026-09-01 00:00:00' : null,
                last_date: solarCount > 0 ? '2026-09-25 12:00:00' : null,
            },
        },
    }
}

describe('ReportsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('shows loading spinner while fetching summary', async () => {
        reportsApi.getSummary.mockReturnValue(new Promise(() => {})) // never resolves

        render(<ReportsPage />)

        expect(screen.getByText(/Loading available reports/i)).toBeInTheDocument()
    })

    it('renders both report cards after successful summary load', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(5, 120))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText('Verified Billing History')).toBeInTheDocument()
        })

        expect(screen.getByText('GoodWe Solar Telemetry')).toBeInTheDocument()
        const billCountEl = screen.getByText((c) => c.includes('verified statement'))
        expect(billCountEl).toBeInTheDocument()
        const solarCountEl = screen.getByText((c) => c.includes('telemetry interval'))
        expect(solarCountEl).toBeInTheDocument()
    })

    it('shows date coverage for bills when available', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(3, 0))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText('Verified Billing History')).toBeInTheDocument()
        })

        // Date coverage — the dates appear in a compound string like "2024-01-01 → 2026-08-31"
        const dateEl = screen.getByText((content) => content.includes('2024-01-01'))
        expect(dateEl).toBeInTheDocument()
        const lastDateEl = screen.getByText((content) => content.includes('2026-08-31'))
        expect(lastDateEl).toBeInTheDocument()
    })

    it('shows empty state guidance when bills count is 0', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(0, 0))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText('Verified Billing History')).toBeInTheDocument()
        })

        expect(screen.getByText((c) => c.includes('No verified statements'))).toBeInTheDocument()
        expect(screen.getByText((c) => c.includes('No telemetry recorded yet'))).toBeInTheDocument()
        expect(screen.getByText((c) => c.includes('0 readings recorded'))).toBeInTheDocument()
    })

    it('shows error banner when summary fetch fails', async () => {
        reportsApi.getSummary.mockRejectedValue(new Error('Network error'))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText(/Network error/i)).toBeInTheDocument()
        })
    })

    it('Download Billing History button calls downloadBills and shows success', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(5, 50))
        reportsApi.downloadBills.mockResolvedValue({ filename: 'spms_bills_history_2026-09-25.csv' })

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('Verified Billing History'))

        const btn = screen.getByRole('button', { name: /Download Billing History/i })
        fireEvent.click(btn)

        await waitFor(() => {
            expect(reportsApi.downloadBills).toHaveBeenCalledTimes(1)
        })

        await waitFor(() => {
            expect(screen.getByText(/Successfully downloaded/i)).toBeInTheDocument()
        })
    })

    it('Download Telemetry button calls downloadSolar and shows success', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(5, 50))
        reportsApi.downloadSolar.mockResolvedValue({ filename: 'spms_solar_telemetry_2026-09-25.csv' })

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('GoodWe Solar Telemetry'))

        const btn = screen.getByRole('button', { name: /Download Telemetry/i })
        fireEvent.click(btn)

        await waitFor(() => {
            expect(reportsApi.downloadSolar).toHaveBeenCalledTimes(1)
        })

        await waitFor(() => {
            expect(screen.getByText(/Successfully downloaded/i)).toBeInTheDocument()
        })
    })

    it('shows download error banner when bills export fails', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(5, 50))
        reportsApi.downloadBills.mockRejectedValue(new Error('Export failed'))

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('Verified Billing History'))

        const btn = screen.getByRole('button', { name: /Download Billing History/i })
        fireEvent.click(btn)

        await waitFor(() => {
            expect(screen.getByText(/Export failed/i)).toBeInTheDocument()
        })
    })

    it('shows loading spinner on Download button while in progress', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(5, 50))
        let resolve
        reportsApi.downloadBills.mockReturnValue(new Promise((r) => { resolve = r }))

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('Verified Billing History'))

        const btn = screen.getByRole('button', { name: /Download Billing History/i })
        fireEvent.click(btn)

        await waitFor(() => {
            expect(screen.getByText(/Preparing CSV/i)).toBeInTheDocument()
        })

        resolve({ filename: 'test.csv' })
    })

    it('renders column field names in bills report card', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(2, 10))

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('Verified Billing History'))

        expect(screen.getByText('billing_period_start')).toBeInTheDocument()
        expect(screen.getByText('bill_amount')).toBeInTheDocument()
        expect(screen.getByText('verification_status')).toBeInTheDocument()
    })

    it('renders column field names in solar report card', async () => {
        reportsApi.getSummary.mockResolvedValue(summaryData(2, 10))

        render(<ReportsPage />)
        await waitFor(() => screen.getByText('GoodWe Solar Telemetry'))

        expect(screen.getByText('measurement_time')).toBeInTheDocument()
        expect(screen.getByText('daily_generation_kwh')).toBeInTheDocument()
        expect(screen.getByText('inverter_status')).toBeInTheDocument()
    })
})
