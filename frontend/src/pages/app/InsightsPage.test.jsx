import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { ApiError } from '../../services/api/client'

vi.mock('../../services/api/intelligence', () => ({
    getInsights: vi.fn(),
}))

import { getInsights } from '../../services/api/intelligence'
import { InsightsPage } from './InsightsPage'

function renderComponent() {
    return render(
        <MemoryRouter>
            <InsightsPage />
        </MemoryRouter>
    )
}

function sampleInsights() {
    return [
        {
            id: 'ins-1',
            title: 'Solar Generation Peak',
            summary: 'Peak solar generation reached 24.5 kWh yesterday.',
            category: 'SOLAR',
            type: 'PRODUCTION_INCREASE',
            significance: 'HIGH',
            confidence: 'HIGH',
            data_nature: 'ACTUAL',
            data_status: 'CURRENT',
            data_quality: 'RELIABLE',
            affected_period: { start: '2026-09-20', end: '2026-09-20' },
            supporting_facts: ['Clear sky conditions recorded'],
            limitations: [],
        },
        {
            id: 'ins-2',
            title: 'Grid Dependence Spike',
            summary: 'Grid imports increased by 18% during nighttime hours.',
            category: 'CONSUMPTION',
            type: 'CONSUMPTION_INCREASE',
            significance: 'MEDIUM',
            confidence: 'MEDIUM',
            data_nature: 'ACTUAL',
            data_status: 'CURRENT',
            data_quality: 'RELIABLE',
            affected_period: { start: '2026-09-10', end: '2026-09-20' },
            supporting_facts: ['Air conditioner usage increased'],
            limitations: [],
        },
        {
            id: 'ins-3',
            title: 'Weather Forecast Delay',
            summary: 'Solar yield forecast is using stale weather observations.',
            category: 'DATA_LIMITATION',
            type: 'STALE_FORECAST',
            significance: 'LOW',
            confidence: 'LOW',
            data_nature: 'ESTIMATED',
            data_status: 'STALE',
            data_quality: 'DEGRADED',
            affected_period: { start: '2026-09-21', end: '2026-09-21' },
            supporting_facts: ['Weather API timeout on last fetch'],
            limitations: ['Estimated baseline applied'],
        },
    ]
}

describe('InsightsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('shows loading state initially', () => {
        getInsights.mockReturnValue(new Promise(() => {}))
        renderComponent()

        expect(screen.getByText(/Loading insights/i)).toBeInTheDocument()
    })

    it('shows error state on failure and allows retry', async () => {
        getInsights.mockRejectedValueOnce(
            new ApiError({ code: 'SERVER_ERROR', message: 'Failed to load insights', status: 500 })
        )

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('Insights unavailable')).toBeInTheDocument()
        })
        expect(screen.getByText('Failed to load insights')).toBeInTheDocument()

        // Mock success for retry
        getInsights.mockResolvedValueOnce({
            data: { insights: sampleInsights() },
        })

        const retryBtn = screen.getByRole('button', { name: /try again/i })
        fireEvent.click(retryBtn)

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
        })
    })

    it('renders empty state with action links when insights array is empty', async () => {
        getInsights.mockResolvedValueOnce({
            data: { insights: [] },
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('No insight right now')).toBeInTheDocument()
        })
        expect(screen.getByText(/SPMS does not invent insights to fill the page/i)).toBeInTheDocument()
        expect(screen.getByRole('link', { name: /view solar telemetry/i })).toHaveAttribute('href', '/solar')
        expect(screen.getByRole('link', { name: /review bills/i })).toHaveAttribute('href', '/bills')
    })

    it('renders degraded banner when any insight has STALE or DEGRADED status', async () => {
        getInsights.mockResolvedValueOnce({
            data: { insights: sampleInsights() },
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
        })

        expect(
            screen.getByText(/Some insights rest on stale, degraded, or unavailable evidence/i)
        ).toBeInTheDocument()
    })

    it('renders insight cards and allows category filtering', async () => {
        getInsights.mockResolvedValueOnce({
            data: { insights: sampleInsights() },
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
            expect(screen.getByText('Grid Dependence Spike')).toBeInTheDocument()
            expect(screen.getByText('Weather Forecast Delay')).toBeInTheDocument()
        })

        // Check category tabs specifically by exact start of label
        const solarTab = screen.getByRole('button', { name: /^Solar/ })
        expect(solarTab).toBeInTheDocument()

        // Filter to Solar
        fireEvent.click(solarTab)

        expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
        expect(screen.queryByText('Grid Dependence Spike')).not.toBeInTheDocument()
        expect(screen.queryByText('Weather Forecast Delay')).not.toBeInTheDocument()

        // Filter back to All
        const allTab = screen.getByRole('button', { name: /^All/ })
        fireEvent.click(allTab)

        expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
        expect(screen.getByText('Grid Dependence Spike')).toBeInTheDocument()
    })

    it('allows manual refresh via header button', async () => {
        getInsights.mockResolvedValueOnce({
            data: { insights: sampleInsights() },
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('Solar Generation Peak')).toBeInTheDocument()
        })

        // Mock new insight on refresh
        const refreshedInsights = [
            ...sampleInsights(),
            {
                id: 'ins-4',
                title: 'Tariff Peak Notice',
                summary: 'New evening peak rate active.',
                category: 'COST',
                type: 'TARIFF_INCREASE',
                significance: 'HIGH',
                confidence: 'HIGH',
                data_nature: 'VERIFIED',
                data_status: 'CURRENT',
                data_quality: 'RELIABLE',
                affected_period: { start: '2026-09-01', end: '2026-09-30' },
                supporting_facts: [],
                limitations: [],
            },
        ]
        getInsights.mockResolvedValueOnce({
            data: { insights: refreshedInsights },
        })

        const refreshBtn = screen.getByRole('button', { name: /refresh insights/i })
        fireEvent.click(refreshBtn)

        await waitFor(() => {
            expect(screen.getByText('Tariff Peak Notice')).toBeInTheDocument()
        })
    })
})
