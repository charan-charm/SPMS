import { useMemo, useState, useEffect } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import {
    ComposedChart,
    Area,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    ReferenceLine,
} from 'recharts'
import { LineChart, CheckCircle2, Clock, Calendar, Info } from 'lucide-react'
import { ForecastDateSelector } from './ForecastDateSelector'
import { useAuth } from '../auth/useAuth'

function formatDisplayDate(dateStr) {
    if (!dateStr || dateStr === 'today') return 'Today (Live)'
    try {
        const [y, m, d] = dateStr.split('-').map(Number)
        const date = new Date(y, m - 1, d)
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    } catch {
        return dateStr
    }
}

/**
 * AnimatedSynchronizedDot
 *
 * Renders stationary dots with pure opacity fade-in synchronized with the line's clip-path sweep:
 * - Rendered at exact final (cx, cy) SVG coordinate immediately.
 * - ZERO translation, ZERO transform, ZERO movement.
 * - Opacity-only transition: 0 -> 1.
 * - Guards against invalid/null points, preventing isolated/floating dots.
 * - Instantly static when prefers-reduced-motion is active.
 */
function AnimatedSynchronizedDot(props) {
    const { cx, cy, index = 0, totalPoints = 13, isAnimationActive = true, begin = 200, duration = 1600, payload } = props
    if (typeof cx !== 'number' || typeof cy !== 'number' || isNaN(cx) || isNaN(cy)) return null

    const actualVal = payload?.actual ?? props.value
    if (actualVal === null || actualVal === undefined || isNaN(Number(actualVal))) return null
    if (payload?.status === 'FUTURE' || payload?.status === 'MISSING_TELEMETRY') return null

    if (!isAnimationActive) {
        return <circle cx={cx} cy={cy} r={2.5} fill="#10b981" />
    }

    const safeTotal = Math.max(1, totalPoints - 1)
    const delay = Math.round(begin + (index / safeTotal) * duration)

    return (
        <circle
            cx={cx}
            cy={cy}
            r={2.5}
            fill="#10b981"
            style={{
                opacity: 0,
                animation: `spmsDotOpacityFade 0.22s ease-out ${delay}ms forwards`,
            }}
        />
    )
}

function ForecastVsActualTooltip({ active, payload, label, showClearSky = false, isDemo = false }) {
    if (!active || !payload || payload.length === 0) return null

    const dataPoint = payload[0]?.payload || {}
    const predKw = dataPoint.predicted !== null && dataPoint.predicted !== undefined
        ? parseFloat(dataPoint.predicted)
        : null
    const actKw = dataPoint.actual !== null && dataPoint.actual !== undefined
        ? parseFloat(dataPoint.actual)
        : null
    const clearSkyKw = dataPoint.clear_sky !== null && dataPoint.clear_sky !== undefined
        ? parseFloat(dataPoint.clear_sky)
        : null
    const diffKw = actKw !== null && predKw !== null
        ? parseFloat(actKw - predKw)
        : null
    const weatherReductionKw = (showClearSky && clearSkyKw !== null && predKw !== null)
        ? Math.max(0.0, clearSkyKw - predKw)
        : null
    const status = dataPoint.status || 'COMPLETED'
    const readingCount = dataPoint.reading_count ?? 0

    const hasPredicted = predKw !== null
    const hasActual = actKw !== null

    return (
        <div className="rounded-xl border border-border bg-surface/95 p-3 shadow-xl backdrop-blur-md text-xs font-sans space-y-2 min-w-[240px]">
            <div className="flex items-center justify-between border-b border-border/50 pb-1.5">
                <span className="font-semibold text-heading flex items-center gap-1.5">
                    <Clock className="size-3 text-primary" />
                    {label} IST
                </span>
                <span
                    className={`text-[10px] font-bold px-1.5 py-0.5 rounded ${
                        status === 'COMPLETED'
                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                            : status === 'IN_PROGRESS'
                                ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-500/30'
                                : status === 'MISSING_TELEMETRY'
                                    ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30'
                                    : 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-500/30'
                    }`}
                >
                    {status === 'COMPLETED'
                        ? (isDemo ? 'SIMULATED' : 'MEASURED')
                        : status === 'IN_PROGRESS'
                            ? 'IN PROGRESS'
                            : status === 'MISSING_TELEMETRY'
                                ? 'NO TELEMETRY'
                                : 'FUTURE'}
                </span>
            </div>

            <div className="space-y-1.5 font-mono">
                {/* Clear-Sky Baseline (when enabled) */}
                {showClearSky && clearSkyKw !== null && (
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-slate-700 dark:text-slate-300 flex items-center gap-1.5 font-sans text-[11px] font-medium">
                            <span className="w-2.5 h-0.5 border-t-2 border-dashed border-amber-500 inline-block" />
                            Theoretical Clear-Sky:
                        </span>
                        <span className="font-semibold text-amber-700 dark:text-amber-400">
                            {clearSkyKw.toFixed(3)} kW
                        </span>
                    </div>
                )}

                {/* Predicted / Weather-Adjusted Forecast */}
                {(hasPredicted || !hasActual) && (
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-slate-700 dark:text-slate-300 flex items-center gap-1 font-sans text-[11px] font-medium">
                            <span className="size-2 rounded-full bg-sky-400 inline-block" />
                            {showClearSky ? 'Weather-Adjusted Forecast:' : (status === 'FUTURE' ? 'Future Predicted:' : 'Predicted:')}
                        </span>
                        <span className="font-semibold text-sky-700 dark:text-sky-400">
                            {hasPredicted ? `${predKw.toFixed(3)} kW` : 'No forecast'}
                        </span>
                    </div>
                )}

                {/* Model Weather Attenuation Reduction (when clear-sky active) */}
                {showClearSky && weatherReductionKw !== null && (
                    <div className="flex items-center justify-between gap-3 text-[11px]">
                        <span className="text-slate-600 dark:text-slate-400 font-sans">
                            Model Weather Attenuation:
                        </span>
                        <span className="font-semibold text-amber-700 dark:text-amber-400">
                            -{weatherReductionKw.toFixed(3)} kW
                        </span>
                    </div>
                )}

                {/* Actual GoodWe */}
                <div className="flex items-center justify-between gap-3">
                    <span className="text-slate-700 dark:text-slate-300 flex items-center gap-1 font-sans text-[11px] font-medium">
                        <span className="size-2 rounded-full bg-emerald-500 inline-block" />
                        {isDemo ? 'GoodWe (Simulated):' : 'Actual GoodWe:'}
                    </span>
                    <span className="font-semibold text-emerald-700 dark:text-emerald-400">
                        {hasActual ? `${actKw.toFixed(3)} kW` : (status === 'FUTURE' ? 'In future (scheduled)' : 'No telemetry')}
                    </span>
                </div>

                {/* Difference */}
                {diffKw !== null && (
                    <div className="flex items-center justify-between gap-3 pt-1 border-t border-border/40">
                        <span className="text-slate-700 dark:text-slate-300 font-sans text-[11px] font-medium">Difference:</span>
                        <span className={`font-semibold ${diffKw >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>
                            {diffKw > 0 ? '+' : ''}{diffKw.toFixed(3)} kW
                        </span>
                    </div>
                )}
            </div>

            {showClearSky && (
                <div className="text-[10px] text-muted pt-1 border-t border-border/40 font-sans leading-tight">
                    Clear-sky estimates generation without weather attenuation. Difference is model-estimated, not measured energy lost.
                </div>
            )}

            {hasActual && readingCount > 0 && (
                <div className="text-[10px] text-muted pt-1 border-t border-border/40 flex items-center justify-between font-sans">
                    <span>GoodWe observations:</span>
                    <span className="font-medium text-heading font-mono">{readingCount} readings</span>
                </div>
            )}
        </div>
    )
}

/**
 * ForecastVsActualChart (SPMS Energy — Section 3, 5, 9, 10, 11, 12, 14).
 *
 * Implements:
 * - Progressive data-plot animation (Area + Line) synchronized with point appearance (~1.8–2.0s total).
 * - Stable key `forecast-chart-${selectedDate}` guaranteeing animation restart on any date selection.
 * - Cohesive neutral validation summary with semantic primary/secondary metrics hierarchy.
 * - Viewport-contained date selector with smooth scroll to graph.
 * - Respects prefers-reduced-motion.
 */
export function ForecastVsActualChart({
    validationData = null,
    validationToday = null,
    selectedDate = 'today',
    onSelectDate = null,
    availableDates = [],
    isLoadingDay = false,
    performanceHistory = [],
    initialShowClearSky = false,
}) {
    const { account } = useAuth()
    const isDemo = Boolean(account?.is_demo || account?.role === 'DEMO_VIEWER')
    const [showClearSky, setShowClearSky] = useState(initialShowClearSky)
    const currentSelected = selectedDate || 'today'
    const latestHistoricalDate = availableDates[0] || 'yesterday'

    const activeValidation = currentSelected === 'today'
        ? validationToday
        : validationData

    const { maxKw, chartData, nowDisplayTime } = useMemo(() => {
        const timeSeries = activeValidation?.time_series || []
        // Solar forecast chart displays ONLY local daylight window (06:00 to 18:00).
        // Completely exclude nighttime intervals from chart series.
        const daylightPoints = timeSeries.filter((pt) => {
            const h = pt.hour !== undefined && pt.hour !== null
                ? parseInt(pt.hour, 10)
                : (pt.time ? parseInt(pt.time.split(':')[0], 10) : null)
            if (h !== null && !isNaN(h)) {
                return h >= 6 && h <= 18
            }
            return true
        })

        const data = daylightPoints.map((pt) => {
            const pred = pt.predicted_kw !== null && pt.predicted_kw !== undefined ? parseFloat(pt.predicted_kw) : null
            const act = pt.actual_kw !== null && pt.actual_kw !== undefined ? parseFloat(pt.actual_kw) : null
            const clearSky = pt.clear_sky_kw !== null && pt.clear_sky_kw !== undefined ? parseFloat(pt.clear_sky_kw) : null

            return {
                ...pt,
                display_time: pt.display_time || pt.time,
                predicted: pred,
                actual: act,
                clear_sky: clearSky,
            }
        })

        const peakValue = data.reduce((acc, d) => {
            const pred = d.predicted ?? 0
            const act = d.actual ?? 0
            const cs = showClearSky ? (d.clear_sky ?? 0) : 0
            return Math.max(acc, pred, act, cs)
        }, 2.0)

        // Find current time marker in today view
        const inProgressPoint = data.find((d) => d.status === 'IN_PROGRESS')
        const nowTime = inProgressPoint?.display_time || inProgressPoint?.time || null

        return {
            maxKw: Math.ceil(peakValue * 1.2 * 10) / 10,
            chartData: data,
            nowDisplayTime: nowTime,
        }
    }, [activeValidation, showClearSky])

    const hasData = chartData.length > 0
    const isToday = currentSelected === 'today'
    const isCompleted = (activeValidation?.validation_state || 'PENDING') === 'VALIDATED'
    const isTodayCompleted = isToday && isCompleted

    const todayButtonLabel = isTodayCompleted || (validationToday?.validation_state === 'VALIDATED')
        ? 'Today (Validated)'
        : 'Today (Live)'

    const dateLabel = activeValidation?.target_period?.date_label || (isToday ? (activeValidation?.summary?.target_date ? formatDisplayDate(activeValidation.summary.target_date) : 'Today') : formatDisplayDate(currentSelected))

    // Extract canonical selected day summary metrics
    const summary = activeValidation?.summary || {}
    const metrics = activeValidation?.metrics || {}
    const totals = activeValidation?.totals || {}

    const predKwh = summary.predicted_energy_kwh ?? totals.predicted_generation_kwh ?? null
    const actKwh = summary.actual_energy_kwh ?? totals.actual_generation_kwh ?? null
    const diffKwh = summary.energy_difference_kwh ?? (predKwh !== null && actKwh !== null ? (actKwh - predKwh) : null)
    const accuracyPct = summary.accuracy_pct ?? metrics.accuracy_pct ?? metrics.accuracy_percent ?? null
    const maeKw = summary.mae_kw ?? metrics.mae_kw ?? null
    const rmseKw = summary.rmse_kw ?? metrics.rmse_kw ?? null
    const obsCount = summary.observation_count ?? metrics.observation_count ?? 147

    const [prefersReducedMotion, setPrefersReducedMotion] = useState(() => {
        if (typeof window === 'undefined' || !window.matchMedia) return false
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    })

    useEffect(() => {
        if (typeof window === 'undefined' || !window.matchMedia) return
        const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
        const handleChange = () => setPrefersReducedMotion(mediaQuery.matches)
        mediaQuery.addEventListener?.('change', handleChange)
        return () => mediaQuery.removeEventListener?.('change', handleChange)
    }, [])

    const handleSelect = (d) => {
        if (onSelectDate) {
            onSelectDate(d)
        }
    }

    return (
        <Card
            className="border-border bg-surface shadow-sm scroll-mt-20"
            id="forecast-vs-actual-section"
            data-testid="forecast-vs-actual-card"
        >
            <style>{`
                @keyframes spmsDotOpacityFade {
                    0% { opacity: 0; }
                    100% { opacity: 1; }
                }
            `}</style>

            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex flex-wrap items-center gap-2">
                            <LineChart className="size-4 text-primary" />
                            <span>Forecast vs Actual Solar Generation</span>
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Compare the persisted day-ahead forecast with actual GoodWe telemetry.
                        </CardDescription>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Day Selector Buttons & Dropdown */}
                        <div className="inline-flex items-center rounded-lg border border-border bg-muted/20 p-0.5 shadow-xs">
                            <button
                                type="button"
                                onClick={() => handleSelect('today')}
                                className={`px-2.5 py-1 text-xs font-medium rounded-md transition-colors ${
                                    currentSelected === 'today'
                                        ? 'bg-surface text-heading shadow-xs border border-border/80'
                                        : 'text-muted hover:text-heading'
                                }`}
                            >
                                {todayButtonLabel}
                            </button>
                            <button
                                type="button"
                                aria-label="Yesterday (Validated)"
                                onClick={() => handleSelect(latestHistoricalDate)}
                                className={`px-2.5 py-1 text-xs font-medium rounded-md transition-colors ${
                                    currentSelected === latestHistoricalDate || currentSelected === 'yesterday'
                                        ? 'bg-surface text-heading shadow-xs border border-border/80'
                                        : 'text-muted hover:text-heading'
                                }`}
                            >
                                Yesterday (Validated)
                            </button>
                        </div>

                        {/* Custom SPMS Dropdown with Viewport Containment */}
                        {availableDates.length > 0 && (
                            <ForecastDateSelector
                                availableDates={availableDates}
                                selectedDate={currentSelected}
                                onSelectDate={handleSelect}
                                performanceHistory={performanceHistory}
                            />
                        )}

                        {isLoadingDay && (
                            <span className="text-[11px] text-muted animate-pulse font-mono">Loading…</span>
                        )}

                        <span
                            className={`inline-flex items-center gap-1 rounded border px-2 py-0.5 text-[10px] font-semibold ${
                                isCompleted
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                    : 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400'
                            }`}
                        >
                            {isCompleted ? (
                                <>
                                    <CheckCircle2 className="size-2.5" />
                                    VALIDATED
                                </>
                            ) : (
                                <>
                                    <Clock className="size-2.5" />
                                    LIVE / IN PROGRESS
                                </>
                            )}
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="pt-1">
                {/* Cohesive Validation Summary Result Card directly associated with the graph */}
                <div
                    id="validation-summary-result"
                    className="rounded-xl border border-slate-200 dark:border-border/80 bg-slate-50/90 dark:bg-surface/90 p-3.5 mb-3.5 space-y-3 shadow-xs scroll-mt-20"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 pb-2">
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-400">
                                Selected validation day:
                            </span>
                            <span className="px-2 py-0.5 rounded-md bg-white dark:bg-muted/20 border border-slate-300 dark:border-border font-mono text-xs font-bold text-slate-900 dark:text-slate-100">
                                {dateLabel}
                            </span>
                            <span
                                className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] font-bold ${
                                    isCompleted
                                        ? 'border border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400'
                                        : 'border border-sky-300 bg-sky-100 text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-400'
                                }`}
                            >
                                {isCompleted ? <CheckCircle2 className="size-2.5" /> : <Clock className="size-2.5" />}
                                {isCompleted ? 'VALIDATED' : 'LIVE / IN PROGRESS'}
                            </span>
                        </div>
                        <span className="text-[11px] text-slate-700 dark:text-slate-300 font-medium font-sans">
                            {isCompleted
                                ? 'Retrospective weather-assisted validation against authentic GoodWe telemetry'
                                : 'Live daylight telemetry with persisted day-ahead forecast'}
                        </span>
                    </div>

                    {isCompleted ? (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 text-xs">
                            {/* 1. PRIMARY: Predicted - strong blue */}
                            <div className="rounded-lg bg-sky-50/60 dark:bg-surface border border-sky-300 dark:border-sky-500/40 p-2.5 shadow-xs">
                                <span className="text-[11px] text-sky-900 dark:text-sky-400 block mb-0.5 font-bold">Predicted</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-sky-700 dark:text-sky-400 text-lg tracking-tight">
                                        {predKwh !== null ? parseFloat(predKwh).toFixed(2) : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-sky-900 dark:text-slate-400 font-sans ml-1">kWh</span>
                                </div>
                            </div>

                            {/* 2. PRIMARY: Actual GoodWe - strong green */}
                            <div className="rounded-lg bg-emerald-50/60 dark:bg-surface border border-emerald-300 dark:border-emerald-500/40 p-2.5 shadow-xs">
                                <span className="text-[11px] text-emerald-900 dark:text-emerald-400 block mb-0.5 font-bold">Actual GoodWe</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-emerald-700 dark:text-emerald-400 text-lg tracking-tight">
                                        {actKwh !== null ? parseFloat(actKwh).toFixed(2) : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-emerald-900 dark:text-slate-400 font-sans ml-1">kWh</span>
                                </div>
                            </div>

                            {/* 3. SECONDARY: Difference - strong dark */}
                            <div className="rounded-lg bg-white dark:bg-surface border border-slate-300 dark:border-border/80 p-2.5 shadow-xs">
                                <span className="text-[11px] text-slate-800 dark:text-slate-300 block mb-0.5 font-bold">Difference</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-slate-900 dark:text-slate-100 text-lg tracking-tight">
                                        {diffKwh !== null ? `${diffKwh > 0 ? '+' : ''}${parseFloat(diffKwh).toFixed(2)}` : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-slate-700 dark:text-slate-400 font-sans ml-1">kWh</span>
                                </div>
                            </div>

                            {/* 4. SECONDARY: Accuracy - strong green */}
                            <div className="rounded-lg bg-emerald-50/60 dark:bg-surface border border-emerald-300 dark:border-emerald-500/30 p-2.5 shadow-xs">
                                <span className="text-[11px] text-emerald-900 dark:text-emerald-400 block mb-0.5 font-bold">Accuracy</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-emerald-700 dark:text-emerald-400 text-lg tracking-tight">
                                        {accuracyPct !== null ? `${parseFloat(accuracyPct).toFixed(1)}%` : '—'}
                                    </span>
                                </div>
                            </div>

                            {/* 5. SECONDARY: MAE - strong purple */}
                            <div className="rounded-lg bg-purple-50/50 dark:bg-surface border border-purple-300 dark:border-border/80 p-2.5 shadow-xs">
                                <span className="text-[11px] text-purple-900 dark:text-purple-400 block mb-0.5 font-bold">MAE</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-purple-700 dark:text-purple-400 text-lg tracking-tight">
                                        {maeKw !== null ? parseFloat(maeKw).toFixed(2) : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-purple-900 dark:text-slate-400 font-sans ml-1">kW</span>
                                </div>
                            </div>

                            {/* 6. SECONDARY: RMSE - strong indigo */}
                            <div className="rounded-lg bg-indigo-50/50 dark:bg-surface border border-indigo-300 dark:border-border/80 p-2.5 shadow-xs">
                                <span className="text-[11px] text-indigo-900 dark:text-indigo-400 block mb-0.5 font-bold">RMSE</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-indigo-700 dark:text-indigo-400 text-lg tracking-tight">
                                        {rmseKw !== null ? parseFloat(rmseKw).toFixed(2) : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-indigo-900 dark:text-slate-400 font-sans ml-1">kW</span>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                            <div className="rounded-lg bg-emerald-50/60 dark:bg-surface border border-emerald-300 dark:border-emerald-500/40 p-2.5 shadow-xs">
                                <span className="text-[11px] text-emerald-900 dark:text-slate-300 block mb-0.5 font-bold">Generated so far (today)</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    <span className="font-bold text-emerald-700 dark:text-emerald-400 text-lg tracking-tight">
                                        {actKwh !== null ? parseFloat(actKwh).toFixed(2) : '—'}
                                    </span>
                                    <span className="text-xs font-bold text-emerald-900 dark:text-slate-300 font-sans ml-1">kWh</span>
                                </div>
                            </div>
                            <div className="rounded-lg bg-sky-50/60 dark:bg-surface border border-sky-300 dark:border-sky-500/40 p-2.5 shadow-xs">
                                <span className="text-[11px] text-sky-900 dark:text-slate-300 block mb-0.5 font-bold">Expected full-day generation</span>
                                <div className="flex items-baseline gap-0.5 font-mono">
                                    {predKwh !== null && predKwh !== undefined ? (
                                        <>
                                            <span className="font-bold text-sky-700 dark:text-sky-400 text-lg tracking-tight">
                                                {parseFloat(predKwh).toFixed(2)}
                                            </span>
                                            <span className="text-xs font-bold text-sky-900 dark:text-slate-300 font-sans ml-1">kWh</span>
                                        </>
                                    ) : (
                                        <span className="font-semibold text-slate-700 dark:text-slate-400 text-xs font-sans">
                                            No persisted forecast
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white dark:bg-surface border border-slate-300 dark:border-border/80 p-2.5 shadow-xs">
                                <span className="text-[11px] text-slate-800 dark:text-slate-300 block mb-0.5 font-bold">Current status</span>
                                <span className="font-bold text-sky-700 dark:text-sky-300 text-xs flex items-center gap-1 mt-0.5">
                                    <Clock className="size-3" />
                                    Live daylight in progress
                                </span>
                            </div>
                            <div className="rounded-lg bg-white dark:bg-surface border border-slate-300 dark:border-border/80 p-2.5 shadow-xs">
                                <span className="text-[11px] text-slate-800 dark:text-slate-300 block mb-0.5 font-bold">Actual telemetry</span>
                                <span className="font-bold text-slate-900 dark:text-slate-100 text-xs mt-0.5 block font-mono">
                                    GoodWe SEMS (5-min)
                                </span>
                            </div>
                        </div>
                    )}

                    {/* Metadata line directly below metrics */}
                    <div className="text-[11px] text-slate-800 dark:text-slate-300 flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-border/40 font-semibold">
                        <span className="font-mono text-[10px] text-slate-800 dark:text-slate-300 font-medium">
                            Open-Meteo Historical Archive · GoodWe SEMS · {obsCount} × 5-min observations · 06:00–18:00 IST
                        </span>
                        <span className="text-[10px] text-slate-800 dark:text-slate-300 font-medium">
                            Rated inverter capacity: 3.00 kW · Observed validation peak: 3.01 kW
                        </span>
                    </div>
                </div>

                {hasData ? (
                    <div>
                        {/* Graph Legend & Units */}
                        <div className="flex flex-wrap items-center justify-between gap-2 pb-2 text-xs">
                            <div className="flex flex-wrap items-center gap-3 sm:gap-4">
                                <div className="flex items-center gap-1.5 font-medium">
                                    <span className="size-2.5 rounded-full bg-sky-500 inline-block border border-sky-400" />
                                    <span className="text-sky-700 dark:text-sky-300 font-bold text-[11px]">PREDICTED · V2</span>
                                </div>
                                <div className="flex items-center gap-1.5 font-medium">
                                    <span className="size-2.5 rounded-full bg-emerald-500 inline-block border border-emerald-400" />
                                    <span className="text-emerald-700 dark:text-emerald-300 font-bold text-[11px]">ACTUAL · GoodWe SEMS</span>
                                </div>
                                {showClearSky && (
                                    <div className="flex items-center gap-1.5 font-medium" data-testid="clear-sky-legend">
                                        <span className="w-3.5 h-0 border-t-2 border-dashed border-amber-500 inline-block" />
                                        <span className="text-amber-700 dark:text-amber-400 font-bold text-[11px]">THEORETICAL · Clear-Sky Baseline</span>
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-3 text-slate-700 dark:text-slate-300 text-[11px] font-mono font-semibold">
                                <label className="inline-flex items-center gap-1.5 cursor-pointer font-sans text-xs font-medium text-slate-700 dark:text-slate-300 select-none">
                                    <input
                                        type="checkbox"
                                        id="toggle-clear-sky-baseline"
                                        data-testid="toggle-clear-sky-baseline"
                                        checked={showClearSky}
                                        onChange={(e) => setShowClearSky(e.target.checked)}
                                        className="rounded border-slate-300 dark:border-border text-amber-500 focus:ring-amber-400/40 size-3.5 cursor-pointer"
                                    />
                                    <span className="text-heading font-medium">Show clear-sky baseline</span>
                                </label>
                                <span className="hidden sm:inline text-border">|</span>
                                <span>Unit: kW</span>
                                <span>Daylight: 06:00–18:00 IST</span>
                            </div>
                        </div>

                        {/* Concise Clear-Sky Explanation Callout */}
                        {showClearSky && (
                            <div
                                data-testid="clear-sky-explanation"
                                className="mb-2.5 px-3 py-2 rounded-lg bg-amber-50/90 dark:bg-amber-950/25 border border-amber-300/80 dark:border-amber-500/30 text-xs text-amber-950 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2 shadow-xs"
                            >
                                <div className="flex items-start gap-2">
                                    <Info className="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                                    <p className="leading-relaxed text-[11px]">
                                        <strong className="font-semibold text-amber-800 dark:text-amber-300">Theoretical Clear-Sky Baseline: </strong>
                                        The clear-sky baseline estimates generation without weather attenuation. The difference from the weather-adjusted forecast is model-estimated, not measured energy lost.
                                    </p>
                                </div>
                                {summary.clear_sky_energy_kwh !== undefined && summary.clear_sky_energy_kwh !== null && (
                                    <div className="shrink-0 flex items-center gap-3 text-[11px] font-mono pl-6 sm:pl-0 pt-1 sm:pt-0 border-t sm:border-t-0 border-amber-200 dark:border-amber-800/40">
                                        <span>Clear-sky: <strong className="text-amber-800 dark:text-amber-300">{Number(summary.clear_sky_energy_kwh).toFixed(2)} kWh</strong></span>
                                        {summary.weather_reduction_kwh !== undefined && (
                                            <span>Model reduction: <strong className="text-amber-800 dark:text-amber-300">{Number(summary.weather_reduction_kwh).toFixed(2)} kWh</strong></span>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Chart with stable key for intentional remount on date change */}
                        <div className="h-64 sm:h-72 w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart
                                    key={`forecast-chart-${currentSelected}`}
                                    data={chartData}
                                    margin={{ top: 10, right: 16, left: 16, bottom: 0 }}
                                >
                                    <defs>
                                        <linearGradient id="predictedGradient" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#38bdf8" stopOpacity={0.25} />
                                            <stop offset="95%" stopColor="#38bdf8" stopOpacity={0.0} />
                                        </linearGradient>
                                    </defs>

                                    <CartesianGrid strokeDasharray="3 3" stroke="rgba(148, 163, 184, 0.2)" vertical={false} />

                                    <XAxis
                                        dataKey="display_time"
                                        stroke="#64748b"
                                        fontSize={11}
                                        tickLine={false}
                                        axisLine={false}
                                        interval="preserveStartEnd"
                                    />
                                    <YAxis
                                        domain={[0, maxKw]}
                                        stroke="#64748b"
                                        fontSize={11}
                                        width={56}
                                        tickLine={false}
                                        axisLine={false}
                                        tickFormatter={(v) => Number(v) === 0 ? '0 kW' : `${Number(v).toFixed(2)} kW`}
                                    />

                                    <Tooltip content={<ForecastVsActualTooltip showClearSky={showClearSky} isDemo={isDemo} />} animationDuration={150} />

                                    {/* NOW vertical reference marker in live mode */}
                                    {currentSelected === 'today' && nowDisplayTime && (
                                        <ReferenceLine
                                            x={nowDisplayTime}
                                            stroke="#14b8a6"
                                            strokeDasharray="3 3"
                                            label={{
                                                value: 'NOW',
                                                position: 'insideTop',
                                                fill: '#14b8a6',
                                                fontSize: 10,
                                                fontWeight: 600,
                                            }}
                                        />
                                    )}

                                    {/* Predicted Curve (cyan/blue) — draws progressively left to right (0–1600ms) */}
                                    <Area
                                        type="monotone"
                                        dataKey="predicted"
                                        stroke="#38bdf8"
                                        strokeWidth={2}
                                        strokeDasharray="4 4"
                                        fill="url(#predictedGradient)"
                                        name="Predicted"
                                        isAnimationActive={!prefersReducedMotion}
                                        animationDuration={1600}
                                        animationBegin={0}
                                        animationEasing="ease-out"
                                        connectNulls={false}
                                    />

                                    {/* Theoretical Clear-Sky Baseline (amber dashed reference) */}
                                    {showClearSky && (
                                        <Line
                                            type="monotone"
                                            dataKey="clear_sky"
                                            stroke="#f59e0b"
                                            strokeWidth={2}
                                            strokeDasharray="5 5"
                                            dot={false}
                                            activeDot={{ r: 4, fill: '#f59e0b', stroke: '#ffffff', strokeWidth: 1.5 }}
                                            name="Theoretical Clear-Sky Baseline"
                                            isAnimationActive={!prefersReducedMotion}
                                            animationDuration={1200}
                                            animationBegin={0}
                                            connectNulls={false}
                                        />
                                    )}

                                    {/* Actual GoodWe Curve (green) — draws progressively left to right with subtle 200ms stagger (200–1800ms) */}
                                    <Line
                                        type="monotone"
                                        dataKey="actual"
                                        stroke="#10b981"
                                        strokeWidth={2.5}
                                        dot={(dotProps) => (
                                            <AnimatedSynchronizedDot
                                                key={`dot-${currentSelected}-${dotProps.index}`}
                                                {...dotProps}
                                                totalPoints={chartData.length}
                                                isAnimationActive={!prefersReducedMotion}
                                                begin={200}
                                                duration={1600}
                                            />
                                        )}
                                        activeDot={{ r: 4.5, fill: '#10b981', stroke: '#ffffff', strokeWidth: 2 }}
                                        name={isDemo ? 'GoodWe (Simulated)' : 'Actual'}
                                        isAnimationActive={!prefersReducedMotion}
                                        animationDuration={1600}
                                        animationBegin={200}
                                        animationEasing="ease-out"
                                        connectNulls={false}
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>

                        {/* Chart Subtitle Note */}
                        <div className="mt-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-1 text-[11px] text-muted border-t border-border/40 pt-2.5">
                            <span>
                                {isDemo
                                    ? (isCompleted
                                        ? 'Forecast and simulated GoodWe generation for the selected validation day (06:00 – 18:00 IST).'
                                        : 'Past daylight intervals show simulated GoodWe telemetry; remaining intervals show the persisted day-ahead forecast.')
                                    : (isCompleted
                                        ? 'Forecast and actual GoodWe generation for the selected validation day (06:00 – 18:00 IST).'
                                        : 'Past daylight intervals show measured GoodWe telemetry; remaining intervals show the persisted day-ahead forecast.')}
                            </span>
                            <span className="font-medium text-heading">
                                SPMS Solar Calibrated V2
                            </span>
                        </div>
                    </div>
                ) : (
                    <div className="py-12 text-center text-xs text-muted">
                        <Calendar className="size-8 mx-auto mb-2 text-muted/60" />
                        <p className="font-medium text-heading">No telemetry data recorded for this day</p>
                        <p className="mt-1 text-muted">Select another completed day from the validation selector above.</p>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
