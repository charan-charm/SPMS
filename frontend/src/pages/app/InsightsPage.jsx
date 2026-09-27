import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router'
import { Lightbulb, Sparkles } from 'lucide-react'
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

function normalizeError(err) {
    return err instanceof ApiError
        ? err
        : new ApiError({ code: 'UNEXPECTED_ERROR', message: 'Something went wrong.', status: 0 })
}

export function InsightsPage() {
    const [phase, setPhase] = useState('loading')
    const [insights, setInsights] = useState([])
    const [error, setError] = useState(null)

    const loadInsights = useCallback(() => {
        getInsights()
            .then((response) => {
                setInsights(Array.isArray(response.data?.insights) ? response.data.insights : [])
                setPhase('ready')
            })
            .catch((err) => {
                setError(normalizeError(err))
                setPhase('error')
            })
    }, [])

    useEffect(() => {
        loadInsights()
    }, [loadInsights])

    const retryLoad = () => {
        setPhase('loading')
        setError(null)
        loadInsights()
    }

    const degraded = insights.some((insight) => DEGRADED_STATUSES.includes(insight.data_status))

    return (
        <div className="mx-auto flex max-w-6xl flex-col gap-6">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-heading">Insights</h1>
                    <p className="mt-1 text-sm text-muted">
                        Deterministic observations derived by the backend from your energy evidence. For actionable household suggestions, see Recommendations.
                    </p>
                </div>
                <Link to="/recommendations">
                    <Button variant="secondary" size="sm" className="flex items-center gap-1.5">
                        <Sparkles className="size-3.5 text-primary" aria-hidden="true" />
                        <span>View Recommendations</span>
                    </Button>
                </Link>
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
                        />
                    ) : (
                        <div className="flex flex-col gap-4" aria-live="polite">
                            {insights.map((insight) => (
                                <InsightCard key={insight.id} insight={insight} />
                            ))}
                        </div>
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
