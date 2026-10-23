import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

// Mock recharts to prevent canvas/SVG rendering issues in JSDOM
vi.mock('recharts', () => ({
    ResponsiveContainer: ({ children }) => <div data-testid="responsive-container"><svg>{children}</svg></div>,
    ComposedChart: ({ children }) => <g data-testid="composed-chart">{children}</g>,
    BarChart: ({ children }) => <g data-testid="bar-chart">{children}</g>,
    AreaChart: ({ children }) => <g data-testid="area-chart">{children}</g>,
    LineChart: ({ children }) => <g data-testid="line-chart">{children}</g>,
    Bar: ({ children }) => <g data-testid="bar">{children}</g>,
    Cell: () => null,
    Line: () => null,
    Area: () => null,
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
        getDaily: vi.fn(),
        getDayCurve: vi.fn(),
        getSummary: vi.fn(),
    },
}))

import { analyticsApi } from '../../services/api/analytics'
import { AnalyticsPage } from './AnalyticsPage'
import {
    formatKwhAxis,
    formatKwAxis,
    formatCurrencyAxis,
} from '../../lib/axisFormatters'

function renderComponent() {
    return render(
        <MemoryRouter>
            <AnalyticsPage />
        </MemoryRouter>
    )
}

/** Realistic populated summary based on GoodWe historical dataset */
function mockSummary(overrides = {}) {
    return {
        solar: {
            reading_count: 39726,
            reading_days: 267,
            first_month: '2026-01',
            last_month: '2026-10',
            earliest_date: '2026-01-15',
            latest_date: '2026-10-08',
            total_generation_kwh: 3468.105,
            displayed_generation_kwh: 3468.11,
            avg_daily_generation_kwh: 13.03,
            peak_power_w: 2988.0,
            peak_power_kw: 2.99,
            calendar_coverage_days: 267,
            calendar_coverage_total: 267,
            calendar_coverage_pct: 100.0,
            telemetry_completeness_pct: 99.71,
            unexpected_gap_count: 50,
            missing_intervals_count: 116,
            largest_gap: {
                duration_minutes: 240,
                duration_hours: 4.0,
                date: '2026-01-30',
                window: '10:30–14:30 IST',
            },
            gap_warning: {
                title: 'Telemetry gap detected',
                description: 'Solar telemetry was not recorded between 10:30 and 14:30 IST on 30 Jan 2026. Analytics does not treat this missing period as zero generation.',
                date: '2026-01-30',
                window: '10:30–14:30 IST',
                duration: '4.0 hours',
            },
            data_nature: 'ACTUAL',
        },
        bills: {
            bill_count: 7,
            verified_bill_count: 7,
            unverified_count: 0,
            first_month: '2026-03',
            last_month: '2026-09',
            data_nature: 'VERIFIED',
        },
        has_solar_data: true,
        has_bill_data: true,
        has_verified_bills: true,
        ...overrides,
    }
}

/** Mock daily response */
function mockDaily(overrides = {}) {
    return {
        range: { from_date: '2026-01-15', to_date: '2026-10-08' },
        points: [
            {
                date: '2026-01-30',
                label: '30 Jan 2026',
                solar_kwh: 7.21,
                peak_power_w: 1980.0,
                reading_count: 116,
                first_reading: '06:10:00',
                last_reading: '18:25:00',
                has_gap: true,
                gap_count: 1,
                max_gap_min: 240,
                gap_warning: '4 hour telemetry gap',
                is_commissioning_day: false,
                is_today: false,
                data_nature: 'ACTUAL',
            },
            {
                date: '2026-04-08',
                label: '08 Apr 2026',
                solar_kwh: 18.48,
                peak_power_w: 2890.0,
                reading_count: 147,
                first_reading: '06:00:00',
                last_reading: '18:35:00',
                has_gap: false,
                gap_count: 0,
                max_gap_min: 0,
                gap_warning: null,
                is_commissioning_day: false,
                is_today: false,
                data_nature: 'ACTUAL',
            },
        ],
        summary: {
            days_with_data: 267,
            total_kwh: 3468.11,
            avg_daily_kwh: 13.03,
            highest_day: {
                date: '2026-04-08',
                label: '08 Apr 2026',
                kwh: 18.475,
                peak_w: 2890.0,
            },
            lowest_day: {
                date: '2026-02-14',
                label: '14 Feb 2026',
                kwh: 0.256,
                peak_w: 210.0,
            },
            days_with_gaps: 21,
            peak_power_w: 2988.0,
        },
        ...overrides,
    }
}

/** Mock monthly response */
function mockMonthly(overrides = {}) {
    return {
        range: { from_month: '2026-01', to_month: '2026-10' },
        points: [
            {
                month: '2026-01',
                label: "Jan '26",
                is_current_period: false,
                solar_kwh: 197.65,
                solar_nature: 'ACTUAL',
                solar_days: 17,
                solar_peak_w: 2650.0,
                has_gap: true,
                gap_warning: '4 hour telemetry gap',
                consumption_kwh: null,
                verified_consumption_kwh: null,
                bill_nature: 'UNAVAILABLE',
                bill_amount: null,
                potential_balance_kwh: null,
                potential_balance_nature: 'UNAVAILABLE',
            },
            {
                month: '2026-03',
                label: "Mar '26",
                is_current_period: false,
                solar_kwh: 485.98,
                solar_nature: 'ACTUAL',
                solar_days: 31,
                solar_peak_w: 2950.0,
                has_gap: false,
                gap_warning: null,
                consumption_kwh: 210.0,
                verified_consumption_kwh: 210.0,
                bill_nature: 'VERIFIED',
                bill_amount: 1450.0,
                potential_balance_kwh: 275.98,
                potential_balance_nature: 'POTENTIAL',
            },
            {
                month: '2026-10',
                label: "Oct '26",
                is_current_period: true,
                solar_kwh: 111.5,
                solar_nature: 'ACTUAL',
                solar_days: 8,
                solar_peak_w: 2780.0,
                has_gap: false,
                gap_warning: null,
                consumption_kwh: null,
                verified_consumption_kwh: null,
                bill_nature: 'UNAVAILABLE',
                bill_amount: null,
                potential_balance_kwh: null,
                potential_balance_nature: 'UNAVAILABLE',
            },
        ],
        highlights: {
            highest_month: { month: '2026-03', label: 'March 2026', solar_kwh: 485.98 },
            lowest_full_month: { month: '2026-05', label: 'May 2026', solar_kwh: 303.47 },
            peak_inverter_output_w: 2988.0,
            historical_generation_kwh: 3468.11,
        },
        ...overrides,
    }
}

/** Mock day curve response */
function mockDayCurve(overrides = {}) {
    return {
        date: '2026-01-30',
        date_label: '30 Jan 2026',
        metrics: {
            reading_count: 116,
            daily_kwh: 7.21,
            peak_power_w: 1980.0,
            peak_power_kw: 1.98,
            first_reading: '06:10:00',
            last_reading: '18:25:00',
            has_gap: true,
            gap_count: 1,
            gaps: [
                {
                    from_time: '10:30',
                    to_time: '14:30',
                    gap_minutes: 240,
                    missing_readings: 47,
                },
            ],
            is_commissioning_day: false,
        },
        readings: [
            { time: '06:10', power_w: 25.0, power_kw: 0.025, daily_generation_kwh: 0.01, inverter_status: 'Normal', data_nature: 'ACTUAL' },
            { time: '10:30', power_w: 1850.0, power_kw: 1.85, daily_generation_kwh: 4.1, inverter_status: 'Normal', data_nature: 'ACTUAL' },
            { time: '14:30', power_w: 1200.0, power_kw: 1.2, daily_generation_kwh: 5.6, inverter_status: 'Normal', data_nature: 'ACTUAL' },
            { time: '18:25', power_w: 0.0, power_kw: 0.0, daily_generation_kwh: 7.21, inverter_status: 'Normal', data_nature: 'ACTUAL' },
        ],
        ...overrides,
    }
}

describe('AnalyticsPage (V2 Redesign)', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders top heading and subtitle', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Analytics/i })).toBeInTheDocument()
            expect(screen.getByText(/Historical performance from your solar system and verified electricity records/i)).toBeInTheDocument()
        })
    })

    it('shows empty state when no data exists', async () => {
        analyticsApi.getSummary.mockResolvedValue({
            solar: { reading_count: 0, reading_days: 0 },
            bills: { bill_count: 0 },
            has_solar_data: false,
            has_bill_data: false,
        })
        analyticsApi.getDaily.mockResolvedValue({ points: [], summary: {} })
        analyticsApi.getMonthly.mockResolvedValue({ points: [], highlights: {} })
        analyticsApi.getDayCurve.mockResolvedValue({ readings: [], metrics: {} })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/No Analytics Data Available/i)).toBeInTheDocument()
        })
    })

    it('renders the 4 overview KPI cards with ACTUAL badges and dynamic numbers', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            // Card 1: Solar Generation
            expect(screen.getByText(/3,468.1 kWh/i)).toBeInTheDocument()
            // Card 2: Average Daily Generation
            expect(screen.getByText(/13.0 kWh\/day/i)).toBeInTheDocument()
            // Card 3: Peak Power
            expect(screen.getByText(/2.99 kW/i)).toBeInTheDocument()
            // Card 4: Solar Data Coverage
            expect(screen.getAllByText(/267 days/i).length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText(/99.71% interval completeness/i)).toBeInTheDocument()
        })
    })

    it('renders Primary Solar Performance section with daily detail insights when 30-day view is selected', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Last 30 days/i })).toBeInTheDocument()
        })

        fireEvent.click(screen.getByRole('button', { name: /Last 30 days/i }))

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /^Solar Performance$/i })).toBeInTheDocument()
            expect(screen.getByText(/Highest Day/i)).toBeInTheDocument()
            expect(screen.getByText(/18.48 kWh/i)).toBeInTheDocument()
            expect(screen.getByText(/Lowest Operating Day/i)).toBeInTheDocument()
            expect(screen.getByText(/0.26 kWh/i)).toBeInTheDocument()
            expect(screen.getByText(/21 days/i)).toBeInTheDocument()
            expect(screen.getByText(/Select a day to inspect 5-minute inverter telemetry/i)).toBeInTheDocument()
        })
    })

    it('renders Monthly Solar Performance section and Performance Highlights in multi-month range', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Monthly Solar Performance/i })).toBeInTheDocument()
            expect(screen.getByText(/Performance Highlights/i)).toBeInTheDocument()
            expect(screen.getByText(/Highest Production Month/i)).toBeInTheDocument()
            expect(screen.getByText(/March 2026/i)).toBeInTheDocument()
            expect(screen.getByText(/Lowest Full Month/i)).toBeInTheDocument()
            expect(screen.getByText(/May 2026/i)).toBeInTheDocument()
            expect(screen.getByText(/Switch to Daily \(30 Days\)/i)).toBeInTheDocument()
        })
    })

    it('renders Potential Energy Balance section when bills and solar overlap', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Potential Energy Balance/i })).toBeInTheDocument()
            expect(screen.getByText(/potential_balance_kwh = actual_solar_generation_kwh − verified_demand_kwh/i)).toBeInTheDocument()
        })
    })

    it('renders Verified Billing & Consumption section', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Verified Billing & Consumption/i })).toBeInTheDocument()
            expect(screen.getByText(/Verified Consumption \(kWh\)/i)).toBeInTheDocument()
            expect(screen.getByText(/Verified Bill Amount \(₹\)/i)).toBeInTheDocument()
        })
    })

    it('renders Data Quality & Coverage section with 50 unexpected gaps and warning banner', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Data Quality & Coverage/i })).toBeInTheDocument()
            expect(screen.getByText('50')).toBeInTheDocument()
            expect(screen.getByText('116')).toBeInTheDocument()
            // Warning banner
            expect(screen.getByText(/Telemetry gap detected/i)).toBeInTheDocument()
            expect(screen.getByText(/Solar telemetry was not recorded between 10:30 and 14:30 IST on 30 Jan 2026/i)).toBeInTheDocument()
        })
    })

    it('renders 5-Minute Power Curve drill-down with gap disclosure', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /5-Minute Power Curve \(Telemetry Drill-Down\)/i })).toBeInTheDocument()
            expect(screen.getByText(/30 Jan \(Largest Gap\)/i)).toBeInTheDocument()
            expect(screen.getByText(/Telemetry Gap Disclosed:/i)).toBeInTheDocument()
            expect(screen.getByText(/does not fabricate artificial power readings across missing intervals/i)).toBeInTheDocument()
        })
    })

    it('renders Monthly Breakdown Table with semantic badges and gap warnings', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Monthly Breakdown/i })).toBeInTheDocument()
            expect(screen.getAllByText(/Jan '26/i).length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText(/197\.65 kWh/i)).toBeInTheDocument()
            expect(screen.getAllByText(/Mar '26/i).length).toBeGreaterThanOrEqual(1)
            expect(screen.getAllByText(/485\.98 kWh/i).length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText(/\+276\.0 kWh/i)).toBeInTheDocument()
        })
    })

    it('renders Data Sources & Limitations disclosures', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Data Sources & Methodology Disclosures/i)).toBeInTheDocument()
            expect(screen.getByText(/NOT MEASURED/i)).toBeInTheDocument()
            expect(screen.getByText(/No physical bidirectional smart-meter telemetry is installed/i)).toBeInTheDocument()
        })
    })

    it('preset buttons trigger range change', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Last 30 days/i })).toBeInTheDocument()
        })

        const btn30d = screen.getByRole('button', { name: /Last 30 days/i })
        fireEvent.click(btn30d)

        await waitFor(() => {
            expect(analyticsApi.getDaily).toHaveBeenCalledWith(expect.objectContaining({ range: '30d' }))
        })
    })

    it('shows error banner when API fails', async () => {
        analyticsApi.getSummary.mockRejectedValue(new Error('Network connection timeout'))
        analyticsApi.getDaily.mockRejectedValue(new Error('Network connection timeout'))
        analyticsApi.getMonthly.mockRejectedValue(new Error('Network connection timeout'))
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Network connection timeout/i)).toBeInTheDocument()
        })
    })

    it('in-card switcher toggles between Daily (30 Days) and Monthly Overview', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /^Daily \(30 Days\)$/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /^Monthly Overview$/i })).toBeInTheDocument()
        })

        // Switch to Daily via in-card button
        fireEvent.click(screen.getByRole('button', { name: /^Daily \(30 Days\)$/i }))

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /^Solar Performance$/i })).toBeInTheDocument()
            expect(screen.getByText(/Select a day to inspect 5-minute inverter telemetry/i)).toBeInTheDocument()
            expect(screen.getByRole('link', { name: /Inspect Telemetry/i })).toHaveAttribute('href', '#telemetry-drilldown')
        })

        // Switch back to Monthly via in-card button
        fireEvent.click(screen.getByRole('button', { name: /^Monthly Overview$/i }))

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Monthly Solar Performance/i })).toBeInTheDocument()
            expect(screen.getByText(/Monthly Aggregation Overview/i)).toBeInTheDocument()
        })
    })

    it('excludes incomplete in-progress month from ranking and shows disclaimer', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary())
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/October is in progress and is excluded from full-month performance ranking/i)).toBeInTheDocument()
            // Lowest full month is May 2026, NOT October 2026
            expect(screen.getByText(/May 2026/i)).toBeInTheDocument()
            expect(screen.getByText(/303\.5 kWh/i)).toBeInTheDocument()
        })
    })

    it('renders honest explanation notice when solar and verified bills have no overlapping months', async () => {
        // Solar in Sep 2026, bill in Aug 2026 (no overlap)
        const nonOverlappingMonthly = {
            points: [
                {
                    month: '2026-08',
                    label: "Aug '26",
                    solar_kwh: null,
                    verified_consumption_kwh: 216.0,
                    bill_amount: 1450.0,
                },
                {
                    month: '2026-09',
                    label: "Sep '26",
                    solar_kwh: 74.33,
                    verified_consumption_kwh: null,
                    bill_amount: null,
                },
            ],
            highlights: {},
        }
        analyticsApi.getSummary.mockResolvedValue(mockSummary({
            solar: { ...mockSummary().solar, first_month: '2026-09', last_month: '2026-09' },
            bills: { ...mockSummary().bills, first_month: '2026-08', last_month: '2026-08' },
        }))
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(nonOverlappingMonthly)
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve())

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/No Overlapping Historical Months Available/i)).toBeInTheDocument()
            expect(screen.getByText(/Potential energy balance requires both measured solar generation and verified electricity billing in the same reporting month/i)).toBeInTheDocument()
        })
    })

    it('defaults selected date for day curve to latest telemetry date from summary', async () => {
        analyticsApi.getSummary.mockResolvedValue(mockSummary({
            solar: { ...mockSummary().solar, latest_date: '2026-09-20' },
        }))
        analyticsApi.getDaily.mockResolvedValue(mockDaily())
        analyticsApi.getMonthly.mockResolvedValue(mockMonthly())
        analyticsApi.getDayCurve.mockResolvedValue(mockDayCurve({ date: '2026-09-20' }))

        renderComponent()

        await waitFor(() => {
            expect(analyticsApi.getDayCurve).toHaveBeenCalled()
        })
    })
})

describe('Canonical Axis Formatters', () => {
    describe('formatKwhAxis', () => {
        it('formats numbers as clean integers without repeating kWh unit', () => {
            expect(formatKwhAxis(0)).toBe('0')
            expect(formatKwhAxis(5)).toBe('5')
            expect(formatKwhAxis(15)).toBe('15')
            expect(formatKwhAxis(420)).toBe('420')
            expect(formatKwhAxis(486.02)).toBe('486')
            expect(formatKwhAxis('250')).toBe('250')
        })

        it('never appends the unit "kWh" to tick labels', () => {
            const result = formatKwhAxis(20)
            expect(result).not.toContain('kWh')
            expect(result).toBe('20')
        })

        it('handles null, undefined, and non-numeric inputs gracefully', () => {
            expect(formatKwhAxis(null)).toBe('')
            expect(formatKwhAxis(undefined)).toBe('')
            expect(formatKwhAxis('invalid')).toBe('')
        })
    })

    describe('formatKwAxis', () => {
        it('formats power values cleanly without trailing zeros when integer', () => {
            expect(formatKwAxis(0)).toBe('0')
            expect(formatKwAxis(0.75)).toBe('0.75')
            expect(formatKwAxis(1.5)).toBe('1.5')
            expect(formatKwAxis(2.25)).toBe('2.25')
            expect(formatKwAxis(3)).toBe('3')
            expect(formatKwAxis(2.99)).toBe('2.99')
        })

        it('never appends the unit "kW" to tick labels', () => {
            const result = formatKwAxis(1.5)
            expect(result).not.toContain('kW')
            expect(result).toBe('1.5')
        })

        it('handles null, undefined, and non-numeric inputs gracefully', () => {
            expect(formatKwAxis(null)).toBe('')
            expect(formatKwAxis(undefined)).toBe('')
            expect(formatKwAxis('invalid')).toBe('')
        })
    })

    describe('formatCurrencyAxis', () => {
        it('formats numbers as clean currency tick values without currency symbol', () => {
            expect(formatCurrencyAxis(0)).toBe('0')
            expect(formatCurrencyAxis(50)).toBe('50')
            expect(formatCurrencyAxis(100)).toBe('100')
            expect(formatCurrencyAxis(250)).toBe('250')
            expect(formatCurrencyAxis(500)).toBe('500')
            expect(formatCurrencyAxis(149)).toBe('149')
        })

        it('never appends or prepends the currency symbol "₹" to tick labels', () => {
            const result = formatCurrencyAxis(150)
            expect(result).not.toContain('₹')
            expect(result).not.toContain('00 ₹')
            expect(result).toBe('150')
        })

        it('handles null, undefined, and non-numeric inputs gracefully', () => {
            expect(formatCurrencyAxis(null)).toBe('')
            expect(formatCurrencyAxis(undefined)).toBe('')
            expect(formatCurrencyAxis('invalid')).toBe('')
        })
    })
})

