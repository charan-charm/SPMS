import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router'
import {
    Sparkles,
    RefreshCw,
    AlertTriangle,
    Lightbulb,
    CheckCircle,
    SlidersHorizontal,
} from 'lucide-react'
import {
    getRecommendations,
    generateRecommendations,
    dismissRecommendation,
} from '../../services/api/recommendations'
import { RecommendationCard } from '../../features/recommendations/RecommendationCard'
import { RecommendationLimitationsCard } from '../../features/recommendations/RecommendationLimitationsCard'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { Button } from '../../components/ui/button'

const STATUS_FILTERS = [
    { id: 'ACTIVE', label: 'Active Recommendations' },
    { id: 'ALL', label: 'All History' },
    { id: 'DISMISSED', label: 'Dismissed' },
]

export function RecommendationsPage() {
    const [statusFilter, setStatusFilter] = useState('ACTIVE')
    const [recommendations, setRecommendations] = useState([])
    const [isLoading, setIsLoading] = useState(true)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [dismissingId, setDismissingId] = useState(null)
    const [error, setError] = useState(null)

    const fetchRecommendations = useCallback(async (status = 'ACTIVE', forceFresh = false) => {
        if (forceFresh) {
            setIsRefreshing(true)
        }
        setError(null)

        try {
            if (forceFresh) {
                const genRes = await generateRecommendations()
                const recList = Array.isArray(genRes?.data?.recommendations)
                    ? genRes.data.recommendations
                    : (Array.isArray(genRes?.recommendations) ? genRes.recommendations : [])
                setRecommendations(recList)
            } else {
                const res = await getRecommendations({ status })
                const recList = Array.isArray(res?.data?.recommendations)
                    ? res.data.recommendations
                    : (Array.isArray(res?.recommendations) ? res.recommendations : [])
                setRecommendations(recList)
            }
        } catch (err) {
            setError(err.message || 'Failed to load recommendations.')
        } finally {
            setIsLoading(false)
            setIsRefreshing(false)
        }
    }, [])

    useEffect(() => {
        let mounted = true
        const load = async () => {
            setError(null)
            try {
                const res = await getRecommendations({ status: statusFilter })
                if (mounted) {
                    const recList = Array.isArray(res?.data?.recommendations)
                        ? res.data.recommendations
                        : (Array.isArray(res?.recommendations) ? res.recommendations : [])
                    setRecommendations(recList)
                }
            } catch (err) {
                if (mounted) {
                    setError(err.message || 'Failed to load recommendations.')
                }
            } finally {
                if (mounted) {
                    setIsLoading(false)
                }
            }
        }
        load()
        return () => {
            mounted = false
        }
    }, [statusFilter])

    const handleRefresh = () => {
        if (isRefreshing || isLoading) return
        fetchRecommendations(statusFilter, true)
    }

    const handleDismiss = async (id) => {
        setDismissingId(id)
        try {
            await dismissRecommendation(id)
            // Remove from active list locally
            setRecommendations((prev) => prev.filter((r) => r.id !== id))
        } catch (err) {
            setError(err.message || 'Failed to dismiss recommendation.')
        } finally {
            setDismissingId(null)
        }
    }

    // Separate actionable recommendations from limitation notices
    const actionableRecommendations = recommendations.filter((r) => r.type !== 'DATA_LIMITATION')
    const limitationNotices = recommendations.filter((r) => r.type === 'DATA_LIMITATION')
    const hasDegraded = recommendations.some((r) => r.quality === 'DEGRADED' || r.quality === 'STALE')

    return (
        <div className="mx-auto flex max-w-5xl flex-col gap-6">
            {/* Header & Page Context */}
            <div className="flex flex-col justify-between gap-4 border-b border-border pb-5 sm:flex-row sm:items-center">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-heading sm:text-3xl">
                        Recommendations & Advisory
                    </h1>
                    <p className="mt-1 text-sm text-muted">
                        Actionable energy guidance tailored to your solar availability and household demand.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={handleRefresh}
                        disabled={isRefreshing || isLoading}
                        className="flex items-center gap-1.5"
                    >
                        <RefreshCw
                            className={`size-3.5 ${isRefreshing ? 'animate-spin text-primary' : ''}`}
                            aria-hidden="true"
                        />
                        <span>{isRefreshing ? 'Evaluating...' : 'Re-evaluate Recommendations'}</span>
                    </Button>

                    <Link to="/insights">
                        <Button variant="tertiary" size="sm" className="flex items-center gap-1.5">
                            <Lightbulb className="size-3.5 text-secondary" aria-hidden="true" />
                            <span>View Insights</span>
                        </Button>
                    </Link>
                </div>
            </div>

            {isLoading && recommendations.length === 0 ? (
                <div className="py-12">
                    <SPMSLoadingState
                        title="Evaluating Household Recommendations"
                        subtitle="Analyzing solar availability, household demand, and balance outlook..."
                        variant="card"
                    />
                </div>
            ) : (
                <>
                    {/* Error State if any */}
                    {error && (
                        <ErrorState
                            title="Could not load recommendations"
                            message={error}
                            retryLabel="Try again"
                            onRetry={() => fetchRecommendations(statusFilter, false)}
                        />
                    )}

            {/* Degraded Evidence Notice Banner */}
            {hasDegraded && (
                <div
                    role="status"
                    className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/10 p-3.5 text-sm text-warning"
                >
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div>
                        <span className="font-semibold">Degraded Evidence Notice: </span>
                        <span>
                            Some recommendations reflect stale telemetry or partial billing history. Values and confidence are honestly marked and degrade gracefully.
                        </span>
                    </div>
                </div>
            )}

            {/* Status Filter Tabs */}
            <div className="flex items-center justify-between gap-2 overflow-x-auto border-b border-border pb-2">
                <div className="flex items-center gap-1.5">
                    <SlidersHorizontal className="size-4 text-muted" aria-hidden="true" />
                    <span className="text-xs font-semibold uppercase tracking-wider text-muted">Filter:</span>
                </div>
                <div className="flex items-center gap-1">
                    {STATUS_FILTERS.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => setStatusFilter(tab.id)}
                            className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                statusFilter === tab.id
                                    ? 'bg-primary-soft font-semibold text-primary-active'
                                    : 'text-muted hover:bg-surface-alt hover:text-heading'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Data Limitation Notices if any */}
            {limitationNotices.length > 0 && (
                <div className="space-y-3">
                    {limitationNotices.map((notice) => (
                        <RecommendationLimitationsCard key={notice.id} notice={notice} />
                    ))}
                </div>
            )}

            {/* Actionable Recommendations List */}
            {actionableRecommendations.length > 0 ? (
                <div className="space-y-4">
                    {actionableRecommendations.map((rec) => (
                        <RecommendationCard
                            key={rec.id}
                            recommendation={rec}
                            onDismiss={handleDismiss}
                            isDismissing={dismissingId === rec.id}
                        />
                    ))}
                </div>
            ) : (
                limitationNotices.length === 0 && (
                    <EmptyState
                        variant="noInsight"
                        icon={Sparkles}
                        title="No Active Recommendations"
                        explanation="Your energy profile is currently balanced. Check back as new solar forecasts or bill cycles become available."
                        limitation="Advisory recommendations require verified bills and active solar telemetry to support evidence-backed guidance."
                        action={
                            <div className="flex items-center gap-3">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={handleRefresh}
                                    disabled={isRefreshing}
                                >
                                    Check Now
                                </Button>
                                <Link to="/forecasts">
                                    <Button variant="tertiary" size="sm">
                                        View Energy Outlook
                                    </Button>
                                </Link>
                            </div>
                        }
                    />
                )
            )}
                </>
            )}
        </div>
    )
}
