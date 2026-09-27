import React from 'react'
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ForecastChart, NowMarkerLabel } from './ForecastChart'
import {
    getNowMarkerInfo,
    formatNowTime,
    parseDateSafe,
    computePillX,
} from './forecastMarkerUtils'

// Mock ResponsiveContainer for JSDOM with explicit width & height passed to child chart
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

describe('ForecastChart — NOW Reference Marker', () => {
    const sampleTimeSeries = Array.from({ length: 24 }, (_, i) => ({
        hour: i,
        timestamp: `2026-09-26T${String(i).padStart(2, '0')}:00:00Z`,
        date: '2026-09-26',
        predicted_solar_kwh: i >= 6 && i <= 18 ? 2.5 : 0,
        lower_solar_kwh: i >= 6 && i <= 18 ? 2.0 : 0,
        upper_solar_kwh: i >= 6 && i <= 18 ? 3.0 : 0,
        predicted_consumption_kwh: 1.2,
        lower_consumption_kwh: 1.0,
        upper_consumption_kwh: 1.5,
    }))

    const samplePeriod = {
        start: '2026-09-26T00:00:00Z',
        end: '2026-09-26T23:59:59Z',
        resolution: 'HOURLY',
    }

    it('1. NOW marker renders when current/reference time is inside the forecast horizon', () => {
        // 10:11 UTC is 15:41 in Asia/Kolkata (+05:30)
        const refTime = '2026-09-26T10:11:00Z'
        render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
                referenceTime={refTime}
                timezone="Asia/Kolkata"
                period={samplePeriod}
            />
        )

        expect(screen.getByTestId('now-marker-label')).toBeInTheDocument()
        expect(screen.getByText('NOW · 15:41')).toBeInTheDocument()
    })

    it('2. NOW marker uses the correct x-axis position', () => {
        // 10:11 UTC is 15:41 in Asia/Kolkata (+05:30) -> hour 15 -> displayLabel "15:00"
        const refTime = '2026-09-26T10:11:00Z'
        const formattedData = sampleTimeSeries.map((pt) => ({
            ...pt,
            displayLabel: `${String(pt.hour).padStart(2, '0')}:00`,
        }))

        const markerInfo = getNowMarkerInfo(refTime, 'DAY_AHEAD', formattedData, 'Asia/Kolkata', samplePeriod)
        expect(markerInfo).not.toBeNull()
        expect(markerInfo.x).toBe('15:00')
        expect(markerInfo.hour).toBe(15)
        expect(markerInfo.time).toBe('15:41')
    })

    it('3. NOW label contains the formatted time in 24-hour format without seconds', () => {
        const d = parseDateSafe('2026-09-26T10:11:00Z')
        const formattedTime = formatNowTime(d, 'Asia/Kolkata')
        expect(formattedTime).toBe('15:41')
        expect(formattedTime).not.toMatch(/:\d{2}:\d{2}/) // No seconds

        const formattedData = sampleTimeSeries.map((pt) => ({
            ...pt,
            displayLabel: `${String(pt.hour).padStart(2, '0')}:00`,
        }))
        const markerInfo = getNowMarkerInfo('2026-09-26T10:11:00Z', 'DAY_AHEAD', formattedData, 'Asia/Kolkata')
        expect(markerInfo.label).toBe('NOW · 15:41')
    })

    it('4. Marker is hidden when current/reference time is outside the displayed horizon', () => {
        // Reference time is tomorrow (2026-09-27), whereas horizon is 2026-09-26
        const outsideRefTime = '2026-09-27T10:11:00Z'
        const { queryByTestId, queryByText } = render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
                referenceTime={outsideRefTime}
                timezone="Asia/Kolkata"
                period={samplePeriod}
            />
        )

        expect(queryByTestId('now-marker-label')).toBeNull()
        expect(queryByText(/NOW ·/)).toBeNull()

        // Also verify getNowMarkerInfo returns null directly
        const formattedData = sampleTimeSeries.map((pt) => ({
            ...pt,
            displayLabel: `${String(pt.hour).padStart(2, '0')}:00`,
        }))
        const markerInfo = getNowMarkerInfo(outsideRefTime, 'DAY_AHEAD', formattedData, 'Asia/Kolkata', samplePeriod)
        expect(markerInfo).toBeNull()
    })

    it('5. Existing forecast series still render unchanged', () => {
        const refTime = '2026-09-26T10:11:00Z'
        render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
                solarConfidence="HIGH"
                consumptionConfidence="MODERATE"
                referenceTime={refTime}
                timezone="Asia/Kolkata"
                period={samplePeriod}
            />
        )

        // Title and container
        expect(screen.getByText('Solar Generation & Consumption Outlook')).toBeInTheDocument()
        // Confidence badges
        expect(screen.getByText(/High confidence/i)).toBeInTheDocument()
        expect(screen.getByText(/Moderate confidence/i)).toBeInTheDocument()
        // Series in legend
        expect(screen.getByText('Predicted Solar (kWh)')).toBeInTheDocument()
        expect(screen.getByText('Predicted Consumption (kWh)')).toBeInTheDocument()
        // NOW marker present alongside series
        expect(screen.getByTestId('now-marker-label')).toBeInTheDocument()
    })

    it('6. Missing current/reference timestamp does not crash the chart', () => {
        // null referenceTime
        const { unmount } = render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
                referenceTime={null}
            />
        )
        expect(screen.getByText('Solar Generation & Consumption Outlook')).toBeInTheDocument()
        expect(screen.queryByTestId('now-marker-label')).toBeNull()
        unmount()

        // undefined referenceTime
        const { unmount: unmount2 } = render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
            />
        )
        expect(screen.getByText('Solar Generation & Consumption Outlook')).toBeInTheDocument()
        expect(screen.queryByTestId('now-marker-label')).toBeNull()
        unmount2()

        // invalid string referenceTime
        const { unmount: unmount3 } = render(
            <ForecastChart
                timeSeries={sampleTimeSeries}
                horizon="DAY_AHEAD"
                referenceTime="invalid-not-a-date"
            />
        )
        expect(screen.getByText('Solar Generation & Consumption Outlook')).toBeInTheDocument()
        expect(screen.queryByTestId('now-marker-label')).toBeNull()
        unmount3()
    })

    describe('Label Positioning (Micro-fix verification)', () => {
        const chartBounds = { x: 40, width: 500 }
        const pillWidth = 76
        const halfWidth = 38

        it('centers label horizontally over the NOW marker coordinate when space allows', () => {
            const lineX = 350 // e.g. hour 18:00
            const pillX = computePillX(lineX, pillWidth, chartBounds, 4)
            // Center of pill must match lineX
            expect(pillX + halfWidth).toBe(lineX)
            expect(pillX).toBe(312)
        })

        it('clamps label cleanly at left boundary without collapsing to 0 or clipping past left edge', () => {
            const lineX = 45 // near left border (left border is x=40, with pad=4 minLeft=44)
            const pillX = computePillX(lineX, pillWidth, chartBounds, 4)
            // Should clamp to minLeft = 44
            expect(pillX).toBe(44)
            // Center is shifted slightly inward from 45 to 82, staying right next to lineX
            expect(pillX + halfWidth).toBe(82)
            expect(pillX).toBeGreaterThanOrEqual(44)
        })

        it('clamps label cleanly at right boundary without clipping past right edge', () => {
            const lineX = 535 // near right border (right border is 40 + 500 = 540, with pad=4 maxRight=536)
            const pillX = computePillX(lineX, pillWidth, chartBounds, 4)
            // maxPillX = 536 - 76 = 460
            expect(pillX).toBe(460)
            expect(pillX + pillWidth).toBe(536)
        })

        it('handles zero-width or missing viewBox safely without collapsing to chart origin', () => {
            // Vertical ReferenceLine passes width: 0
            const pillX = computePillX(350, pillWidth, { x: 350, y: 12, width: 0, height: 260 })
            // Must remain centered around lineX, not pinned to 4px
            expect(pillX).toBe(350 - halfWidth)
            expect(pillX).toBe(312)
        })

        it('NowMarkerLabel renders rect and text centered around x prop', () => {
            const { container } = render(
                <svg>
                    <NowMarkerLabel x={350} value="NOW · 18:52" />
                </svg>
            )

            const rect = container.querySelector('rect')
            const text = container.querySelector('text')

            expect(rect).toHaveAttribute('x', '312')
            expect(rect).toHaveAttribute('width', '76')
            expect(text).toHaveAttribute('x', '350') // exactly lineX!
            expect(text).toHaveTextContent('NOW · 18:52')
        })
    })
})
