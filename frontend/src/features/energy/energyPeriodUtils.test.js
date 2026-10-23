import { describe, expect, it } from 'vitest'
import {
    formatBillingMonth,
    formatBillingPeriod,
    formatEnergyUnits,
    formatHumanForecastPeriod,
    formatKwh,
    formatKwhSigned,
    formatNetEnergy,
} from './energyPeriodUtils'

describe('energyPeriodUtils', () => {
    describe('formatKwh', () => {
        it('formats numbers to 2 decimal places with sensible defaults', () => {
            expect(formatKwh(12.278)).toBe('12.28')
            expect(formatKwh(2.732)).toBe('2.73')
            expect(formatKwh(0)).toBe('0.00')
            expect(formatKwh('9.546')).toBe('9.55')
        })

        it('handles null and undefined gracefully', () => {
            expect(formatKwh(null)).toBe('—')
            expect(formatKwh(undefined)).toBe('—')
            expect(formatKwh('invalid')).toBe('—')
        })
    })

    describe('formatEnergyUnits', () => {
        it('formats integer values without decimal places', () => {
            expect(formatEnergyUnits(203)).toBe('203 kWh')
            expect(formatEnergyUnits(303)).toBe('303 kWh')
            expect(formatEnergyUnits('145')).toBe('145 kWh')
        })

        it('formats fractional values with 1 decimal place', () => {
            expect(formatEnergyUnits(145.5)).toBe('145.5 kWh')
            expect(formatEnergyUnits('203.2')).toBe('203.2 kWh')
        })

        it('handles empty/null gracefully', () => {
            expect(formatEnergyUnits(null)).toBe('—')
            expect(formatEnergyUnits(undefined)).toBe('—')
            expect(formatEnergyUnits('')).toBe('—')
        })
    })

    describe('formatNetEnergy', () => {
        it('calculates surplus when solar exceeds consumption', () => {
            const res1 = formatNetEnergy(203, 303)
            expect(res1.text).toBe('100 kWh surplus')
            expect(res1.isSurplus).toBe(true)

            const res2 = formatNetEnergy(145, 322)
            expect(res2.text).toBe('177 kWh surplus')
            expect(res2.isSurplus).toBe(true)
        })

        it('calculates deficit when consumption exceeds solar', () => {
            const res = formatNetEnergy(271, 177)
            expect(res.text).toBe('94 kWh deficit')
            expect(res.isSurplus).toBe(false)
        })

        it('falls back to net_units_kwh when consumption or solar is missing', () => {
            const resSurplus = formatNetEnergy(null, null, -177)
            expect(resSurplus.text).toBe('177 kWh surplus')
            expect(resSurplus.isSurplus).toBe(true)

            const resDeficit = formatNetEnergy(null, null, 94)
            expect(resDeficit.text).toBe('94 kWh deficit')
            expect(resDeficit.isSurplus).toBe(false)
        })

        it('handles zero or balanced energy', () => {
            const res = formatNetEnergy(200, 200)
            expect(res.text).toBe('0 kWh')
            expect(res.isSurplus).toBe(null)
        })
    })

    describe('formatKwhSigned', () => {
        it('adds explicit plus sign for positive numbers and preserves minus for negative', () => {
            expect(formatKwhSigned(9.546)).toBe('+9.55')
            expect(formatKwhSigned(-3.21)).toBe('-3.21')
            expect(formatKwhSigned(0)).toBe('0.00')
            expect(formatKwhSigned(null)).toBe('—')
        })
    })

    describe('formatHumanForecastPeriod', () => {
        it('formats period with resolution', () => {
            const result = formatHumanForecastPeriod({
                start: '2027-10-04T00:00:00Z',
                end: '2027-10-04T23:59:59Z',
                resolution: 'HOURLY',
            }, 'UTC')
            expect(result).toContain('Oct 4, 2027')
            expect(result).toContain('Hourly')
        })

        it('returns fallback if start is invalid or null', () => {
            expect(formatHumanForecastPeriod(null)).toBe('Day-Ahead · Forecast')
            expect(formatHumanForecastPeriod({ start: 'invalid-date' })).toBe('Day-Ahead · Forecast')
        })
    })

    describe('formatBillingPeriod and formatBillingMonth', () => {
        it('formats billing period month and range correctly', () => {
            const formatted = formatBillingPeriod('2026-08-01', '2026-08-31', 'Asia/Kolkata')
            expect(formatted).toContain('Aug 2026')

            const singleMonth = formatBillingPeriod('2026-08-01', null, 'Asia/Kolkata')
            expect(singleMonth).toBe('Aug 2026')

            expect(formatBillingPeriod(null, null)).toBe('Billing Cycle')
        })

        it('formats billing month correctly', () => {
            expect(formatBillingMonth('2026-09-01', '2026-10-01', 'Asia/Kolkata')).toBe('Sep 2026')
            expect(formatBillingMonth(null, null)).toBe('Billing Period')
        })
    })
})

