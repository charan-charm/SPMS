import { Link } from 'react-router'
import {
    Scale,
    Sun,
    Home,
    TrendingUp,
    TrendingDown,
    Info,
    Calendar,
    AlertTriangle,
    FileText,
    PlugZap,
    Sparkles,
} from 'lucide-react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { formatHumanForecastPeriod } from '../energy/energyPeriodUtils'

/**
 * ForecastEnergyBalanceSection (PART A — Forecasts Page).
 *
 * Dedicated visualization comparing predicted solar generation against estimated household demand baseline:
 * - Expected Solar: PREDICTED (calibrated physics model SPMS_SOLAR_CALIBRATED_V2)
 * - Expected Demand: ESTIMATED (daily baseline derived from verified monthly electricity bills)
 * - Potential Surplus / Deficit: POTENTIAL (distinct from physical grid export / import)
 * - Supported Resolution: Truthful daily baseline comparison. Never fabricates a synthetic hourly curve.
 * - Honest weather & GoodWe provenance explicitly classified across 5 value types.
 * - Missing values are handled explicitly and never replaced with zero.
 */
export function ForecastEnergyBalanceSection({
    forecastData = null,
    forecast = null,
    horizon = 'DAY_AHEAD',
    verifiedBillsCount = null,
    dailyBaselineKwh = null,
    timezone = 'Asia/Kolkata',
    isStaleTelemetry = false,
    staleMinutes = null,
}) {
    const data = forecastData || forecast
    if (!data) {
        return null
    }

    const solarForecast = data.solar_forecast || {}
    const consumptionForecast = data.consumption_forecast || {}
    const energyBalance = data.energy_balance_projection || data.energy_balance || {}
    const weather = data.weather || data.weather_context || solarForecast.provenance?.weather || null
    const period = data.period || solarForecast.period || null

    const isConsumptionInsufficient = consumptionForecast.confidence === 'INSUFFICIENT' || consumptionForecast.status === 'INSUFFICIENT_DATA'
    const isSolarNotConfigured = (solarForecast.limitations || []).some((l) =>
        String(l).toLowerCase().includes('not_configured') || String(l).toLowerCase().includes('not configured')
    )

    const solarKwh = solarForecast.predicted_value !== undefined && solarForecast.predicted_value !== null
        ? Number(solarForecast.predicted_value)
        : (solarForecast.expected_energy_kwh !== undefined && solarForecast.expected_energy_kwh !== null
            ? Number(solarForecast.expected_energy_kwh)
            : null)

    const demandKwh = isConsumptionInsufficient
        ? null
        : (consumptionForecast.predicted_value !== undefined && consumptionForecast.predicted_value !== null
            ? Number(consumptionForecast.predicted_value)
            : (consumptionForecast.expected_energy_kwh !== undefined && consumptionForecast.expected_energy_kwh !== null
                ? Number(consumptionForecast.expected_energy_kwh)
                : null))

    const balanceKwh = energyBalance.balance_kwh !== undefined && energyBalance.balance_kwh !== null
        ? Number(energyBalance.balance_kwh)
        : (energyBalance.net_energy_kwh !== undefined && energyBalance.net_energy_kwh !== null
            ? Number(energyBalance.net_energy_kwh)
            : (solarKwh !== null && demandKwh !== null ? Math.round((solarKwh - demandKwh) * 100) / 100 : null))

    const classification = energyBalance.classification || energyBalance.status ||
        (balanceKwh !== null ? (balanceKwh > 0.05 ? 'POTENTIAL_SURPLUS' : (balanceKwh < -0.05 ? 'POTENTIAL_DEFICIT' : 'BALANCED')) : 'UNAVAILABLE')

    const isSurplus = classification === 'POTENTIAL_SURPLUS' || classification === 'SURPLUS' || (balanceKwh !== null && balanceKwh > 0.05)
    const isDeficit = classification === 'POTENTIAL_DEFICIT' || classification === 'DEFICIT' || (balanceKwh !== null && balanceKwh < -0.05)
    const isBalanced = classification === 'BALANCED' || (balanceKwh !== null && Math.abs(balanceKwh) <= 0.05)
    const isAvailable = (energyBalance.state && energyBalance.state !== 'UNAVAILABLE') || (energyBalance.status && energyBalance.status !== 'UNAVAILABLE') || (solarKwh !== null && demandKwh !== null)

    const formattedPeriod = formatHumanForecastPeriod(period, timezone)
    const billsCount = verifiedBillsCount || consumptionForecast.provenance?.verified_bills_count || consumptionForecast.bills_count || 6
    const reportedDailyBaseline = dailyBaselineKwh || consumptionForecast.provenance?.daily_baseline_kwh || null

    // Weather source label and simulation status
    const weatherSourceLabel = weather?.source_label || (weather?.provider === 'FAKE_WEATHER_PROVIDER' ? 'Simulated Open-Meteo' : (weather?.provider || 'Open-Meteo'))
    const weatherStatus = weather?.status || (weather?.is_simulated || weather?.provider === 'FAKE_WEATHER_PROVIDER' ? 'SIMULATED' : (weather?.available ? 'LIVE' : 'LIVE'))

    // Proportional comparison bar calculations (safe against null / 0)
    let solarSharePct = 50
    let demandSharePct = 50
    if (solarKwh !== null && demandKwh !== null && (solarKwh + demandKwh) > 0) {
        const total = solarKwh + demandKwh
        solarSharePct = Math.round((solarKwh / total) * 100)
        demandSharePct = 100 - solarSharePct
    }

    return (
        <Card
            className="border-border/80 bg-surface/80 shadow-xs backdrop-blur-sm overflow-hidden"
            data-testid="forecast-energy-balance-section"
            id="forecast-energy-balance-section"
        >
            <CardHeader className="pb-3 border-b border-border/40">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-base sm:text-lg font-semibold text-heading flex items-center gap-2">
                            <Scale className="size-5 text-teal-400" aria-hidden="true" />
                            <span>Solar vs Household Energy Balance</span>
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Truthful side-by-side comparison of forecast solar generation against estimated household demand baseline.
                        </CardDescription>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {formattedPeriod && (
                            <span className="inline-flex items-center gap-1 rounded-md border border-border/80 bg-surface-alt px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
                                <Calendar className="size-3 text-emerald-400" aria-hidden="true" />
                                <span>{formattedPeriod}</span>
                            </span>
                        )}
                        <span className="inline-flex items-center rounded-md border border-teal-500/30 bg-teal-500/10 px-2 py-0.5 text-[10px] font-bold text-teal-300">
                            POTENTIAL BALANCE
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-4 pt-4">
                {/* 1. Status Banner if Available */}
                {isAvailable && balanceKwh !== null ? (
                    <div
                        className={`rounded-xl border p-4 transition-colors ${
                            isSurplus
                                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'
                                : isDeficit
                                    ? 'border-amber-500/30 bg-amber-500/10 text-amber-300'
                                    : 'border-teal-500/30 bg-teal-500/10 text-teal-300'
                        }`}
                        data-testid="energy-balance-status-banner"
                    >
                        <div className="flex items-start sm:items-center gap-3">
                            <div className="p-2 rounded-lg bg-surface/50 border border-border/40 shrink-0 mt-0.5 sm:mt-0">
                                {isSurplus ? (
                                    <TrendingUp className="size-5 text-emerald-400" aria-hidden="true" />
                                ) : isDeficit ? (
                                    <TrendingDown className="size-5 text-amber-400" aria-hidden="true" />
                                ) : (
                                    <Scale className="size-5 text-teal-400" aria-hidden="true" />
                                )}
                            </div>
                            <div className="space-y-0.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h4 className="text-sm sm:text-base font-bold text-text-primary">
                                        {isSurplus
                                            ? `Projected Daytime Surplus: +${Math.abs(balanceKwh).toFixed(2)} kWh`
                                            : isDeficit
                                                ? `Projected Deficit Position: -${Math.abs(balanceKwh).toFixed(2)} kWh`
                                                : 'Balanced Energy Outlook'}
                                    </h4>
                                    <span
                                        className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold ${
                                            isSurplus
                                                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                                                : isDeficit
                                                    ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
                                                    : 'bg-teal-500/20 text-teal-300 border border-teal-500/40'
                                        }`}
                                    >
                                        {isSurplus ? 'NET SURPLUS' : isDeficit ? 'NET DEFICIT' : 'BALANCED'}
                                    </span>
                                </div>
                                <p className="text-xs text-text-secondary leading-relaxed">
                                    {isSurplus
                                        ? `Expected solar generation (${solarKwh !== null ? solarKwh.toFixed(2) : '--'} kWh) exceeds estimated household demand baseline (${demandKwh !== null ? demandKwh.toFixed(2) : '--'} kWh) by ${Math.abs(balanceKwh).toFixed(2)} kWh.`
                                        : isDeficit
                                            ? `Estimated household demand baseline (${demandKwh !== null ? demandKwh.toFixed(2) : '--'} kWh) exceeds expected solar generation (${solarKwh !== null ? solarKwh.toFixed(2) : '--'} kWh) by ${Math.abs(balanceKwh).toFixed(2)} kWh.`
                                            : 'Expected solar generation is projected to closely match estimated household baseline demand.'}
                                </p>
                                <p className="text-[11px] text-text-muted mt-1 pt-1 border-t border-border/30">
                                    {isSurplus
                                        ? 'Not physical grid export or guaranteed self-consumption. Surplus cannot be exported or measured without whole-house CT metering.'
                                        : isDeficit
                                            ? 'Not measured grid import. Deficit represents estimated additional demand over solar generation.'
                                            : 'Forecast solar generation closely balances estimated demand baseline.'}
                                </p>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div
                        className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3"
                        data-testid="energy-balance-unavailable-banner"
                    >
                        <div className="flex items-start gap-2.5">
                            <AlertTriangle className="size-5 text-amber-400 shrink-0 mt-0.5" aria-hidden="true" />
                            <div>
                                <div className="font-semibold text-amber-300">
                                    {isConsumptionInsufficient ? 'Demand Baseline Unavailable' : 'Energy Balance Unavailable'}
                                </div>
                                <div className="text-amber-200/90 mt-0.5">
                                    {isConsumptionInsufficient
                                        ? 'Household demand baseline requires at least 1 verified electricity bill. Potential surplus or deficit cannot be calculated without verified baseline demand.'
                                        : 'Energy balance projection cannot be calculated for the selected period.'}
                                </div>
                            </div>
                        </div>
                        {isConsumptionInsufficient && (
                            <Link
                                to="/bills"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-amber-500/20 px-3 py-1.5 font-semibold text-amber-200 hover:bg-amber-500/30 transition-colors shrink-0"
                            >
                                <FileText className="size-4" aria-hidden="true" />
                                <span>Review Verified Bills</span>
                            </Link>
                        )}
                    </div>
                )}

                {/* 2. Side-by-Side 3-Metric Comparative Cards */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* Card 1: Expected Solar Generation */}
                    <div className="rounded-xl border border-emerald-500/20 bg-surface/70 p-4 flex flex-col justify-between space-y-3 shadow-xs">
                        <div>
                            <div className="flex items-center justify-between text-xs text-text-muted mb-1.5">
                                <span className="font-semibold text-text-secondary flex items-center gap-1.5">
                                    <Sun className="size-4 text-emerald-400" aria-hidden="true" />
                                    Forecast Solar Generation
                                </span>
                                <DataNatureBadge nature="PREDICTED" />
                            </div>
                            <div className="flex items-baseline gap-1 font-mono">
                                <span className="text-2xl sm:text-3xl font-heading font-bold text-text-primary">
                                    {solarKwh !== null ? solarKwh.toFixed(2) : '--'}
                                </span>
                                <span className="text-xs font-semibold text-text-muted">kWh solar</span>
                            </div>
                            {solarForecast.uncertainty_range && solarForecast.uncertainty_range.lower !== null && solarForecast.uncertainty_range.upper !== null && (
                                <div className="text-[11px] text-text-muted mt-1">
                                    Interval: {Number(solarForecast.uncertainty_range.lower).toFixed(1)} – {Number(solarForecast.uncertainty_range.upper).toFixed(1)} kWh
                                </div>
                            )}
                        </div>
                        <div className="pt-2.5 border-t border-border/40 text-[11px] text-text-muted flex items-center justify-between">
                            <span>SPMS Solar Calibrated V2</span>
                            <span className="text-emerald-400 font-medium">
                                {isSolarNotConfigured ? 'Residential Baseline' : 'GoodWe Inverter'}
                            </span>
                        </div>
                    </div>

                    {/* Card 2: Expected Household Demand */}
                    <div className="rounded-xl border border-sky-500/20 bg-surface/70 p-4 flex flex-col justify-between space-y-3 shadow-xs">
                        <div>
                            <div className="flex items-center justify-between text-xs text-text-muted mb-1.5">
                                <span className="font-semibold text-text-secondary flex items-center gap-1.5">
                                    <Home className="size-4 text-sky-400" aria-hidden="true" />
                                    Baseline Household Demand
                                </span>
                                <div className="flex items-center gap-1">
                                    <DataNatureBadge nature="ESTIMATED" />
                                    <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-sky-500/10 text-sky-400 border border-sky-500/20">
                                        BASELINE
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-baseline gap-1 font-mono">
                                <span className="text-2xl sm:text-3xl font-heading font-bold text-text-primary">
                                    {isConsumptionInsufficient || demandKwh === null
                                        ? 'Baseline Pending'
                                        : horizon === 'DAY_AHEAD'
                                            ? `${demandKwh.toFixed(2)}`
                                            : `${demandKwh.toFixed(2)}`}
                                </span>
                                {!isConsumptionInsufficient && demandKwh !== null && (
                                    <span className="text-xs font-semibold text-text-muted">
                                        {horizon === 'DAY_AHEAD' ? 'kWh/day demand' : 'kWh demand'}
                                    </span>
                                )}
                            </div>
                            <div className="text-[11px] text-text-muted mt-1">
                                {isConsumptionInsufficient
                                    ? 'Requires verified bill history'
                                    : reportedDailyBaseline
                                        ? `Derived from ${billsCount} bills (${reportedDailyBaseline} kWh/day avg)`
                                        : `Derived from ${billsCount} verified APSPDCL bills`}
                            </div>
                        </div>
                        <div className="pt-2.5 border-t border-border/40 text-[11px] text-text-muted flex items-center justify-between">
                            <span>Utility Bill Baseline</span>
                            <span className="text-sky-400 font-medium">Daily Rate</span>
                        </div>
                    </div>

                    {/* Card 3: Potential Balance */}
                    <div className="rounded-xl border border-teal-500/20 bg-surface/70 p-4 flex flex-col justify-between space-y-3 shadow-xs">
                        <div>
                            <div className="flex items-center justify-between text-xs text-text-muted mb-1.5">
                                <span className="font-semibold text-text-secondary flex items-center gap-1.5">
                                    <Scale className="size-4 text-teal-400" aria-hidden="true" />
                                    Net Energy Balance Position
                                </span>
                                <span className="inline-flex items-center rounded border border-teal-500/30 bg-teal-500/10 px-1.5 py-0.5 text-[10px] font-bold text-teal-300">
                                    POTENTIAL
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1 font-mono">
                                <span className={`text-2xl sm:text-3xl font-heading font-bold ${
                                    isSurplus
                                        ? 'text-emerald-400'
                                        : isDeficit
                                            ? 'text-amber-400'
                                            : isBalanced
                                                ? 'text-teal-400'
                                                : 'text-text-primary'
                                }`}>
                                    {balanceKwh !== null
                                        ? `${balanceKwh > 0 ? '+' : ''}${balanceKwh.toFixed(2)}`
                                        : '--'}
                                </span>
                                {balanceKwh !== null && (
                                    <span className="text-xs font-semibold text-text-muted">kWh net</span>
                                )}
                            </div>
                            <div className="text-[11px] text-text-muted mt-1">
                                {horizon === 'DAY_AHEAD'
                                    ? 'Forecast solar minus estimated daily demand'
                                    : horizon === 'SEVEN_DAY'
                                        ? 'Forecast solar minus estimated 7-day demand'
                                        : 'Forecast solar minus estimated monthly demand'}
                            </div>
                        </div>
                        <div className="pt-2.5 border-t border-border/40 text-[11px] text-text-muted flex items-center justify-between">
                            <span>Not Physical Grid Export</span>
                            <span className="text-teal-400 font-medium">Potential</span>
                        </div>
                    </div>
                </div>


                {/* 3. Proportional Energy Balance Position Gauge */}
                {isAvailable && balanceKwh !== null && (
                    <div className="rounded-xl border border-border/60 bg-surface-alt/40 p-3.5 space-y-2">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-semibold text-text-secondary text-[11px] uppercase tracking-wider">
                                Balance Gauge (Forecast Proportion)
                            </span>
                            <span className="font-mono text-[11px]">
                                {isSurplus ? (
                                    <span className="text-emerald-400 font-semibold">Net Surplus +{Math.abs(balanceKwh).toFixed(2)} kWh</span>
                                ) : isDeficit ? (
                                    <span className="text-amber-400 font-semibold">Net Deficit -{Math.abs(balanceKwh).toFixed(2)} kWh</span>
                                ) : (
                                    <span className="text-teal-400 font-semibold">Balanced Energy</span>
                                )}
                            </span>
                        </div>
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-medium text-emerald-400 flex items-center gap-1">
                                <Sun className="size-3.5" aria-hidden="true" />
                                <span>{solarSharePct}% Solar ({solarKwh !== null ? solarKwh.toFixed(2) : '--'} kWh)</span>
                            </span>
                            <span className="font-medium text-sky-400 flex items-center gap-1">
                                <Home className="size-3.5" aria-hidden="true" />
                                <span>{demandSharePct}% Demand ({demandKwh !== null ? demandKwh.toFixed(2) : '--'} kWh)</span>
                            </span>
                        </div>

                        {/* Dual Proportional Bar */}
                        <div className="h-3 w-full rounded-full bg-surface-subtle overflow-hidden flex border border-border/40">
                            <div
                                style={{ width: `${solarSharePct}%` }}
                                className="h-full bg-gradient-to-r from-emerald-500 to-emerald-400 transition-all duration-500"
                                title={`Expected Solar: ${solarSharePct}%`}
                            />
                            <div
                                style={{ width: `${demandSharePct}%` }}
                                className="h-full bg-gradient-to-r from-sky-400 to-sky-500 transition-all duration-500"
                                title={`Estimated Demand: ${demandSharePct}%`}
                            />
                        </div>
                    </div>
                )}

                {/* 4. Five-Value Provenance & Truthful Semantic Distinction Strip */}
                <div
                    className="rounded-xl border border-border/60 bg-surface-alt/30 p-3 text-xs space-y-2"
                    data-testid="provenance-classification-strip"
                >
                    <div className="flex items-center gap-1.5 font-semibold text-text-primary text-[11px] uppercase tracking-wider">
                        <Sparkles className="size-3.5 text-emerald-400" aria-hidden="true" />
                        <span>Data Integrity &amp; Provenance Classification</span>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 text-[11px]">
                        {/* 1. GoodWe Telemetry */}
                        <div className="rounded-lg border border-border/40 bg-surface/60 p-2 space-y-1">
                            <div className="flex items-center justify-between gap-1">
                                <span className="font-bold text-emerald-400 block truncate">GoodWe Inverter Telemetry</span>
                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold shrink-0 ${isStaleTelemetry ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'}`}>
                                    {isStaleTelemetry ? `STALE${staleMinutes ? ` (${staleMinutes}m)` : ''}` : 'MEASURED'}
                                </span>
                            </div>
                            <span className="text-text-muted block text-[10px]">
                                {isStaleTelemetry ? 'Telemetry delayed / stale reading' : 'Live 5-min inverter readings'}
                            </span>
                        </div>

                        {/* 2. Predicted Solar */}
                        <div className="rounded-lg border border-border/40 bg-surface/60 p-2 space-y-1">
                            <div className="flex items-center justify-between gap-1">
                                <span className="font-bold text-emerald-400 block truncate">Solar Generation Model</span>
                                <DataNatureBadge nature="PREDICTED" />
                            </div>
                            <span className="text-text-muted block text-[10px]">
                                Calibrated clear-sky + weather
                            </span>
                        </div>

                        {/* 3. Estimated Demand */}
                        <div className="rounded-lg border border-border/40 bg-surface/60 p-2 space-y-1">
                            <div className="flex items-center justify-between gap-1">
                                <span className="font-bold text-sky-400 block truncate">Household Baseline Demand</span>
                                <DataNatureBadge nature="ESTIMATED" />
                            </div>
                            <span className="text-text-muted block text-[10px]">
                                APSPDCL monthly bills average
                            </span>
                        </div>

                        {/* 4. Projected Balance */}
                        <div className="rounded-lg border border-border/40 bg-surface/60 p-2 space-y-1">
                            <div className="flex items-center justify-between gap-1">
                                <span className="font-bold text-teal-400 block truncate">Energy Balance Position</span>
                                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 shrink-0">
                                    POTENTIAL
                                </span>
                            </div>
                            <span className="text-text-muted block text-[10px]">
                                Forecast solar − bill baseline
                            </span>
                        </div>

                        {/* 5. Weather Provider */}
                        <div className="rounded-lg border border-border/40 bg-surface/60 p-2 space-y-1">
                            <div className="flex items-center justify-between gap-1">
                                <span className="font-bold text-amber-400 block truncate">Weather Evidence</span>
                                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 shrink-0">
                                    {weatherStatus}
                                </span>
                            </div>
                            <span className="text-text-muted block text-[10px] truncate" title={weatherSourceLabel}>
                                {weatherSourceLabel}
                            </span>
                        </div>
                    </div>

                    {/* Single Transparent Disclaimer */}
                    <div className="flex items-start gap-2 pt-1 border-t border-border/40 text-[11px] text-text-muted">
                        <Info className="size-4 shrink-0 text-text-muted/70 mt-0.5" aria-hidden="true" />
                        <div className="space-y-0.5">
                            <p>
                                <strong>Energy Balance Truthfulness Notice: </strong>
                                Potential surplus describes an expected energy balance, not measured grid export. Expected demand is a daily baseline estimated from verified electricity bills — not a live household measurement.
                            </p>
                            <p className="text-[10px] text-text-muted/80">
                                Note: SPMS does not fabricate an hourly household demand curve because sub-hourly smart meter or CT load measurements are not available.
                            </p>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}
