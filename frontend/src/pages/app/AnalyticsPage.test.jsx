import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

// Mock recharts to prevent canvas/SVG rendering issues in JSDOM
vi.mock('recharts', () => ({
    ResponsiveContainer: ({ children }) => <div data-testid="responsive-container">{children}</div>,
    ComposedChart: ({ children }) => <div data-testid="composed-chart">{children}</div>,
    Bar: () => null,
    Line: () => null,
    XAxis: () => null,
    YAxis: () => null,
    CartesianGrid: () => null,
    Tooltip: () => null,
    Legend: () => null,
    ReferenceLine: () => null,
}))

vi.mock('../../services/api/analytics', () => ({
    analyticsApi: {
        getMonthly: vi.fn(),
        getSummary: vi.fn(),
    },
}))

import { analyticsApi } from '../../services/api/analytics'
import { AnalyticsPage } from './AnalyticsPage'

function renderComponent() {
    return render(
        <MemoryRouter>
            <AnalyticsPage />
        </MemoryRouter>
    )
}

/** Minimal monthly response with no data */
function emptyMonthly() {
    return {
        range: { from_month: '2026-01', to_month: '2026-03' },
        points: [
            {
                month: '2026-01',
                solar_kwh: null,
                solar_nature: 'UNAVAILABLE',
                solar_days: 0,
                consumption_kwh: null,
                bill_nature: 'UNAVAILABLE',
                bill_count: 0,
                solar_units_kwh: null,
                bill_amount: null,
                net_kwh: null,
                net_nature: 'UNAVAILABLE',
            },
        ],
        summary: {
            total_months: 1,
            solar_months_with_data: 0,
            bill_months_with_data: 0,
            overlap_months: 0,
            total_solar_kwh: null,
            total_consumption_kwh: null,
        },
        data_notes: {
            solar: 'ACTUAL — from GoodWe inverter telemetry',
            consumption: 'VERIFIED/EXTRACTED — from APSPDCL bills only',
            net: 'Derived from solar − consumption',
        },
    }
}

/** Monthly response with solar + bill data for one month */
function populatedMonthly() {
    return {
        range: { from_month: '2026-07', to_month: '2026-07' },
        points: [
            {
                month: '2026-07',
                solar_kwh: 45.5,
                solar_nature: 'ACTUAL',
                solar_days: 12,
                consumption_kwh: 180.0,
                bill_nature: 'VERIFIED',
                bill_count: 1,
                solar_units_kwh: 20.0,
                bill_amount: 1400.0,
                net_kwh: -134.5,
                net_nature: 'ACTUAL',
            },
        ],
        summary: {
            total_months: 1,
            solar_months_with_data: 1,
            bill_months_with_data: 1,
            overlap_months: 1,
            total_solar_kwh: 45.5,
            total_consumption_kwh: 180.0,
        },
        data_notes: {
            solar: 'ACTUAL — from GoodWe inverter telemetry',
            consumption: 'VERIFIED/EXTRACTED — from APSPDCL bills only',
            net: 'Derived from solar − consumption',
        },
    }
}

describe('AnalyticsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('shows loading state initially', async () => {
        analyticsApi.getSummary.mockReturnValue(new Promise(() => {}))
        analyticsApi.getMonthly.mockReturnValue(new Promise(() => {}))

        renderComponent()

        expect(screen.getByText(/Loading analytics data/i)).toBeInTheDocument()
    })

    it('renders page heading', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 0, reading_days: 0, first_month: null, last_month: null },
            bills: { bill_count: 0, first_month: null, last_month: null },
            has_solar_data: false,
            has_bill_data: false,
        })
        analyticsApi.getMonthly.mockResolvedValue(emptyMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Analytics/i })).toBeInTheDocument()
        })
    })

    it('shows no-data empty state when household has no solar and no bills', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 0, reading_days: 0, first_month: null, last_month: null },
            bills: { bill_count: 0, first_month: null, last_month: null },
            has_solar_data: false,
            has_bill_data: false,
        })
        analyticsApi.getMonthly.mockResolvedValue(emptyMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/No analytics data yet/i)).toBeInTheDocument()
        })

        // Call-to-action links present
        expect(screen.getByText(/Connect inverter/i)).toBeInTheDocument()
        expect(screen.getByText(/Upload bills/i)).toBeInTheDocument()
    })

    it('renders stat cards when data is present', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 12, reading_days: 12, first_month: '2026-07', last_month: '2026-07' },
            bills: { bill_count: 1, first_month: '2026-07', last_month: '2026-07' },
            has_solar_data: true,
            has_bill_data: true,
        })
        analyticsApi.getMonthly.mockResolvedValue(populatedMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Total Solar Generated/i)).toBeInTheDocument()
            expect(screen.getByText(/Total Billed Consumption/i)).toBeInTheDocument()
            expect(screen.getByText(/Net Balance/i)).toBeInTheDocument()
        })
    })

    it('renders monthly breakdown table with correct data', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 12, reading_days: 12, first_month: '2026-07', last_month: '2026-07' },
            bills: { bill_count: 1, first_month: '2026-07', last_month: '2026-07' },
            has_solar_data: true,
            has_bill_data: true,
        })
        analyticsApi.getMonthly.mockResolvedValue(populatedMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('table', { name: /Monthly analytics breakdown/i })).toBeInTheDocument()
        })

        // Verify the month row renders kWh values (value appears in stat card + table row)
        const kwhMatches = screen.getAllByText(/45\.5 kWh/i)
        expect(kwhMatches.length).toBeGreaterThanOrEqual(1)
    })

    it('shows data sources & limitations note when data is available', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 5, reading_days: 5, first_month: '2026-07', last_month: '2026-07' },
            bills: { bill_count: 1, first_month: '2026-07', last_month: '2026-07' },
            has_solar_data: true,
            has_bill_data: true,
        })
        analyticsApi.getMonthly.mockResolvedValue(populatedMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Data Sources/i)).toBeInTheDocument()
            expect(screen.getByText(/GoodWe inverter telemetry/i)).toBeInTheDocument()
        })
    })

    it('shows error banner when API fails', async () => {
        analyticsApi.getSummary.mockRejectedValue(new Error('Network failure'))
        analyticsApi.getMonthly.mockRejectedValue(new Error('Network failure'))

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Failed to load analytics/i)).toBeInTheDocument()
        })
    })

    it('preset buttons switch the date range', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 0, reading_days: 0, first_month: null, last_month: null },
            bills: { bill_count: 0, first_month: null, last_month: null },
            has_solar_data: false,
            has_bill_data: false,
        })
        analyticsApi.getMonthly.mockResolvedValue(emptyMonthly())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/No analytics data yet/i)).toBeInTheDocument()
        })

        const btn6m = screen.getByRole('button', { name: /Last 6 months/i })
        fireEvent.click(btn6m)

        // After clicking, getMonthly should be called again with a from_month
        await waitFor(() => {
            expect(analyticsApi.getMonthly).toHaveBeenCalledTimes(2)
        })
    })
})
