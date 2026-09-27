import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { RecommendationsPage } from '../../pages/app/RecommendationsPage'
import { RecommendationCard } from './RecommendationCard'
import { RecommendationLimitationsCard } from './RecommendationLimitationsCard'
import * as recommendationApi from '../../services/api/recommendations'

const mockActiveRecommendation = {
    id: 'rec-001',
    household_id: 'hh-123',
    type: 'SHIFT_TO_SOLAR_WINDOW',
    status: 'ACTIVE',
    priority: 'HIGH',
    title: 'Shift Flexible Loads to Solar Generation Window',
    summary: 'Midday solar surplus of 3.5 kWh expected tomorrow.',
    action: 'Run washing machine or water pump between 11:30 and 14:00.',
    reason: 'Solar generation peak creates usable surplus.',
    timing_window: '11:30 - 14:00',
    potential_impact: 'Potential 3.5 kWh solar self-consumption increase.',
    impact: {
        type: 'SOLAR_SELF_CONSUMPTION',
        kwh: 3.5,
        inr: 26.25,
        description: 'Potential 3.5 kWh solar self-consumption increase.',
    },
    evidence: {
        surplus_window: { start: '11:30', end: '14:00', surplus_kwh: 3.5 },
    },
    confidence: 'HIGH',
    quality: 'GOOD',
    data_nature: 'PREDICTED',
    provenance: ['ForecastService (F3)'],
    limitations: [],
    valid_from: '2026-09-22T00:00:00Z',
    valid_until: '2026-09-23T00:00:00Z',
}

const mockLimitationRecommendation = {
    id: 'rec-002',
    household_id: 'hh-123',
    type: 'DATA_LIMITATION',
    status: 'ACTIVE',
    priority: 'MEDIUM',
    title: 'Inverter Telemetry Degraded — Recommendations Constrained',
    summary: 'Telemetry gaps detected in solar generation records for the evaluation period.',
    action: 'Check inverter Wi-Fi / RS485 communication link to restore automated recommendation accuracy.',
    reason: 'Missing interval data impairs reliable surplus/deficit forecasting.',
    timing_window: 'Immediate',
    potential_impact: null,
    impact: null,
    evidence: {
        degraded_status: 'DEGRADED',
    },
    confidence: 'LOW',
    quality: 'DEGRADED',
    data_nature: 'ACTUAL',
    provenance: ['DataQualityChecker'],
    limitations: ['Advisory models operating in degraded mode; numerical savings estimates suppressed.'],
    valid_from: '2026-09-22T00:00:00Z',
    valid_until: '2026-09-23T00:00:00Z',
}

describe('F7 Recommendations Feature Flow', () => {
    beforeEach(() => {
        vi.restoreAllMocks()
    })

    it('renders RecommendationsPage with header, controls, and active recommendation cards', async () => {
        vi.spyOn(recommendationApi, 'getRecommendations').mockResolvedValue({
            recommendations: [mockActiveRecommendation],
            active_count: 1,
            meta: { quality: 'GOOD', data_nature: 'PREDICTED' },
        })

        render(
            <MemoryRouter>
                <RecommendationsPage />
            </MemoryRouter>
        )

        expect(screen.getByText('Recommendations & Advisory')).toBeInTheDocument()
        expect(screen.getByText('Actionable energy guidance tailored to your solar availability and household demand.')).toBeInTheDocument()

        await waitFor(() => {
            expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
            expect(screen.getByText('Run washing machine or water pump between 11:30 and 14:00.')).toBeInTheDocument()
            expect(screen.getByText('11:30 - 14:00')).toBeInTheDocument()
        })
    })

    it('renders RecommendationCard with priority, confidence, impact, and expandable lineage', () => {
        const onDismiss = vi.fn()

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockActiveRecommendation} onDismiss={onDismiss} />
            </MemoryRouter>
        )

        // Title and action
        expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
        expect(screen.getByText('Run washing machine or water pump between 11:30 and 14:00.')).toBeInTheDocument()

        // Badges
        expect(screen.getByText('HIGH PRIORITY')).toBeInTheDocument()
        expect(screen.getByText('HIGH CONFIDENCE')).toBeInTheDocument()

        // Impact
        expect(screen.getByText('Potential 3.5 kWh solar self-consumption increase.')).toBeInTheDocument()

        // Technical Evidence collapsible
        const toggleBtn = screen.getByText(/View evidence & details/i)
        expect(toggleBtn).toBeInTheDocument()
        fireEvent.click(toggleBtn)

        expect(screen.getByText(/Hide evidence & details/i)).toBeInTheDocument()
        expect(screen.getByText(/ForecastService \(F3\)/i)).toBeInTheDocument()

        // Dismiss action
        const dismissBtn = screen.getByRole('button', { name: /Dismiss/i })
        fireEvent.click(dismissBtn)
        expect(onDismiss).toHaveBeenCalledWith('rec-001')
    })

    it('renders RecommendationLimitationsCard for DATA_LIMITATION with unlock guidance', () => {
        render(
            <MemoryRouter>
                <RecommendationLimitationsCard recommendation={mockLimitationRecommendation} />
            </MemoryRouter>
        )

        expect(screen.getByText('Inverter Telemetry Degraded — Recommendations Constrained')).toBeInTheDocument()
        expect(screen.getByText('Data Quality Notice')).toBeInTheDocument()
        expect(screen.getByText(/Check inverter Wi-Fi \/ RS485 communication link/i)).toBeInTheDocument()
        expect(screen.getByText(/Connect Live Inverter/i)).toBeInTheDocument()
    })

    it('displays empty state when no recommendations are returned', async () => {
        vi.spyOn(recommendationApi, 'getRecommendations').mockResolvedValue({
            recommendations: [],
            active_count: 0,
            meta: { quality: 'GOOD', data_nature: 'ACTUAL' },
        })

        render(
            <MemoryRouter>
                <RecommendationsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('No Active Recommendations')).toBeInTheDocument()
            expect(screen.getByText('Your energy profile is currently balanced. Check back as new solar forecasts or bill cycles become available.')).toBeInTheDocument()
        })
    })

    it('handles Re-evaluate Recommendations button click', async () => {
        vi.spyOn(recommendationApi, 'getRecommendations').mockResolvedValue({
            recommendations: [mockActiveRecommendation],
            active_count: 1,
            meta: { quality: 'GOOD', data_nature: 'PREDICTED' },
        })

        const generateSpy = vi.spyOn(recommendationApi, 'generateRecommendations').mockResolvedValue({
            recommendations: [mockActiveRecommendation],
            generated_count: 1,
            active_count: 1,
        })

        render(
            <MemoryRouter>
                <RecommendationsPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Re-evaluate Recommendations')).toBeInTheDocument()
        })

        const reevaluateBtn = screen.getByRole('button', { name: /Re-evaluate Recommendations/i })
        fireEvent.click(reevaluateBtn)

        await waitFor(() => {
            expect(generateSpy).toHaveBeenCalledTimes(1)
        })
    })

    it('visually and semantically distinguishes HIGH priority from LOW confidence', () => {
        const mockHighPriorityLowConfidence = {
            ...mockActiveRecommendation,
            priority: 'HIGH',
            confidence: 'LOW',
            limitations: ['GoodWe telemetry is stale', 'Derived from 3 verified bills'],
        }

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockHighPriorityLowConfidence} />
            </MemoryRouter>
        )

        const priorityBadge = screen.getByText('HIGH PRIORITY')
        const confidenceBadge = screen.getByText('LOW CONFIDENCE')

        expect(priorityBadge).toBeInTheDocument()
        expect(confidenceBadge).toBeInTheDocument()
        // Priority badge has primary styling
        expect(priorityBadge.className).toContain('text-primary-active')
        // Confidence badge has warning styling (amber, not primary)
        expect(confidenceBadge.className).toContain('text-warning')
        expect(confidenceBadge.className).not.toContain('text-primary-active')
    })

    it('faithfully preserves null energy_kwh, renders MODERATE confidence, and avoids client-side fallback to 2.0', () => {
        const mockRealHighDemand = {
            id: 'rec-real-001',
            household_id: 'hh-real',
            type: 'HIGH_DEMAND_REDUCTION',
            status: 'ACTIVE',
            priority: 'MEDIUM',
            title: 'Household Consumption Moderation',
            summary: 'Recurring high household consumption observed across recent billing periods.',
            action: 'Review recurring household electricity consumption and reduce avoidable usage where practical.',
            reason: 'Your last 3 verified bills show recurring household consumption above the current baseline.',
            timing_window: 'Ongoing billing cycle',
            potential_impact: 'Review recurring household electricity consumption',
            impact: {
                type: 'HIGH_DEMAND_CURTAILMENT',
                energy_kwh: null,
                cost_inr: null,
                description: 'Review recurring household electricity consumption',
            },
            evidence: {
                insights: [
                    {
                        id: 'ins-001',
                        type: 'RECURRING_HIGH_DEMAND',
                        summary: 'Household consumption stayed at or above 5 kWh in every one of the 3 observed periods.',
                        confidence: 'MODERATE',
                    },
                ],
            },
            confidence: 'MODERATE',
            quality: 'VERIFIED',
            data_nature: 'ACTUAL',
            provenance: ['F6_INSIGHTS'],
            limitations: ['High demand is an observation about reported values; SPMS does not know which appliances caused it.'],
            valid_from: '2026-09-26T15:02:57Z',
            valid_until: '2026-09-27T15:02:57Z',
        }

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockRealHighDemand} />
            </MemoryRouter>
        )

        // Title and Authoritative Action
        expect(screen.getByText('Household Consumption Moderation')).toBeInTheDocument()
        expect(screen.getByText('Review recurring household electricity consumption and reduce avoidable usage where practical.')).toBeInTheDocument()
        expect(screen.getByText('Your last 3 verified bills show recurring household consumption above the current baseline.')).toBeInTheDocument()

        // Separate Priority and Confidence Badges
        expect(screen.getByText('MEDIUM PRIORITY')).toBeInTheDocument()
        expect(screen.getByText('MODERATE CONFIDENCE')).toBeInTheDocument()

        // MUST NOT render fake numbers or fallback to 2.0 kWh
        expect(screen.queryByText(/2\.0/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/≈2/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/≈.*kWh/i)).not.toBeInTheDocument()

        // Primary card contains single limitation
        expect(screen.getByText(/Contextual Limitation:/i)).toBeInTheDocument()
        expect(screen.getByText(/High demand is an observation about reported values/i)).toBeInTheDocument()

        // Expandable details reveals rule ID and source insight
        const detailsBtn = screen.getByText(/View evidence & details/i)
        fireEvent.click(detailsBtn)

        expect(screen.getByText('HIGH_DEMAND_REDUCTION')).toBeInTheDocument()
        expect(screen.getByText(/Household consumption stayed at or above 5 kWh/i)).toBeInTheDocument()
    })

    it('renders USE_AVAILABLE_SOLAR with potential solar-window opportunity and forecast disclaimer', () => {
        const mockSolarAvailable = {
            id: 'rec-solar-001',
            household_id: 'hh-real',
            type: 'USE_AVAILABLE_SOLAR',
            status: 'ACTIVE',
            priority: 'MEDIUM',
            title: 'Utilize Available Solar Window',
            action: 'Shift discretionary consumption into the peak solar window.',
            reason: 'Significant solar generation is expected during midday hours.',
            timing_window: 'Daytime solar window (11:00–15:00)',
            potential_impact: 'Potential solar-window opportunity of approximately 21.1 kWh',
            impact: {
                type: 'SOLAR_UTILIZATION',
                energy_kwh: 21.053,
                cost_inr: null,
                description: 'Potential solar-window opportunity of approximately 21.1 kWh',
            },
            evidence: {
                solar_forecast: { value_kwh: 21.1, confidence: 'HIGH' },
            },
            confidence: 'MODERATE',
            quality: 'GOOD',
            data_nature: 'PREDICTED',
            provenance: ['F3_FORECAST'],
            limitations: [],
        }

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockSolarAvailable} />
            </MemoryRouter>
        )

        expect(screen.getByText(/Potential solar-window opportunity · ≈21.1 kWh/i)).toBeInTheDocument()
        expect(screen.getByText('≈21.1 kWh')).toBeInTheDocument()
        expect(screen.getByText(/Forecast-derived potential; not guaranteed savings\./i)).toBeInTheDocument()
    })
})
