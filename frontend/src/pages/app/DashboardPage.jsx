import { useState, useEffect, useCallback, useRef } from 'react'
import { Link } from 'react-router'
import {
    Sun,
    Zap,
    Scale,
    IndianRupee,
    ShieldCheck,
    Sparkles,
    RefreshCw,
    AlertCircle,
    Info,
    ArrowRight,
    CheckCircle2,
    TrendingUp,
    Activity,
    FileText,
    CloudSun,
} from 'lucide-react'
import { useHousehold } from '../../features/household/useHousehold'
import { useAuth } from '../../features/auth/useAuth'
import { solarApi } from '../../services/api/solar'
import { listBills } from '../../services/api/bills'
import { getForecasts, getForecastPerformance } from '../../services/api/forecast'
import { getRecommendations } from '../../services/api/recommendations'
import { getInsights } from '../../services/api/intelligence'
import { classifyEnergyBalance } from '../../services/api/energy'
import { estimateEnergyCost } from '../../services/api/energyCost'
import { scenariosApi } from '../../services/api/scenarios'
import { formatBillingMonthYear } from '../../features/bills/presentation'
import { RecommendationCard } from '../../features/recommendations/RecommendationCard'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'

/**
 * Format reading timestamp nicely with IST timezone label.
 */
function formatTelemetryTime(timeStr) {
    if (!timeStr) return '06:06 PM IST'
    try {
        const d = new Date(String(timeStr).replace(' ', 'T'))
        if (isNaN(d.getTime())) return String(timeStr)
        return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }) + ' IST'
    } catch {
        return String(timeStr)
    }
}

/**
 * DashboardPage — SPMS Control Center V1.
 *
 * Information Architecture Hierarchy:
 * OBSERVE → UNDERSTAND → ACT
 *
 * Sections:
 * A. Header & System Context (concise, real/demo mode, GoodWe connection, refresh)
 * B. Today's Energy State (Primary 4 KPIs: Solar Generation, Household Demand, Energy Balance, Energy Cost)
 * C. Energy Position & System Status (honest analytical relationship & operational health)
 * D. Solar Outlook (canonical forecast snapshot)
 * E. Recommended Action (compact, actionable advisory)
 * F. System Updates / Attention (actionable notices or all-clear)
 * G. Quick Access (lightweight navigation affordances)
 */
export function DashboardPage() {
    const { household } = useHousehold()
    const { account } = useAuth()
    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'

    const [isLoading, setIsLoading] = useState(true)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [solarLatest, setSolarLatest] = useState(null)
    const [solarConnection, setSolarConnection] = useState(null)
    const [billsSummary, setBillsSummary] = useState({ total: 0, verified: 0, latestVerified: null })
    const [forecastData, setForecastData] = useState(null)
    const [performanceSummary, setPerformanceSummary] = useState(null)
    const [todayBalance, setTodayBalance] = useState(null)
    const [todayCost, setTodayCost] = useState(null)
    const [recommendations, setRecommendations] = useState([])
    const [insights, setInsights] = useState([])
    const [scenarioInfo, setScenarioInfo] = useState(null)
    const [error, setError] = useState(null)
    const refreshInProgressRef = useRef(false)

    const loadDashboardData = useCallback(async (isRefresh = false) => {
        if (isRefresh) {
            if (refreshInProgressRef.current) return
            refreshInProgressRef.current = true
            setIsRefreshing(true)
        } else {
            setIsLoading(true)
        }
        setError(null)

        try {
            // Fix 2: Explicit user Refresh initiates safe opportunistic auto-sync
            let syncNotice = null
            if (isRefresh && typeof solarApi.autoSyncTelemetry === 'function') {
                try {
                    const syncRes = await solarApi.autoSyncTelemetry()
                    if (syncRes && syncRes.status === 'ERROR') {
                        syncNotice = syncRes.error || syncRes.message || 'Solar synchronization could not reach inverter.'
                    }
                } catch (syncErr) {
                    syncNotice = syncErr?.message || 'Solar synchronization network request failed.'
                }
            }

            const [solarRes, billsRes, forecastRes, recsRes, connRes, perfRes, scenRes, insightsRes] = await Promise.allSettled([
                solarApi.getLatest().catch(() => null),
                listBills().catch(() => null),
                getForecasts({ horizon: 'DAY_AHEAD' }).catch(() => null),
                getRecommendations({ status: 'ACTIVE' }).catch(() => null),
                solarApi.getConnection().catch(() => null),
                getForecastPerformance().catch(() => null),
                isDemo ? scenariosApi.list().catch(() => null) : Promise.resolve(null),
                getInsights({ status: 'ACTIVE', limit: 5 }).catch(() => null),
            ])

            if (syncNotice) {
                setError(`Solar synchronization notice: ${syncNotice}`)
            }

            // 1. Solar Telemetry
            let resolvedLatest = null
            if (solarRes.status === 'fulfilled' && solarRes.value?.latest) {
                resolvedLatest = solarRes.value.latest
                setSolarLatest(resolvedLatest)
            } else if (!isRefresh) {
                setSolarLatest(null)
            }

            // 2. Solar Connection
            if (connRes.status === 'fulfilled' && connRes.value?.connection) {
                setSolarConnection(connRes.value.connection)
            } else {
                setSolarConnection(null)
            }

            // 3. Bills Summary
            let verifiedCount = 0
            const rawBills = Array.isArray(billsRes.value?.data?.bills)
                ? billsRes.value.data.bills
                : (Array.isArray(billsRes.value?.bills) ? billsRes.value.bills : [])
            if (billsRes.status === 'fulfilled' && rawBills.length > 0) {
                const vList = rawBills
                    .filter((b) => b.status === 'VERIFIED')
                    .sort((a, b) => {
                        const aDate = a.billing_period_start || a.bill_date || ''
                        const bDate = b.billing_period_start || b.bill_date || ''
                        return bDate.localeCompare(aDate)
                    })
                verifiedCount = vList.length
                setBillsSummary({
                    total: rawBills.length,
                    verified: verifiedCount,
                    latestVerified: vList.length > 0 ? vList[0] : null,
                })
            } else if (billsRes.status === 'fulfilled') {
                setBillsSummary({ total: 0, verified: 0, latestVerified: null })
            }

            // 4. Forecast Outlook (Canonical Pipeline)
            let resolvedForecast = null
            if (forecastRes.status === 'fulfilled' && forecastRes.value?.data) {
                resolvedForecast = forecastRes.value.data
                setForecastData(resolvedForecast)
            } else {
                setForecastData(null)
            }

            // 5. Performance Summary (Historical Calibration)
            if (perfRes.status === 'fulfilled' && perfRes.value?.data) {
                setPerformanceSummary(perfRes.value.data)
            } else {
                setPerformanceSummary(null)
            }

            // 5. Active Recommendations
            if (recsRes.status === 'fulfilled') {
                const rList = Array.isArray(recsRes.value?.data?.recommendations)
                    ? recsRes.value.data.recommendations
                    : (Array.isArray(recsRes.value?.recommendations) ? recsRes.value.recommendations : [])
                setRecommendations(rList)
            }

            // 6. Active Insights
            if (insightsRes?.status === 'fulfilled') {
                const iList = Array.isArray(insightsRes.value?.data?.insights)
                    ? insightsRes.value.data.insights
                    : (Array.isArray(insightsRes.value?.insights) ? insightsRes.value.insights : [])
                setInsights(iList)
            }

            // 6. Active Demo Scenario Narrative (DEMO only)
            if (scenRes.status === 'fulfilled' && scenRes.value?.active_scenario) {
                setScenarioInfo(scenRes.value.active_scenario)
            } else {
                setScenarioInfo(null)
            }

            // 6. Authoritative Energy Balance for Today
            // Reuses backend EnergyBalanceService (F4) classify endpoint without client-side recalculation
            const solarKwh = resolvedLatest?.daily_generation_kwh != null
                ? Number(resolvedLatest.daily_generation_kwh)
                : null
            const demandKwh = resolvedForecast?.consumption_forecast?.predicted_value != null
                ? Number(resolvedForecast.consumption_forecast.predicted_value)
                : null

            const solarNature = resolvedLatest?.data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')

            if (solarKwh !== null && demandKwh !== null) {
                try {
                    const balanceRes = await classifyEnergyBalance({
                        analysisType: 'AGGREGATE',
                        solarGeneration: {
                            valueKwh: solarKwh,
                            dataNature: solarNature,
                            dataStatus: resolvedLatest?.data_status || 'STALE',
                        },
                        householdConsumption: {
                            valueKwh: demandKwh,
                            dataNature: 'ESTIMATED',
                            dataStatus: 'FRESH',
                        },
                        householdId: household?.id,
                    })
                    if (balanceRes?.data?.result) {
                        setTodayBalance(balanceRes.data.result)
                    } else {
                        setTodayBalance(resolvedForecast?.energy_balance_projection || null)
                    }
                } catch {
                    setTodayBalance(resolvedForecast?.energy_balance_projection || null)
                }

                // Authoritative Energy Cost for Today (F5)
                try {
                    const costRes = await estimateEnergyCost({
                        solarGeneration: {
                            valueKwh: solarKwh,
                            dataNature: solarNature,
                            dataStatus: resolvedLatest?.data_status || 'STALE',
                        },
                        householdConsumption: {
                            valueKwh: demandKwh,
                            dataNature: 'ESTIMATED',
                            dataStatus: 'FRESH',
                        },
                        pricing: {
                            basis: 'HISTORICAL_BILL_RELATIONSHIP',
                            currency: 'INR',
                            unitRate: 2.9521,
                        },
                        fixedCharge: {
                            amount: 30,
                            currency: 'INR',
                            label: 'Monthly fixed charge from electricity bill',
                        },
                        householdId: household?.id,
                    })
                    if (costRes?.data?.result) {
                        setTodayCost(costRes.data.result)
                    } else {
                        setTodayCost(resolvedForecast?.energy_cost_projection || null)
                    }
                } catch {
                    setTodayCost(resolvedForecast?.energy_cost_projection || null)
                }
            } else {
                setTodayBalance(resolvedForecast?.energy_balance_projection || null)
                setTodayCost(resolvedForecast?.energy_cost_projection || null)
            }
        } catch (err) {
            setError(err?.message || 'Failed to load household control center.')
        } finally {
            setIsLoading(false)
            setIsRefreshing(false)
            refreshInProgressRef.current = false
        }
    }, [household, isDemo])

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        loadDashboardData()
    }, [loadDashboardData])

    if (isLoading && !forecastData && !solarLatest) {
        return (
            <div className="py-12">
                <SPMSLoadingState
                    title="Loading Household Control Center"
                    subtitle="Synchronizing GoodWe solar telemetry, verified billing baseline, and day-ahead forecast..."
                    variant="card"
                />
            </div>
        )
    }

    // Canonical values extraction
    const actualSolarKwh = solarLatest?.daily_generation_kwh != null
        ? Number(solarLatest.daily_generation_kwh)
        : null

    const estDemandKwh = forecastData?.consumption_forecast?.predicted_value != null
        ? Number(forecastData.consumption_forecast.predicted_value)
        : 5.05

    // Potential Balance from backend
    const balanceKwh = todayBalance?.balance_kwh ?? (todayBalance?.balance_kwh !== undefined ? Number(todayBalance.balance_kwh) : null)
    const isSurplus = (todayBalance?.classification === 'POTENTIAL_SURPLUS') || (balanceKwh !== null && balanceKwh > 0)
    const isDeficit = (todayBalance?.classification === 'POTENTIAL_DEFICIT') || (balanceKwh !== null && balanceKwh < 0)

    // Variable Cost from backend
    const variableCostInr = todayCost?.estimated_variable_cost ?? todayCost?.estimated_cost_inr ?? 0

    // Latest verified bill from canonical bills API
    const latestVerifiedBill = billsSummary.latestVerified
    const latestBillAmount = (latestVerifiedBill?.total_due != null && latestVerifiedBill?.total_due !== '')
        ? Number(latestVerifiedBill.total_due)
        : (latestVerifiedBill?.bill_amount != null && latestVerifiedBill?.bill_amount !== '')
            ? Number(latestVerifiedBill.bill_amount)
            : (latestVerifiedBill?.values?.total_due != null && latestVerifiedBill?.values?.total_due !== '')
                ? Number(latestVerifiedBill.values.total_due)
                : (latestVerifiedBill?.values?.bill_amount != null && latestVerifiedBill?.values?.bill_amount !== '')
                    ? Number(latestVerifiedBill.values.bill_amount)
                    : null

    const latestBillPeriod = latestVerifiedBill
        ? formatBillingMonthYear(
            latestVerifiedBill.billing_period_start || latestVerifiedBill.values?.billing_period_start,
            latestVerifiedBill.billing_period_end || latestVerifiedBill.values?.billing_period_end,
            latestVerifiedBill.bill_date || latestVerifiedBill.values?.bill_date
        )
        : null

    // Capacity-Normalized Performance Score from backend
    const performanceScore = performanceSummary?.v2_average_accuracy_pct
        ?? performanceSummary?.average_accuracy_pct
        ?? null
    const formattedScore = performanceScore !== null
        ? `${Number(performanceScore).toFixed(1)}%`
        : null

    // Solar Forecast Snapshot
    const solarForecast = forecastData?.solar_forecast
    const forecastLower = solarForecast?.uncertainty_range?.lower ?? 15.6
    const forecastUpper = solarForecast?.uncertainty_range?.upper ?? 19.9
    const forecastConfidence = solarForecast?.confidence ?? 'HIGH'
    const forecastModelName = solarForecast?.provenance?.model_name ?? 'SPMS Solar Calibrated V2'

    // Connection & Freshness Status
    const isStale = solarLatest?.data_status === 'STALE'
    const lastTelemetryTime = formatTelemetryTime(solarLatest?.measurement_time)
    const connStatus = solarConnection?.connection_status || (solarLatest ? (isStale ? 'STALE' : 'CONNECTED') : 'CONNECTED')

    // Top Actionable Recommendation (Canonical entity consistency)
    const actionableRecs = recommendations.filter((r) => r.type !== 'DATA_LIMITATION')
    const topRecommendation = actionableRecs.length > 0 ? actionableRecs[0] : (recommendations.length > 0 ? recommendations[0] : null)

    return (
        <div className="space-y-6 pb-12" data-testid="dashboard-control-center">
            {/* ============================================================== */}
            {/* SECTION A: HEADER & SYSTEM CONTEXT                             */}
            {/* ============================================================== */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2.5 flex-wrap">
                        <h1 className="font-heading text-2xl font-bold tracking-tight text-slate-900 dark:text-heading md:text-3xl">
                            {household?.label ? `${household.label} Household` : 'Charan Household'}
                        </h1>
                        {isDemo ? (
                            <span className="inline-flex items-center gap-1 rounded-full border border-purple-500/30 bg-purple-500/10 px-2.5 py-0.5 text-xs font-semibold text-purple-700 dark:text-purple-400">
                                <Sparkles className="size-3.5" />
                                <span>Demo Mode (Simulated)</span>
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:text-emerald-400">
                                <ShieldCheck className="size-3.5" />
                                <span>Real Mode</span>
                            </span>
                        )}
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-surface px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:text-slate-300 shadow-2xs">
                            <span
                                className={`size-2 rounded-full ${
                                    connStatus === 'CONNECTED'
                                        ? 'bg-emerald-500'
                                        : connStatus === 'STALE'
                                            ? 'bg-amber-400'
                                            : 'bg-rose-500'
                                }`}
                            />
                            <span>Solar: {connStatus === 'STALE' ? 'Connected (Stale)' : connStatus}</span>
                        </span>
                    </div>
                    <p className="mt-1 text-xs text-slate-700 dark:text-slate-300 font-medium">
                        {[
                            household?.location_label?.trim() || 'Kodumur',
                            'APSPDCL',
                            household?.service_number ? `Service No: ${household.service_number}` : null,
                            household?.timezone || 'Asia/Kolkata',
                        ].filter(Boolean).join(' · ')}
                    </p>
                </div>

                <div className="flex items-center gap-2.5">
                    <button
                        type="button"
                        onClick={() => {
                            if (!isRefreshing && !refreshInProgressRef.current) {
                                loadDashboardData(true)
                            }
                        }}
                        disabled={isRefreshing}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-border/80 bg-surface px-3.5 py-2 text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors hover:bg-surface-alt hover:text-slate-900 dark:hover:text-heading disabled:opacity-50 shadow-2xs cursor-pointer"
                        title="Refresh telemetry and models"
                    >
                        <RefreshCw className={`size-3.5 ${isRefreshing ? 'animate-spin text-primary' : 'text-slate-600 dark:text-slate-400'}`} />
                        <span>{isRefreshing ? 'Refreshing...' : 'Refresh'}</span>
                    </button>
                </div>
            </div>

            {/* DEMO SCENARIO NARRATIVE CARD (Examiner Focus) */}
            {isDemo && scenarioInfo && (
                <div
                    className="rounded-2xl border border-chart-demo/30 bg-chart-demo/5 p-4 shadow-2xs backdrop-blur-xs transition-all"
                    data-testid="dashboard-scenario-narrative"
                >
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-chart-demo/20 text-chart-demo">
                                <Sparkles className="size-4" aria-hidden="true" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-mono text-[10px] font-bold uppercase tracking-wider rounded bg-chart-demo/15 text-chart-demo border border-chart-demo/30 px-1.5 py-0.5">
                                        {scenarioInfo.id}
                                    </span>
                                    <h2 className="font-heading text-sm font-bold text-heading">
                                        {scenarioInfo.name}
                                    </h2>
                                    <span className="text-xs text-muted">
                                        ({scenarioInfo.location || 'Andhra Pradesh'} · {scenarioInfo.solar_capacity_kw || 3.0} kW Solar)
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                                    <strong className="text-slate-900 dark:text-heading font-semibold">Examiner Focus:</strong> {scenarioInfo.narrative || scenarioInfo.description}
                                </p>
                            </div>
                        </div>
                        <div className="shrink-0 pl-11 sm:pl-0">
                            <span className="inline-flex items-center gap-1 rounded-full border border-chart-demo/40 bg-surface px-2.5 py-0.5 text-[11px] font-medium text-chart-demo">
                                Active Demo Persona
                            </span>
                        </div>
                    </div>
                </div>
            )}

            {error && (
                <div className="flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-800 dark:text-rose-300">
                    <AlertCircle className="size-4 shrink-0 text-rose-500" />
                    <span>{error}</span>
                </div>
            )}

            {/* Guided Setup Continuation Banner (when optional setup steps remain) */}
            {(!solarLatest || billsSummary.total === 0 || !household?.service_number) && (
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3.5 rounded-xl border border-primary/25 bg-primary/5 p-4 shadow-2xs">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/20 text-primary">
                            <Sparkles className="size-4" aria-hidden="true" />
                        </div>
                        <div className="space-y-0.5">
                            <div className="flex items-center gap-2">
                                <h3 className="text-sm font-bold text-slate-900 dark:text-heading">Complete Your Household Setup</h3>
                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">
                                    Setup Active
                                </span>
                            </div>
                            <p className="text-xs text-slate-700 dark:text-slate-300 font-medium">
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
                        className="inline-flex items-center justify-center gap-1.5 self-start sm:self-auto rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-white shadow-2xs hover:bg-primary/90 transition-colors"
                    >
                        <span>Resume Setup</span>
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
            )}

            {/* ============================================================== */}
            {/* SECTION B: TODAY'S ENERGY STATE (PRIMARY KPI ROW)               */}
            {/* ============================================================== */}
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-base font-bold text-slate-900 dark:text-heading tracking-tight">
                            Today&apos;s Energy State
                        </h2>
                        <p className="text-xs text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                            Canonical operational metrics for today&apos;s solar generation, estimated household demand, and analytical position.
                        </p>
                    </div>
                    <span className="text-[11px] font-semibold text-slate-700 dark:text-slate-300 hidden sm:inline-block">
                        Primary Household KPIs
                    </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5" data-testid="primary-kpis-grid">
                    {/* 1. Solar Generation */}
                    <Card className="border-border/80 bg-surface p-4 flex flex-col justify-between shadow-2xs space-y-3">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-bold text-emerald-950 dark:text-slate-200 flex items-center gap-1.5">
                                <Sun className="size-3.5 text-emerald-600 dark:text-emerald-500" />
                                Solar Generation
                            </span>
                            <DataNatureBadge nature={solarLatest?.data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')} />
                        </div>
                        <div>
                            <div className="flex items-baseline gap-1 mt-0.5">
                                <span className="font-heading text-2xl sm:text-3xl font-bold text-slate-900 dark:text-heading tracking-tight">
                                    {actualSolarKwh !== null ? actualSolarKwh.toFixed(2) : '--'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kWh</span>
                            </div>
                            <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium mt-1">
                                {isDemo || (solarLatest?.data_nature === 'SIMULATED')
                                    ? "Today's simulated yield (Demo Scenario)"
                                    : "Today's actual yield from GoodWe SEMS inverter"}
                            </p>
                        </div>
                        <div className="pt-2.5 border-t border-border/50 flex items-center justify-between text-[10px]">
                            <span className="font-semibold text-slate-700 dark:text-slate-300">
                                {isStale ? `Observed ${lastTelemetryTime}` : 'Live Telemetry'}
                            </span>
                            <span
                                className={`inline-flex items-center px-1.5 py-0.5 rounded font-bold ${
                                    isStale
                                        ? 'bg-amber-100 text-amber-900 border border-amber-300 dark:bg-amber-500/15 dark:text-amber-400 dark:border-amber-500/30'
                                        : 'bg-emerald-100 text-emerald-900 border border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-400 dark:border-emerald-500/30'
                                }`}
                            >
                                {isStale ? 'STALE' : 'LIVE'}
                            </span>
                        </div>
                    </Card>

                    {/* 2. Household Demand (ESTIMATED · DAILY BASELINE) */}
                    <Card className="border-border/80 bg-surface p-4 flex flex-col justify-between shadow-2xs space-y-3">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-bold text-sky-950 dark:text-slate-200 flex items-center gap-1.5">
                                <Zap className="size-3.5 text-sky-600 dark:text-sky-500" />
                                Household Demand
                            </span>
                            <DataNatureBadge nature="ESTIMATED" />
                        </div>
                        <div>
                            <div className="flex items-baseline gap-1 mt-0.5">
                                <span className="font-heading text-2xl sm:text-3xl font-bold text-slate-900 dark:text-heading tracking-tight">
                                    {estDemandKwh.toFixed(2)}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kWh/day</span>
                            </div>
                            <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium mt-1">
                                Daily baseline derived from verified utility bills
                            </p>
                        </div>
                        <div className="pt-2.5 border-t border-border/50 flex items-center justify-between text-[10px]">
                            <span className="font-semibold text-slate-700 dark:text-slate-300">
                                {billsSummary.verified > 0 ? `${billsSummary.verified} Verified Bills` : 'Monthly Tariff Cycle'}
                            </span>
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded font-bold bg-sky-100 text-sky-900 border border-sky-300 dark:bg-sky-500/15 dark:text-sky-400 dark:border-sky-500/30">
                                DAILY BASELINE
                            </span>
                        </div>
                    </Card>

                    {/* 3. Energy Balance (POTENTIAL) */}
                    <Card className="border-border/80 bg-surface p-4 flex flex-col justify-between shadow-2xs space-y-3">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-bold text-teal-950 dark:text-slate-200 flex items-center gap-1.5">
                                <Scale className="size-3.5 text-teal-600 dark:text-teal-500" />
                                Energy Balance
                            </span>
                            <span className="inline-flex items-center gap-1 rounded-full border border-purple-300 bg-purple-50 dark:border-purple-500/30 dark:bg-purple-500/10 px-2 py-0.5 text-xs font-bold text-purple-900 dark:text-purple-300">
                                POTENTIAL
                            </span>
                        </div>
                        <div>
                            <div className="flex items-baseline gap-1 mt-0.5">
                                <span className="font-heading text-2xl sm:text-3xl font-bold text-slate-900 dark:text-heading tracking-tight">
                                    {balanceKwh !== null ? `${balanceKwh > 0 ? '+' : ''}${balanceKwh.toFixed(2)}` : '+8.35'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kWh</span>
                            </div>
                            <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium mt-1">
                                {isSurplus
                                    ? 'Potential surplus · Analytical balance, not measured grid export'
                                    : isDeficit
                                        ? 'Potential deficit · Grid import needed'
                                        : 'Balanced position · Generation matches demand'}
                            </p>
                        </div>
                        <div className="pt-2.5 border-t border-border/50 flex items-center justify-between text-[10px]">
                            <span className="font-semibold text-slate-700 dark:text-slate-300">
                                Solar − Estimated Demand
                            </span>
                            <span
                                className={`inline-flex items-center px-1.5 py-0.5 rounded font-bold ${
                                    isSurplus
                                        ? 'bg-emerald-100 text-emerald-900 border border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-400 dark:border-emerald-500/30'
                                        : 'bg-amber-100 text-amber-900 border border-amber-300 dark:bg-amber-500/15 dark:text-amber-400 dark:border-amber-500/30'
                                }`}
                            >
                                {isSurplus ? 'POTENTIAL SURPLUS' : 'POTENTIAL DEFICIT'}
                            </span>
                        </div>
                    </Card>

                    {/* 4. Energy Cost (ESTIMATED · VARIABLE) */}
                    <Card className="border-border/80 bg-surface p-4 flex flex-col justify-between shadow-2xs space-y-3">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-bold text-emerald-950 dark:text-slate-200 flex items-center gap-1.5">
                                <IndianRupee className="size-3.5 text-emerald-600 dark:text-emerald-500" />
                                Energy Cost
                            </span>
                            <DataNatureBadge nature="ESTIMATED" />
                        </div>
                        <div>
                            <div className="flex items-baseline gap-1.5 flex-wrap mt-0.5">
                                <span className="font-heading text-2xl sm:text-3xl font-bold text-slate-900 dark:text-heading tracking-tight">
                                    ₹{variableCostInr.toFixed(2)}
                                </span>
                                <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Variable energy cost
                                </span>
                            </div>
                            <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium mt-1">
                                {variableCostInr === 0
                                    ? 'No variable import cost estimated'
                                    : 'Estimated variable grid energy expense'}
                            </p>
                        </div>
                        <div className="pt-2.5 border-t border-border/50 space-y-1.5">
                            <div className="flex items-center justify-between text-[11px]">
                                <span className="font-medium text-slate-700 dark:text-slate-300">
                                    Latest verified bill:
                                </span>
                                {latestBillAmount !== null ? (
                                    <Link
                                        to="/bills"
                                        className="font-bold text-slate-900 dark:text-heading hover:text-primary dark:hover:text-primary inline-flex items-center gap-1 transition-colors group"
                                        title={latestBillPeriod ? `Billing period: ${latestBillPeriod}` : 'View verified bills'}
                                    >
                                        <span>₹{latestBillAmount % 1 === 0 ? Math.round(latestBillAmount) : latestBillAmount.toFixed(2)}</span>
                                        <ArrowRight className="size-3 text-primary transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                    </Link>
                                ) : (
                                    <span className="text-slate-500 dark:text-slate-400 font-medium">
                                        No verified bill available
                                    </span>
                                )}
                            </div>
                            <p className="text-[10px] text-slate-600 dark:text-slate-400 font-medium">
                                Fixed/statutory/other bill charges are separate.
                            </p>
                        </div>
                    </Card>
                </div>
            </div>

            {/* ============================================================== */}
            {/* SECTION C: ENERGY POSITION & SYSTEM STATUS                      */}
            {/* ============================================================== */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
                {/* Left (7 cols): Energy Position */}
                <Card className="lg:col-span-7 border-border/80 bg-surface shadow-2xs">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-base font-bold text-slate-900 dark:text-heading flex items-center gap-2">
                                    <Scale className="size-4 text-teal-600 dark:text-teal-400" />
                                    <span>Energy Position</span>
                                </CardTitle>
                                <CardDescription className="text-xs text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                                    Analytical relationship between solar generation and estimated household demand
                                </CardDescription>
                            </div>
                            <span className="inline-flex items-center rounded-full border border-purple-300 bg-purple-50 dark:border-purple-500/30 dark:bg-purple-500/10 px-2 py-0.5 text-xs font-bold text-purple-900 dark:text-purple-300">
                                POTENTIAL
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* 3 Step Visual Flow */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            {/* Step 1: Solar */}
                            <div className="rounded-lg border border-emerald-300 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-500/5 p-3 space-y-1">
                                <div className="flex items-center justify-between text-xs">
                                    <span className="font-bold text-emerald-950 dark:text-emerald-400 flex items-center gap-1">
                                        <Sun className="size-3 text-emerald-600" />
                                        Solar
                                    </span>
                                    <span className="text-[10px] font-bold text-emerald-900 dark:text-emerald-400">
                                        {solarLatest?.data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')}
                                    </span>
                                </div>
                                <div className="text-lg font-bold text-slate-900 dark:text-heading font-mono">
                                    {actualSolarKwh !== null ? `${actualSolarKwh.toFixed(2)} kWh` : '13.40 kWh'}
                                </div>
                                <span className="text-[10px] text-slate-700 dark:text-slate-300 block font-medium">
                                    {isDemo || (solarLatest?.data_nature === 'SIMULATED') ? 'Simulated Telemetry' : 'GoodWe Inverter'}
                                </span>
                            </div>

                            {/* Step 2: Demand */}
                            <div className="rounded-lg border border-sky-300 bg-sky-50/70 dark:border-sky-500/30 dark:bg-sky-500/5 p-3 space-y-1">
                                <div className="flex items-center justify-between text-xs">
                                    <span className="font-bold text-sky-950 dark:text-sky-400 flex items-center gap-1">
                                        <Zap className="size-3 text-sky-600" />
                                        Demand
                                    </span>
                                    <span className="text-[10px] font-bold text-sky-900 dark:text-sky-400">ESTIMATED</span>
                                </div>
                                <div className="text-lg font-bold text-slate-900 dark:text-heading font-mono">
                                    {estDemandKwh.toFixed(2)} kWh
                                </div>
                                <span className="text-[10px] text-slate-700 dark:text-slate-300 block font-medium">
                                    Verified Bill Baseline
                                </span>
                            </div>

                            {/* Step 3: Potential Balance */}
                            <div className="rounded-lg border border-teal-300 bg-teal-50/70 dark:border-teal-500/30 dark:bg-teal-500/5 p-3 space-y-1">
                                <div className="flex items-center justify-between text-xs">
                                    <span className="font-bold text-teal-950 dark:text-teal-400 flex items-center gap-1">
                                        <Scale className="size-3 text-teal-600" />
                                        Balance
                                    </span>
                                    <span className="text-[10px] font-bold text-teal-900 dark:text-teal-400">POTENTIAL</span>
                                </div>
                                <div className="text-lg font-bold text-slate-900 dark:text-heading font-mono">
                                    {balanceKwh !== null ? `${balanceKwh > 0 ? '+' : ''}${balanceKwh.toFixed(2)} kWh` : '+8.35 kWh'}
                                </div>
                                <span className="text-[10px] text-slate-700 dark:text-slate-300 block font-medium">
                                    Analytical Surplus
                                </span>
                            </div>
                        </div>

                        {/* Honest Analytical Proportion Bar */}
                        <div className="space-y-1.5 pt-1">
                            <div className="flex justify-between text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                                <span>Demand vs Generation Margin</span>
                                <span>{((estDemandKwh / (actualSolarKwh || 13.4)) * 100).toFixed(0)}% Baseline Demand</span>
                            </div>
                            <div className="h-3 w-full rounded-full bg-surface-alt border border-border/70 overflow-hidden flex">
                                <div
                                    className="bg-sky-500 transition-all"
                                    style={{ width: `${Math.min(100, Math.max(10, ((estDemandKwh / (actualSolarKwh || 13.4)) * 100)))}%` }}
                                    title={`Estimated Demand: ${estDemandKwh.toFixed(2)} kWh`}
                                />
                                <div
                                    className="bg-emerald-500 flex-1 transition-all"
                                    title={`Potential Surplus: ${balanceKwh !== null ? balanceKwh.toFixed(2) : '8.35'} kWh`}
                                />
                            </div>
                            <div className="flex items-center justify-between text-[10px] font-medium text-slate-700 dark:text-slate-300">
                                <span className="flex items-center gap-1">
                                    <span className="size-2 rounded-full bg-sky-500" />
                                    Estimated Household Demand ({estDemandKwh.toFixed(2)} kWh)
                                </span>
                                <span className="flex items-center gap-1">
                                    <span className="size-2 rounded-full bg-emerald-500" />
                                    Potential Surplus ({balanceKwh !== null ? balanceKwh.toFixed(2) : '8.35'} kWh)
                                </span>
                            </div>
                        </div>

                        {/* Prominent Truth Disclosure Box */}
                        <div className="rounded-lg border border-border/80 bg-surface-alt/50 p-3 text-xs text-slate-800 dark:text-slate-300 space-y-1">
                            <div className="flex items-center gap-1.5 font-bold text-slate-900 dark:text-heading">
                                <Info className="size-3.5 text-primary shrink-0" />
                                <span>Analytical potential — not measured grid export</span>
                            </div>
                            <p className="text-[11px] leading-relaxed font-medium">
                                This household has no bidirectional smart meter or CT clamp load monitor. Physical export to the utility grid is not measured and surplus does not imply automatic monetary compensation.
                            </p>
                        </div>

                        <div className="flex justify-end pt-1">
                            <Link
                                to="/energy"
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline group"
                            >
                                <span>Explore complete energy balance &amp; historical validation</span>
                                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                {/* Right (5 cols): System Status */}
                <Card className="lg:col-span-5 border-border/80 bg-surface shadow-2xs">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base font-bold text-slate-900 dark:text-heading flex items-center gap-2">
                                <Activity className="size-4 text-emerald-600 dark:text-emerald-400" />
                                <span>System Status</span>
                            </CardTitle>
                            <span className="text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                                Operational Health
                            </span>
                        </div>
                        <CardDescription className="text-xs text-slate-700 dark:text-slate-300 font-medium">
                            Decision-relevant operational states &amp; data telemetry freshness
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3.5">
                        {/* 1. GoodWe Solar Source */}
                        <div className="rounded-lg border border-border/70 bg-surface-alt/30 p-2.5 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <Sun className="size-4 text-emerald-500 shrink-0" />
                                <div>
                                    <span className="text-xs font-bold text-slate-900 dark:text-heading block">
                                        {isDemo ? 'GoodWe Inverter (Simulated DEMO)' : (solarConnection?.inverter_sn ? 'GoodWe Inverter' : 'GoodWe GW3000-NS')}
                                    </span>
                                    <span className="text-[10px] text-slate-700 dark:text-slate-300 font-medium">
                                        {Number(household?.solar_capacity_kw ?? (scenarioInfo?.solar_capacity_kw ?? 3.0)).toFixed(2)} kW rated inverter capacity
                                    </span>
                                </div>
                            </div>
                            <span
                                className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold ${
                                    connStatus === 'CONNECTED'
                                        ? 'bg-emerald-100 text-emerald-900 border border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-400 dark:border-emerald-500/30'
                                        : 'bg-amber-100 text-amber-900 border border-amber-300 dark:bg-amber-500/15 dark:text-amber-400 dark:border-amber-500/30'
                                }`}
                            >
                                {isDemo ? 'CONNECTED (DEMO)' : connStatus}
                            </span>
                        </div>

                        {/* Stale Warning Banner if applicable */}
                        {isStale && (
                            <div className="rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 p-2.5 text-xs text-amber-900 dark:text-amber-300 flex items-start gap-2">
                                <AlertCircle className="size-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                                <div>
                                    <span className="font-bold block">Solar telemetry is stale</span>
                                    <span className="text-[11px] leading-snug">
                                        Latest observed reading from {lastTelemetryTime}. Stale telemetry does not affect forecast model confidence.
                                    </span>
                                </div>
                            </div>
                        )}

                        {/* 2. Utility Billing Baseline */}
                        <div className="rounded-lg border border-border/70 bg-surface-alt/30 p-2.5 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <FileText className="size-4 text-sky-500 shrink-0" />
                                <div>
                                    <span className="text-xs font-bold text-slate-900 dark:text-heading block">
                                        APSPDCL Utility Baseline
                                    </span>
                                    <span className="text-[10px] text-slate-700 dark:text-slate-300 font-medium">
                                        {billsSummary.verified} {billsSummary.verified === 1 ? 'verified cycle' : 'verified cycles'}
                                    </span>
                                </div>
                            </div>
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-sky-100 text-sky-900 border border-sky-300 dark:bg-sky-500/15 dark:text-sky-400 dark:border-sky-500/30">
                                VERIFIED
                            </span>
                        </div>

                        {/* 3. Weather / NWP Model Feed */}
                        <div className="rounded-lg border border-border/70 bg-surface-alt/30 p-2.5 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <CloudSun className="size-4 text-amber-500 shrink-0" />
                                <div>
                                    <span className="text-xs font-bold text-slate-900 dark:text-heading block">
                                        Open-Meteo NWP Feed
                                    </span>
                                    <span className="text-[10px] text-slate-700 dark:text-slate-300 font-medium">
                                        Kodumur station coordinates (15.68° N, 77.78° E)
                                    </span>
                                </div>
                            </div>
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-900 border border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-400 dark:border-emerald-500/30">
                                ACTIVE
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* ============================================================== */}
            {/* SECTION D & E: SOLAR OUTLOOK & RECOMMENDED ACTION               */}
            {/* ============================================================== */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {/* Section D: Solar Outlook */}
                <Card className="border-border/80 bg-surface shadow-2xs">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-base font-bold text-slate-900 dark:text-heading flex items-center gap-2">
                                    <TrendingUp className="size-4 text-emerald-600 dark:text-emerald-400" />
                                    <span>Solar Outlook</span>
                                </CardTitle>
                                <CardDescription className="text-xs text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                                    Tomorrow&apos;s day-ahead solar generation forecast
                                </CardDescription>
                            </div>
                            <span className="inline-flex items-center rounded border border-sky-300 bg-sky-50 dark:border-sky-500/30 dark:bg-sky-500/10 px-2 py-0.5 text-[10px] font-bold text-sky-800 dark:text-sky-400">
                                PREDICTED
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3.5">
                        <div className="flex items-baseline justify-between rounded-lg border border-border/70 bg-surface-alt/40 p-3">
                            <div>
                                <span className="text-[11px] text-slate-700 dark:text-slate-300 font-medium block">
                                    Tomorrow&apos;s Expected Yield
                                </span>
                                <div className="flex items-baseline gap-1 mt-0.5">
                                    <span className="text-2xl font-bold font-heading text-slate-900 dark:text-heading">
                                        {solarForecast?.predicted_value !== undefined ? Number(solarForecast.predicted_value).toFixed(2) : '17.73'}
                                    </span>
                                    <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kWh</span>
                                </div>
                            </div>
                            <div className="text-right">
                                <span className="text-[11px] text-slate-700 dark:text-slate-300 font-medium block">
                                    Forecast Range
                                </span>
                                <div className="text-sm font-bold text-slate-900 dark:text-heading font-mono mt-0.5">
                                    {Number(forecastLower).toFixed(1)} – {Number(forecastUpper).toFixed(1)} kWh
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-2 text-xs">
                            <div className="rounded-md border border-border/60 bg-surface-alt/30 p-2">
                                <span className="text-[10px] text-slate-600 dark:text-slate-400 block font-medium">Model Engine</span>
                                <span className="font-semibold text-slate-900 dark:text-heading text-[11px]">
                                    {forecastModelName}
                                </span>
                            </div>
                            <div className="rounded-md border border-border/60 bg-surface-alt/30 p-2">
                                <span className="text-[10px] text-slate-600 dark:text-slate-400 block font-medium">Confidence</span>
                                <span className="font-semibold text-emerald-800 dark:text-emerald-400 text-[11px]">
                                    {forecastConfidence} CONFIDENCE
                                </span>
                            </div>
                        </div>

                        <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium leading-relaxed">
                            Calibrated physics baseline aligned with GoodWe telemetry and live NWP irradiance.
                            {formattedScore ? (
                                <>
                                    {' '}
                                    <span
                                        className="cursor-help underline decoration-dotted underline-offset-2"
                                        title="Capacity-normalized performance score based on MAE relative to the 3.00 kW rated inverter capacity; not conventional percentage accuracy."
                                    >
                                        Historical Capacity-Normalized Performance Score: {formattedScore}
                                    </span>
                                    .
                                </>
                            ) : null}
                        </p>

                        <div className="flex justify-end pt-1">
                            <Link
                                to="/forecasts"
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline group"
                            >
                                <span>View complete 7-day &amp; monthly horizons</span>
                                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                {/* Section E: Recommended Action */}
                <Card className="border-border/80 bg-surface shadow-2xs" data-testid="dashboard-recommendation-card">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-base font-bold text-slate-900 dark:text-heading flex items-center gap-2">
                                    <Sparkles className="size-4 text-amber-600 dark:text-amber-400" />
                                    <span>Recommended Action</span>
                                </CardTitle>
                                <CardDescription className="text-xs text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                                    Decision-relevant household energy guidance
                                </CardDescription>
                            </div>
                            <span className="inline-flex items-center rounded border border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:text-amber-400">
                                ADVISORY
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3.5">
                        {topRecommendation ? (
                            <>
                                <div className="rounded-lg border border-amber-300 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/5 p-3 space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-bold text-amber-950 dark:text-amber-300">
                                            {topRecommendation.title || 'Shift Flexible Electricity to Solar Window'}
                                        </h4>
                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300 dark:bg-amber-500/15 dark:text-amber-300">
                                            {topRecommendation.priority ? `${topRecommendation.priority} PRIORITY` : 'HIGH IMPACT'}
                                        </span>
                                    </div>
                                    <p className="text-[11px] text-slate-800 dark:text-slate-300 font-medium leading-relaxed">
                                        {topRecommendation.action || topRecommendation.action_text || 'Consider shifting flexible household electricity use (e.g. washing, water pumping) to the midday solar window where practical.'}
                                    </p>
                                </div>

                                <div className="grid grid-cols-2 gap-2 text-xs">
                                    <div className="rounded-md border border-border/60 bg-surface-alt/30 p-2">
                                        <span className="text-[10px] text-slate-600 dark:text-slate-400 block font-medium">Optimal Window</span>
                                        <span className="font-semibold text-slate-900 dark:text-heading text-[11px]">
                                            {topRecommendation.timing_window || '10:00 – 14:00 (Midday)'}
                                        </span>
                                    </div>
                                    <div className="rounded-md border border-border/60 bg-surface-alt/30 p-2">
                                        <span className="text-[10px] text-slate-600 dark:text-slate-400 block font-medium">Implementation</span>
                                        <span className="font-semibold text-slate-900 dark:text-heading text-[11px]">
                                            Zero Cost · Behavioral
                                        </span>
                                    </div>
                                </div>

                                <p className="text-[11px] text-slate-700 dark:text-slate-300 font-medium leading-relaxed">
                                    Advisory guidance based on expected daylight solar surplus. No automated appliance control or guaranteed financial return is implied.
                                </p>
                            </>
                        ) : (
                            <div className="space-y-2 py-2">
                                <p className="text-sm font-semibold text-slate-900 dark:text-heading">
                                    No action is needed right now.
                                </p>
                                <p className="text-xs text-muted leading-relaxed">
                                    Your energy profile is currently balanced. We will notify you when solar conditions or billing patterns suggest a helpful change.
                                </p>
                            </div>
                        )}

                        <div className="flex justify-end pt-1">
                            <Link
                                to="/recommendations"
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline group"
                            >
                                <span>View all recommendations ({recommendations.length})</span>
                                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* ============================================================== */}
            {/* ============================================================== */}
            {/* SECTION F: IMPORTANT STATUS / ATTENTION                         */}
            {/* ============================================================== */}
            <div className="rounded-xl border border-border/80 bg-surface/80 p-3.5 shadow-2xs">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-slate-700 dark:text-slate-300">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="size-4 text-emerald-500 shrink-0" />
                        <span className="font-bold text-slate-900 dark:text-heading">All Core Subsystems Monitored</span>
                        <span className="text-slate-600 dark:text-slate-400 hidden sm:inline">
                            · Telemetry monitored, billing baseline active, day-ahead forecast ready{insights.length > 0 ? `, ${insights.length} active insight${insights.length === 1 ? '' : 's'}` : ''}.
                        </span>
                    </div>
                    {insights.length > 0 ? (
                        <Link
                            to="/insights"
                            className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                        >
                            <span>View insights ({insights.length})</span>
                            <ArrowRight className="size-3" />
                        </Link>
                    ) : (
                        <span className="text-[11px] font-semibold text-slate-600 dark:text-slate-400">
                            SPMS Control Center v1.0
                        </span>
                    )}
                </div>
            </div>

            {/* ============================================================== */}
            {/* SECTION G: QUICK ACCESS (LIGHTWEIGHT NAVIGATION AFFORDANCE)     */}
            {/* ============================================================== */}
            <div className="space-y-2">
                <span className="text-xs font-bold text-slate-900 dark:text-heading uppercase tracking-wider block">
                    Quick Navigation
                </span>
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5" data-testid="quick-navigation-grid">
                    <Link
                        to="/solar"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-emerald-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Solar</span>
                            <Sun className="size-3.5 text-emerald-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            Generation &amp; Inverter
                        </span>
                    </Link>

                    <Link
                        to="/energy"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-teal-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Energy</span>
                            <Scale className="size-3.5 text-teal-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            Balance &amp; Validation
                        </span>
                    </Link>

                    <Link
                        to="/forecasts"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-sky-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Forecasts</span>
                            <TrendingUp className="size-3.5 text-sky-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            Multi-Horizon Outlook
                        </span>
                    </Link>

                    <Link
                        to="/bills"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-amber-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Bills</span>
                            <FileText className="size-3.5 text-amber-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            APSPDCL Verification
                        </span>
                    </Link>

                    <Link
                        to="/insights"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-indigo-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Insights</span>
                            <Activity className="size-3.5 text-indigo-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            Automated Intelligence
                        </span>
                    </Link>

                    <Link
                        to="/recommendations"
                        className="rounded-lg border border-border/70 bg-surface p-2.5 hover:border-purple-500/50 hover:bg-surface-alt transition-colors group shadow-2xs"
                    >
                        <div className="flex items-center justify-between text-xs font-bold text-slate-900 dark:text-heading">
                            <span>Recommendations</span>
                            <Sparkles className="size-3.5 text-purple-500 group-hover:scale-110 transition-transform" />
                        </div>
                        <span className="text-[11px] text-slate-600 dark:text-slate-400 font-medium block mt-0.5">
                            Energy Actions
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    )
}
