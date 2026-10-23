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

    it('defaults to collapsed state and expands explanation on click with slide-down interaction', () => {
        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockActiveRecommendation} />
            </MemoryRouter>
        )

        const toggleBtn = screen.getByRole('button', { name: /Why this recommendation/i })
        expect(toggleBtn).toHaveAttribute('aria-expanded', 'false')

        // Click to expand
        fireEvent.click(toggleBtn)
        expect(toggleBtn).toHaveAttribute('aria-expanded', 'true')
        expect(screen.getByText('Why This Recommendation?')).toBeInTheDocument()
        expect(screen.getByText('Based on:')).toBeInTheDocument()
        expect(screen.getByText(/Solar forecast/i)).toBeInTheDocument()
        expect(screen.getByText(/Household electricity use is estimated from verified bills and is not measured hour by hour/i)).toBeInTheDocument()
        expect(screen.getByText(/Savings are not guaranteed/i)).toBeInTheDocument()

        // Click to collapse
        fireEvent.click(toggleBtn)
        expect(toggleBtn).toHaveAttribute('aria-expanded', 'false')
    })

    it('displays honest stale telemetry messaging when solar telemetry is stale without claiming live inverter output', () => {
        const mockStaleSolarRec = {
            ...mockActiveRecommendation,
            quality: 'STALE',
            limitations: ['STALE_SOLAR_TELEMETRY'],
        }

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockStaleSolarRec} />
            </MemoryRouter>
        )

        expect(screen.getByText(/Your solar connection has not sent a fresh reading recently\. This recommendation is based on the forecast and recent data\./i)).toBeInTheDocument()
        // Ensure it does NOT claim current live generation
        expect(screen.queryByText(/Your inverter is currently generating/i)).not.toBeInTheDocument()
    })

    it('renders dynamic Best Time window clearly in primary card', () => {
        const mockDynamicWindowRec = {
            ...mockActiveRecommendation,
            timing_window: '08:00 AM – 01:00 PM',
        }

        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockDynamicWindowRec} />
            </MemoryRouter>
        )

        expect(screen.getByText('Best Time')).toBeInTheDocument()
        expect(screen.getByText('08:00 AM – 01:00 PM')).toBeInTheDocument()
    })

    it('renders compact mode with title, action, best time, and link for Dashboard consistency', () => {
        render(
            <MemoryRouter>
                <RecommendationCard recommendation={mockActiveRecommendation} compact={true} />
            </MemoryRouter>
        )

        expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
        expect(screen.getByText('Run washing machine or water pump between 11:30 and 14:00.')).toBeInTheDocument()
        expect(screen.getByText('11:30 - 14:00')).toBeInTheDocument()
        expect(screen.getByText('HIGH PRIORITY')).toBeInTheDocument()
        expect(screen.getByText(/View full recommendations & explanation/i)).toBeInTheDocument()
    })

    describe('Recommendation Feedback Loop & Consumer UX', () => {
        it('handles positive usefulness feedback and subsequent follow-through selection', async () => {
            const feedbackSpy = vi.spyOn(recommendationApi, 'submitRecommendationFeedback').mockImplementation(
                (id, data) => Promise.resolve({
                    success: true,
                    data: {
                        feedback: {
                            id: 'fb-001',
                            recommendation_id: id,
                            usefulness: data.usefulness === 'YES' ? 'USEFUL' : 'NOT_USEFUL',
                            followed_status: data.followed_status || null,
                            reason: data.reason || null,
                            optional_comment: data.optional_comment || null,
                        },
                    },
                })
            )

            render(
                <MemoryRouter>
                    <RecommendationCard recommendation={mockActiveRecommendation} />
                </MemoryRouter>
            )

            // Primary question
            expect(screen.getByText('Was this recommendation useful?')).toBeInTheDocument()
            const yesBtn = screen.getByRole('button', { name: /Mark recommendation as useful/i })
            fireEvent.click(yesBtn)

            await waitFor(() => {
                expect(feedbackSpy).toHaveBeenCalledWith('rec-001', { usefulness: 'YES' })
                expect(screen.getByText(/Thanks\. Your feedback helps improve recommendations\./i)).toBeInTheDocument()
                expect(screen.getByText('Did you follow it?')).toBeInTheDocument()
            })

            // Follow-through response
            const partlyBtn = screen.getByRole('button', { name: 'Partly' })
            fireEvent.click(partlyBtn)

            await waitFor(() => {
                expect(feedbackSpy).toHaveBeenCalledWith('rec-001', {
                    usefulness: 'YES',
                    followed_status: 'PARTLY',
                })
                expect(screen.getByText('Marked useful')).toBeInTheDocument()
                expect(screen.getByText('Partly followed')).toBeInTheDocument()
            })
        })

        it('handles negative feedback with reason selection and optional comment', async () => {
            const feedbackSpy = vi.spyOn(recommendationApi, 'submitRecommendationFeedback').mockResolvedValue({
                success: true,
                data: {
                    feedback: {
                        id: 'fb-002',
                        recommendation_id: 'rec-001',
                        usefulness: 'NOT_USEFUL',
                        reason: 'WRONG_TIMING',
                        optional_comment: 'Was away from home during midday',
                    },
                },
            })

            render(
                <MemoryRouter>
                    <RecommendationCard recommendation={mockActiveRecommendation} />
                </MemoryRouter>
            )

            const noBtn = screen.getByRole('button', { name: /Mark recommendation as not useful/i })
            fireEvent.click(noBtn)

            await waitFor(() => {
                expect(screen.getByText('What could be better?')).toBeInTheDocument()
            })

            // Select reason
            const wrongTimingBtn = screen.getByRole('button', { name: 'Wrong timing' })
            fireEvent.click(wrongTimingBtn)

            // Fill comment
            const commentInput = screen.getByPlaceholderText(/Optional short note/i)
            fireEvent.change(commentInput, { target: { value: 'Was away from home during midday' } })

            const submitBtn = screen.getByRole('button', { name: 'Submit Feedback' })
            fireEvent.click(submitBtn)

            await waitFor(() => {
                expect(feedbackSpy).toHaveBeenCalledWith('rec-001', {
                    usefulness: 'NO',
                    reason: 'WRONG_TIMING',
                    optional_comment: 'Was away from home during midday',
                })
                expect(screen.getByText('Feedback recorded')).toBeInTheDocument()
                expect(screen.getByText('(Wrong timing)')).toBeInTheDocument()
            })
        })

        it('renders read-only feedback states in history mode without prompting', () => {
            const recWithUsefulFeedback = {
                ...mockActiveRecommendation,
                user_feedback: {
                    usefulness: 'USEFUL',
                    followed_status: 'YES',
                },
            }

            const { rerender } = render(
                <MemoryRouter>
                    <RecommendationCard recommendation={recWithUsefulFeedback} isHistorical={true} />
                </MemoryRouter>
            )

            expect(screen.queryByText('Was this recommendation useful?')).not.toBeInTheDocument()
            expect(screen.getByText('Useful')).toBeInTheDocument()
            expect(screen.getByText('Followed')).toBeInTheDocument()

            // Negative feedback in history
            const recWithNegativeFeedback = {
                ...mockActiveRecommendation,
                user_feedback: {
                    usefulness: 'NOT_USEFUL',
                    reason: 'WRONG_TIMING',
                },
            }

            rerender(
                <MemoryRouter>
                    <RecommendationCard recommendation={recWithNegativeFeedback} isHistorical={true} />
                </MemoryRouter>
            )

            expect(screen.getByText('Not useful')).toBeInTheDocument()
            expect(screen.getByText(/Wrong timing/i)).toBeInTheDocument()

            // No feedback in history
            const recWithNoFeedback = {
                ...mockActiveRecommendation,
                user_feedback: null,
            }

            rerender(
                <MemoryRouter>
                    <RecommendationCard recommendation={recWithNoFeedback} isHistorical={true} />
                </MemoryRouter>
            )

            expect(screen.getByText('— No feedback')).toBeInTheDocument()
        })

        it('renders canonical action-first copy directly and hides internal rule IDs in primary card', () => {
            const canonicalRec = {
                ...mockActiveRecommendation,
                title: 'Use flexible appliances during solar hours',
                action: 'Run the washing machine, water pump or water heater during the higher-solar period.',
            }

            render(
                <MemoryRouter>
                    <RecommendationCard recommendation={canonicalRec} />
                </MemoryRouter>
            )

            // Consumer-friendly copy rendered directly from canonical backend fields
            expect(screen.getByText('Use flexible appliances during solar hours')).toBeInTheDocument()
            expect(screen.getByText('Run the washing machine, water pump or water heater during the higher-solar period.')).toBeInTheDocument()

            // Primary card does not expose rule ID or internal identifiers in top-level view; housed in details
            expect(screen.getByText('Technical details & audit evidence')).toBeInTheDocument()
            const technicalDetails = screen.getByText('Technical details & audit evidence').closest('details')
            expect(technicalDetails).toBeInTheDocument()
            expect(technicalDetails).toHaveTextContent('Rule ID:')
        })

        it('displays friendly weather data limitation UX without raw network error in primary view', () => {
            const weatherLimitationRec = {
                ...mockActiveRecommendation,
                limitations: ['Open-Meteo geocoding request failed: Network error (28)...'],
            }

            render(
                <MemoryRouter>
                    <RecommendationCard recommendation={weatherLimitationRec} />
                </MemoryRouter>
            )

            expect(screen.getByText('Weather data unavailable')).toBeInTheDocument()
            expect(screen.getByText(/This recommendation is currently based on the expected solar pattern rather than live weather conditions\./i)).toBeInTheDocument()

            // Raw error is tucked inside secondary technical disclosure
            const technicalDetails = screen.getByText('Technical details & audit evidence').closest('details')
            expect(technicalDetails).toBeInTheDocument()
            expect(technicalDetails).toHaveTextContent(/Network error \(28\)/i)
        })

        it('displays stale telemetry warning with Check connection CTA and avoids showing Actual beside it', () => {
            const staleRec = {
                ...mockActiveRecommendation,
                quality: 'STALE',
                limitations: ['STALE_SOLAR_TELEMETRY'],
            }

            render(
                <MemoryRouter>
                    <RecommendationCard recommendation={staleRec} />
                </MemoryRouter>
            )

            expect(screen.getByText('Solar data is out of date')).toBeInTheDocument()
            expect(screen.getByText(/Your inverter has not sent a fresh reading recently\. Recommendations that depend on live solar data may be less reliable\./i)).toBeInTheDocument()
            expect(screen.getByText(/Check connection →/i)).toBeInTheDocument()
        })

        it('deduplicates active recommendations of the same type on RecommendationsPage', async () => {
            const duplicateRecs = [
                {
                    ...mockActiveRecommendation,
                    id: 'rec-001',
                    type: 'SHIFT_TO_SOLAR_WINDOW',
                    title: 'Shift Flexible Loads to Solar Generation Window',
                },
                {
                    ...mockActiveRecommendation,
                    id: 'rec-002',
                    type: 'SHIFT_TO_SOLAR_WINDOW',
                    title: 'Shift Flexible Loads to Solar Generation Window',
                },
            ]

            vi.spyOn(recommendationApi, 'getRecommendations').mockResolvedValue({
                recommendations: duplicateRecs,
                active_count: 2,
            })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                const cards = screen.getAllByTestId('recommendation-card')
                // Deduplicated from 2 to 1 in the active list
                expect(cards).toHaveLength(1)
            })
        })
    })
})

