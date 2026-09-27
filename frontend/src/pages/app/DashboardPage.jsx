import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router'
import {
    Sun,
    Zap,
    TrendingUp,
    Sparkles,
    FileText,
    ArrowRight,
    RefreshCw,
    ShieldCheck,
    Scale,
    IndianRupee,
    AlertCircle,
    Info,
    Calendar,
} from 'lucide-react'
import { useHousehold } from '../../features/household/useHousehold'
import { useAuth } from '../../features/auth/useAuth'
import { solarApi } from '../../services/api/solar'
import { listBills } from '../../services/api/bills'
import { getForecasts } from '../../services/api/forecast'
import { getRecommendations } from '../../services/api/recommendations'
import { getInsights } from '../../services/api/intelligence'
import { MetricCard } from '../../components/data-display/MetricCard'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { StatusBadge } from '../../components/data-display/StatusBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { RecommendationCard } from '../../features/recommendations/RecommendationCard'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { Button } from '../../components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'

export function DashboardPage() {
    const { household } = useHousehold()
    const { account } = useAuth()
    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'

    const [isLoading, setIsLoading] = useState(true)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [solarLatest, setSolarLatest] = useState(null)
    const [billsSummary, setBillsSummary] = useState({ total: 0, verified: 0, latestVerified: null })
    const [forecastData, setForecastData] = useState(null)
    const [recommendations, setRecommendations] = useState([])
    const [insightsCount, setInsightsCount] = useState(0)
    const [error, setError] = useState(null)

    const loadDashboardData = useCallback(async (isRefresh = false) => {
        if (isRefresh) {
            setIsRefreshing(true)
        } else {
            setIsLoading(true)
        }
        setError(null)

        try {
            const [solarRes, billsRes, forecastRes, recsRes, insightsRes] = await Promise.allSettled([
                solarApi.getLatest().catch(() => null),
                listBills().catch(() => null),
                getForecasts({ horizon: 'DAY_AHEAD' }).catch(() => null),
                getRecommendations({ status: 'ACTIVE' }).catch(() => null),
                getInsights().catch(() => null),
            ])

            // Solar telemetry
            if (solarRes.status === 'fulfilled' && solarRes.value?.latest) {
                setSolarLatest(solarRes.value.latest)
            } else {
                setSolarLatest(null)
            }

            // Bills summary
            if (billsRes.status === 'fulfilled' && Array.isArray(billsRes.value?.data?.bills)) {
                const bList = billsRes.value.data.bills
                const vList = bList.filter((b) => b.status === 'VERIFIED')
                setBillsSummary({
                    total: bList.length,
                    verified: vList.length,
                    latestVerified: vList.length > 0 ? vList[0] : null,
                })
            }

            // Forecast outlook
            if (forecastRes.status === 'fulfilled' && forecastRes.value?.data) {
                setForecastData(forecastRes.value.data)
            }

            // Recommendations
            if (recsRes.status === 'fulfilled') {
                const rList = Array.isArray(recsRes.value?.data?.recommendations)
                    ? recsRes.value.data.recommendations
                    : (Array.isArray(recsRes.value?.recommendations) ? recsRes.value.recommendations : [])
                setRecommendations(rList)
            }

            // Insights
            if (insightsRes.status === 'fulfilled') {
                const iCount = insightsRes.value?.data?.count ?? (Array.isArray(insightsRes.value?.data?.insights) ? insightsRes.value.data.insights.length : 0)
                setInsightsCount(iCount)
            }
        } catch (err) {
            setError(err.message || 'Failed to load household dashboard.')
        } finally {
            setIsLoading(false)
            setIsRefreshing(false)
        }
    }, [])

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        loadDashboardData()
    }, [loadDashboardData])

    if (isLoading && !forecastData && !solarLatest) {
        return (
            <div className="py-12">
                <SPMSLoadingState
                    title="Loading Household Dashboard"
                    subtitle="Aggregating solar telemetry, verified billing, and forecast models..."
                    variant="card"
                />
            </div>
        )
    }

    const solarForecast = forecastData?.solar_forecast
    const consumptionForecast = forecastData?.consumption_forecast
    const energyBalance = forecastData?.energy_balance_projection
    const energyCost = forecastData?.energy_cost_projection

    const topRecommendation = recommendations.length > 0 ? recommendations[0] : null

    return (
        <div className="space-y-6 pb-12">
            {/* Page Header */}
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="font-heading text-2xl font-bold tracking-tight text-text-primary md:text-3xl">
                            {household?.label ? `${household.label} Household` : 'Household Overview'}
                        </h1>
                        {isDemo ? (
                            <span className="inline-flex items-center gap-1 rounded-full border border-chart-demo/40 bg-chart-demo/10 px-2.5 py-0.5 text-xs font-semibold text-chart-demo">
                                <Sparkles className="h-3.5 w-3.5" />
                                <span>Demo Mode (Simulated)</span>
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">
                                <ShieldCheck className="h-3.5 w-3.5" />
                                <span>Real Mode</span>
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-text-muted">
                        {household?.service_number ? `Service No: ${household.service_number} · ` : ''}APSPDCL Tirupati · {household?.timezone || 'Asia/Kolkata'}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => loadDashboardData(true)}
                        disabled={isRefreshing}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-border/80 bg-surface/80 px-3.5 py-2 text-xs font-medium text-text-secondary transition-colors hover:bg-surface-subtle hover:text-text-primary disabled:opacity-50 shadow-sm"
                        title="Refresh live data"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin text-emerald-400' : ''}`} />
                        <span>{isRefreshing ? 'Refreshing...' : 'Refresh'}</span>
                    </button>
                </div>
            </div>

            {error && (
                <div className="flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-300">
                    <AlertCircle className="h-4 w-4 shrink-0 text-rose-400" />
                    <span>{error}</span>
                </div>
            )}

            {/* Guided Setup Continuation Banner (when optional setup steps remain) */}
            {(!solarLatest || billsSummary.total === 0 || !household?.service_number) && (
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3.5 rounded-xl border border-primary/25 bg-primary/5 p-4 shadow-sm">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/20 text-primary">
                            <Sparkles className="size-4" aria-hidden="true" />
                        </div>
                        <div className="space-y-0.5">
                            <div className="flex items-center gap-2">
                                <h3 className="text-sm font-semibold text-text-primary">Complete Your Household Setup</h3>
                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                                    Guided Setup
                                </span>
                            </div>
                            <p className="text-xs text-text-muted">
                                {!household?.service_number
                                    ? 'Set your electricity service number to verify tariffs and cross-match bills.'
                                    : !solarLatest
                                      ? 'Link your GoodWe inverter to activate live solar telemetry and solar forecast outlooks.'
                                      : 'Upload your APSPDCL bill statements to unlock historical analytics and tariff auditing.'}
                            </p>
                        </div>
                    </div>

                    <Link
                        to="/onboarding?continue=true"
                        className="inline-flex items-center justify-center gap-1.5 self-start sm:self-auto rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-primary/90 transition-colors"
                    >
                        <span>Resume Setup</span>
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
            )}

            {/* Subsystem Quick-Status Bar */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Link
                    to="/solar"
                    className="flex flex-col p-3 rounded-xl border border-border/70 bg-surface/60 hover:bg-surface/90 hover:border-emerald-500/40 transition-all group"
                >
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">GoodWe Inverter</span>
                        <Sun className="h-3.5 w-3.5 text-emerald-400 group-hover:scale-110 transition-transform" />
                    </div>
                    <div className="mt-1 text-sm font-semibold text-text-primary flex items-center gap-1.5">
                        {solarLatest ? (
                            <>
                                <span>{Number(solarLatest.daily_generation_kwh || 0).toFixed(1)} kWh today</span>
                                <span className={`size-1.5 rounded-full ${solarLatest.data_status === 'STALE' ? 'bg-amber-400' : 'bg-emerald-400'}`} />
                            </>
                        ) : (
                            <span>Connected</span>
                        )}
                    </div>
                    <span className="text-[11px] text-text-muted">
                        {solarLatest?.data_status === 'STALE' ? 'Telemetry Stale' : 'Inverter Ready'}
                    </span>
                </Link>

                <Link
                    to="/bills"
                    className="flex flex-col p-3 rounded-xl border border-border/70 bg-surface/60 hover:bg-surface/90 hover:border-sky-500/40 transition-all group"
                >
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">APSPDCL Bills</span>
                        <FileText className="h-3.5 w-3.5 text-sky-400 group-hover:scale-110 transition-transform" />
                    </div>
                    <div className="mt-1 text-sm font-semibold text-text-primary">
                        {billsSummary.verified} Verified Cycle{billsSummary.verified === 1 ? '' : 's'}
                    </div>
                    <span className="text-[11px] text-text-muted">
                        {billsSummary.total} Total Statements
                    </span>
                </Link>

                <Link
                    to="/forecasts"
                    className="flex flex-col p-3 rounded-xl border border-border/70 bg-surface/60 hover:bg-surface/90 hover:border-teal-500/40 transition-all group"
                >
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Energy Forecast</span>
                        <TrendingUp className="h-3.5 w-3.5 text-teal-400 group-hover:scale-110 transition-transform" />
                    </div>
                    <div className="mt-1 text-sm font-semibold text-text-primary">
                        {energyBalance?.classification === 'POTENTIAL_SURPLUS' ? 'Potential Surplus' : energyBalance?.classification || 'Available'}
                    </div>
                    <span className="text-[11px] text-text-muted">
                        Day-Ahead Projection
                    </span>
                </Link>

                <Link
                    to="/recommendations"
                    className="flex flex-col p-3 rounded-xl border border-border/70 bg-surface/60 hover:bg-surface/90 hover:border-amber-500/40 transition-all group"
                >
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Advisory</span>
                        <Sparkles className="h-3.5 w-3.5 text-amber-400 group-hover:scale-110 transition-transform" />
                    </div>
                    <div className="mt-1 text-sm font-semibold text-text-primary">
                        {recommendations.length} Active Advice
                    </div>
                    <span className="text-[11px] text-text-muted">
                        {insightsCount} Insights evaluated
                    </span>
                </Link>
            </div>

            {/* Main KPI Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* 1. Solar Generation */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Solar Generation Today</span>
                        <Sun className="h-4 w-4 text-emerald-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {solarLatest?.daily_generation_kwh !== undefined
                                ? `${Number(solarLatest.daily_generation_kwh).toFixed(2)} kWh`
                                : (solarForecast?.predicted_value !== undefined ? `${solarForecast.predicted_value.toFixed(2)} kWh` : '--')}
                        </div>
                        <div className="text-[11px] text-text-muted mt-0.5">
                            Total lifetime: {solarLatest?.total_generation_kwh ? `${Number(solarLatest.total_generation_kwh).toFixed(1)} kWh` : '--'}
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={solarLatest?.data_nature || 'ACTUAL'} />
                        <StatusBadge status={solarLatest?.data_status || 'STALE'} />
                    </div>
                </div>

                {/* 2. Projected Household Demand */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Projected Day Demand</span>
                        <Zap className="h-4 w-4 text-sky-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {consumptionForecast?.predicted_value !== undefined && consumptionForecast.predicted_value !== null
                                ? `${consumptionForecast.predicted_value.toFixed(2)} kWh`
                                : 'Evaluating'}
                        </div>
                        <div className="text-[11px] text-text-muted mt-0.5">
                            Based on {billsSummary.verified} verified APSPDCL bill{billsSummary.verified === 1 ? '' : 's'}
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={consumptionForecast?.data_nature || 'ESTIMATED'} />
                        <ConfidenceIndicator confidence={consumptionForecast?.confidence || 'MODERATE'} />
                    </div>
                </div>

                {/* 3. Projected Energy Balance */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">
                            {energyBalance?.classification === 'POTENTIAL_SURPLUS'
                                ? 'Potential Surplus'
                                : (energyBalance?.classification === 'POTENTIAL_DEFICIT' ? 'Potential Deficit' : 'Projected Balance')}
                        </span>
                        <Scale className="h-4 w-4 text-teal-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {energyBalance?.balance_kwh !== undefined && energyBalance.balance_kwh !== null
                                ? `${Math.abs(energyBalance.balance_kwh).toFixed(2)} kWh`
                                : '--'}
                        </div>
                        <div className={`text-[11px] mt-0.5 font-medium ${
                            energyBalance?.classification === 'POTENTIAL_DEFICIT'
                                ? 'text-amber-400'
                                : energyBalance?.classification === 'POTENTIAL_SURPLUS'
                                    ? 'text-emerald-400'
                                    : 'text-text-muted'
                        }`}>
                            {energyBalance?.classification === 'POTENTIAL_SURPLUS'
                                ? 'Potential Surplus · Midday opportunity'
                                : energyBalance?.classification === 'POTENTIAL_DEFICIT'
                                    ? 'Potential Deficit · Evening demand'
                                    : (energyBalance?.classification || 'Evaluating')}
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={energyBalance?.data_nature || 'PREDICTED'} />
                        <ConfidenceIndicator confidence={energyBalance?.confidence || 'LOW'} />
                    </div>
                </div>

                {/* 4. Estimated Energy Cost */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Estimated Future Cost</span>
                        <IndianRupee className="h-4 w-4 text-emerald-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {energyCost?.estimated_cost_inr !== undefined && energyCost.estimated_cost_inr !== null
                                ? `₹${Number(energyCost.estimated_cost_inr).toFixed(2)}`
                                : '--'}
                        </div>
                        <div className="text-[11px] text-text-muted mt-0.5">
                            {energyCost?.estimated_cost_inr === null || energyCost?.estimated_cost_inr === undefined
                                ? 'Cost estimate unavailable'
                                : energyCost.estimated_cost_inr === 0
                                    ? 'Surplus covers estimated demand'
                                    : 'Potential grid energy cost'}
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature="ESTIMATED" />
                        <span className="text-[11px] text-text-muted font-medium">Estimated (INR)</span>
                    </div>
                </div>
            </div>

            {/* Top Actionable Recommendation Highlight */}
            {topRecommendation && (
                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-semibold text-text-primary flex items-center gap-2">
                            <Sparkles className="h-4 w-4 text-amber-400" />
                            <span>Recommended Household Action</span>
                        </h2>
                        <Link
                            to="/recommendations"
                            className="text-xs font-semibold text-primary hover:underline flex items-center gap-1"
                        >
                            <span>View All Recommendations ({recommendations.length})</span>
                            <ArrowRight className="h-3 w-3" />
                        </Link>
                    </div>
                    <RecommendationCard
                        recommendation={topRecommendation}
                        onDismiss={() => loadDashboardData(true)}
                    />
                </div>
            )}

            {/* Subsystem Deep Dive Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {/* Solar & Inverter Card */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Sun className="h-4 w-4 text-emerald-400" />
                                <span>GoodWe Telemetry</span>
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Live & persisted solar generation metrics
                            </CardDescription>
                        </div>
                        <Link
                            to="/solar"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                        >
                            <span>Solar Page</span>
                            <ArrowRight className="h-3 w-3" />
                        </Link>
                    </CardHeader>
                    <CardContent className="space-y-3 pt-2">
                        <div className="grid grid-cols-2 gap-3 text-sm">
                            <div className="rounded-lg bg-surface-alt p-3 border border-border/50">
                                <span className="text-xs text-text-muted block">Inverter SN</span>
                                <span className="font-mono text-xs font-medium text-text-primary">
                                    {solarLatest?.inverter_sn || '--'}
                                </span>
                            </div>
                            <div className="rounded-lg bg-surface-alt p-3 border border-border/50">
                                <span className="text-xs text-text-muted block">Internal Temp</span>
                                <span className="text-xs font-medium text-text-primary">
                                    {solarLatest?.temperature_c ? `${solarLatest.temperature_c}°C` : '--'}
                                </span>
                            </div>
                        </div>
                        <p className="text-xs text-text-muted flex items-start gap-1.5">
                            <Info className="h-3.5 w-3.5 text-text-muted shrink-0 mt-0.5" />
                            <span>Telemetry provenance is GOODWE_SEMS. Instantaneous inverter readings remain separate from utility settlement evidence.</span>
                        </p>
                    </CardContent>
                </Card>

                {/* Verified Billing Card */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-4 w-4 text-sky-400" />
                                <span>APSPDCL Electricity Bills</span>
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Authoritative monthly utility settlement history
                            </CardDescription>
                        </div>
                        <Link
                            to="/bills"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                        >
                            <span>Bills Page</span>
                            <ArrowRight className="h-3 w-3" />
                        </Link>
                    </CardHeader>
                    <CardContent className="space-y-3 pt-2">
                        <div className="grid grid-cols-2 gap-3 text-sm">
                            <div className="rounded-lg bg-surface-alt p-3 border border-border/50">
                                <span className="text-xs text-text-muted block">Verified Statements</span>
                                <span className="text-sm font-semibold text-text-primary">
                                    {billsSummary.verified} of {billsSummary.total} recognized
                                </span>
                            </div>
                            <div className="rounded-lg bg-surface-alt p-3 border border-border/50">
                                <span className="text-xs text-text-muted block">Latest Period</span>
                                <span className="text-xs font-medium text-text-primary">
                                    {billsSummary.latestVerified
                                        ? `${billsSummary.latestVerified.values?.billing_period_start || ''} → ${billsSummary.latestVerified.values?.billing_period_end || ''}`
                                        : '--'}
                                </span>
                            </div>
                        </div>
                        <p className="text-xs text-text-muted flex items-start gap-1.5">
                            <Info className="h-3.5 w-3.5 text-text-muted shrink-0 mt-0.5" />
                            <span>Verified bills provide the empirical consumption baseline for forecasting and energy balance calculations.</span>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    )
}
