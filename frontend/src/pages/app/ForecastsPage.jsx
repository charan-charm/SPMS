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
import { getForecasts, generateForecast } from '../../services/api/forecast'
import { ForecastChart } from '../../features/forecasts/ForecastChart'
import { ForecastLimitationsCard } from '../../features/forecasts/ForecastLimitationsCard'
import { WeatherInputCard } from '../../features/forecasts/WeatherInputCard'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { CostRange } from '../../components/data-display/CostRange'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { formatMoney } from '../../lib/money'

const HORIZONS = [
    { id: 'DAY_AHEAD', label: 'Day-Ahead (24h)' },
    { id: 'SEVEN_DAY', label: '7-Day Trend' },
    { id: 'MONTHLY', label: 'Monthly Planning' },
]

export function ForecastsPage() {
    const [horizon, setHorizon] = useState('DAY_AHEAD')
    const [forecastData, setForecastData] = useState(null)
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
            const response = await apiCall({ horizon: selectedHorizon })
            setForecastData(response.data)
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
                const response = await getForecasts({ horizon })
                if (mounted) {
                    setForecastData(response.data)
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
        if (isRefreshing || isLoading) return
        fetchForecast(horizon, true)
    }

    if (isLoading && !forecastData) {
        return (
            <div className="py-12">
                <SPMSLoadingState
                    title="Generating Forecast Projections"
                    subtitle="Evaluating solar generation and consumption models..."
                    variant="card"
                />
            </div>
        )
    }

    if (error && !forecastData) {
        return (
            <div className="py-12">
                <ErrorState
                    title="Unable to Load Forecasts"
                    message={error}
                    retryLabel="Try Again"
                    onRetry={() => {
                        setIsLoading(true)
                        fetchForecast(horizon, false)
                    }}
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

    return (
        <div className="space-y-6 pb-12">
            {/* Page Header */}
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <TrendingUp className="h-6 w-6 text-emerald-400" />
                        <h1 className="font-heading text-2xl font-bold tracking-tight text-text-primary md:text-3xl">
                            Energy Forecasts
                        </h1>
                    </div>
                    <p className="mt-1 text-sm text-text-muted">
                        Backend-authoritative solar generation, household demand, and balance outlook.
                    </p>
                </div>

                {/* Controls: Horizon Tabs + Refresh + SolarAI Button */}
                <div className="flex flex-wrap items-center gap-2.5">
                    {/* Horizon Tabs */}
                    <div className="flex rounded-xl border border-border/70 bg-surface/90 p-1 backdrop-blur-sm">
                        {HORIZONS.map((h) => (
                            <button
                                key={h.id}
                                onClick={() => handleHorizonChange(h.id)}
                                className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                                    horizon === h.id
                                        ? 'bg-emerald-500 text-white shadow-sm'
                                        : 'text-text-muted hover:text-text-primary hover:bg-surface-subtle/50'
                                }`}
                            >
                                {h.label}
                            </button>
                        ))}
                    </div>

                    {/* Recalculate Button */}
                    <button
                        onClick={handleRefresh}
                        disabled={isRefreshing || isLoading}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-border/80 bg-surface/80 px-3.5 py-2 text-xs font-medium text-text-secondary transition-colors hover:bg-surface-subtle hover:text-text-primary disabled:opacity-50 shadow-sm"
                        title="Force recalculate fresh forecast snapshot"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin text-emerald-400' : ''}`} />
                        <span>{isRefreshing ? 'Calculating...' : 'Recalculate'}</span>
                    </button>
                </div>
            </div>

            {/* Missing Data Guidance Banners */}
            {isConsumptionInsufficient && (
                <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-sm">
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
                <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs text-emerald-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-sm">
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

            {/* KPI Metric Cards Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* 1. Predicted Solar Generation */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Solar Generation</span>
                        <Sun className="h-4 w-4 text-emerald-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {solarForecast.predicted_value !== undefined
                                ? `${solarForecast.predicted_value.toFixed(2)} kWh`
                                : '--'}
                        </div>
                        {solarForecast.uncertainty_range && (
                            <div className="text-[11px] text-text-muted mt-0.5">
                                Range: {solarForecast.uncertainty_range.lower.toFixed(1)} –{' '}
                                {solarForecast.uncertainty_range.upper.toFixed(1)} kWh
                            </div>
                        )}
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={solarForecast.data_nature || 'PREDICTED'} />
                        <ConfidenceIndicator confidence={solarForecast.confidence || 'LOW'} />
                    </div>
                </div>

                {/* 2. Predicted Consumption */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Household Demand</span>
                        <Home className="h-4 w-4 text-sky-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {isConsumptionInsufficient || consumptionForecast.predicted_value === null || consumptionForecast.predicted_value === undefined
                                ? 'Insufficient'
                                : `${consumptionForecast.predicted_value.toFixed(2)} kWh`}
                        </div>
                        {!isConsumptionInsufficient && consumptionForecast.uncertainty_range?.lower !== null && consumptionForecast.uncertainty_range?.lower !== undefined ? (
                            <div className="text-[11px] text-text-muted mt-0.5">
                                Range: {consumptionForecast.uncertainty_range.lower.toFixed(1)} –{' '}
                                {consumptionForecast.uncertainty_range.upper.toFixed(1)} kWh
                            </div>
                        ) : (
                            <div className="text-[11px] text-amber-400 mt-0.5">Requires verified bill history</div>
                        )}
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={consumptionForecast.data_nature || 'ESTIMATED'} />
                        <ConfidenceIndicator confidence={consumptionForecast.confidence || 'INSUFFICIENT'} />
                    </div>
                </div>

                {/* 3. Projected Energy Balance */}
                <div className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm flex flex-col justify-between space-y-3">
                    <div className="flex items-center justify-between text-xs text-text-muted">
                        <span className="font-medium">Projected Balance</span>
                        <Scale className="h-4 w-4 text-teal-400" />
                    </div>
                    <div>
                        <div className="font-heading text-2xl font-bold text-text-primary">
                            {energyBalance.balance_kwh !== null && energyBalance.balance_kwh !== undefined
                                ? `${energyBalance.balance_kwh > 0 ? '+' : ''}${energyBalance.balance_kwh.toFixed(2)} kWh`
                                : '--'}
                        </div>
                        <div className="mt-1">
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
                        </div>
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature={energyBalance.data_nature || 'PREDICTED'} />
                        <ConfidenceIndicator confidence={energyBalance.confidence || 'LOW'} />
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
                            {!isCostUnavailable
                                ? formatMoney(energyCost.estimated_cost_inr, energyCost.currency || 'INR')
                                : '--'}
                        </div>
                        {isCostUnavailable ? (
                            <div className="text-[11px] text-text-muted mt-1">
                                Requires verified consumption history
                            </div>
                        ) : isZeroCost ? (
                            <div className="mt-1 space-y-1">
                                <div className="text-xs font-medium text-emerald-400">
                                    {isSurplus
                                        ? 'Zero variable grid cost (solar surplus)'
                                        : isBalanced
                                            ? 'Zero variable grid cost (balanced)'
                                            : 'No variable energy cost identified'}
                                </div>
                                <div className="text-[11px] text-text-muted">
                                    {energyCost.fixed_charge?.amount
                                        ? `Fixed charges (${formatMoney(energyCost.fixed_charge.amount, energyCost.currency || 'INR')}/mo) & taxes apply separately`
                                        : 'Fixed charges & statutory taxes apply separately · Not a bill'}
                                </div>
                            </div>
                        ) : (
                            <div className="mt-1 space-y-1">
                                {energyCost.cost_range && (energyCost.cost_range.min_inr > 0 || energyCost.cost_range.max_inr > 0) ? (
                                    <div className="text-xs text-amber-300 font-medium">
                                        Range: {formatMoney(energyCost.cost_range.min_inr, energyCost.currency || 'INR')} – {formatMoney(energyCost.cost_range.max_inr, energyCost.currency || 'INR')}
                                        {energyCost.cost_range.half_width_pct ? ` (±${energyCost.cost_range.half_width_pct}%)` : ''}
                                    </div>
                                ) : (
                                    <div className="text-xs text-text-muted">
                                        Potential cost impact
                                    </div>
                                )}
                                <div className="text-[11px] text-text-muted">
                                    {energyCost.fixed_charge?.amount
                                        ? `Fixed charges (${formatMoney(energyCost.fixed_charge.amount, energyCost.currency || 'INR')}/mo) & taxes apply separately`
                                        : 'Fixed charges & statutory taxes apply separately · Not a bill'}
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="flex items-center justify-between pt-2 border-t border-border/40">
                        <DataNatureBadge nature="ESTIMATED" />
                        <span className="text-[11px] text-text-muted font-medium">Estimated (INR)</span>
                    </div>
                </div>
            </div>

            {/* Weather Input Evidence Card */}
            <WeatherInputCard
                weather={forecastData?.weather || solarForecast?.provenance?.weather || null}
                isLoading={isLoading || isRefreshing}
            />

            {/* Interactive Forecast Chart */}
            <ForecastChart
                timeSeries={timeSeries}
                horizon={horizon}
                solarConfidence={solarForecast.confidence || 'LOW'}
                consumptionConfidence={consumptionForecast.confidence || 'INSUFFICIENT'}
                isLoading={isLoading || isRefreshing}
                referenceTime={forecastData?.reference_time || forecastData?.generated_at}
                timezone={forecastData?.timezone || 'Asia/Kolkata'}
                period={forecastData?.period}
            />

            {/* Limitations & Provenance Section */}
            <ForecastLimitationsCard
                limitations={limitations}
                provenance={forecastData?.provenance || {}}
                combinedConfidence={forecastData?.combined_confidence || 'MODERATE'}
                forecastSource={forecastData?.forecast_source || 'DETERMINISTIC'}
                mlProvenance={forecastData?.ml_provenance || null}
            />
        </div>
    )
}
