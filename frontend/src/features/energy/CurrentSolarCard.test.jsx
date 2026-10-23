import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CurrentSolarCard } from './CurrentSolarCard'

describe('CurrentSolarCard — Telemetry Honesty & Stale Handling', () => {
    it('renders last valid active power (2.20 kW) with STALE state, NOT 0 kW, when telemetry is stale', () => {
        const staleSolar = {
            power_w: '2200.00',
            daily_generation_kwh: '4.100',
            measurement_time: '2026-10-05 10:24:00',
            inverter_status: 'NORMAL',
            data_status: 'STALE',
            data_nature: 'ACTUAL',
        }

        render(<CurrentSolarCard solarLatest={staleSolar} />)

        // Must display 2.20 kW, NOT 0.00 kW
        expect(screen.getByText('2.20')).toBeInTheDocument()
        expect(screen.getByText('(2200 W)')).toBeInTheDocument()
        expect(screen.queryByText('(0 W)')).not.toBeInTheDocument()

        // Context-aware labeling: avoids "Live Power" when stale
        expect(screen.getByText('Last Observed Active Power')).toBeInTheDocument()
        expect(screen.queryByText('Active Power Output')).not.toBeInTheDocument()

        // Shows exact observation timestamp
        expect(screen.getAllByText(/10:24 IST/).length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText(/STALE · Last observed 10:24 IST/)).toBeInTheDocument()
    })

    it('renders fresh active power with Live Generating state when telemetry is fresh', () => {
        const todayStr = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Kolkata',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date())

        const freshSolar = {
            power_w: '1850.00',
            daily_generation_kwh: '6.200',
            measurement_time: `${todayStr} 12:15:00`,
            inverter_status: 'NORMAL',
            data_status: 'FRESH',
            data_nature: 'ACTUAL',
        }

        render(<CurrentSolarCard solarLatest={freshSolar} />)

        expect(screen.getByText('1.85')).toBeInTheDocument()
        expect(screen.getByText('(1850 W)')).toBeInTheDocument()
        expect(screen.getByText('Active Power Output')).toBeInTheDocument()
        expect(screen.getByText('NORMAL')).toBeInTheDocument()
    })

    it('renders real measured zero as 0.00 kW without hiding it', () => {
        const todayStr = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Kolkata',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date())

        const zeroSolar = {
            power_w: '0.00',
            daily_generation_kwh: '0.000',
            measurement_time: `${todayStr} 02:00:00`,
            inverter_status: 'WAITING',
            data_status: 'FRESH',
            data_nature: 'ACTUAL',
        }

        render(<CurrentSolarCard solarLatest={zeroSolar} />)

        expect(screen.getAllByText('0.00').length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('Night / standby (0 W)')).toBeInTheDocument()
    })

    it('renders Unavailable / Awaiting first valid telemetry when no power has ever existed', () => {
        const emptySolar = {
            power_w: null,
            daily_generation_kwh: null,
            measurement_time: null,
            inverter_status: 'UNKNOWN',
            data_status: 'UNAVAILABLE',
        }

        render(<CurrentSolarCard solarLatest={emptySolar} />)

        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('Awaiting first valid telemetry')).toBeInTheDocument()
        expect(screen.queryByText('0.00 kW')).not.toBeInTheDocument()
    })

    it('renders Disconnected state when solarLatest is null', () => {
        render(<CurrentSolarCard solarLatest={null} />)

        expect(screen.getAllByText(/Solar:\s*Disconnected/).length).toBeGreaterThanOrEqual(1)
    })

    it('renders SIMULATED (DEMO) badge and simulated description when data_nature is SIMULATED', () => {
        const simulatedSolar = {
            power_w: '2500.00',
            daily_generation_kwh: '14.200',
            measurement_time: '2026-10-10 12:00:00',
            inverter_status: 'NORMAL',
            data_status: 'FRESH',
            data_nature: 'SIMULATED',
        }

        render(<CurrentSolarCard solarLatest={simulatedSolar} />)

        expect(screen.getByText('SIMULATED (DEMO)')).toBeInTheDocument()
        expect(screen.getByText('Simulated solar generation telemetry for demonstration.')).toBeInTheDocument()
        expect(screen.queryByText('ACTUAL · GoodWe SEMS')).not.toBeInTheDocument()
    })

    it('renders ACTUAL · GoodWe SEMS badge and physical inverter description when data_nature is ACTUAL', () => {
        const actualSolar = {
            power_w: '2500.00',
            daily_generation_kwh: '14.200',
            measurement_time: '2026-10-10 12:00:00',
            inverter_status: 'NORMAL',
            data_status: 'FRESH',
            data_nature: 'ACTUAL',
        }

        render(<CurrentSolarCard solarLatest={actualSolar} />)

        expect(screen.getByText('ACTUAL · GoodWe SEMS')).toBeInTheDocument()
        expect(screen.getByText('Latest recorded GoodWe solar generation for your household.')).toBeInTheDocument()
        expect(screen.queryByText('SIMULATED (DEMO)')).not.toBeInTheDocument()
    })
})

