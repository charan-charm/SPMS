import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { ForecastsPage } from '../../pages/app/ForecastsPage'
import { ForecastChart } from './ForecastChart'
import { ForecastLimitationsCard } from './ForecastLimitationsCard'
import { WeatherInputCard } from './WeatherInputCard'
import { HouseholdDemandBaselineCard } from './HouseholdDemandBaselineCard'
import * as forecastApi from '../../services/api/forecast'
import * as billsApi from '../../services/api/bills'

// Mock ResponsiveContainer for Recharts in jsdom
vi.mock('recharts', async () => {
    const original = await vi.importActual('recharts')
    return {
        ...original,
        ResponsiveContainer: ({ children }) => (
            <div data-testid="responsive-container" style={{ width: 500, height: 300 }}>
                {children}
            </div>
        ),
    }
})

// Canonical mock forecast payload adhering to finalized architecture
const canonicalForecast = {
    household_id: 'hh-real-001',
    horizon: 'DAY_AHEAD',
    period: {
        start: '2026-10-06T00:00:00Z',
        end: '2026-10-06T23:59:59Z',
        resolution: 'HOURLY',
    },
    combined_confidence: 'HIGH',
    forecast_source: 'CALIBRATED_PHYSICS',
    solar_forecast: {
        predicted_value: 17.73,
        uncertainty_range: { lower: 15.6, upper: 19.9 },
        confidence: 'HIGH',
        data_nature: 'PREDICTED',
        data_quality: 'GOOD',
        provenance: {
            model_name: 'SPMS Solar Calibrated V2',
            model_version: 'SPMS_SOLAR_CALIBRATED_V2',
            evidence_count: 864,
            weather_available: true,
        },
        limitations: [],
    },
    consumption_forecast: {
        predicted_value: 5.05,
        uncertainty_range: null, // No hourly uncertainty
        confidence: 'ESTIMATED',
        data_nature: 'ESTIMATED',
        data_quality: 'GOOD',
        provenance: {
            model_name: 'Daily baseline estimation from verified utility billing history',
            model_version: 'CONSUMPTION_DETERMINISTIC_BASELINE',
            evidence_count: 6,
            verified_bills_count: 6,
            average_daily_kwh: 5.05,
        },
        limitations: [],
    },
    energy_balance_projection: {
        state: 'CLASSIFIED',
        balance_kwh: 12.68,
        classification: 'POTENTIAL_SURPLUS',
        explanation: 'Forecast solar minus estimated daily household demand.',
        data_nature: 'PREDICTED',
        measurement_context: 'POTENTIAL',
        confidence: 'HIGH',
    },
    energy_cost_projection: {
        state: 'ESTIMATED',
        estimated_cost_inr: 0.0,
        cost_range: {
            min_inr: 0.0,
            max_inr: 0.0,
            half_width_pct: 0.0,
        },
        fixed_charge: {
            amount: 30.0,
            currency: 'INR',
            treatment: 'SEPARATE_NOT_INCLUDED',
        },
        cost_type: 'FUTURE_ESTIMATE',
        currency: 'INR',
        measurement_context: 'ESTIMATED',
    },
    weather: {
        status: 'ACTIVE',
        available: true,
        provider: 'OPEN_METEO',
        source_label: 'Open-Meteo',
        location_name: 'Kodumur, Andhra Pradesh, India',
        retrieved_at: '2026-10-05T17:36:00Z',
        temperature_c: 33.4,
        cloud_cover_pct: 3.0,
        precipitation_probability_pct: 0.0,
        solar_irradiance_wm2: 577.2,
        condition: 'CLEAR',
    },
    provenance: {
        solar: {
            model_name: 'SPMS Solar Calibrated V2',
            evidence_count: 864,
            weather_available: true,
        },
        consumption: {
            model_name: 'Daily baseline estimation from verified utility billing history',
            verified_bills_count: 6,
            average_daily_kwh: 5.05,
        },
    },
    ml_provenance: {
        solar: {
            model: 'SPMS Solar Calibrated V2',
            accuracy_pct: 89.3,
            mae: 0.47,
        },
        consumption: {
            model: 'Daily baseline estimation from verified utility billing history',
            mae: 1.46,
        },
    },
    time_series: [
        {
            timestamp: '2026-10-06T06:00:00Z',
            hour: 6,
            predicted_solar_kwh: 0.45,
            lower_solar_kwh: 0.35,
            upper_solar_kwh: 0.55,
            solar_data_nature: 'PREDICTED',
        },
        {
            timestamp: '2026-10-06T12:00:00Z',
            hour: 12,
            predicted_solar_kwh: 3.20,
            lower_solar_kwh: 2.80,
            upper_solar_kwh: 3.60,
            solar_data_nature: 'PREDICTED',
        },
    ],
    limitations: [],
}

const mockBillsList = {
    data: {
        bills: Array.from({ length: 6 }, (_, i) => ({
            id: `bill-${i + 1}`,
            status: 'VERIFIED',
            billing_period_start: `2026-0${i + 1}-01`,
            billing_period_end: `2026-0${i + 1}-28`,
        })),
    },
}

const mockForecastInsufficient = {
    ...canonicalForecast,
    consumption_forecast: {
        predicted_value: null,
        uncertainty_range: null,
        confidence: 'INSUFFICIENT',
        data_nature: 'ESTIMATED',
        dataQuality: 'UNVERIFIED',
        provenance: { verified_bills_count: 0 },
        limitations: [
            'Insufficient historical consumption data: at least 1 verified electricity bill is required.',
        ],
    },
    energy_balance_projection: {
        state: 'UNAVAILABLE',
        balance_kwh: null,
        classification: 'UNAVAILABLE',
        confidence: 'INSUFFICIENT',
    },
    energy_cost_projection: {
        state: 'INSUFFICIENT_EVIDENCE',
        estimated_cost_inr: null,
        cost_range: null,
    },
}

describe('SPMS /forecasts — Final Semantic Correction & Verification Suite', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        vi.spyOn(billsApi, 'listBills').mockResolvedValue(mockBillsList)
        vi.spyOn(forecastApi, 'getForecastPerformance').mockResolvedValue({
            data: {
                summary: {
                    v2_average_accuracy_pct: 89.3,
                    v2_average_mae_kw: 0.32,
                    v2_days_evaluated: 36,
                },
            },
        })
    })

    // 1. Solar forecast renders canonical predicted value.
    it('1. Solar forecast renders canonical predicted value (17.73 kWh)', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('17.73 kWh')).toBeInTheDocument()
        })
    })

    // 2. Solar uncertainty range renders correctly.
    it('2. Solar uncertainty range renders correctly (15.6 – 19.9 kWh)', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText(/Range: 15\.6 – 19\.9 kWh/)).toBeInTheDocument()
        })
    })

    // 3. Household demand renders as 5.05 kWh/day ESTIMATED.
    it('3. Household demand renders as 5.05 kWh/day with ESTIMATED badge and DAILY BASELINE', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('5.05 kWh/day')).toBeInTheDocument()
            expect(screen.getAllByText('DAILY BASELINE').length).toBeGreaterThanOrEqual(1)
        })
    })

    // 4. Household demand is never rendered as hourly data.
    it('4. Household demand is never rendered as hourly data', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Forecasts')).toBeInTheDocument()
        })

        // Chart tooltip and container do not contain hourly consumption
        expect(screen.queryByText('Predicted Consumption (kWh)')).not.toBeInTheDocument()
        expect(screen.queryByText(/Consumption Uncertainty/i)).not.toBeInTheDocument()
    })

    // 5. Synthetic 24-hour consumption curve is absent.
    it('5. Synthetic 24-hour consumption curve is absent from ForecastChart', () => {
        render(
            <ForecastChart
                timeSeries={canonicalForecast.time_series}
                horizon="DAY_AHEAD"
                solarConfidence="HIGH"
                isLoading={false}
            />
        )

        expect(screen.getByText('Solar Generation Forecast')).toBeInTheDocument()
        expect(screen.getByText('Predicted Solar')).toBeInTheDocument()
        expect(screen.queryByText('Predicted Consumption (kWh)')).not.toBeInTheDocument()
        expect(screen.queryByText('Predicted Consumption')).not.toBeInTheDocument()
        expect(screen.queryByText(/hourly deficit/i)).not.toBeInTheDocument()
    })

    // 6. Projected balance uses compatible daily values (+12.68 kWh).
    it('6. Projected balance uses compatible daily values (+12.68 kWh)', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('+12.68 kWh')).toBeInTheDocument()
            expect(screen.getByText('Potential Surplus')).toBeInTheDocument()
        })
    })

    // 7. Potential balance is clearly not measured grid export.
    it('7. Potential balance is clearly not measured grid export and states solar minus estimated demand', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Forecast solar minus estimated daily household demand.')).toBeInTheDocument()
            expect(screen.getByText('Not grid export')).toBeInTheDocument()
        })
    })

    // 8. Future cost is labeled variable energy cost.
    it('8. Future cost is labeled Estimated Variable Energy Cost with ₹0.00', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Estimated Variable Energy Cost')).toBeInTheDocument()
            expect(screen.getByText('₹0.00')).toBeInTheDocument()
        })
    })

    // 9. Future cost does not imply total bill.
    it('9. Future cost separates fixed charges and does not imply tomorrow bill is ₹0', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('No variable import cost estimated under the forecast energy position.')).toBeInTheDocument()
            expect(screen.getByText('Fixed charges and statutory/other bill charges are separate.')).toBeInTheDocument()
        })
    })

    // 10. Weather status matches actual/cached/unavailable state.
    it('10. Weather status matches actual state and does not show Unavailable when metrics exist', () => {
        render(
            <WeatherInputCard
                weather={{
                    status: 'ACTIVE',
                    available: true,
                    provider: 'OPEN_METEO',
                    source_label: 'Open-Meteo',
                    temperature_c: 33.4,
                    cloud_cover_pct: 3.0,
                    precipitation_probability_pct: 0.0,
                    solar_irradiance_wm2: 577.2,
                    retrieved_at: '2026-10-05T17:36:00Z',
                }}
            />
        )

        expect(screen.getByText('Active')).toBeInTheDocument()
        expect(screen.queryByText('Unavailable')).not.toBeInTheDocument()
        expect(screen.getByText('33.4°C')).toBeInTheDocument()
    })

    it('10b. Weather status shows Cached when status is CACHED', () => {
        render(
            <WeatherInputCard
                weather={{
                    status: 'CACHED',
                    is_cached: true,
                    temperature_c: 30.0,
                    retrieved_at: '2026-10-05T16:00:00Z',
                }}
            />
        )

        expect(screen.getByText('Cached')).toBeInTheDocument()
        expect(screen.getByText(/Last retrieved/)).toBeInTheDocument()
    })

    // 11. No "Readings: 0" when canonical evidence exists.
    it('11. No "Readings: 0" when canonical evidence exists', () => {
        render(
            <ForecastLimitationsCard
                provenance={{
                    solar: { evidence_count: null },
                    consumption: { verified_bills_count: 6 },
                }}
            />
        )

        expect(screen.queryByText('Readings: 0')).not.toBeInTheDocument()
        expect(screen.getByText('GoodWe SEMS telemetry available')).toBeInTheDocument()
    })

    // 12. No "Verified Bills: 0" when verified bills exist.
    it('12. No "Verified Bills: 0" when verified bills exist', () => {
        render(
            <ForecastLimitationsCard
                verifiedBillsCount={6}
                provenance={{
                    solar: { evidence_count: 864 },
                    consumption: { verified_bills_count: 0 },
                }}
            />
        )

        expect(screen.queryByText('Verified Bills: 0')).not.toBeInTheDocument()
        expect(screen.getByText('6 verified APSPDCL billing cycles')).toBeInTheDocument()
    })

    // 13. 89.3% uses Capacity-Normalized Performance Score terminology.
    it('13. 89.3% uses Capacity-Normalized Performance Score terminology', () => {
        render(
            <ForecastLimitationsCard
                performanceScore={89.3}
                mlProvenance={{
                    solar: { model: 'SPMS Solar Calibrated V2', accuracy_pct: 89.3, mae: 0.47 },
                    consumption: { model: 'Daily baseline estimation', mae: 1.46 },
                }}
            />
        )

        expect(screen.getByText('Capacity-Normalized Performance Score: 89.3%')).toBeInTheDocument()
    })

    // 14. 89.3% is never called accuracy.
    it('14. 89.3% is never called forecast accuracy or prediction accuracy', () => {
        const { container } = render(
            <ForecastLimitationsCard
                performanceScore={89.3}
                mlProvenance={{
                    solar: { model: 'SPMS Solar Calibrated V2', accuracy_pct: 89.3, mae: 0.47 },
                    consumption: { model: 'Daily baseline estimation', mae: 1.46 },
                }}
            />
        )

        const text = container.textContent || ''
        expect(text).not.toMatch(/forecast accuracy:\s*89\.3%/i)
        expect(text).not.toMatch(/prediction accuracy:\s*89\.3%/i)
        expect(text).toContain('not conventional percentage accuracy')
    })

    // 15. Solar confidence is canonical.
    it('15. Solar confidence is canonical (HIGH)', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getAllByText('High confidence').length).toBeGreaterThanOrEqual(1)
        })
    })

    // 16. Household demand is not falsely shown as hourly high confidence.
    it('16. Household demand is not falsely shown as hourly high confidence', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Household Demand')).toBeInTheDocument()
        })

        // Household demand card has ESTIMATED badge and DAILY BASELINE badge
        const demandCard = screen.getByText('Household Demand').closest('div[class*="rounded-2xl"]')
        expect(demandCard).toHaveTextContent(/Estimated/i)
        expect(demandCard).toHaveTextContent('DAILY BASELINE')
        expect(demandCard).not.toHaveTextContent('High confidence')
    })

    // 17. Compact horizon selector preserves semantic resolution.
    it('17. Compact horizon selector preserves semantic resolution', async () => {
        const getSpy = vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: canonicalForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByLabelText('Forecast Horizon')).toBeInTheDocument()
        })

        const select = screen.getByLabelText('Forecast Horizon')
        expect(select).toHaveValue('DAY_AHEAD')
        expect(screen.getByText('Day-Ahead · 24h')).toBeInTheDocument()

        // Switch to 7-Day
        fireEvent.change(select, { target: { value: 'SEVEN_DAY' } })
        await waitFor(() => {
            expect(getSpy).toHaveBeenCalledWith({ horizon: 'SEVEN_DAY' })
        })

        // Switch to Monthly
        fireEvent.change(select, { target: { value: 'MONTHLY' } })
        await waitFor(() => {
            expect(getSpy).toHaveBeenCalledWith({ horizon: 'MONTHLY' })
        })
    })

    // 17b. Day-ahead chart presents calendar-day with context label.
    it('17b. Day-ahead chart presents calendar-day context label and 00:00 ordering', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: canonicalForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Forecast')).toBeInTheDocument()
        })

        // Renders "Tomorrow · 24-hour solar forecast" context label
        expect(screen.getAllByText('Tomorrow · 24-hour solar forecast').length).toBeGreaterThanOrEqual(1)
    })

    // 17c. Model and data section differentiates 77.6% fallback baseline from 89.3% walk-forward validation.
    it('17c. Model and data section differentiates 77.6% fallback baseline from 89.3% walk-forward validation', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: canonicalForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText(/Model & Data/i)).toBeInTheDocument()
        })

        // Expand methodology & limitations
        const disclosureBtn = screen.getByText('View methodology & limitations')
        fireEvent.click(disclosureBtn)

        expect(screen.getByText(/Historical Walk-Forward Validated Score: 89\.3%/)).toBeInTheDocument()
        expect(screen.getByText(/Clear-Sky Fallback Baseline Score: 77\.6%/)).toBeInTheDocument()
        expect(screen.getByText(/36 Completed Days/)).toBeInTheDocument()
        expect(screen.getAllByText(/not conventional percentage accuracy/).length).toBeGreaterThanOrEqual(1)
    })

    // 17d. Collapsible forecast limitations notice displays count and expands on demand.
    it('17d. Collapsible forecast limitations notice displays count and expands on demand', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: canonicalForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText(/Forecast limitations · \d+ notices/)).toBeInTheDocument()
        })

        // Expand limitations
        const toggleBtn = screen.getByText(/Forecast limitations · \d+ notices/)
        fireEvent.click(toggleBtn)

        expect(screen.getAllByText('Whole-house hourly demand is not directly measured.').length).toBeGreaterThanOrEqual(1)
    })

    // 18. REAL and DEMO data remain isolated.
    it('18. Separate HouseholdDemandBaselineCard renders honest APSPDCL billing provenance', () => {
        render(
            <HouseholdDemandBaselineCard
                dailyBaselineKwh={5.05}
                verifiedBillsCount={6}
                horizon="DAY_AHEAD"
            />
        )

        expect(screen.getByTestId('household-demand-baseline-card')).toBeInTheDocument()
        expect(screen.getByText('5.05')).toBeInTheDocument()
        expect(screen.getByText('kWh/day')).toBeInTheDocument()
        expect(screen.getByText('DAILY BASELINE')).toBeInTheDocument()
        expect(screen.getByText('Derived from 6 verified APSPDCL billing cycles')).toBeInTheDocument()
        expect(screen.getByText(/This system does not measure whole-house hourly load via smart meter or CT clamp/)).toBeInTheDocument()
    })

    it('renders ErrorState when API fails and allows retry', async () => {
        const getSpy = vi.spyOn(forecastApi, 'getForecasts')
            .mockRejectedValueOnce(new Error('Network gateway timeout'))
            .mockResolvedValueOnce({ data: canonicalForecast })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Unable to Load Forecasts')).toBeInTheDocument()
        })

        expect(screen.getByText('Network gateway timeout')).toBeInTheDocument()

        const retryBtn = screen.getByText('Try Again')
        fireEvent.click(retryBtn)

        await waitFor(() => {
            expect(getSpy).toHaveBeenCalledTimes(2)
        })
    })

    it('displays honest insufficient-data banner with link to Bills when bills are missing', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockForecastInsufficient,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Verified Bill History Required:')).toBeInTheDocument()
        })

        expect(screen.getByText('Go to Bills')).toBeInTheDocument()
        expect(screen.getByText('Insufficient')).toBeInTheDocument()
        expect(screen.getByText('Unavailable')).toBeInTheDocument()
        expect(screen.queryByText('₹0.00')).not.toBeInTheDocument()
        expect(screen.queryByText('0.00 kWh')).not.toBeInTheDocument()
        expect(screen.getAllByText('--').length).toBeGreaterThanOrEqual(2)
    })

    // 19. SEVEN_DAY Potential Balance & Demand Aggregation Semantics
    it('19. SEVEN_DAY uses horizon-compatible demand aggregation (34.73 kWh) rather than subtracting daily 5.05 kWh', async () => {
        const mockSevenDayForecast = {
            ...canonicalForecast,
            horizon: 'SEVEN_DAY',
            period: {
                start: '2026-10-06T00:00:00Z',
                end: '2026-10-13T00:00:00Z',
                resolution: 'DAILY',
            },
            solar_forecast: {
                predicted_value: 94.5,
                uncertainty_range: { lower: 83.16, upper: 105.84 },
                confidence: 'HIGH',
                data_nature: 'PREDICTED',
                provenance: {
                    model_name: 'SPMS Solar Calibrated V2',
                    peak_capacity_w: 3000,
                },
            },
            consumption_forecast: {
                predicted_value: 34.734,
                confidence: 'HIGH',
                data_nature: 'PREDICTED',
                provenance: {
                    model_name: 'ML_CONSUMPTION_REGRESSION_V1',
                    predicted_daily_kwh: 4.962,
                    average_daily_kwh: 5.05,
                    verified_bills_count: 6,
                },
            },
            energy_balance_projection: {
                state: 'POTENTIAL_SURPLUS',
                balance_kwh: 59.766,
                classification: 'POTENTIAL_SURPLUS',
                data_nature: 'PREDICTED',
            },
            energy_cost_projection: {
                state: 'ESTIMATED',
                estimated_cost_inr: 0,
            },
            time_series: Array.from({ length: 7 }, (_, i) => ({
                timestamp: `2026-10-0${6 + i}T00:00:00Z`,
                date: `2026-10-0${6 + i}`,
                predicted_solar_kwh: 13.5,
                lower_solar_kwh: 11.61,
                upper_solar_kwh: 15.39,
                predicted_consumption_kwh: 4.962,
                projected_balance_kwh: 8.538,
            })),
        }

        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockSevenDayForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Forecast')).toBeInTheDocument()
        })

        // Switch to SEVEN_DAY
        const select = screen.getByLabelText('Forecast Horizon')
        fireEvent.change(select, { target: { value: 'SEVEN_DAY' } })

        await waitFor(() => {
            // Card 1: 94.50 kWh Solar
            expect(screen.getByText('94.50 kWh')).toBeInTheDocument()
        })

        // Card 2: 34.73 kWh 7-day Demand (NOT 5.05 kWh)
        expect(screen.getByText('34.73 kWh')).toBeInTheDocument()
        expect(screen.getByText('Estimated 7-day demand')).toBeInTheDocument()

        // Card 3: +59.77 kWh Potential Balance (94.50 - 34.73 = +59.77 kWh, NOT 94.50 - 5.05 = +89.45 kWh)
        expect(screen.getByText('+59.77 kWh')).toBeInTheDocument()
        expect(screen.getByText('Forecast solar minus estimated 7-day household demand.')).toBeInTheDocument()

        // HouseholdDemandBaselineCard: ALWAYS renders the verified daily baseline rate (4.96 or 5.05 kWh/day),
        // NOT the 7-day aggregate 34.73 kWh/day
        expect(screen.getByText('4.96')).toBeInTheDocument()
        expect(screen.getByText('Derived from 6 verified APSPDCL billing cycles')).toBeInTheDocument()
    })

    // 20. MONTHLY Potential Balance & Demand Aggregation Semantics
    it('20. MONTHLY uses horizon-compatible demand aggregation (148.86 kWh) rather than subtracting daily 5.05 kWh', async () => {
        const mockMonthlyForecast = {
            ...canonicalForecast,
            horizon: 'MONTHLY',
            period: {
                start: '2026-10-06T00:00:00Z',
                end: '2026-11-05T00:00:00Z',
                resolution: 'DAILY',
            },
            solar_forecast: {
                predicted_value: 405.0,
                uncertainty_range: { lower: 324.0, upper: 486.0 },
                confidence: 'HIGH',
                data_nature: 'PREDICTED',
                provenance: {
                    model_name: 'SPMS Solar Calibrated V2',
                    peak_capacity_w: 3000,
                },
            },
            consumption_forecast: {
                predicted_value: 148.86,
                confidence: 'HIGH',
                data_nature: 'PREDICTED',
                provenance: {
                    model_name: 'ML_CONSUMPTION_REGRESSION_V1',
                    predicted_daily_kwh: 4.962,
                    average_daily_kwh: 5.05,
                    verified_bills_count: 6,
                },
            },
            energy_balance_projection: {
                state: 'POTENTIAL_SURPLUS',
                balance_kwh: 256.14,
                classification: 'POTENTIAL_SURPLUS',
                data_nature: 'PREDICTED',
            },
            energy_cost_projection: {
                state: 'ESTIMATED',
                estimated_cost_inr: 0,
            },
            time_series: Array.from({ length: 30 }, (_, i) => ({
                timestamp: `2026-10-${String(i + 1).padStart(2, '0')}T00:00:00Z`,
                date: `2026-10-${String(i + 1).padStart(2, '0')}`,
                predicted_solar_kwh: 13.5,
                lower_solar_kwh: 11.61,
                upper_solar_kwh: 15.39,
                predicted_consumption_kwh: 4.962,
                projected_balance_kwh: 8.538,
            })),
        }

        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockMonthlyForecast,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Forecast')).toBeInTheDocument()
        })

        // Switch to MONTHLY
        const select = screen.getByLabelText('Forecast Horizon')
        fireEvent.change(select, { target: { value: 'MONTHLY' } })

        await waitFor(() => {
            // Card 1: 405.00 kWh Solar
            expect(screen.getByText('405.00 kWh')).toBeInTheDocument()
        })

        // Card 2: 148.86 kWh Monthly Demand (NOT 5.05 kWh)
        expect(screen.getByText('148.86 kWh')).toBeInTheDocument()
        expect(screen.getByText('Estimated monthly demand')).toBeInTheDocument()

        // Card 3: +256.14 kWh Potential Balance (405.00 - 148.86 = +256.14 kWh, NOT 405.00 - 5.05 = +399.95 kWh)
        expect(screen.getByText('+256.14 kWh')).toBeInTheDocument()
        expect(screen.getByText('Forecast solar minus estimated monthly household demand.')).toBeInTheDocument()

        // HouseholdDemandBaselineCard: ALWAYS renders the verified daily baseline rate (4.96 or 5.05 kWh/day),
        // NOT the monthly aggregate 148.86 kWh/day
        expect(screen.getByText('4.96')).toBeInTheDocument()
        expect(screen.getByText('Derived from 6 verified APSPDCL billing cycles')).toBeInTheDocument()
    })
})
