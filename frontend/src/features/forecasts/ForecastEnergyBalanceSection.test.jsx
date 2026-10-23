import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router'
import { ForecastEnergyBalanceSection } from './ForecastEnergyBalanceSection'

describe('ForecastEnergyBalanceSection', () => {
    const mockDayAheadForecast = {
        horizon: 'DAY_AHEAD',
        period: {
            start: '2026-10-22 00:00:00',
            end: '2026-10-22 23:59:59',
            label: 'Tomorrow · Thu, Oct 22',
        },
        solar_forecast: {
            expected_energy_kwh: 17.73,
            confidence: 'HIGH',
            uncertainty_range: { lower: 14.18, upper: 21.28 },
            status: 'OPERATIONAL',
        },
        consumption_forecast: {
            expected_energy_kwh: 5.05,
            confidence: 'MODERATE',
            status: 'DERIVED_FROM_BILLS',
            bills_count: 3,
            model_used: 'CONSUMPTION_WEIGHTED_AVERAGE',
        },
        energy_balance: {
            net_energy_kwh: 12.68,
            confidence: 'MODERATE',
            status: 'SURPLUS',
        },
        weather_context: {
            provider: 'Open-Meteo',
            source_label: 'Open-Meteo',
            status: 'LIVE',
            temperature_max_c: 32.5,
            cloud_cover_pct: 18,
            condition: 'Sunny',
        },
    }

    const renderSection = (props = {}) => {
        return render(
            <MemoryRouter>
                <ForecastEnergyBalanceSection
                    forecast={mockDayAheadForecast}
                    horizon="DAY_AHEAD"
                    isStaleTelemetry={false}
                    staleMinutes={null}
                    {...props}
                />
            </MemoryRouter>
        )
    }

    it('renders the dedicated Solar vs Household Energy Balance section with truthful title and badges', () => {
        renderSection()

        expect(screen.getByRole('heading', { name: /Solar vs Household Energy Balance/i })).toBeInTheDocument()
        expect(screen.getByText('POTENTIAL BALANCE')).toBeInTheDocument()
        expect(screen.getByText(/Day-Ahead/i)).toBeInTheDocument()
    })

    it('displays comparative cards with solar, demand, and potential balance', () => {
        renderSection()

        // Card 1: Solar
        expect(screen.getByText('Forecast Solar Generation')).toBeInTheDocument()
        expect(screen.getByText('17.73')).toBeInTheDocument()
        expect(screen.getByText('kWh solar')).toBeInTheDocument()
        expect(screen.getByText('Interval: 14.2 – 21.3 kWh')).toBeInTheDocument()

        // Card 2: Household Demand
        expect(screen.getByText('Baseline Household Demand')).toBeInTheDocument()
        expect(screen.getByText('5.05')).toBeInTheDocument()
        expect(screen.getByText('kWh/day demand')).toBeInTheDocument()
        expect(screen.getByText('Derived from 3 verified APSPDCL bills')).toBeInTheDocument()

        // Card 3: Potential Balance
        expect(screen.getByText('Net Energy Balance Position')).toBeInTheDocument()
        expect(screen.getByText('+12.68')).toBeInTheDocument()
        expect(screen.getByText('kWh net')).toBeInTheDocument()
    })

    it('renders net surplus status banner and emphasizes that surplus is not physical grid export', () => {
        renderSection()

        expect(screen.getByTestId('energy-balance-status-banner')).toBeInTheDocument()
        expect(screen.getByText('Projected Daytime Surplus: +12.68 kWh')).toBeInTheDocument()
        expect(screen.getByText('NET SURPLUS')).toBeInTheDocument()
        expect(screen.getByText(/Not physical grid export or guaranteed self-consumption/i)).toBeInTheDocument()
        expect(screen.getByText(/Surplus cannot be exported or measured without whole-house CT metering/i)).toBeInTheDocument()
    })

    it('renders proportional balance gauge with solar and demand share', () => {
        renderSection()

        expect(screen.getByText('Balance Gauge (Forecast Proportion)')).toBeInTheDocument()
        expect(screen.getByText(/78% Solar/i)).toBeInTheDocument()
        expect(screen.getByText(/22% Demand/i)).toBeInTheDocument()
    })

    it('renders 5-part data classification strip with explicit provenance types', () => {
        renderSection()

        const strip = screen.getByTestId('provenance-classification-strip')
        expect(strip).toBeInTheDocument()

        // 1. GoodWe Telemetry (MEASURED)
        expect(screen.getByText('GoodWe Inverter Telemetry')).toBeInTheDocument()
        expect(screen.getByText('MEASURED')).toBeInTheDocument()
        expect(screen.getByText('Live 5-min inverter readings')).toBeInTheDocument()

        // 2. Solar Generation (PREDICTED)
        expect(screen.getByText('Solar Generation Model')).toBeInTheDocument()
        expect(screen.getAllByText('Predicted').length).toBeGreaterThan(0)
        expect(screen.getByText('Calibrated clear-sky + weather')).toBeInTheDocument()

        // 3. Household Demand (ESTIMATED)
        expect(screen.getByText('Household Baseline Demand')).toBeInTheDocument()
        expect(screen.getAllByText('Estimated').length).toBeGreaterThan(0)
        expect(screen.getByText('APSPDCL monthly bills average')).toBeInTheDocument()

        // 4. Energy Balance (POTENTIAL)
        expect(screen.getByText('Energy Balance Position')).toBeInTheDocument()
        expect(screen.getAllByText('POTENTIAL').length).toBeGreaterThan(0)
        expect(screen.getByText('Forecast solar − bill baseline')).toBeInTheDocument()

        // 5. Weather Provider (LIVE)
        expect(screen.getByText('Weather Evidence')).toBeInTheDocument()
        expect(screen.getByText('LIVE')).toBeInTheDocument()
        expect(screen.getByText('Open-Meteo')).toBeInTheDocument()
    })

    it('truthfully reports SIMULATED weather when provider is fake/simulated', () => {
        const simForecast = {
            ...mockDayAheadForecast,
            weather_context: {
                provider: 'FAKE_WEATHER_PROVIDER',
                source_label: 'Simulated Open-Meteo',
                status: 'SIMULATED',
                temperature_max_c: 31.0,
                cloud_cover_pct: 25,
            },
        }

        renderSection({ forecast: simForecast })

        expect(screen.getByText('SIMULATED')).toBeInTheDocument()
        expect(screen.getByText('Simulated Open-Meteo')).toBeInTheDocument()
    })

    it('reports STALE telemetry provenance when inverter readings are stale', () => {
        renderSection({ isStaleTelemetry: true, staleMinutes: 45 })

        expect(screen.getByText('STALE (45m)')).toBeInTheDocument()
    })

    it('handles deficit balance truthfully without misrepresenting as measured grid import', () => {
        const deficitForecast = {
            ...mockDayAheadForecast,
            solar_forecast: {
                expected_energy_kwh: 3.20,
                confidence: 'MODERATE',
                uncertainty_range: { lower: 2.10, upper: 4.30 },
            },
            consumption_forecast: {
                expected_energy_kwh: 5.05,
                confidence: 'MODERATE',
                status: 'DERIVED_FROM_BILLS',
                bills_count: 3,
            },
            energy_balance: {
                net_energy_kwh: -1.85,
                confidence: 'MODERATE',
                status: 'DEFICIT',
            },
        }

        renderSection({ forecast: deficitForecast })

        expect(screen.getByText('Projected Deficit Position: -1.85 kWh')).toBeInTheDocument()
        expect(screen.getByText('NET DEFICIT')).toBeInTheDocument()
        expect(screen.getByText('-1.85')).toBeInTheDocument()
        expect(screen.getByText(/Not measured grid import/i)).toBeInTheDocument()
    })

    it('handles missing verified bills gracefully without zeroing values', () => {
        const missingBillsForecast = {
            ...mockDayAheadForecast,
            consumption_forecast: {
                expected_energy_kwh: null,
                confidence: null,
                status: 'INSUFFICIENT_DATA',
                bills_count: 0,
            },
            energy_balance: {
                net_energy_kwh: null,
                confidence: null,
                status: 'UNAVAILABLE',
            },
        }

        renderSection({ forecast: missingBillsForecast })

        // Demand baseline shows Baseline Pending, not 0.00
        expect(screen.getByText('Baseline Pending')).toBeInTheDocument()
        expect(screen.queryByText('0.00 kWh/day')).not.toBeInTheDocument()
        expect(screen.getByText('Requires verified bill history')).toBeInTheDocument()

        // Banner indicates insufficient bills with link to /bills
        expect(screen.getByText('Demand Baseline Unavailable')).toBeInTheDocument()
        expect(screen.getByText('Review Verified Bills')).toBeInTheDocument()
        expect(screen.getByRole('link', { name: /Review Verified Bills/i })).toHaveAttribute('href', '/bills')
    })
})
