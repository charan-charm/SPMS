import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router'
import {
    Sun,
    Home,
    Scale,
    IndianRupee,
    RefreshCw,
    AlertTriangle,
    FileText,
    PlugZap,
    TrendingUp,
} from 'lucide-react'
import { getForecasts, generateForecast, getForecastPerformance } from '../../services/api/forecast'
import { listBills } from '../../services/api/bills'
import {
    ForecastChart,
    ForecastLimitationsCard,
    WeatherInputCard,
    HouseholdDemandBaselineCard,
    ForecastEnergyBalanceSection,
    ForecastHorizonSelector,
} from '../../features/forecasts'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { formatMoney } from '../../lib/money'

export function ForecastsPage() {
    const [horizon, setHorizon] = useState('DAY_AHEAD')
    const [forecastData, setForecastData] = useState(null)
    const [verifiedBillsCount, setVerifiedBillsCount] = useState(null)
    const [performanceScore, setPerformanceScore] = useState(null)
    const [isLoading, setIsLoading] = useState(true)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [error, setError] = useState(null)

    const fetchForecast = useCallback(async (selectedHorizon, forceRecalculate = false) => {
        if (forceRecalculate) {
            setIsRefreshing(true)
        }
        setError(null)

        try {
            const apiCall = forceRecalculate ? generateForecast : getForecasts
            const [forecastRes, billsRes, perfRes] = await Promise.allSettled([
                apiCall({ horizon: selectedHorizon }),
                listBills().catch(() => null),
                getForecastPerformance().catch(() => null),
            ])

            if (forecastRes.status === 'fulfilled') {
                setForecastData(forecastRes.value.data)
            } else {
                throw forecastRes.reason || new Error('Failed to load forecast data.')
            }

            if (billsRes.status === 'fulfilled') {
                const rawBills = Array.isArray(billsRes.value?.data?.bills)
                    ? billsRes.value.data.bills
                    : (Array.isArray(billsRes.value?.bills) ? billsRes.value.bills : [])
                const vCount = rawBills.filter((b) => b.status === 'VERIFIED').length
                if (vCount > 0) {
                    setVerifiedBillsCount(vCount)
                }
            }

            if (perfRes.status === 'fulfilled') {
                const perfData = perfRes.value?.data?.summary || perfRes.value?.summary
                if (perfData?.v2_average_accuracy_pct !== undefined && perfData?.v2_average_accuracy_pct !== null) {
                    setPerformanceScore(Number(perfData.v2_average_accuracy_pct))
                }
            }
        } catch (err) {
            setError(err.message || 'Failed to load forecast data.')
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
                const [forecastRes, billsRes, perfRes] = await Promise.allSettled([
                    getForecasts({ horizon }),
                    listBills().catch(() => null),
                    getForecastPerformance().catch(() => null),
                ])

                if (!mounted) return

                if (forecastRes.status === 'fulfilled') {
                    setForecastData(forecastRes.value.data)
                } else {
                    setError(forecastRes.reason?.message || 'Failed to load forecast data.')
                }

                if (billsRes.status === 'fulfilled') {
                    const rawBills = Array.isArray(billsRes.value?.data?.bills)
                        ? billsRes.value.data.bills
                        : (Array.isArray(billsRes.value?.bills) ? billsRes.value.bills : [])
                    const vCount = rawBills.filter((b) => b.status === 'VERIFIED').length
                    if (vCount > 0) {
                        setVerifiedBillsCount(vCount)
                    }
                }

                if (perfRes.status === 'fulfilled') {
                    const perfData = perfRes.value?.data?.summary || perfRes.value?.summary
                    if (perfData?.v2_average_accuracy_pct !== undefined && perfData?.v2_average_accuracy_pct !== null) {
                        setPerformanceScore(Number(perfData.v2_average_accuracy_pct))
                    }
                }
            } catch (err) {
                if (mounted) {
                    setError(err.message || 'Failed to load forecast data.')
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
    }, [horizon])

    const handleHorizonChange = (newHorizon) => {
        if (newHorizon === horizon) return
        setIsLoading(true)
        setHorizon(newHorizon)
    }

    const handleRefresh = () => {
        fetchForecast(horizon, true)
    }

    if (isLoading && !forecastData) {
        return (
            <div className="space-y-6 pb-12">
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-6 w-6 text-emerald-400" />
                            <h1 className="font-heading text-2xl font-bold tracking-tight text-text-primary md:text-3xl">
                                Energy Forecasts
                            </h1>
                        </div>
                        <p className="mt-1 text-sm text-text-muted">
                            Backend-authoritative solar generation, household baseline demand, and potential balance outlook.
                        </p>
                    </div>
                </div>
                <SPMSLoadingState message="Loading forecast models &amp; telemetry..." />
            </div>
        )
    }

    if (error && !forecastData) {
        return (
            <div className="space-y-6 pb-12">
                <div className="flex items-center gap-2">
                    <TrendingUp className="h-6 w-6 text-emerald-400" />
                    <h1 className="font-heading text-2xl font-bold tracking-tight text-text-primary md:text-3xl">
                        Energy Forecasts
                    </h1>
                </div>
                <ErrorState
                    title="Unable to Load Forecasts"
                    message={error}
                    retryLabel="Try Again"
                    onRetry={() => fetchForecast(horizon, false)}
                />
            </div>
        )
    }

    const solarForecast = forecastData?.solar_forecast || {}
    const consumptionForecast = forecastData?.consumption_forecast || {}
    const energyBalance = forecastData?.energy_balance_projection || {}
    const energyCost = forecastData?.energy_cost_projection || {}
    const timeSeries = forecastData?.time_series || []
    const limitations = forecastData?.limitations || []

    const isConsumptionInsufficient = consumptionForecast.confidence === 'INSUFFICIENT'
    const isSolarNotConfigured = (solarForecast.limitations || []).some((l) =>
        l.toLowerCase().includes('not_configured') || l.toLowerCase().includes('not configured')
    )

    // Format balance badge style
    const balanceClassification = energyBalance.classification || 'UNAVAILABLE'
    const isSurplus = balanceClassification === 'POTENTIAL_SURPLUS'
    const isDeficit = balanceClassification === 'POTENTIAL_DEFICIT'
    const isBalanced = balanceClassification === 'BALANCED'

    const isCostUnavailable =
        energyCost.state === 'INSUFFICIENT_EVIDENCE' ||
        energyCost.estimated_cost_inr === null ||
        energyCost.estimated_cost_inr === undefined
    const isZeroCost = !isCostUnavailable && Number(energyCost.estimated_cost_inr) === 0

    // Resolved daily baseline demand value (always daily rate, regardless of multi-day horizon)
    const dailyDemandBaseline = consumptionForecast.provenance?.predicted_daily_kwh
        ?? consumptionForecast.provenance?.average_daily_kwh
        ?? (horizon === 'DAY_AHEAD' && consumptionForecast.predicted_value !== undefined && consumptionForecast.predicted_value !== null
            ? Number(consumptionForecast.predicted_value)
            : (consumptionForecast.predicted_value !== undefined && consumptionForecast.predicted_value !== null
                ? Number(consumptionForecast.predicted_value) / (horizon === 'SEVEN_DAY' ? 7 : 30)
                : 5.05))

    const resolvedProvenance = forecastData?.provenance || {
        solar: solarForecast.provenance || {},
        consumption: consumptionForecast.provenance || {},
    }

    return (
        <div className="space-y-6 pb-12">
            {/* Page Header (Section 1: Compact Horizon Selector) */}
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <TrendingUp className="h-6 w-6 text-emerald-400" />
                        <h1 className="font-heading text-2xl font-bold tracking-tight text-text-primary md:text-3xl">
                            Energy Forecasts
                        </h1>
                    </div>
                    <p className="mt-1 text-sm text-text-muted">
                        Backend-authoritative solar generation, household baseline demand, and potential balance outlook.
                    </p>
                </div>

                {/* Compact Horizon Selector & Recalculate Control (Section 1) */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex items-center gap-2">
                        <label htmlFor="forecast-horizon-select" className="text-xs font-semibold text-text-muted">
                            Forecast Horizon
                        </label>
                        <ForecastHorizonSelector
                            value={horizon}
                            onChange={handleHorizonChange}
                            disabled={isRefreshing || isLoading}
                            id="forecast-horizon-select"
                        />
                    </div>

                    {/* Recalculate Button */}
                    <button
                        onClick={handleRefresh}
                        disabled={isRefreshing || isLoading}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-border/80 bg-surface/80 px-3 py-1.5 text-xs font-medium text-text-secondary transition-colors hover:bg-surface-subtle hover:text-text-primary disabled:opacity-50 shadow-xs cursor-pointer"
                        title="Force recalculate fresh forecast snapshot"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin text-emerald-400' : ''}`} />
                        <span>{isRefreshing ? 'Calculating...' : 'Recalculate'}</span>
                    </button>
                </div>
            </div>

            {/* Missing Data Guidance Banners */}
            {isConsumptionInsufficient && (
                <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-xs">
                    <div className="flex items-start gap-2.5">
                        <AlertTriangle className="h-5 w-5 text-amber-400 shrink-0 mt-0.5" />
                        <div>
                            <span className="font-semibold text-amber-300">Verified Bill History Required:</span>{' '}
                            Household demand forecasting requires at least 1 verified electricity bill. No synthetic consumption numbers are invented.
                        </div>
                    </div>
                    <Link
                        to="/bills"
                        className="inline-flex items-center gap-1.5 rounded-lg bg-amber-500/20 px-3 py-1.5 font-semibold text-amber-200 hover:bg-amber-500/30 transition-colors shrink-0"
                    >
                        <FileText className="h-4 w-4" />
                        <span>Go to Bills</span>
                    </Link>
                </div>
            )}

            {isSolarNotConfigured && (
                <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs text-emerald-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-xs">
                    <div className="flex items-start gap-2.5">
                        <PlugZap className="h-5 w-5 text-emerald-400 shrink-0 mt-0.5" />
                        <div>
                            <span className="font-semibold text-emerald-300">GoodWe Inverter Not Connected:</span>{' '}
                            Solar predictions currently reflect a default residential capacity baseline. Connect your GoodWe SEMS+ account for personalized generation telemetry.
                        </div>
                    </div>
                    <Link
                        to="/solar"
                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500/20 px-3 py-1.5 font-semibold text-emerald-200 hover:bg-emerald-500/30 transition-colors shrink-0"
                    >
                        <Sun className="h-4 w-4" />
                        <span>Connect Solar</span>
                    </Link>
                </div>
            )}

            {/* Top KPI Metric Cards Grid (Section 3: Reduced Text Density) */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* 1. Solar Generation */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-xs backdrop-blur-sm flex flex-col justify-between space-y-2.5">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Solar Generation</span>
                        <Sun className="h-4 w-4 text-emerald-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {solarForecast.predicted_value !== undefined && solarForecast.predicted_value !== null
                                ? `${Number(solarForecast.predicted_value).toFixed(2)} kWh`
                                : '--'}
                        </div>
                        {solarForecast.uncertainty_range && solarForecast.uncertainty_range.lower !== null && solarForecast.uncertainty_range.upper !== null && (
                            <div className="text-[11px] text-text-muted mt-0.5">
                                Range: {Number(solarForecast.uncertainty_range.lower).toFixed(1)} – {Number(solarForecast.uncertainty_range.upper).toFixed(1)} kWh
                            </div>
                        )}
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={solarForecast.data_nature || 'PREDICTED'} />
                        <ConfidenceIndicator confidence={solarForecast.confidence || 'HIGH'} />
                    </div>
                </div>

                {/* 2. Household Demand */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-xs backdrop-blur-sm flex flex-col justify-between space-y-2.5">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Household Demand</span>
                        <Home className="h-4 w-4 text-sky-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {isConsumptionInsufficient || consumptionForecast.predicted_value === null || consumptionForecast.predicted_value === undefined
                                ? 'Insufficient'
                                : horizon === 'DAY_AHEAD'
                                    ? `${Number(consumptionForecast.predicted_value).toFixed(2)} kWh/day`
                                    : `${Number(consumptionForecast.predicted_value).toFixed(2)} kWh`}
                        </div>
                        <div className="text-[11px] text-text-muted mt-0.5">
                            {isConsumptionInsufficient
                                ? 'Requires verified bill history'
                                : horizon === 'DAY_AHEAD'
                                    ? 'Estimated daily baseline'
                                    : horizon === 'SEVEN_DAY'
                                        ? 'Estimated 7-day demand'
                                        : 'Estimated monthly demand'}
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={consumptionForecast.data_nature || 'ESTIMATED'} />
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-500/15 text-sky-300 border border-sky-500/30">
                            DAILY BASELINE
                        </span>
                    </div>
                </div>

                {/* 3. Potential Energy Balance */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-xs backdrop-blur-sm flex flex-col justify-between space-y-2.5">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Potential Energy Balance</span>
                        <Scale className="h-4 w-4 text-teal-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {energyBalance.balance_kwh !== null && energyBalance.balance_kwh !== undefined
                                ? `${energyBalance.balance_kwh > 0 ? '+' : ''}${Number(energyBalance.balance_kwh).toFixed(2)} kWh`
                                : '--'}
                        </div>
                        <div className="mt-1 space-y-0.5">
                            <span
                                className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ${
                                    isSurplus
                                        ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                                        : isDeficit
                                            ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                                            : isBalanced
                                                ? 'bg-teal-500/15 text-teal-400 border border-teal-500/30'
                                                : 'bg-surface-subtle text-text-muted border border-border/40'
                                }`}
                            >
                                {isSurplus
                                    ? 'Potential Surplus'
                                    : isDeficit
                                        ? 'Potential Deficit'
                                        : isBalanced
                                            ? 'Balanced'
                                            : 'Unavailable'}
                            </span>
                            <div className="text-[10px] text-text-muted leading-tight">
                                {horizon === 'DAY_AHEAD'
                                    ? 'Forecast solar minus estimated daily household demand.'
                                    : horizon === 'SEVEN_DAY'
                                        ? 'Forecast solar minus estimated 7-day household demand.'
                                        : 'Forecast solar minus estimated monthly household demand.'}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={energyBalance.data_nature || 'PREDICTED'} />
                        <span className="text-[10px] text-text-muted">Not grid export</span>
                    </div>
                </div>

                {/* 4. Variable Energy Cost */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-xs backdrop-blur-sm flex flex-col justify-between space-y-2.5">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Estimated Variable Energy Cost</span>
                        <IndianRupee className="h-4 w-4 text-emerald-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {!isCostUnavailable
                                ? formatMoney(energyCost.estimated_cost_inr, energyCost.currency || 'INR')
                                : '--'}
                        </div>
                        <div className="mt-1 space-y-0.5">
                            <div className="text-xs font-medium text-emerald-400">
                                {isCostUnavailable
                                    ? 'Requires verified bill history'
                                    : isZeroCost
                                        ? 'No variable import cost estimated under the forecast energy position.'
                                        : 'Estimated energy cost'}
                            </div>
                            <div className="text-[10px] text-text-muted">
                                Fixed charges and statutory/other bill charges are separate.
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature="ESTIMATED" />
                        <span className="text-[11px] text-text-muted font-medium">Variable Only</span>
                    </div>
                </div>
            </div>

            {/* Weather Input Evidence Card (Section 4) */}
            <WeatherInputCard
                weather={forecastData?.weather || solarForecast?.provenance?.weather || null}
                isLoading={isLoading || isRefreshing}
            />

            {/* Dedicated Solar vs Household Energy Balance Section (Part A) */}
            <ForecastEnergyBalanceSection
                forecastData={forecastData}
                horizon={horizon}
                verifiedBillsCount={verifiedBillsCount}
                dailyBaselineKwh={dailyDemandBaseline}
                timezone={forecastData?.timezone || 'Asia/Kolkata'}
            />

            {/* Main Solar Forecast Chart (Section 2 & 9) */}
            <ForecastChart
                timeSeries={timeSeries}
                horizon={horizon}
                solarConfidence={solarForecast.confidence || 'HIGH'}
                isLoading={isLoading || isRefreshing}
                referenceTime={forecastData?.reference_time || forecastData?.generated_at}
                timezone={forecastData?.timezone || 'Asia/Kolkata'}
                period={forecastData?.period}
            />

            {/* Separate Household Demand Baseline Card (Section 5) */}
            <HouseholdDemandBaselineCard
                dailyBaselineKwh={dailyDemandBaseline}
                verifiedBillsCount={verifiedBillsCount || 6}
                horizon={horizon}
                isInsufficient={isConsumptionInsufficient}
                uncertaintyRange={consumptionForecast.uncertainty_range}
            />

            {/* Model & Data Metadata + Collapsible Limitations (Section 6, 7, 10) */}
            <ForecastLimitationsCard
                limitations={limitations}
                provenance={resolvedProvenance}
                combinedConfidence={forecastData?.combined_confidence || 'MODERATE'}
                forecastSource={forecastData?.forecast_source || 'DETERMINISTIC'}
                mlProvenance={forecastData?.ml_provenance || null}
                verifiedBillsCount={verifiedBillsCount || 6}
                performanceScore={performanceScore}
                telemetryFreshness={resolvedProvenance?.solar?.telemetry_freshness}
            />
        </div>
    )
}
