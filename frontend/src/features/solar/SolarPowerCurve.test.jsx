import React from 'react'
import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { SolarPowerCurve } from './SolarPowerCurve'
import { buildSingleDayCurveData, buildSegmentedData } from './solarCurveData'
import { deriveTelemetryState, deriveTelemetryFreshness } from './solarStatus'

// Mock ResponsiveContainer for JSDOM with explicit dimensions
vi.mock('recharts', async () => {
    const original = await vi.importActual('recharts')
    return {
        ...original,
        ResponsiveContainer: ({ children }) => (
            <div data-testid="responsive-container" style={{ width: 500, height: 300 }}>
                {React.isValidElement(children)
                    ? React.cloneElement(children, { width: 500, height: 300 })
                    : children}
            </div>
        ),
    }
})

describe('SolarPowerCurve — Real Persisted Date-Based Generation Curve', () => {
    const now = new Date('2026-10-05T12:00:00Z')
    const isoToday1 = new Date(now.getTime() - 2 * 60 * 60 * 1000).toISOString()
    const isoToday2 = new Date(now.getTime() - 1 * 60 * 60 * 1000).toISOString()
    const isoToday3 = new Date(now.getTime() - 30 * 60 * 1000).toISOString()

    const realTodayHistory = [
        {
            id: 'r-1',
            measurement_time: isoToday1,
            power_w: '1200.50',
            daily_generation_kwh: '4.200',
            data_nature: 'ACTUAL',
            data_status: 'FRESH',
            inverter_status: 'NORMAL',
        },
        {
            id: 'r-2',
            measurement_time: isoToday2,
            power_w: '2800.00',
            daily_generation_kwh: '6.800',
            data_nature: 'ACTUAL',
            data_status: 'FRESH',
            inverter_status: 'NORMAL',
        },
        {
            id: 'r-3',
            measurement_time: isoToday3,
            power_w: '3150.00',
            daily_generation_kwh: '8.450',
            data_nature: 'ACTUAL',
            data_status: 'FRESH',
            inverter_status: 'NORMAL',
        },
    ]

    const mockAvailableDays = [
        {
            date: '2026-10-05',
            date_label: '05 Oct 2026',
            is_today: true,
            generation_kwh: 4.10,
            peak_power_kw: 2.20,
            observation_count: 53,
            sampling_interval_min: 5,
            source: 'GoodWe SEMS',
            status: 'LIVE',
            status_label: 'Today / Live',
        },
        {
            date: '2026-10-04',
            date_label: '04 Oct 2026',
            is_today: false,
            generation_kwh: 11.12,
            peak_power_kw: 3.01,
            observation_count: 147,
            sampling_interval_min: 5,
            source: 'GoodWe SEMS',
            status: 'COMPLETE',
            status_label: 'Complete',
        },
    ]

    it('renders the card header and mode toggles (Power kW and Energy kWh)', () => {
        render(<SolarPowerCurve history={realTodayHistory} availableDays={mockAvailableDays} />)

        expect(screen.getByText('Solar Generation Curve')).toBeInTheDocument()
        expect(screen.getByText(/Real instantaneous power \(kW\) and cumulative energy \(kWh\)/i)).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Power (kW)' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Energy (kWh)' })).toBeInTheDocument()
    })

    it('renders the real date selector dropdown with available dates', () => {
        render(
            <SolarPowerCurve
                history={realTodayHistory}
                availableDays={mockAvailableDays}
                selectedDate="2026-10-05"
            />
        )

        const btn = screen.getByRole('button', { name: /Select Date/i })
        expect(btn).toBeInTheDocument()
        expect(btn).toHaveTextContent(/05 Oct 2026 · Today \/ Live/i)

        // Open custom dropdown
        fireEvent.click(btn)

        expect(screen.getByRole('listbox', { name: /Available Recorded Dates/i })).toBeInTheDocument()
        expect(screen.getByText('04 Oct 2026')).toBeInTheDocument()
        expect(screen.getByText('11.12 kWh')).toBeInTheDocument()
    })

    it('renders compact selected-date summary attributes with real values', () => {
        render(
            <SolarPowerCurve
                history={realTodayHistory}
                availableDays={mockAvailableDays}
                selectedDate="2026-10-05"
            />
        )

        expect(screen.getByText('Selected date')).toBeInTheDocument()
        expect(screen.getByText('05 Oct 2026')).toBeInTheDocument()
        expect(screen.getByText('4.10 kWh')).toBeInTheDocument()
        expect(screen.getByText('2.20 kW')).toBeInTheDocument()
        expect(screen.getByText('53')).toBeInTheDocument()
        expect(screen.getByText('5 min')).toBeInTheDocument()
        expect(screen.getByText('Today / Live')).toBeInTheDocument()
    })

    it('invokes onSelectDate when changing date in the dropdown', () => {
        const onSelectDate = vi.fn()
        render(
            <SolarPowerCurve
                history={realTodayHistory}
                availableDays={mockAvailableDays}
                selectedDate="2026-10-05"
                onSelectDate={onSelectDate}
            />
        )

        const btn = screen.getByRole('button', { name: /Select Date/i })
        fireEvent.click(btn)

        const option = screen.getByRole('option', { name: /04 Oct 2026/i })
        fireEvent.click(option)
        expect(onSelectDate).toHaveBeenCalledWith('2026-10-04')
    })

    it('toggles mode between Power (kW) and Energy (kWh) without altering date', () => {
        render(
            <SolarPowerCurve
                history={realTodayHistory}
                availableDays={mockAvailableDays}
                selectedDate="2026-10-05"
            />
        )

        const energyBtn = screen.getByRole('button', { name: 'Energy (kWh)' })
        fireEvent.click(energyBtn)

        const btn = screen.getByRole('button', { name: /Select Date/i })
        expect(btn).toHaveTextContent(/05 Oct 2026/i)
    })

    it('renders real telemetry chart when sufficient persisted points exist', () => {
        render(<SolarPowerCurve history={realTodayHistory} availableDays={mockAvailableDays} />)

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument()
        expect(screen.queryByTestId('solar-curve-empty')).not.toBeInTheDocument()
    })

    it('displays honest empty state when insufficient historical data exists (< 2 points)', () => {
        const singlePointHistory = [
            {
                id: 'r-single',
                measurement_time: isoToday1,
                power_w: '1500.00',
                daily_generation_kwh: '3.100',
                data_nature: 'ACTUAL',
                data_status: 'FRESH',
                inverter_status: 'NORMAL',
            },
        ]

        render(<SolarPowerCurve history={singlePointHistory} availableDays={[]} />)

        expect(screen.getByTestId('solar-curve-empty')).toBeInTheDocument()
        expect(screen.getByText('Not enough historical solar telemetry for this date.')).toBeInTheDocument()
        expect(screen.getByText(/SPMS only renders genuine telemetry recorded from your GoodWe inverter/i)).toBeInTheDocument()
        expect(screen.getByText(/never fabricated/i)).toBeInTheDocument()
        expect(screen.queryByTestId('responsive-container')).not.toBeInTheDocument()
    })
})

describe('Solar Telemetry State Semantics (solarStatus.js)', () => {
    it('derives LIVE state when telemetry is fresh and power > 0', () => {
        const reading = {
            power_w: '2400.00',
            data_status: 'FRESH',
            inverter_status: 'NORMAL',
        }
        expect(deriveTelemetryState(reading)).toBe('LIVE')
        expect(deriveTelemetryFreshness(reading)).toBe('FRESH')
    })

    it('derives NO_GENERATION when telemetry is fresh and power is 0 (night standby)', () => {
        const reading = {
            power_w: '0.00',
            data_status: 'FRESH',
            inverter_status: 'WAITING',
        }
        expect(deriveTelemetryState(reading)).toBe('NO_GENERATION')
        expect(deriveTelemetryFreshness(reading)).toBe('FRESH')
    })

    it('derives STALE state when reading data_status is STALE', () => {
        const reading = {
            power_w: '0.00',
            data_status: 'STALE',
            inverter_status: 'NORMAL',
        }
        expect(deriveTelemetryState(reading)).toBe('STALE')
        expect(deriveTelemetryFreshness(reading)).toBe('STALE')
    })

    it('derives OFFLINE state when inverter_status is OFFLINE', () => {
        const reading = {
            power_w: '0.00',
            data_status: 'FRESH',
            inverter_status: 'OFFLINE',
        }
        expect(deriveTelemetryState(reading)).toBe('OFFLINE')
    })

    it('derives WAITING state only when waiting for initial telemetry from connected provider', () => {
        const connection = { connection_status: 'CONNECTED', configured: true }
        expect(deriveTelemetryState(null, connection)).toBe('WAITING')
        expect(deriveTelemetryFreshness(null)).toBe('UNKNOWN')
    })
})

describe('Single Day Curve Data (solarCurveData.js - buildSingleDayCurveData)', () => {
    const dayReadings = [
        {
            id: 'r-1',
            measurement_time: '2026-10-05T06:30:00.000Z',
            power_w: '0.00',
            daily_generation_kwh: '0.000',
        },
        {
            id: 'r-2',
            measurement_time: '2026-10-05T06:35:00.000Z',
            power_w: '1850.00',
            daily_generation_kwh: '2.500',
        },
        {
            id: 'r-3',
            measurement_time: '2026-10-05T06:40:00.000Z',
            power_w: '2980.00',
            daily_generation_kwh: '7.800',
        },
    ]

    it('computes peak kW and cumulative kWh accurately in power mode', () => {
        const result = buildSingleDayCurveData(dayReadings, 'power')
        expect(result.peakKw).toBe('2.98')
        expect(result.totalEnergyKwh).toBe('7.80')
        expect(result.observations).toBe(3)
        expect(result.flatData.length).toBeGreaterThanOrEqual(3)

        // Check power mode plots powerKw in segment
        const p2 = result.flatData.find((d) => d._id === 'r-2')
        expect(p2.seg_0).toBe(1.85)
    })

    it('plots running cumulative kWh in energy mode', () => {
        const result = buildSingleDayCurveData(dayReadings, 'energy')
        const p3 = result.flatData.find((d) => d._id === 'r-3')
        expect(p3.seg_0).toBe(7.8)
    })

    it('returns empty structure when readings array is empty', () => {
        const result = buildSingleDayCurveData([], 'power')
        expect(result.flatData).toEqual([])
        expect(result.peakKw).toBe('0.00')
        expect(result.totalEnergyKwh).toBe('0.00')
        expect(result.observations).toBe(0)
    })
})

describe('Solar Gap Handling & Segment Splitting (SPMS Data Honesty)', () => {
    it('splits contiguous telemetry blocks separated by a material gap into distinct segments', () => {
        const historyWithGap = [
            {
                id: 'sep-1',
                measurement_time: '2026-09-28T04:47:52.000Z',
                power_w: '0.00',
                daily_generation_kwh: '0.000',
            },
            {
                id: 'sep-2',
                measurement_time: '2026-09-28T04:47:58.000Z',
                power_w: '0.00',
                daily_generation_kwh: '0.000',
            },
            {
                id: 'oct-1',
                measurement_time: '2026-10-03T06:10:00.000Z',
                power_w: '0.00',
                daily_generation_kwh: '0.000',
            },
            {
                id: 'oct-2',
                measurement_time: '2026-10-03T10:00:00.000Z',
                power_w: '2819.00',
                daily_generation_kwh: '8.500',
            },
            {
                id: 'oct-3',
                measurement_time: '2026-10-03T14:00:00.000Z',
                power_w: '0.00',
                daily_generation_kwh: '13.048',
            },
        ]

        const { flatData, segmentCount, peakKw } = buildSegmentedData(historyWithGap, '7d')

        expect(segmentCount).toBe(2)
        expect(peakKw).toBe('2.82')

        const sepPoints = flatData.filter((r) => !r._isGap && r._measurementTime?.startsWith('2026-09-28'))
        expect(sepPoints.length).toBe(2)
        expect(sepPoints[0].seg_0).toBe(0)
        expect(sepPoints[0].seg_1).toBeNull()

        const gapPoints = flatData.filter((r) => r._isGap)
        expect(gapPoints.length).toBeGreaterThan(0)
        gapPoints.forEach((gap) => {
            expect(gap.seg_0).toBeNull()
            expect(gap.seg_1).toBeNull()
            expect(gap._powerW).toBeNull()
            expect(gap._powerKw).toBeNull()
        })
    })
})
