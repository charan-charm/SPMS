import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { ForecastVsActualChart } from './ForecastVsActualChart'

describe('ForecastVsActualChart — Clear-Sky Baseline Enhancement', () => {
    const mockValidationData = {
        status: 'SUCCESS',
        household_id: '68a5374d-10f0-49a0-b845-03f4acbd5fa8',
        forecast_id: 'fc-test-01',
        validation_state: 'VALIDATED',
        target_period: {
            start: '2026-10-08 06:00:00',
            end: '2026-10-08 18:00:00',
            target_date: '2026-10-08',
            date_label: '08 Oct 2026',
            time_label: '06:00 – 18:00',
            timezone: 'Asia/Kolkata',
            is_completed: true,
        },
        summary: {
            target_date: '2026-10-08',
            date_label: '08 Oct 2026',
            validation_status: 'VALIDATED',
            predicted_energy_kwh: 16.5,
            actual_energy_kwh: 15.8,
            clear_sky_energy_kwh: 19.8,
            weather_reduction_kwh: 3.3,
            energy_difference_kwh: -0.7,
            accuracy_pct: 94.2,
            mae_kw: 0.18,
            rmse_kw: 0.22,
            observation_count: 156,
        },
        time_series: [
            { hour: 6, display_time: '06:00', predicted_kw: 0.2, actual_kw: 0.15, clear_sky_kw: 0.25, reading_count: 12, status: 'COMPLETED' },
            { hour: 7, display_time: '07:00', predicted_kw: 0.8, actual_kw: 0.75, clear_sky_kw: 1.05, reading_count: 12, status: 'COMPLETED' },
            { hour: 8, display_time: '08:00', predicted_kw: 1.6, actual_kw: 1.50, clear_sky_kw: 1.95, reading_count: 12, status: 'COMPLETED' },
            { hour: 9, display_time: '09:00', predicted_kw: 2.1, actual_kw: 2.05, clear_sky_kw: 2.45, reading_count: 12, status: 'COMPLETED' },
            { hour: 10, display_time: '10:00', predicted_kw: 2.4, actual_kw: 2.35, clear_sky_kw: 2.75, reading_count: 12, status: 'COMPLETED' },
            { hour: 11, display_time: '11:00', predicted_kw: 2.6, actual_kw: 2.50, clear_sky_kw: 2.85, reading_count: 12, status: 'COMPLETED' },
            { hour: 12, display_time: '12:00', predicted_kw: 2.5, actual_kw: 2.40, clear_sky_kw: 2.80, reading_count: 12, status: 'COMPLETED' },
            { hour: 13, display_time: '13:00', predicted_kw: 2.2, actual_kw: 2.10, clear_sky_kw: 2.55, reading_count: 12, status: 'COMPLETED' },
            { hour: 14, display_time: '14:00', predicted_kw: 1.7, actual_kw: 1.60, clear_sky_kw: 2.05, reading_count: 12, status: 'COMPLETED' },
            { hour: 15, display_time: '15:00', predicted_kw: 1.1, actual_kw: 1.05, clear_sky_kw: 1.35, reading_count: 12, status: 'COMPLETED' },
            { hour: 16, display_time: '16:00', predicted_kw: 0.5, actual_kw: 0.45, clear_sky_kw: 0.65, reading_count: 12, status: 'COMPLETED' },
            { hour: 17, display_time: '17:00', predicted_kw: 0.1, actual_kw: 0.08, clear_sky_kw: 0.15, reading_count: 12, status: 'COMPLETED' },
            { hour: 18, display_time: '18:00', predicted_kw: 0.0, actual_kw: 0.00, clear_sky_kw: 0.00, reading_count: 12, status: 'COMPLETED' },
        ],
    }

    it('defaults toggle to OFF and preserves existing chart legend & series without clear-sky baseline', () => {
        render(
            <ForecastVsActualChart
                validationData={mockValidationData}
                selectedDate="2026-10-08"
            />
        )

        // Existing chart elements are present
        expect(screen.getByTestId('forecast-vs-actual-card')).toBeInTheDocument()
        expect(screen.getByText('PREDICTED · V2')).toBeInTheDocument()
        expect(screen.getByText('ACTUAL · GoodWe SEMS')).toBeInTheDocument()

        // Toggle checkbox exists and is unchecked by default
        const toggle = screen.getByTestId('toggle-clear-sky-baseline')
        expect(toggle).toBeInTheDocument()
        expect(toggle).not.toBeChecked()

        // Clear-sky legend and callout are NOT rendered when toggle is OFF
        expect(screen.queryByTestId('clear-sky-legend')).not.toBeInTheDocument()
        expect(screen.queryByTestId('clear-sky-explanation')).not.toBeInTheDocument()
    })

    it('shows theoretical clear-sky baseline legend and concise explanation when toggle is enabled', () => {
        render(
            <ForecastVsActualChart
                validationData={mockValidationData}
                selectedDate="2026-10-08"
            />
        )

        const toggle = screen.getByTestId('toggle-clear-sky-baseline')
        fireEvent.click(toggle)

        expect(toggle).toBeChecked()

        // Clear-sky legend item is displayed
        expect(screen.getByTestId('clear-sky-legend')).toBeInTheDocument()
        expect(screen.getByText('THEORETICAL · Clear-Sky Baseline')).toBeInTheDocument()

        // Concise explanation callout is displayed with mandatory disclaimer
        const explanation = screen.getByTestId('clear-sky-explanation')
        expect(explanation).toBeInTheDocument()
        expect(explanation).toHaveTextContent(
            'The clear-sky baseline estimates generation without weather attenuation. The difference from the weather-adjusted forecast is model-estimated, not measured energy lost.'
        )

        // Summary pills show clear-sky reference energy and model reduction
        expect(explanation).toHaveTextContent('Clear-sky: 19.80 kWh')
        expect(explanation).toHaveTextContent('Model reduction: 3.30 kWh')
    })

    it('hides clear-sky baseline elements when toggle is checked and then unchecked', () => {
        render(
            <ForecastVsActualChart
                validationData={mockValidationData}
                selectedDate="2026-10-08"
            />
        )

        const toggle = screen.getByTestId('toggle-clear-sky-baseline')

        // Turn ON
        fireEvent.click(toggle)
        expect(toggle).toBeChecked()
        expect(screen.getByTestId('clear-sky-legend')).toBeInTheDocument()

        // Turn OFF
        fireEvent.click(toggle)
        expect(toggle).not.toBeChecked()
        expect(screen.queryByTestId('clear-sky-legend')).not.toBeInTheDocument()
        expect(screen.queryByTestId('clear-sky-explanation')).not.toBeInTheDocument()
    })

    it('preserves existing predictions, actual measurements, and units when toggle is enabled', () => {
        render(
            <ForecastVsActualChart
                validationData={mockValidationData}
                selectedDate="2026-10-08"
            />
        )

        // Validate units and daylight window are displayed
        expect(screen.getByText('Unit: kW')).toBeInTheDocument()
        expect(screen.getByText('Daylight: 06:00–18:00 IST')).toBeInTheDocument()

        // Toggle ON
        const toggle = screen.getByTestId('toggle-clear-sky-baseline')
        fireEvent.click(toggle)

        // Existing series badges remain untouched
        expect(screen.getByText('PREDICTED · V2')).toBeInTheDocument()
        expect(screen.getByText('ACTUAL · GoodWe SEMS')).toBeInTheDocument()
        expect(screen.getByText('THEORETICAL · Clear-Sky Baseline')).toBeInTheDocument()
    })
})
