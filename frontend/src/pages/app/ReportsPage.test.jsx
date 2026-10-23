import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'

vi.mock('../../services/api/reports', () => ({
    reportsApi: {
        getMonthly: vi.fn(),
        downloadPdf: vi.fn(),
        downloadExcel: vi.fn(),
    },
}))

import { reportsApi } from '../../services/api/reports'
import { ReportsPage } from './ReportsPage'

function mockMonthlyReportData(overrides = {}) {
    return {
        period: {
            month: '2026-09',
            label: 'September 2026',
            year: 2026,
            month_number: 9,
            start_date: '2026-09-01',
            end_date: '2026-09-30',
            days_in_month: 30,
            is_current_month: false,
            previous_month: '2026-08',
            next_month: '2026-10',
        },
        available_months: [
            { month: '2026-09', label: 'September 2026', is_current_month: false },
            { month: '2026-08', label: 'August 2026', is_current_month: false },
        ],
        kpis: {
            solar_generation_kwh: 420.08,
            solar_data_nature: 'ACTUAL',
            solar_provenance: 'GoodWe SEMS Inverter Telemetry',
            household_demand_kwh: 348.0,
            demand_data_nature: 'ESTIMATED',
            demand_provenance: 'Verified billing history',
            potential_balance_kwh: 72.08,
            balance_data_nature: 'POTENTIAL',
            balance_provenance: 'Analytical balance',
            balance_status: 'SURPLUS',
            latest_verified_bill: {
                amount: 329.0,
                billing_period_start: '2026-09-01',
                billing_period_end: '2026-10-01',
                billing_period_label: '01 Sep → 01 Oct',
                matches_calendar_month: false,
                status: 'VERIFIED',
                provenance: 'Verified APSPDCL statement',
            },
            bills_summary: {
                total_count: 1,
                verified_count: 1,
                awaiting_review_count: 0,
            },
            solar_coverage: {
                days_with_data: 30,
                total_days_in_month: 30,
                readings_count: 4320,
                first_reading: '2026-09-01 00:00:00',
                last_reading: '2026-09-30 23:55:00',
                data_status: 'FRESH',
            },
        },
        insight: {
            headline: 'Net Energy Surplus for September 2026',
            narrative: 'Solar generation (420.08 kWh) exceeded estimated household demand (348.00 kWh) during September 2026.',
            provenance_line: 'ACTUAL solar • ESTIMATED demand • POTENTIAL balance',
            limitations: [
                'Potential energy balance is an analytical comparison of generation vs estimated demand, not a measured grid export.',
            ],
        },
        daily_trends: [
            {
                date: '2026-09-01',
                day: 1,
                solar_generation_kwh: 14.5,
                estimated_demand_kwh: 11.6,
                potential_balance_kwh: 2.9,
            },
        ],
        bills: [
            {
                id: 'b-1',
                billing_period_start: '2026-09-01',
                billing_period_end: '2026-10-01',
                service_number: '••••••7890',
                consumption_kwh: 203.0,
                solar_export_units_kwh: 303.0,
                net_units_kwh: -100.0,
                bill_amount: 329.0,
                status: 'VERIFIED',
                source: 'APSPDCL_BILL',
            },
        ],
        ...overrides,
    }
}

describe('ReportsPage', () => {
    beforeEach(() => {
        vi.resetAllMocks()
    })

    it('shows loading state while fetching monthly report', async () => {
        reportsApi.getMonthly.mockReturnValue(new Promise(() => {}))

        render(<ReportsPage />)

        expect(screen.getByText(/Loading monthly energy report/i)).toBeInTheDocument()
    })

    it('renders monthly report with custom month selector and 4 KPI cards', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Select report month, currently September 2026/i })).toBeInTheDocument()
        })

        // KPI card values & badges
        expect(screen.getByText('420.08 kWh')).toBeInTheDocument()
        expect(screen.getByText('ACTUAL')).toBeInTheDocument()
        expect(screen.getByText('GoodWe SEMS Inverter Telemetry')).toBeInTheDocument()

        expect(screen.getByText('348 kWh')).toBeInTheDocument()
        expect(screen.getByText('ESTIMATED')).toBeInTheDocument()
        expect(screen.getByText('Verified billing history')).toBeInTheDocument()

        expect(screen.getByText('+72.08 kWh')).toBeInTheDocument()
        expect(screen.getByText('POTENTIAL')).toBeInTheDocument()
        expect(screen.getByText('Analytical balance')).toBeInTheDocument()

        // Latest Verified Bill
        expect(screen.getByText('Latest Verified Bill')).toBeInTheDocument()
        expect(screen.getAllByText('₹329').length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('Billing: 01 Sep → 01 Oct')).toBeInTheDocument()
    })

    it('renders monthly insight and provenance line', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText(/Solar generation \(420.08 kWh\) exceeded estimated household demand/i)).toBeInTheDocument()
        })

        // Provenance line
        expect(screen.getByText('ACTUAL solar')).toBeInTheDocument()
        expect(screen.getByText('ESTIMATED demand')).toBeInTheDocument()
        expect(screen.getByText('POTENTIAL balance')).toBeInTheDocument()
    })

    it('renders billing summary table with distinct solar export units and verification badge', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText('Billing Summary')).toBeInTheDocument()
        })

        expect(screen.getByText('1 Verified')).toBeInTheDocument()
        expect(screen.getByText('2026-09-01 → 2026-10-01')).toBeInTheDocument()
        expect(screen.getByText('203 kWh')).toBeInTheDocument()
        expect(screen.getByText('303 kWh')).toBeInTheDocument()
        expect(screen.getByText('-100 kWh')).toBeInTheDocument()
    })

    it('triggers primary PDF report download and displays success toast', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())
        reportsApi.downloadPdf.mockResolvedValue({ filename: 'SPMS_Monthly_Energy_Report_September_2026.pdf' })

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText(/Download Monthly PDF Report/i)).toBeInTheDocument()
        })

        const pdfBtn = screen.getByText(/Download Monthly PDF Report/i)
        fireEvent.click(pdfBtn)

        await waitFor(() => {
            expect(reportsApi.downloadPdf).toHaveBeenCalledWith('2026-09')
            expect(screen.getByText(/Successfully downloaded SPMS_Monthly_Energy_Report_September_2026.pdf/i)).toBeInTheDocument()
        })
    })

    it('triggers secondary Excel download', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())
        reportsApi.downloadExcel.mockResolvedValue({ filename: 'SPMS_Monthly_Energy_Report_September_2026.xlsx' })

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText(/Download Detailed Excel Workbook/i)).toBeInTheDocument()
        })

        const excelBtn = screen.getByText(/Download Detailed Excel Workbook/i)
        fireEvent.click(excelBtn)

        await waitFor(() => {
            expect(reportsApi.downloadExcel).toHaveBeenCalledWith('2026-09')
            expect(screen.getByText(/Successfully downloaded SPMS_Monthly_Energy_Report_September_2026.xlsx/i)).toBeInTheDocument()
        })
    })

    it('does NOT display any CSV buttons or CSV export labels', async () => {
        reportsApi.getMonthly.mockResolvedValue(mockMonthlyReportData())

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Select report month, currently September 2026/i })).toBeInTheDocument()
        })

        // Ensure no CSV text or buttons exist
        expect(screen.queryByText(/CSV/i)).toBeNull()
        expect(screen.queryByText(/Billing History \(CSV\)/i)).toBeNull()
        expect(screen.queryByText(/Solar Telemetry \(CSV\)/i)).toBeNull()
    })

    it('navigates to previous month using custom month selector button', async () => {
        reportsApi.getMonthly
            .mockResolvedValueOnce(mockMonthlyReportData())
            .mockResolvedValueOnce(mockMonthlyReportData({
                period: {
                    month: '2026-08',
                    label: 'August 2026',
                    year: 2026,
                    month_number: 8,
                    start_date: '2026-08-01',
                    end_date: '2026-08-31',
                    days_in_month: 31,
                    is_current_month: false,
                    previous_month: '2026-07',
                    next_month: '2026-09',
                },
            }))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Select report month, currently September 2026/i })).toBeInTheDocument()
        })

        const prevBtn = screen.getByLabelText('Previous Month')
        fireEvent.click(prevBtn)

        await waitFor(() => {
            expect(reportsApi.getMonthly).toHaveBeenCalledWith('2026-08', expect.any(Object))
        })
    })

    it('opens custom month selector listbox and selects a different month', async () => {
        reportsApi.getMonthly
            .mockResolvedValueOnce(mockMonthlyReportData())
            .mockResolvedValueOnce(mockMonthlyReportData({
                period: {
                    month: '2026-08',
                    label: 'August 2026',
                    year: 2026,
                    month_number: 8,
                    start_date: '2026-08-01',
                    end_date: '2026-08-31',
                    days_in_month: 31,
                    is_current_month: false,
                    previous_month: '2026-07',
                    next_month: '2026-09',
                },
            }))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Select report month, currently September 2026/i })).toBeInTheDocument()
        })

        const trigger = screen.getByRole('button', { name: /Select report month/i })
        fireEvent.click(trigger)

        expect(screen.getByRole('listbox')).toBeInTheDocument()
        const augustOption = screen.getByRole('option', { name: /August 2026/i })
        fireEvent.click(augustOption)

        await waitFor(() => {
            expect(reportsApi.getMonthly).toHaveBeenCalledWith('2026-08', expect.any(Object))
        })
    })

    it('shows error banner when report fetch fails', async () => {
        reportsApi.getMonthly.mockRejectedValue(new Error('Failed to load report from server'))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getByText('Failed to load report from server')).toBeInTheDocument()
        })
    })

    it('renders SIMULATED badge and demo provenance when solar_data_nature is SIMULATED', async () => {
        reportsApi.getMonthly.mockResolvedValueOnce(mockMonthlyReportData({
            kpis: {
                solar_generation_kwh: 420.08,
                solar_data_nature: 'SIMULATED',
                solar_provenance: 'Simulated DEMO',
                household_demand_kwh: 348.0,
                demand_data_nature: 'ESTIMATED',
                demand_provenance: 'Verified billing history',
                potential_balance_kwh: 72.08,
                balance_data_nature: 'POTENTIAL',
                balance_provenance: 'Analytical balance',
                balance_status: 'SURPLUS',
                latest_verified_bill: null,
                bills_summary: { total_count: 0, verified_count: 0, awaiting_review_count: 0 },
                solar_coverage: { days_with_data: 30, total_days_in_month: 30 },
            },
        }))

        render(<ReportsPage />)

        await waitFor(() => {
            expect(screen.getAllByText('SIMULATED').length).toBeGreaterThanOrEqual(1)
        })
        expect(screen.getByText('Simulated DEMO')).toBeInTheDocument()
        expect(screen.getByText(/SIMULATED solar/)).toBeInTheDocument()
    })
})
