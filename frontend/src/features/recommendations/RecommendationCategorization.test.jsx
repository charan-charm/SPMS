import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router'
import { RecommendationsPage } from '../../pages/app/RecommendationsPage'
import {
    getRecommendationCategory,
    RECOMMENDATION_CATEGORIES,
    CATEGORY_FILTERS,
} from './recommendationCategories'
import * as recommendationsApi from '../../services/api/recommendations'

describe('Recommendation Categorization & Purpose Segregation', () => {
    beforeEach(() => {
        vi.restoreAllMocks()
    })

    describe('getRecommendationCategory mapping', () => {
        it('maps solar-related recommendation types to SOLAR_UTILIZATION', () => {
            expect(getRecommendationCategory({ type: 'USE_AVAILABLE_SOLAR' }).id).toBe('SOLAR_UTILIZATION')
            expect(getRecommendationCategory({ type: 'SOLAR_SELF_CONSUMPTION' }).id).toBe('SOLAR_UTILIZATION')
            expect(getRecommendationCategory({ type: 'EXCESS_SOLAR_DIVERT' }).id).toBe('SOLAR_UTILIZATION')
        })

        it('maps balance and load-shifting types to ENERGY_BALANCE', () => {
            expect(getRecommendationCategory({ type: 'PEAK_SHAVING' }).id).toBe('ENERGY_BALANCE')
            expect(getRecommendationCategory({ type: 'BATTERY_CHARGE_TIMING' }).id).toBe('ENERGY_BALANCE')
            expect(getRecommendationCategory({ type: 'DEFICIT_MITIGATION' }).id).toBe('ENERGY_BALANCE')
        })

        it('maps weather-conditioned types to WEATHER_AWARE', () => {
            expect(getRecommendationCategory({ type: 'WEATHER_AWARE_PREPARATION' }).id).toBe('WEATHER_AWARE')
            expect(getRecommendationCategory({ type: 'CLOUDY_DAY_CONSERVATION' }).id).toBe('WEATHER_AWARE')
        })

        it('maps tariff and export optimization types to ENERGY_COST', () => {
            expect(getRecommendationCategory({ type: 'OFF_PEAK_TARIFF_SHIFT' }).id).toBe('ENERGY_COST')
            expect(getRecommendationCategory({ type: 'SOLAR_EXPORT_OPTIMIZATION' }).id).toBe('ENERGY_COST')
        })

        it('maps baseload and high demand types to CONSUMPTION_PATTERNS', () => {
            expect(getRecommendationCategory({ type: 'HIGH_DEMAND_REDUCTION' }).id).toBe('CONSUMPTION_PATTERNS')
            expect(getRecommendationCategory({ type: 'PHANTOM_LOAD_AUDIT' }).id).toBe('CONSUMPTION_PATTERNS')
        })

        it('maps equipment and telemetry health types to SYSTEM_HEALTH', () => {
            expect(getRecommendationCategory({ type: 'BATTERY_HEALTH_MAINTENANCE' }).id).toBe('SYSTEM_HEALTH')
            expect(getRecommendationCategory({ type: 'INVERTER_EFFICIENCY_CHECK' }).id).toBe('SYSTEM_HEALTH')
            expect(getRecommendationCategory({ type: 'STALE_TELEMETRY_WARNING' }).id).toBe('SYSTEM_HEALTH')
            expect(getRecommendationCategory({ type: 'PANEL_CLEANING_CHECK' }).id).toBe('SYSTEM_HEALTH')
        })

        it('provides graceful fallback category for unknown types', () => {
            const fallback = getRecommendationCategory({ type: 'UNKNOWN_EXPERIMENT' })
            expect(fallback.id).toBe('SOLAR_UTILIZATION')
        })
    })

    describe('RecommendationsPage category filter UX', () => {
        const mockRecommendations = [
            {
                id: 'rec-solar-1',
                household_id: 'hh-1',
                type: 'USE_AVAILABLE_SOLAR',
                title: 'Shift Flexible Loads to Solar Generation Window',
                action: 'Run heavy loads midday.',
                timing_window: '11:00 - 14:00',
                priority: 'HIGH',
                confidence: 'HIGH',
                status: 'ACTIVE',
                quality: 'NORMAL',
                potential_impact: 'Potential 3.2 kWh solar self-consumption',
                limitations: [],
                evidence: {
                    insights: [
                        {
                            id: 'ins-1',
                            type: 'HIGH_SOLAR_WINDOW',
                            summary: 'Solar peak observed from 11:00 to 14:00',
                        },
                    ],
                },
                provenance: ['GoodWe Inverter', 'Open-Meteo Weather'],
            },
            {
                id: 'rec-demand-1',
                household_id: 'hh-1',
                type: 'HIGH_DEMAND_REDUCTION',
                title: 'Household Consumption Moderation',
                action: 'Reduce unneeded baseload.',
                timing_window: 'All day',
                priority: 'MEDIUM',
                confidence: 'MODERATE',
                status: 'ACTIVE',
                quality: 'NORMAL',
                potential_impact: null,
                limitations: ['High demand is an observation about reported values'],
                evidence: {
                    insights: [
                        {
                            id: 'ins-2',
                            type: 'HIGH_DEMAND',
                            summary: 'Demand elevated above 5 kWh daily average',
                        },
                    ],
                },
                provenance: ['APSPDCL Verified Bills'],
            },
            {
                id: 'rec-health-1',
                household_id: 'hh-1',
                type: 'PANEL_CLEANING_CHECK',
                title: 'Solar Generation Performance Audit',
                action: 'Inspect panels for dust deposition.',
                timing_window: 'Next morning',
                priority: 'LOW',
                confidence: 'MODERATE',
                status: 'ACTIVE',
                quality: 'NORMAL',
                potential_impact: null,
                limitations: [],
                evidence: {},
                provenance: ['GoodWe Telemetry'],
            },
        ]

        it('renders category filter tabs with dynamic counts', async () => {
            vi.spyOn(recommendationsApi, 'getRecommendations').mockResolvedValue({
                recommendations: mockRecommendations,
            })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
                expect(screen.getByText('Household Consumption Moderation')).toBeInTheDocument()
                expect(screen.getByText('Solar Generation Performance Audit')).toBeInTheDocument()
            })

            // Check that category filter buttons exist
            expect(screen.getByRole('button', { name: /^All\s*\(/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Solar Utilization/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Consumption Patterns/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /System & Data Health/i })).toBeInTheDocument()
        })

        it('filters recommendations when a purpose category tab is clicked', async () => {
            vi.spyOn(recommendationsApi, 'getRecommendations').mockResolvedValue({
                recommendations: mockRecommendations,
            })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
            })

            // Filter by Solar Utilization
            const solarTab = screen.getByRole('button', { name: /Solar Utilization/i })
            fireEvent.click(solarTab)

            // Solar recommendation visible, other categories filtered out
            expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
            expect(screen.queryByText('Household Consumption Moderation')).not.toBeInTheDocument()
            expect(screen.queryByText('Solar Generation Performance Audit')).not.toBeInTheDocument()

            // Filter by Consumption Patterns
            const consumptionTab = screen.getByRole('button', { name: /Consumption Patterns/i })
            fireEvent.click(consumptionTab)

            expect(screen.queryByText('Shift Flexible Loads to Solar Generation Window')).not.toBeInTheDocument()
            expect(screen.getByText('Household Consumption Moderation')).toBeInTheDocument()
            expect(screen.queryByText('Solar Generation Performance Audit')).not.toBeInTheDocument()
        })

        it('displays purpose category badge on each recommendation card', async () => {
            vi.spyOn(recommendationsApi, 'getRecommendations').mockResolvedValue({
                recommendations: mockRecommendations,
            })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                // Category badges visible on cards
                const badges = screen.getAllByTestId('recommendation-category-badge')
                expect(badges.length).toBeGreaterThan(0)
                expect(screen.getAllByText('Solar Utilization').length).toBeGreaterThan(0)
                expect(screen.getAllByText('Consumption Patterns').length).toBeGreaterThan(0)
                expect(screen.getAllByText('System & Data Health').length).toBeGreaterThan(0)
            })
        })

        it('displays supporting insight relationship with link to /insights', async () => {
            vi.spyOn(recommendationsApi, 'getRecommendations').mockResolvedValue({
                recommendations: mockRecommendations,
            })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                expect(screen.getByText('HIGH SOLAR WINDOW Evidence')).toBeInTheDocument()
                expect(screen.getByText('HIGH DEMAND Evidence')).toBeInTheDocument()
                const insightLinks = screen.getAllByRole('link', { name: /View in Insights/i })
                expect(insightLinks.length).toBeGreaterThan(0)
                expect(insightLinks[0]).toHaveAttribute('href', '/insights')
            })
        })

        it('shows honest empty state when a category has 0 matching recommendations and allows reset', async () => {
            vi.spyOn(recommendationsApi, 'getRecommendations').mockResolvedValue({
                recommendations: mockRecommendations,
            })
            vi.spyOn(recommendationsApi, 'dismissRecommendation').mockResolvedValue({ success: true })

            render(
                <MemoryRouter>
                    <RecommendationsPage />
                </MemoryRouter>
            )

            await waitFor(() => {
                expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
            })

            // Filter by Solar Utilization
            const solarTab = screen.getByRole('button', { name: /Solar Utilization/i })
            fireEvent.click(solarTab)

            expect(screen.getByText('Shift Flexible Loads to Solar Generation Window')).toBeInTheDocument()
            expect(screen.queryByText('Household Consumption Moderation')).not.toBeInTheDocument()

            // Dismiss the single recommendation in this category
            const dismissBtn = screen.getByRole('button', { name: /Dismiss recommendation: Shift Flexible Loads to Solar Generation Window/i })
            fireEvent.click(dismissBtn)

            await waitFor(() => {
                expect(screen.getByText('No matching recommendations')).toBeInTheDocument()
                expect(screen.getByText(/There are no active recommendations in the "Solar Utilization" category/i)).toBeInTheDocument()
            })

            // Click reset button
            const resetBtn = screen.getByRole('button', { name: /Show all recommendations/i })
            fireEvent.click(resetBtn)

            // Back to all recommendations
            expect(screen.getByText('Household Consumption Moderation')).toBeInTheDocument()
            expect(screen.getByText('Solar Generation Performance Audit')).toBeInTheDocument()
        })
    })
})
