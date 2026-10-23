import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { EnergyBalancePage } from './EnergyBalancePage'

let mockAccount = {
    id: 'user-real-123',
    email: 'charan581548@gmail.com',
    mode: 'REAL',
    environment: 'PRODUCTION',
}

let mockHousehold = {
    id: 'hh-charan-123',
    label: 'Charan Residence',
    timezone: 'Asia/Kolkata',
}

vi.mock('../../features/auth/useAuth', () => ({
    useAuth: () => ({
        account: mockAccount,
        isAuthenticated: true,
    }),
}))

vi.mock('../../features/household/useHousehold', () => ({
    useHousehold: () => ({
        household: mockHousehold,
        hasHousehold: true,
    }),
}))

vi.mock('../../services/api/solar', () => ({
    solarApi: {
        getLatest: vi.fn(),
    },
}))

vi.mock('../../services/api/forecast', () => ({
    getForecasts: vi.fn(),
    getForecastValidation: vi.fn(),
    getForecastPerformance: vi.fn(),
}))

import { solarApi } from '../../services/api/solar'
import { getForecasts, getForecastValidation, getForecastPerformance } from '../../services/api/forecast'

const sampleSolarLatest = {
    power_w: 1799.0,
    daily_generation_kwh: 2.758,
    get measurement_time() {
        const d = new Date()
        const y = d.getFullYear()
        const m = String(d.getMonth() + 1).padStart(2, '0')
        const day = String(d.getDate()).padStart(2, '0')
        return `${y}-${m}-${day} 09:35:00`
    },
    inverter_status: 'NORMAL',
    data_status: 'FRESH',
    inverter_sn: '53000N3A258L5685',
}

const sampleForecastData = {
    household_id: 'hh-charan-123',
    horizon: 'DAY_AHEAD',
    generated_at: '2026-10-04T14:18:18Z',
    period: {
        start: '2026-10-04T14:00:00Z',
        end: '2026-10-05T14:00:00Z',
    },
    solar_forecast: {
        predicted_value: 18.15,
        confidence: 'HIGH',
        uncertainty_range: {
            lower: 15.97,
            upper: 20.33,
        },
        period: {
            start: '2026-10-04T14:00:00Z',
            end: '2026-10-05T14:00:00Z',
        },
        provenance: {
            model_name: 'SPMS Solar Calibrated V2',
            peak_capacity_w: 3000.0,
            evidence_count: 5300,
        },
    },
    weather: {
        available: true,
        provider: 'OPEN_METEO',
        source_label: 'Open-Meteo',
        location_name: 'Kodumur, India',
        condition: 'CLEAR',
        cloud_cover_pct: 15.0,
        temperature_c: 28.5,
    },
}

const sampleValidationToday = {
    status: 'SUCCESS',
    validation_state: 'IN_PROGRESS',
    validation_message: 'Intraday validation in progress (4 daylight hours observed so far).',
    target_period: {
        date_label: '04 Oct 2026',
        time_label: '06:00 – 18:00',
        is_completed: false,
    },
    totals: {
        predicted_generation_kwh: 12.24,
        actual_generation_kwh: 2.758,
        completed_intervals: 10,
        daylight_intervals_validated: 4,
    },
    summary: {
        predicted_energy_kwh: 12.24,
        actual_energy_kwh: 2.758,
        accuracy_pct: 82.6,
        mae_kw: 0.52,
        rmse_kw: 0.74,
        observation_count: 147,
    },
    metrics: {
        mae_kw: 0.52,
        rmse_kw: 0.74,
        accuracy_pct: 82.6,
    },
    time_series: [
        {
            timestamp: '2026-10-04T00:30:00Z',
            display_time: '06:00',
            hour: 6,
            predicted_kw: 0.0,
            actual_kw: 0.05,
            error_kw: 0.05,
            reading_count: 10,
            status: 'COMPLETED',
        },
        {
            timestamp: '2026-10-04T01:30:00Z',
            display_time: '07:00',
            hour: 7,
            predicted_kw: 0.78,
            actual_kw: 0.5,
            error_kw: -0.28,
            reading_count: 12,
            status: 'COMPLETED',
        },
        {
            timestamp: '2026-10-04T02:30:00Z',
            display_time: '08:00',
            hour: 8,
            predicted_kw: 1.5,
            actual_kw: 1.18,
            error_kw: -0.32,
            reading_count: 12,
            status: 'COMPLETED',
        },
        {
            timestamp: '2026-10-04T03:30:00Z',
            display_time: '09:00',
            hour: 9,
            predicted_kw: 2.12,
            actual_kw: 1.76,
            error_kw: -0.36,
            reading_count: 12,
            status: 'IN_PROGRESS',
        },
        {
            timestamp: '2026-10-04T04:30:00Z',
            display_time: '10:00',
            hour: 10,
            predicted_kw: 2.6,
            actual_kw: null,
            error_kw: null,
            reading_count: 0,
            status: 'FUTURE',
        },
    ],
}

const sampleValidationYesterday = {
    status: 'SUCCESS',
    validation_state: 'VALIDATED',
    validation_message: 'Historical forecast validation against authentic GoodWe SEMS telemetry (13 hours).',
    target_period: {
        date_label: '03 Oct 2026',
        time_label: '06:00 – 18:00',
        is_completed: true,
    },
    totals: {
        predicted_generation_kwh: 18.674,
        actual_generation_kwh: 13.048,
        completed_intervals: 24,
        daylight_intervals_validated: 13,
    },
    summary: {
        predicted_energy_kwh: 18.674,
        actual_energy_kwh: 13.048,
        accuracy_pct: 78.4,
        mae_kw: 0.77,
        rmse_kw: 0.96,
        observation_count: 156,
    },
    metrics: {
        mae_kw: 0.77,
        rmse_kw: 0.96,
        accuracy_pct: 78.4,
    },
    time_series: [
        {
            timestamp: '2026-10-03T00:30:00Z',
            display_time: '06:00',
            hour: 6,
            predicted_kw: 0.0,
            actual_kw: 0.05,
            error_kw: 0.05,
            reading_count: 10,
            status: 'COMPLETED',
        },
        {
            timestamp: '2026-10-03T06:30:00Z',
            display_time: '12:00',
            hour: 12,
            predicted_kw: 3.0,
            actual_kw: 1.95,
            error_kw: -1.05,
            reading_count: 12,
            status: 'COMPLETED',
        },
    ],
}

const samplePerformanceSummary = {
    status: 'VALIDATED',
    completed_count: 35,
    aggregate_mae_kw: 0.52,
    aggregate_rmse_kw: 0.67,
    average_accuracy_pct: 82.8,
    v2_completed_count: 35,
    v2_aggregate_mae_kw: 0.52,
    v2_aggregate_rmse_kw: 0.67,
    v2_average_accuracy_pct: 82.8,
    legacy_completed_count: 35,
    legacy_average_accuracy_pct: 50.1,
    available_dates: ['2026-10-03', '2026-10-02', '2026-10-01'],
    history: [
        {
            date: '2026-10-03',
            date_label: '03 Oct 2026',
            horizon: 'DAY_AHEAD',
            predicted_kwh: 18.674,
            actual_kwh: 13.048,
            mae_kw: 0.77,
            rmse_kw: 0.96,
            accuracy_pct: 78.4,
            status: 'VALIDATED',
        },
        {
            date: '2026-10-02',
            date_label: '02 Oct 2026',
            horizon: 'DAY_AHEAD',
            predicted_kwh: 13.155,
            actual_kwh: 11.2,
            mae_kw: 0.55,
            rmse_kw: 0.68,
            accuracy_pct: 81.7,
            status: 'VALIDATED',
        },
    ],
}

describe('EnergyBalancePage (Final Energy + Solar Forecast Validation System)', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockAccount.mode = 'REAL'
        mockAccount.environment = 'PRODUCTION'

        solarApi.getLatest.mockResolvedValue({ latest: sampleSolarLatest })
        getForecasts.mockResolvedValue({ data: sampleForecastData })
        getForecastValidation.mockImplementation(async ({ date } = {}) => {
            if (date) {
                return { data: sampleValidationYesterday }
            }
            return { data: sampleValidationToday }
        })
        getForecastPerformance.mockResolvedValue({ data: samplePerformanceSummary })
    })

    it('renders the main Energy & Solar Forecasting heading and refresh button', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/Energy & Solar Forecasting/i)
        })

        expect(screen.getByRole('button', { name: /Refresh/i })).toBeInTheDocument()
    })

    it('Section 1: renders Current Solar with ACTUAL GoodWe telemetry', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('current-solar-card')).toBeInTheDocument()
        })

        // Check ACTUAL badge
        const currentCard = screen.getByTestId('current-solar-card')
        expect(within(currentCard).getByText(/ACTUAL · GoodWe SEMS/i)).toBeInTheDocument()
        // Today's generation
        expect(within(currentCard).getByText('2.76')).toBeInTheDocument()
        // Power output
        expect(within(currentCard).getByText('1.80')).toBeInTheDocument()
    })

    it('Section 2: renders Solar Forecast with PREDICTED badge, target date, and model provenance', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('solar-forecast-card')).toBeInTheDocument()
        })

        const solarCard = screen.getByTestId('solar-forecast-card')
        expect(within(solarCard).getByText('PREDICTED')).toBeInTheDocument()
        expect(within(solarCard).getByText('18.15')).toBeInTheDocument()
        expect(within(solarCard).getByText(/SPMS Solar Calibrated V2/i)).toBeInTheDocument()
        expect(within(solarCard).getByText(/Day-Ahead Horizon/i)).toBeInTheDocument()
        expect(within(solarCard).getByText(/HIGH CONFIDENCE/i)).toBeInTheDocument()
    })

    it('Section 3: renders Forecast vs Actual line visualization with Day toggle', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-vs-actual-card')).toBeInTheDocument()
        })

        const valCard = screen.getByTestId('forecast-vs-actual-card')
        expect(within(valCard).getByRole('button', { name: /Today \(Live\)/i })).toBeInTheDocument()
        expect(within(valCard).getByRole('button', { name: /Yesterday \(Validated\)/i })).toBeInTheDocument()
    })

    it('Section 4: renders Forecast Performance with canonical 82.8% V2 capacity-normalized score', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-performance-card')).toBeInTheDocument()
        })

        const perfCard = screen.getByTestId('forecast-performance-card')
        expect(within(perfCard).getByText('Capacity-Normalized Performance Score')).toBeInTheDocument()
        expect(within(perfCard).getByText('82.8')).toBeInTheDocument()
        expect(within(perfCard).getByText('0.52')).toBeInTheDocument()
        expect(within(perfCard).getByText('0.67')).toBeInTheDocument()
    })

    it('Section 5: renders Forecast Inputs & Model in collapsed state by default and toggles on demand', async () => {
        const user = userEvent.setup()

        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-inputs-card')).toBeInTheDocument()
        })

        expect(screen.getByText(/Forecast Inputs & Model/i)).toBeInTheDocument()
        expect(screen.getByText(/View methodology/i)).toBeInTheDocument()

        // Click View methodology to expand
        await user.click(screen.getByText(/View methodology/i))

        expect(screen.getByText(/Historical Inverter Telemetry/i)).toBeInTheDocument()
        expect(screen.getByText(/System Capacity Specifications/i)).toBeInTheDocument()
        expect(screen.getByText(/Diurnal Solar Geometry/i)).toBeInTheDocument()
        expect(screen.getByText(/Atmospheric Weather Forecast/i)).toBeInTheDocument()
        expect(screen.getByText(/Geocoded Panel Location/i)).toBeInTheDocument()
    })

    it('Section 6: renders Recent Completed Validations audit trail', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('recent-validations-card')).toBeInTheDocument()
        })

        expect(screen.getByText(/Recent Forecast Results/i)).toBeInTheDocument()
        expect(screen.getByText('03 Oct 2026')).toBeInTheDocument()
        expect(screen.getByText('18.67 kWh')).toBeInTheDocument()
        expect(screen.getByText('13.05 kWh')).toBeInTheDocument()
    })

    it('STRICT DATA TRUTH: displays household demand only at supported daily resolution with NO fabricated hourly curve', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('energy-page')).toBeInTheDocument()
        })

        // Assert that NO fake hourly demand curve, fake live load, or sample analysis exists
        expect(screen.queryByTestId('forecast-trend-chart')).not.toBeInTheDocument()
        expect(screen.queryByText(/Sub-Hourly Household Demand/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/Sample Analysis Sandbox/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/Live household load/i)).not.toBeInTheDocument()

        // Assert that supported daily baseline demand and potential balance ARE rendered
        expect(screen.getByTestId('forecast-energy-card')).toBeInTheDocument()
        expect(screen.getByText(/Forecast Energy Balance/i)).toBeInTheDocument()
        expect(screen.getByText(/Daily baseline from verified monthly bills/i)).toBeInTheDocument()
    })

    it('TERMINOLOGY INTEGRITY: completely eliminates misleading walk-forward validation wording', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('energy-page')).toBeInTheDocument()
        })

        // Assert that misleading "walk-forward" terminology is nowhere in the page
        expect(screen.queryByText(/walk-forward/i)).not.toBeInTheDocument()
        // Confirm truthful terminology is present in validation summary & methodology
        expect(screen.getAllByText(/Retrospective weather-assisted validation/i).length).toBeGreaterThan(0)
    })

    it('CAPACITY SPECIFICATION: clearly distinguishes rated inverter capacity from observed validation peak', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-inputs-card')).toBeInTheDocument()
        })

        expect(screen.getAllByText(/Rated inverter capacity: 3.00 kW/i).length).toBeGreaterThan(0)
        expect(screen.getAllByText(/Observed validation peak: 3.01 kW/i).length).toBeGreaterThan(0)
    })

    it('INTERACTION: clicking a validation table row selects that day and remounts graph', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('recent-validations-card')).toBeInTheDocument()
        })

        const user = userEvent.setup()
        // Click on the 03 Oct 2026 row
        const row = screen.getByText('03 Oct 2026').closest('tr')
        expect(row).toBeInTheDocument()
        await user.click(row)

        await waitFor(() => {
            expect(getForecastValidation).toHaveBeenCalledWith(expect.objectContaining({ date: '2026-10-03' }))
        })
    })

    it('ACCESSIBILITY: respects prefers-reduced-motion media query', async () => {
        const originalMatchMedia = window.matchMedia
        window.matchMedia = vi.fn().mockImplementation((query) => ({
            matches: query === '(prefers-reduced-motion: reduce)',
            media: query,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }))

        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-vs-actual-card')).toBeInTheDocument()
        })

        window.matchMedia = originalMatchMedia
    })

    it('PAGE HIERARCHY: renders Forecast vs Actual graph above Tomorrow forecast card', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('energy-page')).toBeInTheDocument()
        })

        const cards = screen.getAllByTestId(/-card$/)
        const cardIds = cards.map((c) => c.getAttribute('data-testid'))

        const graphIndex = cardIds.indexOf('forecast-vs-actual-card')
        const tomorrowIndex = cardIds.indexOf('solar-forecast-card')

        expect(graphIndex).toBeGreaterThan(-1)
        expect(tomorrowIndex).toBeGreaterThan(-1)
        expect(graphIndex).toBeLessThan(tomorrowIndex)
    })

    it('TODAY SEMANTICS: renders Today (Validated) when target period is completed and Today (Live) when in progress', async () => {
        // When today validation is completed
        const completedToday = {
            ...sampleValidationToday,
            validation_state: 'VALIDATED',
        }
        getForecastValidation.mockResolvedValueOnce({ data: completedToday })

        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('forecast-vs-actual-card')).toBeInTheDocument()
        })

        const valCard = screen.getByTestId('forecast-vs-actual-card')
        // When completed, button says "Today (Validated)", NEVER "Today (Live)"
        expect(within(valCard).getByRole('button', { name: /Today \(Validated\)/i })).toBeInTheDocument()
        expect(within(valCard).queryByRole('button', { name: /Today \(Live\)/i })).not.toBeInTheDocument()
        expect(within(valCard).getAllByText(/VALIDATED/i).length).toBeGreaterThan(0)
    })

    it('LIFECYCLE HONESTY: uses truthful recorded telemetry wording and avoids unsupported continuous ingestion claims', async () => {
        render(
            <MemoryRouter>
                <EnergyBalancePage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('energy-page')).toBeInTheDocument()
        })

        // Current solar card uses honest recorded wording
        const currentCard = screen.getByTestId('current-solar-card')
        expect(within(currentCard).getByText(/Latest recorded GoodWe solar generation/i)).toBeInTheDocument()
        expect(within(currentCard).queryByText(/Real-time and intraday/i)).not.toBeInTheDocument()
    })
})
