import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { InsightCard } from './InsightCard'

describe('InsightCard', () => {
    const baseInsight = {
        id: 'ins-1',
        title: 'High Evening Consumption',
        summary: 'Household uses 60% of total energy between 6pm and 10pm.',
        category: 'CONSUMPTION',
        type: 'PEAK_HOURS',
        significance: 'HIGH',
        confidence: 'HIGH',
        data_nature: 'ACTUAL',
        data_status: 'CURRENT',
        data_quality: 'RELIABLE',
        affected_period: {
            start: '2026-09-01',
            end: '2026-09-30',
        },
        supporting_facts: ['Average peak power 4.2 kW', 'Daily peak duration 4 hours'],
        limitations: ['Weekend profile not fully captured'],
    }

    it('renders title, summary, category, and significance badge', () => {
        render(<InsightCard insight={baseInsight} />)

        expect(screen.getByText('High Evening Consumption')).toBeInTheDocument()
        expect(
            screen.getByText('Household uses 60% of total energy between 6pm and 10pm.')
        ).toBeInTheDocument()
        expect(screen.getByText(/Consumption · Peak Hours/i)).toBeInTheDocument()
        expect(screen.getByText('High significance')).toBeInTheDocument()
        expect(screen.getByText('2026-09-01 → 2026-09-30')).toBeInTheDocument()
    })

    it('formats single date when period start equals end', () => {
        const singleDateInsight = {
            ...baseInsight,
            affected_period: {
                start: '2026-09-15',
                end: '2026-09-15',
            },
        }

        render(<InsightCard insight={singleDateInsight} />)
        expect(screen.getByText('2026-09-15')).toBeInTheDocument()
    })

    it('expands and collapses supporting facts and limitations', () => {
        render(<InsightCard insight={baseInsight} />)

        // Details should initially not be visible
        expect(screen.queryByText('Supporting facts')).not.toBeInTheDocument()
        expect(screen.queryByText('Average peak power 4.2 kW')).not.toBeInTheDocument()

        const toggleBtn = screen.getByRole('button', { name: /Why this insight/i })
        expect(toggleBtn).toBeInTheDocument()

        // Click to expand
        fireEvent.click(toggleBtn)
        expect(screen.getByText('Supporting facts')).toBeInTheDocument()
        expect(screen.getByText('Average peak power 4.2 kW')).toBeInTheDocument()
        expect(screen.getByText('Limitations')).toBeInTheDocument()
        expect(screen.getByText('Weekend profile not fully captured')).toBeInTheDocument()

        // Click to collapse
        fireEvent.click(toggleBtn)
        expect(screen.queryByText('Supporting facts')).not.toBeInTheDocument()
    })

    it('handles null or missing tokens defensively without throw', () => {
        const minimalInsight = {
            id: 'ins-2',
            title: 'Minimal Data Insight',
            category: null,
            significance: null,
            confidence: null,
            data_nature: 'ESTIMATED',
            data_status: null,
            data_quality: null,
            affected_period: null,
        }

        render(<InsightCard insight={minimalInsight} />)
        expect(screen.getByText('Minimal Data Insight')).toBeInTheDocument()
    })

    it('renders the three core readability sections: What SPMS Observed, Why It Matters, and Recommended Next Step', () => {
        render(<InsightCard insight={baseInsight} />)

        expect(screen.getByText('What SPMS Observed')).toBeInTheDocument()
        expect(screen.getByText('Why It Matters')).toBeInTheDocument()
        expect(screen.getByText('Recommended Next Step')).toBeInTheDocument()
        expect(screen.getByText(baseInsight.summary)).toBeInTheDocument()
    })

    it('provides tailored household advice and action link for DAYTIME_SURPLUS_EVENING_DEFICIT', () => {
        const surplusInsight = {
            id: 'ins-surplus',
            title: 'Daytime surplus with evening deficit pattern',
            summary: 'Observed periods with declared times show potential surplus around midday and potential deficit in the evening.',
            category: 'ENERGY',
            type: 'DAYTIME_SURPLUS_EVENING_DEFICIT',
            significance: 'MODERATE',
            confidence: 'LOW',
            data_nature: 'SIMULATED',
            data_status: 'FRESH',
            affected_period: { start: '2026-09-20', end: '2026-09-20' },
        }

        render(<InsightCard insight={surplusInsight} />)

        expect(screen.getByText('Daytime surplus with evening deficit pattern')).toBeInTheDocument()
        expect(screen.getByText(/Shifting daytime appliance use helps you use free solar power/i)).toBeInTheDocument()
        expect(screen.getByText(/between 10:00 AM and 3:00 PM during peak sunlight/i)).toBeInTheDocument()
        const link = screen.getByRole('link', { name: /View Load Shifting Recommendations/i })
        expect(link).toHaveAttribute('href', '/recommendations')
    })

    it('provides clear inverter troubleshooting advice for DATA_LIMITATION', () => {
        const limitationInsight = {
            id: 'ins-limit',
            title: 'Limited evidence for insight generation',
            summary: 'Available evidence is incomplete, unavailable, or simulated.',
            category: 'GENERAL',
            type: 'DATA_LIMITATION',
            significance: 'LOW',
            confidence: 'INSUFFICIENT',
            data_nature: 'ESTIMATED',
            data_status: 'STALE',
            affected_period: null,
        }

        render(<InsightCard insight={limitationInsight} />)

        expect(screen.getByText(/SPMS never invents or guesses missing data/i)).toBeInTheDocument()
        expect(screen.getByText(/Check your GoodWe solar inverter Wi-Fi connection/i)).toBeInTheDocument()
        const link = screen.getByRole('link', { name: /Check Solar Inverter Connection/i })
        expect(link).toHaveAttribute('href', '/solar')
        expect(screen.getByText('Stale')).toBeInTheDocument()
    })

    it('distinguishes stable generation patterns where no corrective action is required', () => {
        const stableInsight = {
            id: 'ins-stable',
            title: 'Recurring stable solar pattern',
            summary: 'Solar generation stayed within a stable band around 141.7 kWh across 3 observed periods.',
            category: 'SOLAR',
            type: 'RECURRING_SOLAR_PATTERN',
            significance: 'MODERATE',
            confidence: 'LOW',
            data_nature: 'ACTUAL',
            data_status: 'FRESH',
            affected_period: { start: '2026-06-01', end: '2026-08-31' },
        }

        render(<InsightCard insight={stableInsight} />)

        expect(screen.getByText(/operating steadily and predictably within normal expected variance/i)).toBeInTheDocument()
        expect(screen.getByText(/No corrective action required/i)).toBeInTheDocument()
    })

    it('renders Unavailable status and never implies Actual provenance for WEATHER_DATA_UNAVAILABLE', () => {
        const unavailableWeatherInsight = {
            id: 'ins-weather-unavail',
            title: 'Atmospheric Weather Forecast Unavailable',
            summary: 'Weather data is not currently available for this household. Solar forecasting continues using clear-sky physical calculations.',
            category: 'WEATHER',
            type: 'WEATHER_DATA_UNAVAILABLE',
            significance: 'LOW',
            confidence: 'INSUFFICIENT',
            data_nature: null,
            data_status: 'UNAVAILABLE',
            affected_period: null,
        }

        render(<InsightCard insight={unavailableWeatherInsight} />)

        expect(screen.getByText('Atmospheric Weather Forecast Unavailable')).toBeInTheDocument()
        // Must show Unavailable status badge
        expect(screen.getByText('Unavailable')).toBeInTheDocument()
        // Must NOT render an Actual data nature badge
        expect(screen.queryByText('Actual')).not.toBeInTheDocument()
        // Household guidance directs to settings
        const link = screen.getByRole('link', { name: /Configure Household Location/i })
        expect(link).toHaveAttribute('href', '/settings')
    })
})

