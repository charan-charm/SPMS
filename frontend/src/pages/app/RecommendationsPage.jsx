import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link } from 'react-router'
import {
    Sparkles,
    RefreshCw,
    AlertTriangle,
    Lightbulb,
    SlidersHorizontal,
    Filter,
} from 'lucide-react'
import * as recommendationsApi from '../../services/api/recommendations'
import { RecommendationCard } from '../../features/recommendations/RecommendationCard'
import { RecommendationLimitationsCard } from '../../features/recommendations/RecommendationLimitationsCard'
import {
    CATEGORY_FILTERS,
    RECOMMENDATION_CATEGORIES,
    getRecommendationCategory,
} from '../../features/recommendations/recommendationCategories'
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
    const [categoryFilter, setCategoryFilter] = useState('ALL')
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
                const genRes = await recommendationsApi.generateRecommendations()
                const recList = Array.isArray(genRes?.data?.recommendations)
                    ? genRes.data.recommendations
                    : (Array.isArray(genRes?.recommendations) ? genRes.recommendations : [])
                setRecommendations(recList)
            } else {
                const res = await recommendationsApi.getRecommendations({ status })
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
                const res = await recommendationsApi.getRecommendations({ status: statusFilter })
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
            await recommendationsApi.dismissRecommendation(id)
            // Remove from active list locally
            setRecommendations((prev) => prev.filter((r) => r.id !== id))
        } catch (err) {
            setError(err.message || 'Failed to dismiss recommendation.')
        } finally {
            setDismissingId(null)
        }
    }

    // Separate actionable recommendations from limitation notices
    const rawActionable = recommendations.filter((r) => r.type !== 'DATA_LIMITATION')
    const rawLimitations = recommendations.filter((r) => r.type === 'DATA_LIMITATION')

    // Safeguard deduplication for ACTIVE view so re-evaluations never render duplicates
    const actionableRecommendations = statusFilter === 'ACTIVE'
        ? rawActionable.reduce((acc, rec) => {
            const key = rec.type
            if (!acc.some((existing) => existing.type === key)) {
                acc.push(rec)
            }
            return acc
        }, [])
        : rawActionable

    const limitationNotices = statusFilter === 'ACTIVE'
        ? rawLimitations.reduce((acc, notice) => {
            const key = notice.title || notice.type
            if (!acc.some((existing) => (existing.title || existing.type) === key)) {
                acc.push(notice)
            }
            return acc
        }, [])
        : rawLimitations

    // Calculate category counts across justified recommendations
    const categoryCounts = useMemo(() => {
        const acc = { ALL: actionableRecommendations.length + limitationNotices.length }
        for (const rec of actionableRecommendations) {
            const cat = getRecommendationCategory(rec).id
            acc[cat] = (acc[cat] || 0) + 1
        }
        for (const notice of limitationNotices) {
            const cat = getRecommendationCategory(notice).id
            acc[cat] = (acc[cat] || 0) + 1
        }
        return acc
    }, [actionableRecommendations, limitationNotices])

    // Filter by selected purpose category
    const filteredActionable = useMemo(() => {
        if (categoryFilter === 'ALL') return actionableRecommendations
        return actionableRecommendations.filter((rec) => getRecommendationCategory(rec).id === categoryFilter)
    }, [actionableRecommendations, categoryFilter])

    const filteredLimitations = useMemo(() => {
        if (categoryFilter === 'ALL') return limitationNotices
        return limitationNotices.filter((notice) => getRecommendationCategory(notice).id === categoryFilter)
    }, [limitationNotices, categoryFilter])

    const totalFilteredCount = filteredActionable.length + filteredLimitations.length
    const totalUnfilteredCount = actionableRecommendations.length + limitationNotices.length

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
                                <span className="font-semibold">Notice on Solar &amp; Bill Data: </span>
                                <span>
                                    Some recommendations reflect estimated demand or recent forecast data because live inverter telemetry or verified bills are still updating.
                                </span>
                            </div>
                        </div>
                    )}

                    {/* Status & Lifecycle Filter Tabs */}
                    <div className="flex items-center justify-between gap-2 overflow-x-auto border-b border-border pb-2">
                        <div className="flex items-center gap-1.5 shrink-0">
                            <SlidersHorizontal className="size-4 text-muted" aria-hidden="true" />
                            <span className="text-xs font-semibold uppercase tracking-wider text-muted">Status:</span>
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

                    {/* Category Purpose Filter Tabs (Part B) */}
                    {totalUnfilteredCount > 0 && (
                        <div
                            className="flex items-center justify-between gap-2 overflow-x-auto border-b border-border pb-2"
                            data-testid="category-filter-tabs"
                        >
                            <div className="flex items-center gap-1.5 shrink-0">
                                <Filter className="size-3.5 text-muted" aria-hidden="true" />
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted">Purpose:</span>
                            </div>
                            <div className="flex flex-wrap items-center gap-1">
                                {CATEGORY_FILTERS.map((tab) => {
                                    const count = categoryCounts[tab.id] || 0
                                    // Use only categories justified by existing recommendation types
                                    if (tab.id !== 'ALL' && count === 0) return null
                                    const Icon = tab.icon
                                    return (
                                        <button
                                            key={tab.id}
                                            type="button"
                                            onClick={() => setCategoryFilter(tab.id)}
                                            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                                categoryFilter === tab.id
                                                    ? 'bg-primary-soft font-semibold text-primary-active'
                                                    : 'text-muted hover:bg-surface-alt hover:text-heading'
                                            }`}
                                        >
                                            {Icon && <Icon className="size-3.5" aria-hidden="true" />}
                                            <span>{tab.label}</span>
                                            <span className="ml-0.5 opacity-75">({count})</span>
                                        </button>
                                    )
                                })}
                            </div>
                        </div>
                    )}

                    {/* Data Limitation Notices if any */}
                    {filteredLimitations.length > 0 && (
                        <div className="space-y-3" data-testid="limitation-notices-container">
                            {filteredLimitations.map((notice) => (
                                <RecommendationLimitationsCard key={notice.id} notice={notice} />
                            ))}
                        </div>
                    )}

                    {/* Actionable Recommendations List */}
                    {filteredActionable.length > 0 ? (
                        <div className="space-y-4" data-testid="actionable-recommendations-container">
                            {filteredActionable.map((rec) => (
                                <RecommendationCard
                                    key={rec.id}
                                    recommendation={rec}
                                    onDismiss={handleDismiss}
                                    isDismissing={dismissingId === rec.id}
                                    isHistorical={statusFilter !== 'ACTIVE' || rec.status !== 'ACTIVE'}
                                />
                            ))}
                        </div>
                    ) : totalFilteredCount === 0 && totalUnfilteredCount > 0 ? (
                        /* Filtered category has 0 results */
                        <EmptyState
                            variant="noResults"
                            title="No matching recommendations"
                            explanation={`There are no active recommendations in the "${RECOMMENDATION_CATEGORIES[categoryFilter]?.label || categoryFilter}" category.`}
                            action={
                                <Button variant="secondary" size="sm" onClick={() => setCategoryFilter('ALL')}>
                                    Show all recommendations
                                </Button>
                            }
                        />
                    ) : (
                        /* Entire list is empty */
                        totalUnfilteredCount === 0 && (
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
