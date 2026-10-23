import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { Lightbulb, RefreshCw, SlidersHorizontal, Sparkles } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { InsightCard } from '../../components/data-display/InsightCard'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { LoadingState } from '../../components/feedback/LoadingState'
import { ApiError } from '../../services/api/client'
import { getInsights } from '../../services/api/intelligence'

/**
 * Insights & Recommendations page (F6 — docs/10 D-043..D-048).
 *
 * The backend (/api/v1/intelligence/insights) is the sole authority for
 * insight detection, significance, confidence, lifecycle and data
 * semantics. This page presents persisted insights and never derives or
 * upgrades anything itself.
 *
 * Honesty rules mirrored from the backend contract:
 * - Every stated limitation (unavailable evidence, insufficient pricing,
 *   simulated-only inputs) is shown, never hidden.
 * - Simulated evidence is labeled simulated end to end.
 * - No recommendations exist at this stage: recommendations are a later
 *   milestone (F7) and are never implied by an insight.
 */

const DEGRADED_STATUSES = ['STALE', 'DEGRADED', 'UNAVAILABLE']

const CATEGORY_FILTERS = [
    { id: 'ALL', label: 'All' },
    { id: 'WEATHER', label: 'Weather' },
    { id: 'SOLAR', label: 'Solar' },
    { id: 'CONSUMPTION', label: 'Consumption' },
    { id: 'ENERGY', label: 'Energy' },
    { id: 'COST', label: 'Cost' },
    { id: 'DATA_LIMITATION', label: 'Limitations' },
]

function normalizeError(err) {
    return err instanceof ApiError
        ? err
        : new ApiError({ code: 'UNEXPECTED_ERROR', message: 'Something went wrong.', status: 0 })
}

export function InsightsPage() {
    const [phase, setPhase] = useState('loading')
    const [insights, setInsights] = useState([])
    const [error, setError] = useState(null)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [selectedCategory, setSelectedCategory] = useState('ALL')

    const loadData = useCallback((showLoadingPhase = true) => {
        if (showLoadingPhase) {
            setPhase('loading')
        } else {
            setIsRefreshing(true)
        }
        setError(null)

        return getInsights()
            .then((response) => {
                setInsights(Array.isArray(response.data?.insights) ? response.data.insights : [])
                setPhase('ready')
            })
            .catch((err) => {
                setError(normalizeError(err))
                setPhase('error')
            })
            .finally(() => {
                setIsRefreshing(false)
            })
    }, [])

    useEffect(() => {
        let isMounted = true
        getInsights()
            .then((response) => {
                if (!isMounted) return
                setInsights(Array.isArray(response.data?.insights) ? response.data.insights : [])
                setPhase('ready')
            })
            .catch((err) => {
                if (!isMounted) return
                setError(normalizeError(err))
                setPhase('error')
            })
        return () => {
            isMounted = false
        }
    }, [])

    const retryLoad = () => {
        loadData(true)
    }

    const handleRefresh = () => {
        loadData(false)
    }

    const degraded = insights.some((insight) => DEGRADED_STATUSES.includes(insight.data_status))

    const counts = useMemo(() => {
        const acc = { ALL: insights.length }
        for (const item of insights) {
            acc[item.category] = (acc[item.category] || 0) + 1
        }
        return acc
    }, [insights])

    const filteredInsights = useMemo(() => {
        if (selectedCategory === 'ALL') return insights
        return insights.filter((i) => i.category === selectedCategory)
    }, [insights, selectedCategory])

    return (
        <div className="mx-auto flex max-w-6xl flex-col gap-6">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-heading">Insights</h1>
                    <p className="mt-1 text-sm text-muted">
                        Deterministic observations derived by the backend from your energy evidence. For actionable household suggestions, see Recommendations.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={handleRefresh}
                        disabled={isRefreshing || phase === 'loading'}
                        aria-label="Refresh insights"
                        className="flex items-center gap-1.5"
                    >
                        <RefreshCw
                            className={`size-3.5 ${isRefreshing ? 'animate-spin' : ''}`}
                            aria-hidden="true"
                        />
                        <span>Refresh</span>
                    </Button>
                    <Link to="/recommendations">
                        <Button variant="secondary" size="sm" className="flex items-center gap-1.5">
                            <Sparkles className="size-3.5 text-primary" aria-hidden="true" />
                            <span>View Recommendations</span>
                        </Button>
                    </Link>
                </div>
            </div>

            {phase === 'loading' && <LoadingState label="Loading insights" />}

            {phase === 'error' && (
                <ErrorState
                    title="Insights unavailable"
                    message={
                        error?.message ??
                        'This information could not be loaded. Other parts of the application are unaffected.'
                    }
                    retryLabel="Try again"
                    onRetry={retryLoad}
                />
            )}

            {phase === 'ready' && (
                <>
                    {degraded && (
                        <div
                            role="status"
                            className="rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning"
                        >
                            Some insights rest on stale, degraded, or unavailable evidence. The data status
                            shown on each insight is carried from the backend and is never upgraded here.
                        </div>
                    )}

                    {insights.length === 0 ? (
                        <EmptyState
                            variant="noInsight"
                            icon={Lightbulb}
                            explanation="No insights are available yet. Insight generation is backend-controlled: it runs only through the backend's internal evaluation path and is never triggered by ordinary page loads."
                            limitation="SPMS does not invent insights to fill the page. Insights appear here once controlled generation runs against real energy evidence."
                            action={
                                <div className="flex flex-wrap items-center justify-center gap-2">
                                    <Link to="/solar">
                                        <Button variant="secondary" size="sm">
                                            View Solar Telemetry
                                        </Button>
                                    </Link>
                                    <Link to="/bills">
                                        <Button variant="outline" size="sm">
                                            Review Bills
                                        </Button>
                                    </Link>
                                </div>
                            }
                        />
                    ) : (
                        <>
                            {/* Category Filter Tabs */}
                            <div className="flex items-center justify-between gap-2 overflow-x-auto border-b border-border pb-2">
                                <div className="flex items-center gap-1.5">
                                    <SlidersHorizontal className="size-4 text-muted" aria-hidden="true" />
                                    <span className="text-xs font-semibold uppercase tracking-wider text-muted">Category:</span>
                                </div>
                                <div className="flex items-center gap-1">
                                    {CATEGORY_FILTERS.map((tab) => {
                                        const count = counts[tab.id] || 0
                                        if (tab.id !== 'ALL' && count === 0) return null
                                        return (
                                            <button
                                                key={tab.id}
                                                type="button"
                                                onClick={() => setSelectedCategory(tab.id)}
                                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                                    selectedCategory === tab.id
                                                        ? 'bg-primary-soft font-semibold text-primary-active'
                                                        : 'text-muted hover:bg-surface-alt hover:text-heading'
                                                }`}
                                            >
                                                {tab.label}
                                                <span className="ml-1 opacity-75">({count})</span>
                                            </button>
                                        )
                                    })}
                                </div>
                            </div>

                            {filteredInsights.length === 0 ? (
                                <EmptyState
                                    variant="noResults"
                                    title="No matching insights"
                                    explanation={`There are no insights in the "${selectedCategory}" category for this household.`}
                                    action={
                                        <Button variant="secondary" size="sm" onClick={() => setSelectedCategory('ALL')}>
                                            Show all insights
                                        </Button>
                                    }
                                />
                            ) : (
                                <div className="flex flex-col gap-4" aria-live="polite">
                                    {filteredInsights.map((insight) => (
                                        <InsightCard key={insight.id} insight={insight} />
                                    ))}
                                </div>
                            )}
                        </>
                    )}

                    <p className="text-xs text-muted">
                        Insights are generated deterministically by the backend from F4/F5 energy evidence,
                        persisted with deduplication, and labeled with their data nature, status and quality.
                        Simulated evidence stays simulated end to end. This listing is read-only — ordinary
                        reads never create or update insights.
                    </p>
                </>
            )}
        </div>
    )
}
