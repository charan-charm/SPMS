import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { Sun, Home, TrendingUp, TrendingDown, Scale, Info, Calendar } from 'lucide-react'
import { formatKwh, formatHumanForecastPeriod } from './energyPeriodUtils'

/**
 * ForecastEnergyBalanceCard (SPMS Energy — Section 2).
 *
 * Visualizes the forward-looking balance between predicted solar and estimated household demand:
 * - Expected Solar: PREDICTED (authoritative day-ahead solar prediction)
 * - Expected Demand: ESTIMATED (daily baseline derived from verified monthly electricity bills)
 * - Potential Surplus / Deficit: POTENTIAL (distinct from physical grid export)
 * - Supported Resolution: Daily baseline only. Never fabricates a synthetic hourly curve.
 * - Single, concise, non-repetitive disclaimer with transparent data provenance.
 */
export function ForecastEnergyBalanceCard({ forecastData = null, balanceData = null, timezone = 'Asia/Kolkata' }) {
    const data = forecastData || balanceData
    if (!data) {
        return null
    }

    const solarKwh = forecastData?.solar_forecast?.predicted_value
        ?? balanceData?.inputs?.solar_generation?.value_kwh
        ?? balanceData?.result?.solar_generation?.value_kwh
        ?? null

    const demandKwh = forecastData?.consumption_forecast?.predicted_value
        ?? balanceData?.inputs?.household_consumption?.value_kwh
        ?? balanceData?.result?.household_consumption?.value_kwh
        ?? null

    const balanceProj = forecastData?.energy_balance_projection
        ?? balanceData?.result
        ?? {}

    const balanceKwh = balanceProj.balance_kwh
        ?? (solarKwh !== null && demandKwh !== null ? Math.round((solarKwh - demandKwh) * 100) / 100 : null)

    const isAvailable = Boolean(balanceProj.state && balanceProj.state !== 'UNAVAILABLE')
        || (solarKwh !== null && demandKwh !== null)

    const classification = balanceProj.classification
        || (balanceKwh !== null ? (balanceKwh > 0.05 ? 'POTENTIAL_SURPLUS' : (balanceKwh < -0.05 ? 'POTENTIAL_DEFICIT' : 'BALANCED')) : 'UNAVAILABLE')

    const isSurplus = classification === 'POTENTIAL_SURPLUS' || (balanceKwh !== null && balanceKwh > 0.05)
    const isDeficit = classification === 'POTENTIAL_DEFICIT' || (balanceKwh !== null && balanceKwh < -0.05)

    const period = forecastData?.solar_forecast?.period || forecastData?.period || balanceData?.analysis?.period || balanceData?.result?.period
    const formattedPeriod = formatHumanForecastPeriod(period, timezone)

    // Dynamic user-facing explanation
    let bannerTitle
    let bannerExplanation

    if (isSurplus && balanceKwh !== null) {
        bannerTitle = `Potential Surplus: +${formatKwh(Math.abs(balanceKwh), 2)} kWh`
        bannerExplanation = `Expected solar generation (${solarKwh !== null ? formatKwh(solarKwh, 2) : '—'} kWh) exceeds estimated household demand baseline (${demandKwh !== null ? formatKwh(demandKwh, 2) : '—'} kWh) by ${formatKwh(Math.abs(balanceKwh), 2)} kWh.`
    } else if (isDeficit && balanceKwh !== null) {
        bannerTitle = `Potential Deficit: -${formatKwh(Math.abs(balanceKwh), 2)} kWh`
        bannerExplanation = `Estimated household demand baseline (${demandKwh !== null ? formatKwh(demandKwh, 2) : '—'} kWh) exceeds expected solar generation (${solarKwh !== null ? formatKwh(solarKwh, 2) : '—'} kWh) by ${formatKwh(Math.abs(balanceKwh), 2)} kWh.`
    } else if (isAvailable && balanceKwh !== null) {
        bannerTitle = 'Balanced Energy Condition'
        bannerExplanation = 'Expected solar generation is projected to match estimated household demand baseline.'
    } else {
        bannerTitle = 'Energy Balance Unavailable'
        bannerExplanation = balanceProj.explanation || 'Household consumption baseline requires verified electricity bills. Upload verified bills to establish baseline.'
    }

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="forecast-energy-card">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <Scale className="size-4 text-secondary" />
                            Forecast Energy Balance
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Expected solar generation compared with estimated household demand baseline.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center gap-1 rounded border border-slate-300 bg-slate-100 dark:border-border/80 dark:bg-muted/20 px-2 py-0.5 text-[11px] font-bold text-slate-800 dark:text-muted">
                            <Calendar className="size-3 text-emerald-600 dark:text-muted" />
                            <span>{formattedPeriod}</span>
                        </span>
                        <span className="inline-flex items-center rounded border border-sky-300 bg-sky-50 dark:border-sky-500/30 dark:bg-sky-500/10 px-2 py-0.5 text-[10px] font-bold text-sky-800 dark:text-sky-400">
                            PREDICTED
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-4">
                {/* Status Hero Banner */}
                {isAvailable && (
                    <div
                        className={`rounded-lg border p-4 transition-colors ${
                            isSurplus
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-inherit'
                                : isDeficit
                                    ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-inherit'
                                    : 'border-slate-300 bg-slate-100 text-slate-900 dark:border-border dark:bg-muted/10 dark:text-inherit'
                        }`}
                    >
                        <div className="flex items-center gap-2.5">
                            {isSurplus ? (
                                <TrendingUp className="size-5 text-emerald-500 shrink-0" />
                            ) : isDeficit ? (
                                <TrendingDown className="size-5 text-amber-500 shrink-0" />
                            ) : (
                                <Scale className="size-5 text-secondary shrink-0" />
                            )}
                            <div>
                                <h4 className="text-sm font-semibold text-heading">{bannerTitle}</h4>
                                <p className="text-xs text-slate-700 dark:text-muted mt-0.5">{bannerExplanation}</p>
                            </div>
                        </div>
                    </div>
                )}

                {/* 3 Metrics: Expected Solar, Expected Demand, Potential Balance */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* 1. Expected Solar */}
                    <div className="rounded-lg border border-amber-200 bg-amber-50/40 dark:border-border/80 dark:bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-bold text-amber-950 dark:text-muted flex items-center gap-1.5">
                                    <Sun className="size-3.5 text-amber-500" />
                                    Expected Solar
                                </span>
                                <span className="inline-flex items-center rounded border border-sky-300 bg-sky-50 dark:border-sky-500/30 dark:bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-bold text-sky-800 dark:text-sky-400">
                                    PREDICTED
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1 font-mono">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {solarKwh !== null ? formatKwh(solarKwh, 2) : '—'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-muted">kWh</span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-amber-200/60 dark:border-border/50 text-[11px] font-medium text-slate-700 dark:text-muted">
                            <span>Estimated rooftop generation</span>
                        </div>
                    </div>

                    {/* 2. Expected Demand */}
                    <div className="rounded-lg border border-sky-200 bg-sky-50/40 dark:border-border/80 dark:bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-bold text-sky-950 dark:text-muted flex items-center gap-1.5">
                                    <Home className="size-3.5 text-sky-500" />
                                    Expected Demand
                                </span>
                                <span className="inline-flex items-center rounded border border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 dark:text-amber-400">
                                    ESTIMATED
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1 font-mono">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {demandKwh !== null ? formatKwh(demandKwh, 2) : '—'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-muted">kWh</span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-sky-200/60 dark:border-border/50 text-[11px] font-medium text-slate-700 dark:text-muted">
                            <span>Daily baseline from verified monthly bills</span>
                        </div>
                    </div>

                    {/* 3. Potential Surplus / Deficit */}
                    <div className="rounded-lg border border-purple-200 bg-purple-50/40 dark:border-border/80 dark:bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-bold text-purple-950 dark:text-muted flex items-center gap-1.5">
                                    <Scale className="size-3.5 text-purple-600 dark:text-muted" />
                                    {isDeficit ? 'Potential Deficit' : 'Potential Surplus'}
                                </span>
                                <span className="inline-flex items-center rounded border border-purple-300 bg-purple-50 dark:border-purple-500/30 dark:bg-purple-500/10 px-1.5 py-0.5 text-[10px] font-bold text-purple-800 dark:text-purple-400">
                                    POTENTIAL
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1 font-mono">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {balanceKwh !== null ? `${balanceKwh > 0 ? '+' : ''}${formatKwh(balanceKwh, 2)}` : '—'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-muted">kWh</span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-purple-200/60 dark:border-border/50 text-[11px] font-medium text-slate-700 dark:text-muted">
                            <span>Solar generation minus estimated daily demand</span>
                        </div>
                    </div>
                </div>

                {/* Single Concise Disclaimer */}
                <div className="rounded-md border border-cyan-200 bg-cyan-50/60 dark:border-border/60 dark:bg-muted/5 px-3.5 py-2.5 text-xs text-slate-800 dark:text-muted flex flex-col gap-1.5">
                    <div className="flex items-center gap-2">
                        <Info className="size-4 shrink-0 text-cyan-600 dark:text-info" />
                        <span>
                            Potential surplus describes an expected energy balance, not measured grid export. Expected demand is a daily baseline estimated from verified electricity bills — not a live household measurement.
                        </span>
                    </div>
                    <div className="text-[11px] text-slate-700 dark:text-muted/80 pl-6">
                        Note: SPMS does not fabricate an hourly household demand curve because sub-hourly smart meter or CT load measurements are not available.
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}

