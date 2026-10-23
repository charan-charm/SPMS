import { render, screen, within } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { SolarForecastCard } from './SolarForecastCard'

describe('SolarForecastCard (SPMS Energy — Section 3 & 5)', () => {
    const mockForecastData = {
        household_id: '68a5374d-10f0-49a0-b845-03f4acbd5fa8',
        horizon: 'DAY_AHEAD',
        period: {
            start: '2026-10-04T17:00:00Z',
            end: '2026-10-05T17:00:00Z',
            resolution: 'HOURLY',
        },
        forecast_source: 'CALIBRATED_PHYSICS',
        combined_confidence: 'HIGH',
        generated_at: '2026-10-04T17:02:35Z',
        solar_forecast: {
            predicted_value: 18.15,
            uncertainty_range: {
                lower: 15.97,
                upper: 20.33,
            },
            confidence: 'HIGH',
            provenance: {
                model_name: 'SPMS Solar Calibrated V2',
                model_version: 'SPMS_SOLAR_CALIBRATED_V2',
                peak_capacity_w: 3010,
            },
            time_series: [
                // Night points
                { timestamp: '2026-10-04T17:00:00Z', local_time: '2026-10-04 22:30:00', local_hour: 22, predicted_kw: 0, lower_kw: 0, upper_kw: 0 },
                { timestamp: '2026-10-04T18:00:00Z', local_time: '2026-10-04 23:30:00', local_hour: 23, predicted_kw: 0, lower_kw: 0, upper_kw: 0 },
                { timestamp: '2026-10-05T00:00:00Z', local_time: '2026-10-05 05:30:00', local_hour: 5, predicted_kw: 0, lower_kw: 0, upper_kw: 0 },
                // Daylight points
                { timestamp: '2026-10-05T01:00:00Z', local_time: '2026-10-05 06:30:00', local_hour: 6, predicted_kw: 0.444, lower_kw: 0.391, upper_kw: 0.497, cloud_cover_pct: 12, temperature_c: 26 },
                { timestamp: '2026-10-05T02:00:00Z', local_time: '2026-10-05 07:30:00', local_hour: 7, predicted_kw: 1.100, lower_kw: 0.968, upper_kw: 1.232, cloud_cover_pct: 10, temperature_c: 28 },
                { timestamp: '2026-10-05T03:00:00Z', local_time: '2026-10-05 08:30:00', local_hour: 8, predicted_kw: 1.697, lower_kw: 1.493, upper_kw: 1.901, cloud_cover_pct: 8, temperature_c: 30 },
                { timestamp: '2026-10-05T04:00:00Z', local_time: '2026-10-05 09:30:00', local_hour: 9, predicted_kw: 2.103, lower_kw: 1.851, upper_kw: 2.356, cloud_cover_pct: 7, temperature_c: 31 },
                { timestamp: '2026-10-05T05:00:00Z', local_time: '2026-10-05 10:30:00', local_hour: 10, predicted_kw: 2.358, lower_kw: 2.075, upper_kw: 2.641, cloud_cover_pct: 7, temperature_c: 32 },
                { timestamp: '2026-10-05T06:00:00Z', local_time: '2026-10-05 11:30:00', local_hour: 11, predicted_kw: 2.541, lower_kw: 2.236, upper_kw: 2.846, cloud_cover_pct: 5, temperature_c: 33 },
                { timestamp: '2026-10-05T07:00:00Z', local_time: '2026-10-05 12:30:00', local_hour: 12, predicted_kw: 2.427, lower_kw: 2.135, upper_kw: 2.718, cloud_cover_pct: 7, temperature_c: 33 },
                { timestamp: '2026-10-05T08:00:00Z', local_time: '2026-10-05 13:30:00', local_hour: 13, predicted_kw: 2.196, lower_kw: 1.933, upper_kw: 2.460, cloud_cover_pct: 9, temperature_c: 33 },
                { timestamp: '2026-10-05T09:00:00Z', local_time: '2026-10-05 14:30:00', local_hour: 14, predicted_kw: 1.734, lower_kw: 1.526, upper_kw: 1.942, cloud_cover_pct: 10, temperature_c: 32 },
                { timestamp: '2026-10-05T10:00:00Z', local_time: '2026-10-05 15:30:00', local_hour: 15, predicted_kw: 1.019, lower_kw: 0.897, upper_kw: 1.141, cloud_cover_pct: 12, temperature_c: 31 },
                { timestamp: '2026-10-05T11:00:00Z', local_time: '2026-10-05 16:30:00', local_hour: 16, predicted_kw: 0.513, lower_kw: 0.451, upper_kw: 0.575, cloud_cover_pct: 14, temperature_c: 29 },
                { timestamp: '2026-10-05T12:00:00Z', local_time: '2026-10-05 17:30:00', local_hour: 17, predicted_kw: 0.018, lower_kw: 0.016, upper_kw: 0.020, cloud_cover_pct: 15, temperature_c: 28 },
                { timestamp: '2026-10-05T13:00:00Z', local_time: '2026-10-05 18:30:00', local_hour: 18, predicted_kw: 0, lower_kw: 0, upper_kw: 0 },
            ],
        },
    }

    it('renders tomorrow day-ahead summary metrics with PREDICTED badge and provenance', () => {
        render(<SolarForecastCard forecastData={mockForecastData} />)

        expect(screen.getByTestId('solar-forecast-card')).toBeInTheDocument()
        expect(screen.getByText(/Day-Ahead Solar Forecast/i)).toBeInTheDocument()
        expect(screen.getByText('PREDICTED')).toBeInTheDocument()
        expect(screen.getByText('18.15')).toBeInTheDocument()
        expect(screen.getByText(/16.0 – 20.3/i)).toBeInTheDocument()
        expect(screen.getByText('HIGH CONFIDENCE')).toBeInTheDocument()
        expect(screen.getByText('SPMS Solar Calibrated V2')).toBeInTheDocument()
    })

    it('renders Tomorrow Solar Forecast graph with authoritative title, subtitle, and legend', () => {
        render(<SolarForecastCard forecastData={mockForecastData} />)

        const graphContainer = screen.getByTestId('tomorrow-forecast-graph-container')
        expect(graphContainer).toBeInTheDocument()
        expect(within(graphContainer).getByText(/Solar Forecast/i)).toBeInTheDocument()
        expect(within(graphContainer).getByText('Day-ahead predicted solar generation')).toBeInTheDocument()
        expect(within(graphContainer).getByText('PREDICTED · V2')).toBeInTheDocument()
        expect(within(graphContainer).getAllByText('Forecast Range').length).toBeGreaterThan(0)
        expect(within(graphContainer).getByText('kW · 06:00–18:00 IST')).toBeInTheDocument()
    })

    it('strictly separates future Confidence from historical Accuracy (no tomorrow accuracy shown)', () => {
        render(<SolarForecastCard forecastData={mockForecastData} />)

        // Shows confidence
        expect(screen.getByText('HIGH CONFIDENCE')).toBeInTheDocument()
        expect(screen.getAllByText(/Confidence reflects/i).length).toBeGreaterThan(0)

        // Does NOT display accuracy on tomorrow's forecast (actual data does not exist yet)
        const graphContainer = screen.getByTestId('tomorrow-forecast-graph-container')
        expect(graphContainer).not.toHaveTextContent(/Accuracy:/i)
        expect(graphContainer).not.toHaveTextContent(/Actual GoodWe/i)
        expect(graphContainer).not.toHaveTextContent(/MEASURED/i)
    })

    it('renders gracefully when forecastData is null or empty', () => {
        render(<SolarForecastCard forecastData={null} />)

        expect(screen.getByTestId('solar-forecast-card')).toBeInTheDocument()
        expect(screen.getByText('—')).toBeInTheDocument()
        expect(screen.getByText('Baseline interval')).toBeInTheDocument()
        expect(screen.queryByTestId('tomorrow-forecast-graph-container')).not.toBeInTheDocument()
    })
})
