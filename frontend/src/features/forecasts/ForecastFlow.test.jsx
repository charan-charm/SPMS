import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { ForecastsPage } from '../../pages/app/ForecastsPage'
import { ForecastChart } from './ForecastChart'
import { ForecastLimitationsCard } from './ForecastLimitationsCard'
import * as forecastApi from '../../services/api/forecast'

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

const mockForecastSuccess = {
    household_id: 'hh-test-123',
    horizon: 'DAY_AHEAD',
    period: {
        start: '2026-09-22T00:00:00Z',
        end: '2026-09-22T23:59:59Z',
        resolution: 'HOURLY',
    },
    combined_confidence: 'MODERATE',
    solar_forecast: {
        predicted_value: 22.5,
        uncertainty_range: { lower: 18.0, upper: 27.0 },
        confidence: 'HIGH',
        data_nature: 'PREDICTED',
        data_quality: 'GOOD',
        provenance: {
            model_version: 'SPMS_SOLAR_V1_DETERMINISTIC',
            evidence_count: 500,
            weather_available: true,
        },
        limitations: [],
    },
    consumption_forecast: {
        predicted_value: 16.2,
        uncertainty_range: { lower: 12.5, upper: 20.0 },
        confidence: 'MODERATE',
        data_nature: 'PREDICTED',
        data_quality: 'GOOD',
        provenance: {
            model_version: 'SPMS_CONSUMPTION_V1_DETERMINISTIC',
            evidence_count: 3,
            verified_bills_count: 3,
            average_daily_kwh: 16.2,
        },
        limitations: ['Limited bill history (3 verified bills)'],
    },
    energy_balance_projection: {
        state: 'CLASSIFIED',
        balance_kwh: 6.3,
        classification: 'POTENTIAL_SURPLUS',
        explanation: 'Projected daytime solar surplus exceeds household demand.',
        data_nature: 'PREDICTED',
        measurement_context: 'POTENTIAL',
        confidence: 'MODERATE',
    },
    energy_cost_projection: {
        state: 'ESTIMATED',
        estimated_cost_inr: 45.0,
        cost_range: {
            min_inr: 38.25,
            max_inr: 51.75,
            half_width_pct: 15.0,
        },
        cost_type: 'FUTURE_ESTIMATE',
        currency: 'INR',
        measurement_context: 'ESTIMATED',
    },
    weather: {
        status: 'WEATHER_ENHANCED',
        available: true,
        provider: 'OPEN_METEO',
        source_label: 'Open-Meteo',
        location_name: 'Tirupati, Andhra Pradesh, India',
        retrieved_at: '2026-09-22T06:00:00Z',
        temperature_c: 32.0,
        cloud_cover_pct: 18.0,
        precipitation_probability_pct: 5.0,
        solar_irradiance_wm2: 680.0,
        condition: 'CLEAR',
    },
    time_series: [
        {
            timestamp: '2026-09-22T06:00:00Z',
            hour: 6,
            predicted_solar_kwh: 0.5,
            lower_solar_kwh: 0.4,
            upper_solar_kwh: 0.6,
            predicted_consumption_kwh: 0.6,
            lower_consumption_kwh: 0.5,
            upper_consumption_kwh: 0.7,
            projected_balance_kwh: -0.1,
            solar_data_nature: 'PREDICTED',
            consumption_data_nature: 'PREDICTED',
        },
        {
            timestamp: '2026-09-22T12:00:00Z',
            hour: 12,
            predicted_solar_kwh: 3.8,
            lower_solar_kwh: 3.2,
            upper_solar_kwh: 4.4,
            predicted_consumption_kwh: 0.8,
            lower_consumption_kwh: 0.6,
            upper_consumption_kwh: 1.0,
            projected_balance_kwh: 3.0,
            solar_data_nature: 'PREDICTED',
            consumption_data_nature: 'PREDICTED',
        },
    ],
    limitations: [
        'Limited bill history (3 verified bills): forecast confidence is moderate.',
    ],
}

const mockForecastInsufficientConsumption = {
    ...mockForecastSuccess,
    consumption_forecast: {
        predicted_value: null,
        uncertainty_range: { lower: null, upper: null },
        confidence: 'INSUFFICIENT',
        data_nature: 'ESTIMATED',
        dataQuality: 'UNVERIFIED',
        provenance: { verified_bills_count: 0 },
        limitations: [
            'Insufficient historical consumption data: at least 1 verified electricity bill or smart meter reading is required.',
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

describe('SPMS F3 — Forecast Experience & Verification Suite', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders loading state initially while fetching forecast', () => {
        vi.spyOn(forecastApi, 'getForecasts').mockImplementation(
            () => new Promise(() => {}) // never resolves
        )

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        expect(screen.getByText('Generating Forecast Projections')).toBeInTheDocument()
    })

    it('renders loaded forecast metrics and projections accurately', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockForecastSuccess,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Forecasts')).toBeInTheDocument()
        })

        // Check KPI metrics
        expect(screen.getByText('22.50 kWh')).toBeInTheDocument() // Solar
        expect(screen.getByText('16.20 kWh')).toBeInTheDocument() // Consumption
        expect(screen.getByText('+6.30 kWh')).toBeInTheDocument() // Projected balance
        expect(screen.getByText('Potential Surplus')).toBeInTheDocument()
        expect(screen.getByText('₹45.00')).toBeInTheDocument() // Estimated cost
    })

    it('handles horizon switching from Day-Ahead to 7-Day Trend', async () => {
        const getSpy = vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockForecastSuccess,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Day-Ahead (24h)')).toBeInTheDocument()
        })

        // Click "7-Day Trend"
        const sevenDayTab = screen.getByText('7-Day Trend')
        fireEvent.click(sevenDayTab)

        await waitFor(() => {
            expect(getSpy).toHaveBeenCalledWith({ horizon: 'SEVEN_DAY' })
        })
    })

    it('displays honest insufficient-data banner with link to Bills when bills are missing', async () => {
        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockForecastInsufficientConsumption,
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
        // Must never show fabricated zero cost or zero consumption
        expect(screen.queryByText('₹0.00')).not.toBeInTheDocument()
        expect(screen.queryByText('0.00 kWh')).not.toBeInTheDocument()
        // Shows -- for unavailable balance and cost
        expect(screen.getAllByText('--').length).toBeGreaterThanOrEqual(2)
    })

    it('ForecastLimitationsCard renders active limitations and provenance details', () => {
        render(
            <ForecastLimitationsCard
                limitations={['Weather provider unavailable: using physical solar diurnal model.']}
                provenance={{
                    solar: { evidence_count: 120, weather_available: false },
                    consumption: { verified_bills_count: 2, average_daily_kwh: 14.5 },
                }}
                combinedConfidence="LOW"
            />
        )

        expect(screen.getByText('Forecast Provenance & Limitations')).toBeInTheDocument()
        expect(screen.getByText(/Weather provider unavailable/)).toBeInTheDocument()
        expect(screen.getByText('Readings: 120')).toBeInTheDocument()
        expect(screen.getByText('Verified Bills: 2')).toBeInTheDocument()
        expect(screen.getByText('Overall: LOW')).toBeInTheDocument()
    })

    it('ForecastChart renders chart container with confidence badges and legend', () => {
        render(
            <ForecastChart
                timeSeries={mockForecastSuccess.time_series}
                horizon="DAY_AHEAD"
                solarConfidence="HIGH"
                consumptionConfidence="MODERATE"
                isLoading={false}
            />
        )

        expect(screen.getByText('Solar Generation & Consumption Outlook')).toBeInTheDocument()
        expect(screen.getByTestId('responsive-container')).toBeInTheDocument()
    })

    it('renders ErrorState when API fails and allows retry', async () => {
        const getSpy = vi.spyOn(forecastApi, 'getForecasts')
            .mockRejectedValueOnce(new Error('Network gateway timeout'))
            .mockResolvedValueOnce({ data: mockForecastSuccess })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Unable to Load Forecasts')).toBeInTheDocument()
        })

        expect(screen.getByText('Network gateway timeout')).toBeInTheDocument()

        // Click Try Again
        const retryBtn = screen.getByText('Try Again')
        fireEvent.click(retryBtn)

        await waitFor(() => {
            expect(getSpy).toHaveBeenCalledTimes(2)
        })
    })

    it('renders zero-cost surplus case with zero-width range suppressed and fixed charges separated', async () => {
        const mockZeroCost = {
            ...mockForecastSuccess,
            energy_balance_projection: {
                ...mockForecastSuccess.energy_balance_projection,
                classification: 'POTENTIAL_SURPLUS',
                balance_kwh: 22.54,
            },
            energy_cost_projection: {
                state: 'ESTIMATED',
                estimated_cost_inr: 0.0,
                cost_range: {
                    min_inr: 0.0,
                    max_inr: 0.0,
                    half_width_pct: 25.0,
                    triggers: ['Fallback pricing evidence (USER_PROVIDED_RATE)'],
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
        }

        vi.spyOn(forecastApi, 'getForecasts').mockResolvedValue({
            data: mockZeroCost,
        })

        render(
            <MemoryRouter>
                <ForecastsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Forecasts')).toBeInTheDocument()
        })

        expect(screen.getByText('₹0.00')).toBeInTheDocument()
        expect(screen.getByText('Zero variable grid cost (solar surplus)')).toBeInTheDocument()
        expect(screen.getByText(/Fixed charges \(₹30\.00\/mo\) & taxes apply separately/)).toBeInTheDocument()
        expect(screen.queryByText(/₹0\.00 – ₹0\.00/)).not.toBeInTheDocument()
        expect(screen.queryByText('No variable energy cost identified')).not.toBeInTheDocument()
    })
})
