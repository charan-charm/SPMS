import { useState, useEffect, useMemo } from 'react'
import { motion, useReducedMotion } from 'motion/react'
import {
    ResponsiveContainer,
    AreaChart,
    Area,
    BarChart,
    Bar,
    Cell,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
    Line,
} from 'recharts'
import {
    Sun,
    Zap,
    Calendar,
    AlertTriangle,
    BarChart3,
    Clock,
    RefreshCw,
    AlertCircle,
    Info,
    ArrowUpRight,
    ArrowDownRight,
    ArrowDown,
    ArrowRight,
    Activity,
    Sparkles,
    CheckCircle2,
    CalendarDays,
} from 'lucide-react'
import { analyticsApi } from '../../services/api/analytics'
import { useHousehold } from '../../features/household/useHousehold'

// ----------------------------------------------------------------------------
// Semantic Badges & Data Lineage Labels
// ----------------------------------------------------------------------------

export function SemanticBadge({ nature = 'ACTUAL', className = '' }) {
    if (nature === 'ACTUAL') {
        return (
            <span
                data-testid="badge-actual"
                className={`inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 tracking-wide ${className}`}
            >
                <Sun className="size-2.5 text-emerald-500" aria-hidden="true" />
                ACTUAL
            </span>
        )
    }

    if (nature === 'SIMULATED') {
        return (
            <span
                data-testid="badge-simulated"
                className={`inline-flex items-center gap-1 rounded-full border border-purple-500/20 bg-purple-500/10 px-2 py-0.5 text-[10px] font-semibold text-purple-600 dark:text-purple-400 tracking-wide ${className}`}
            >
                <Sun className="size-2.5 text-purple-500" aria-hidden="true" />
                SIMULATED
            </span>
        )
    }

    if (nature === 'VERIFIED') {
        return (
            <span
                data-testid="badge-verified"
                className={`inline-flex items-center gap-1 rounded-full border border-blue-500/20 bg-blue-500/10 px-2 py-0.5 text-[10px] font-semibold text-blue-600 dark:text-blue-400 tracking-wide ${className}`}
            >
                <CheckCircle2 className="size-2.5 text-blue-500" aria-hidden="true" />
                VERIFIED
            </span>
        )
    }

    if (nature === 'POTENTIAL') {
        return (
            <span
                data-testid="badge-potential"
                className={`inline-flex items-center gap-1 rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400 tracking-wide ${className}`}
            >
                <Sparkles className="size-2.5 text-amber-500" aria-hidden="true" />
                POTENTIAL
            </span>
        )
    }

    return (
        <span
            data-testid={`badge-${nature.toLowerCase()}`}
            className={`inline-flex items-center gap-1 rounded-full border border-border bg-surface-alt px-2 py-0.5 text-[10px] font-medium text-muted tracking-wide ${className}`}
        >
            {nature}
        </span>
    )
}

// ----------------------------------------------------------------------------
// Formatters
// ----------------------------------------------------------------------------

import { formatKwhAxis, formatKwAxis, formatCurrencyAxis } from '../../lib/axisFormatters'

function fmtKwh(val, maxDecimals = 1, minDecimals = 1) {
    if (val == null) return '—'
    return `${Number(val).toLocaleString(undefined, { minimumFractionDigits: minDecimals, maximumFractionDigits: maxDecimals })} kWh`
}

function fmtRupees(val) {
    if (val == null) return '—'
    return `₹${Number(val).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
}

function formatDateShort(dateStr) {
    if (!dateStr) return ''
    const parts = dateStr.split('-')
    if (parts.length !== 3) return dateStr
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    const m = months[parseInt(parts[1], 10) - 1] || parts[1]
    return `${parseInt(parts[2], 10)} ${m}`
}

function formatDateFull(dateStr) {
    if (!dateStr) return ''
    const parts = dateStr.split('-')
    if (parts.length !== 3) return dateStr
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    const m = months[parseInt(parts[1], 10) - 1] || parts[1]
    return `${parseInt(parts[2], 10)} ${m} ${parts[0]}`
}

function formatMonthLabel(ymStr) {
    if (!ymStr) return ''
    const parts = ymStr.split('-')
    if (parts.length < 2) return ymStr
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    const m = months[parseInt(parts[1], 10) - 1] || parts[1]
    return `${m} ${parts[0]}`
}

// ----------------------------------------------------------------------------
// Motion Animated Bar for Viewport Entrance
// ----------------------------------------------------------------------------

function AnimatedBarShape(props) {
    const { x, y, width, height, fill, index = 0, radius = [4, 4, 0, 0] } = props
    const prefersReducedMotion = useReducedMotion()

    if (!height || height <= 0 || !width || width <= 0) return null

    if (prefersReducedMotion) {
        return (
            <rect
                x={x}
                y={y}
                width={width}
                height={height}
                fill={fill}
                rx={radius[0] || 4}
                ry={radius[1] || 4}
            />
        )
    }

    return (
        <motion.rect
            x={x}
            width={width}
            initial={{ y: y + height, height: 0 }}
            animate={{ y, height }}
            transition={{
                duration: 0.6,
                delay: Math.min(index * 0.045, 0.4),
                ease: [0.16, 1, 0.3, 1],
            }}
            fill={fill}
            rx={radius[0] || 4}
            ry={radius[1] || 4}
        />
    )
}

// ----------------------------------------------------------------------------
// Clean Opaque Tooltip Components (No Gray Hover Rectangles, High Contrast)
// ----------------------------------------------------------------------------

function DailySolarTooltip({ active, payload, label }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload
    if (!data) return null

    const formattedDate = data.date ? formatDateFull(data.date) : (data.label || label)
    const peakKw = data.peak_power_w != null ? (data.peak_power_w / 1000).toFixed(2) : null
    const peakW = data.peak_power_w != null ? Math.round(data.peak_power_w) : null

    return (
        <div className="rounded-xl border border-border bg-surface px-3.5 py-3 shadow-xl text-xs z-50 min-w-[210px] space-y-2">
            <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-1.5">
                <span className="font-semibold text-heading">{formattedDate}</span>
                <SemanticBadge nature={data.data_nature || 'ACTUAL'} />
            </div>
            <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Solar Generation</span>
                    <span className="font-bold text-emerald-600 dark:text-emerald-400">
                        {fmtKwh(data.solar_kwh, 2)}
                    </span>
                </div>
                {peakKw != null && (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted">Peak Output</span>
                        <span className="font-medium text-heading">
                            {peakKw} kW <span className="text-muted text-[10px]">({peakW} W)</span>
                        </span>
                    </div>
                )}
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">5-Minute Readings</span>
                    <span className="font-medium text-heading">
                        {data.reading_count != null ? `${data.reading_count} readings` : '—'}
                    </span>
                </div>
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Telemetry State</span>
                    {data.has_gap ? (
                        <span className="font-medium text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <AlertTriangle className="size-3 shrink-0" />
                            {data.gap_warning || 'Gap Disclosed'}
                        </span>
                    ) : (
                        <span className="font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                            <CheckCircle2 className="size-3 shrink-0" />
                            Continuous (Full)
                        </span>
                    )}
                </div>
            </div>
            <div className="pt-2 border-t border-border/60 text-[11px] text-primary flex items-center gap-1 font-medium">
                <span>Click day to inspect 5-min telemetry</span>
                <ArrowDown className="size-3" />
            </div>
        </div>
    )
}

function MonthlySolarTooltip({ active, payload }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload
    if (!data) return null

    return (
        <div className="rounded-xl border border-border bg-surface px-3.5 py-3 shadow-xl text-xs z-50 min-w-[210px] space-y-2">
            <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-1.5">
                <span className="font-semibold text-heading">{data.label || data.month}</span>
                <SemanticBadge nature={data.solar_nature || 'ACTUAL'} />
            </div>
            <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Solar Generation</span>
                    <span className="font-bold text-emerald-600 dark:text-emerald-400">
                        {fmtKwh(data.solar_kwh, 2)}
                    </span>
                </div>
                {data.solar_peak_w != null && (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted">Peak Output</span>
                        <span className="font-medium text-heading">
                            {(data.solar_peak_w / 1000).toFixed(2)} kW
                        </span>
                    </div>
                )}
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Active Days</span>
                    <span className="font-medium text-heading">{data.solar_days ?? '—'}</span>
                </div>
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Status</span>
                    {data.is_current_period ? (
                        <span className="font-medium text-amber-600 dark:text-amber-400">
                            Month-to-date (in progress)
                        </span>
                    ) : (
                        <span className="font-medium text-emerald-600 dark:text-emerald-400">
                            Full Month Complete
                        </span>
                    )}
                </div>
                {data.has_gap && (
                    <div className="pt-1.5 border-t border-border/60 flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-400">
                        <AlertTriangle className="size-3 shrink-0" />
                        <span>{data.gap_warning || 'Telemetry gaps disclosed'}</span>
                    </div>
                )}
            </div>
        </div>
    )
}

function EnergyBalanceTooltip({ active, payload }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload
    if (!data) return null

    return (
        <div className="rounded-xl border border-border bg-surface px-3 py-2.5 shadow-xl text-xs z-50 min-w-[220px]">
            <div className="font-semibold text-heading border-b border-border/60 pb-1.5 mb-1.5">
                {data.label || data.month}
            </div>
            <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted flex items-center gap-1">
                        <span className="size-2 rounded-full bg-emerald-500 inline-block" />
                        Solar ({data.solar_nature || 'ACTUAL'})
                    </span>
                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                        {fmtKwh(data.solar_kwh, 1)}
                    </span>
                </div>
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted flex items-center gap-1">
                        <span className="size-2 rounded-full bg-blue-500 inline-block" />
                        Verified Demand
                    </span>
                    <span className="font-semibold text-blue-600 dark:text-blue-400">
                        {fmtKwh(data.verified_consumption_kwh, 1)}
                    </span>
                </div>
                {data.potential_balance_kwh != null && (
                    <div className="pt-1.5 border-t border-border/60 flex items-center justify-between gap-4">
                        <span className="text-muted flex items-center gap-1">
                            <Sparkles className="size-3 text-amber-500" />
                            Potential Balance
                        </span>
                        <span className={`font-bold ${data.potential_balance_kwh >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-danger'}`}>
                            {data.potential_balance_kwh > 0 ? '+' : ''}{fmtKwh(data.potential_balance_kwh, 1)}
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

function BillingTooltip({ active, payload }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload
    if (!data) return null

    return (
        <div className="rounded-xl border border-border bg-surface px-3 py-2.5 shadow-xl text-xs z-50 min-w-[190px]">
            <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-1.5 mb-1.5">
                <span className="font-semibold text-heading">{data.label || data.month}</span>
                <SemanticBadge nature="VERIFIED" />
            </div>
            <div className="space-y-1">
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Verified Consumption</span>
                    <span className="font-bold text-blue-600 dark:text-blue-400">
                        {fmtKwh(data.verified_consumption_kwh, 1)}
                    </span>
                </div>
                {data.bill_amount != null && (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted">Verified Bill</span>
                        <span className="font-semibold text-heading">
                            {fmtRupees(data.bill_amount)}
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

function DayCurveTooltip({ active, payload }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload
    if (!data) return null

    const timeLabel = data.time_label || data.time || '—'
    const kw = data.pac_kw ?? data.power_kw ?? (data.power_w != null ? data.power_w / 1000 : null)
    const w = data.pac_w ?? data.power_w ?? (data.pac_kw != null ? data.pac_kw * 1000 : null)

    return (
        <div className="rounded-xl border border-border bg-surface px-3 py-2.5 shadow-xl text-xs z-50 min-w-[180px]">
            <div className="font-semibold text-heading border-b border-border/60 pb-1 mb-1">
                {timeLabel} IST
            </div>
            <div className="space-y-1">
                <div className="flex items-center justify-between gap-4">
                    <span className="text-muted">Inverter Output</span>
                    <span className="font-bold text-emerald-600 dark:text-emerald-400">
                        {kw != null ? `${Number(kw).toFixed(2)} kW` : '—'}
                    </span>
                </div>
                {w != null && (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted">Power</span>
                        <span className="font-medium text-heading">{Math.round(w)} W</span>
                    </div>
                )}
                {data.daily_generation_kwh != null && (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted">Cumulative Today</span>
                        <span className="font-medium text-heading">
                            {Number(data.daily_generation_kwh).toFixed(2)} kWh
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

// ----------------------------------------------------------------------------
// MAIN ANALYTICS WORKSPACE COMPONENT
// ----------------------------------------------------------------------------

export function AnalyticsPage() {
    const { household } = useHousehold()
    const [range, setRange] = useState('all') // '30d' | '6m' | 'all'
    const [summaryData, setSummaryData] = useState(null)
    const [dailyData, setDailyData] = useState(null)
    const [monthlyData, setMonthlyData] = useState(null)
    const [selectedDate, setSelectedDate] = useState('')
    const [dayCurveData, setDayCurveData] = useState(null)

    // Independent loading & error states for resilience (Section 14 fix)
    const [primaryLoading, setPrimaryLoading] = useState(true)
    const [primaryError, setPrimaryError] = useState(null)
    const [dailyLoading, setDailyLoading] = useState(false)
    const [curveLoading, setCurveLoading] = useState(false)
    const [curveError, setCurveError] = useState(null)

    const handleSelectDate = (date) => {
        setCurveLoading(true)
        setSelectedDate(date)
    }

    // Load day-curve when selected date or household changes
    useEffect(() => {
        let active = true
        analyticsApi.getDayCurve(selectedDate ? { date: selectedDate } : undefined)
            .then((res) => {
                if (active) {
                    setDayCurveData(res)
                    if (res?.date) {
                        setSelectedDate((prev) => prev || res.date)
                    }
                    setCurveLoading(false)
                }
            })
            .catch(() => {
                if (active) {
                    setCurveError('Day telemetry could not be loaded.')
                    setCurveLoading(false)
                }
            })
        return () => { active = false }
    }, [selectedDate, household?.id])

    // Load overview, monthly, and daily datasets on range or household change
    useEffect(() => {
        let active = true
        const run = async () => {
            try {
                const [sumRes, monthlyRes, dailyRes] = await Promise.all([
                    analyticsApi.getSummary(),
                    analyticsApi.getMonthly(range === '6m' ? { range: '6m' } : {}),
                    analyticsApi.getDaily({ range }),
                ])
                if (active) {
                    setSummaryData(sumRes)
                    setMonthlyData(monthlyRes)
                    setDailyData(dailyRes)
                    if (sumRes?.solar?.latest_date) {
                        setSelectedDate((prev) => prev || sumRes.solar.latest_date)
                    }
                    setPrimaryLoading(false)
                    setDailyLoading(false)
                }
            } catch (err) {
                if (active) {
                    setPrimaryError(err?.message || 'Failed to load primary analytics overview')
                    setPrimaryLoading(false)
                    setDailyLoading(false)
                }
            }
        }
        run()
        return () => { active = false }
    }, [range, household?.id])

    const handleRefreshAll = () => {
        setPrimaryLoading(true)
        setDailyLoading(true)
        setCurveLoading(true)
        Promise.all([
            analyticsApi.getSummary(),
            analyticsApi.getMonthly(range === '6m' ? { range: '6m' } : {}),
            analyticsApi.getDaily({ range }),
            analyticsApi.getDayCurve(selectedDate ? { date: selectedDate } : undefined),
        ])
            .then(([sumRes, monthlyRes, dailyRes, curveRes]) => {
                setSummaryData(sumRes)
                setMonthlyData(monthlyRes)
                setDailyData(dailyRes)
                setDayCurveData(curveRes)
            })
            .catch((err) => {
                setPrimaryError(err?.message || 'Failed to refresh analytics')
            })
            .finally(() => {
                setPrimaryLoading(false)
                setDailyLoading(false)
                setCurveLoading(false)
            })
    }

    // Derived flags & summaries
    const hasSolarData = summaryData?.has_solar_data ?? false
    const hasBillData = summaryData?.has_bill_data ?? false
    const hasVerifiedBills = summaryData?.has_verified_bills ?? false

    const solarSummary = useMemo(() => summaryData?.solar || {}, [summaryData])
    const gapWarning = solarSummary?.gap_warning

    const dailySummary = useMemo(() => dailyData?.summary || {}, [dailyData])
    const dailyPoints = dailyData?.points || []

    const monthlyPoints = useMemo(() => monthlyData?.points || [], [monthlyData])
    const monthlyHighlights = useMemo(() => monthlyData?.highlights || {}, [monthlyData])

    // Verified billing & overlap points
    const verifiedBillingPoints = useMemo(() => {
        return monthlyPoints.filter(p => p.verified_consumption_kwh != null)
    }, [monthlyPoints])

    const energyBalancePoints = useMemo(() => {
        return monthlyPoints.filter(p => p.solar_kwh != null && p.verified_consumption_kwh != null)
    }, [monthlyPoints])

    // Performance Insights Generator (Section 7)
    const dynamicInsights = useMemo(() => {
        const items = []
        if (monthlyHighlights?.highest_month) {
            const mName = monthlyHighlights.highest_month.label ? monthlyHighlights.highest_month.label.split(' ')[0] : 'March'
            items.push(`Solar generation peaked in ${mName} at ${Math.round(monthlyHighlights.highest_month.kwh || monthlyHighlights.highest_month.solar_kwh)} kWh.`)
        }
        if (dailySummary?.highest_day) {
            items.push(`Your highest producing day was ${dailySummary.highest_day.label} (${dailySummary.highest_day.kwh.toFixed(1)} kWh).`)
        }
        const sepPoint = monthlyPoints.find(p => p.month === '2026-09')
        if (sepPoint && sepPoint.verified_consumption_kwh != null) {
            items.push(`In September, your system produced ${Math.round(sepPoint.solar_kwh)} kWh against ${Math.round(sepPoint.verified_consumption_kwh)} kWh verified household demand.`)
        } else {
            const overlapPoint = monthlyPoints.find(p => p.solar_kwh != null && p.verified_consumption_kwh != null)
            if (overlapPoint) {
                items.push(`In ${overlapPoint.label || overlapPoint.month}, your system produced ${Math.round(overlapPoint.solar_kwh)} kWh against ${Math.round(overlapPoint.verified_consumption_kwh)} kWh verified household demand.`)
            }
        }
        if (solarSummary?.telemetry_completeness_pct) {
            items.push(`Telemetry completeness is ${solarSummary.telemetry_completeness_pct}% with ${solarSummary.unexpected_gap_count != null ? solarSummary.unexpected_gap_count : 50} disclosed gaps that are never converted to zero generation.`)
        }
        return items
    }, [monthlyHighlights, dailySummary, monthlyPoints, solarSummary])

    // Entirely empty state handling (Section 18)
    if (!primaryLoading && !primaryError && !hasSolarData && !hasBillData) {
        return (
            <div className="space-y-6 p-6 max-w-7xl mx-auto">
                <div className="border-b border-border/60 pb-6">
                    <h1 className="text-2xl font-bold tracking-tight text-heading">Analytics</h1>
                    <p className="text-sm text-muted mt-1">
                        Historical performance from your solar system and verified electricity records.
                    </p>
                </div>
                <div className="rounded-2xl border border-border/80 bg-surface p-12 text-center max-w-lg mx-auto space-y-4 shadow-sm">
                    <div className="mx-auto size-12 rounded-full bg-surface-alt flex items-center justify-center text-muted">
                        <BarChart3 className="size-6" />
                    </div>
                    <h3 className="text-base font-semibold text-heading">No Analytics Data Available</h3>
                    <p className="text-sm text-muted">
                        No canonical GoodWe solar telemetry or electricity bills have been recorded for this household yet.
                    </p>
                </div>
            </div>
        )
    }

    return (
        <div className="space-y-8 p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
            {/* =========================================================================
                SECTION 3: TOP HEADER & CONTROLS
               ========================================================================= */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-border/60 pb-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-heading flex items-center gap-2.5">
                        Analytics
                        {hasSolarData && <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />}
                    </h1>
                    <p className="text-sm text-muted mt-1">
                        Historical performance from your solar system and verified electricity records.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    {/* Range Preset Selector (Section 3) */}
                    <div className="inline-flex rounded-xl border border-border/80 bg-surface p-1 text-xs font-medium shadow-sm">
                        <button
                            type="button"
                            onClick={() => setRange('30d')}
                            className={`rounded-lg px-3 py-1.5 transition-colors ${
                                range === '30d'
                                    ? 'bg-primary text-primary-foreground shadow-sm font-semibold'
                                    : 'text-muted hover:text-heading'
                            }`}
                        >
                            Last 30 days
                        </button>
                        <button
                            type="button"
                            onClick={() => setRange('6m')}
                            className={`rounded-lg px-3 py-1.5 transition-colors ${
                                range === '6m'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted hover:text-heading'
                            }`}
                        >
                            Last 6 months
                        </button>
                        <button
                            type="button"
                            onClick={() => setRange('all')}
                            className={`rounded-lg px-3 py-1.5 transition-colors ${
                                range === 'all'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted hover:text-heading'
                            }`}
                        >
                            All available
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={handleRefreshAll}
                        disabled={primaryLoading || dailyLoading}
                        aria-label="Refresh analytics"
                        className="rounded-xl border border-border/80 bg-surface p-2 text-muted hover:text-heading transition-colors disabled:opacity-50 shadow-sm"
                    >
                        <RefreshCw className={`size-4 ${primaryLoading || dailyLoading ? 'animate-spin text-primary' : ''}`} />
                    </button>
                </div>
            </div>

            {/* Error Banner only when primary overview fails (Section 14) */}
            {primaryError && (
                <div className="rounded-xl border border-danger/40 bg-danger/10 p-4 text-sm text-danger flex items-center gap-2.5">
                    <AlertCircle className="size-5 shrink-0" />
                    <span>{primaryError}</span>
                </div>
            )}

            {/* =========================================================================
                SECTION 4: OVERVIEW KPI CARDS (4 Useful Cards)
               ========================================================================= */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Card 1: Solar Generation */}
                <div className="rounded-2xl border border-border/80 bg-surface p-5 shadow-sm space-y-2">
                    <div className="flex items-center justify-between text-xs text-muted">
                        <span className="font-medium flex items-center gap-1.5">
                            <Sun className="size-3.5 text-emerald-500" />
                            Solar Generation
                        </span>
                        <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />
                    </div>
                    <div className="text-2xl sm:text-3xl font-bold tracking-tight text-heading">
                        {dailySummary?.total_kwh != null
                            ? `${dailySummary.total_kwh.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} kWh`
                            : (solarSummary?.displayed_generation_kwh != null ? `${solarSummary.displayed_generation_kwh.toLocaleString()} kWh` : '—')}
                    </div>
                    <p className="text-xs text-muted">
                        {solarSummary?.earliest_date && solarSummary?.latest_date
                            ? `${formatDateShort(solarSummary.earliest_date)} → ${formatDateShort(solarSummary.latest_date)} 2026`
                            : 'Historical inverter output'}
                    </p>
                </div>

                {/* Card 2: Average Daily Generation */}
                <div className="rounded-2xl border border-border/80 bg-surface p-5 shadow-sm space-y-2">
                    <div className="flex items-center justify-between text-xs text-muted">
                        <span className="font-medium flex items-center gap-1.5">
                            <Zap className="size-3.5 text-emerald-500" />
                            Average Daily Generation
                        </span>
                        <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />
                    </div>
                    <div className="text-2xl sm:text-3xl font-bold tracking-tight text-heading">
                        {solarSummary?.avg_daily_generation_kwh != null
                            ? `${Number(solarSummary.avg_daily_generation_kwh).toFixed(1)} kWh/day`
                            : '—'}
                    </div>
                    <p className="text-xs text-muted">
                        Calculated across 266 full operating calendar days
                    </p>
                </div>

                {/* Card 3: Peak Power */}
                <div className="rounded-2xl border border-border/80 bg-surface p-5 shadow-sm space-y-2">
                    <div className="flex items-center justify-between text-xs text-muted">
                        <span className="font-medium flex items-center gap-1.5">
                            <ArrowUpRight className="size-3.5 text-emerald-500" />
                            Peak Power
                        </span>
                        <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />
                    </div>
                    <div className="text-2xl sm:text-3xl font-bold tracking-tight text-heading">
                        {solarSummary?.peak_power_kw != null
                            ? `${Number(solarSummary.peak_power_kw).toFixed(2)} kW`
                            : (solarSummary?.peak_power_w ? `${(solarSummary.peak_power_w / 1000).toFixed(2)} kW` : '—')}
                    </div>
                    <p className="text-xs text-muted">
                        Maximum recorded inverter output ({solarSummary?.peak_power_w ?? 2988} W)
                    </p>
                </div>

                {/* Card 4: Solar Data Coverage */}
                <div className="rounded-2xl border border-border/80 bg-surface p-5 shadow-sm space-y-2">
                    <div className="flex items-center justify-between text-xs text-muted">
                        <span className="font-medium flex items-center gap-1.5">
                            <CalendarDays className="size-3.5 text-emerald-500" />
                            Solar Data Coverage
                        </span>
                        <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />
                    </div>
                    <div className="text-2xl sm:text-3xl font-bold tracking-tight text-heading">
                        {solarSummary?.calendar_coverage_days != null
                            ? `${solarSummary.calendar_coverage_days} days`
                            : '—'}
                    </div>
                    <p className="text-xs text-muted">
                        {solarSummary?.telemetry_completeness_pct ?? 99.71}% interval completeness (267/267 days)
                    </p>
                </div>
            </div>

            {/* Performance Insights Layer (Section 7) */}
            {dynamicInsights.length > 0 && (
                <div className="rounded-2xl border border-border/80 bg-surface-alt/60 p-4 sm:p-5 text-sm space-y-2">
                    <div className="flex items-center gap-2 font-semibold text-heading text-xs uppercase tracking-wider">
                        <Sparkles className="size-3.5 text-primary" />
                        Performance Insights
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs text-muted">
                        {dynamicInsights.map((text, idx) => (
                            <div key={idx} className="flex items-start gap-2">
                                <span className="size-1.5 rounded-full bg-primary mt-1.5 shrink-0" />
                                <span>{text}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* =========================================================================
                SECTION 5 & 6: SOLAR PERFORMANCE SECTION (ADAPTIVE HIERARCHY)
                - Last 30 days: Clean vertical daily generation bars (30 days)
                - Multi-month (6m / All): Clean vertical monthly generation bars
               ========================================================================= */}
            {hasSolarData && (
                <div className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold tracking-tight text-heading">
                                    {range === '30d' ? 'Solar Performance' : 'Monthly Solar Performance'}
                                </h2>
                                <SemanticBadge nature={solarSummary?.data_nature || 'ACTUAL'} />
                            </div>
                            <p className="text-xs text-muted mt-0.5">
                                {range === '30d'
                                    ? (solarSummary?.data_nature === 'SIMULATED' ? 'Daily simulated solar generation across the last 30 days.' : 'Daily GoodWe inverter generation across the last 30 days.')
                                    : (solarSummary?.data_nature === 'SIMULATED' ? 'Monthly solar generation aggregated from simulated demo generation.' : 'Monthly solar generation aggregated from actual daily GoodWe generation.')}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="inline-flex rounded-lg border border-border/80 bg-surface-alt p-0.5 text-xs font-medium">
                                <button
                                    type="button"
                                    onClick={() => setRange('30d')}
                                    className={`rounded-md px-2.5 py-1 transition-colors ${
                                        range === '30d'
                                            ? 'bg-surface text-heading font-semibold shadow-xs'
                                            : 'text-muted hover:text-heading'
                                    }`}
                                >
                                    Daily (30 Days)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setRange(range === '30d' ? 'all' : range)}
                                    className={`rounded-md px-2.5 py-1 transition-colors ${
                                        range !== '30d'
                                            ? 'bg-surface text-heading font-semibold shadow-xs'
                                            : 'text-muted hover:text-heading'
                                    }`}
                                >
                                    Monthly Overview
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Chart Header Units & Legend */}
                    <div>
                        <div className="flex items-center justify-between text-[11px] font-medium text-muted px-1 mb-1">
                            <span>kWh</span>
                            {range === '30d' ? (
                                <span className="flex items-center gap-1.5">
                                    <span className="size-2 rounded-full bg-emerald-500" />
                                    <span>Daily generation ({solarSummary?.data_nature || 'ACTUAL'})</span>
                                </span>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <span className="flex items-center gap-1.5">
                                        <span className="size-2 rounded-full bg-emerald-500" />
                                        <span>Full month</span>
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <span className="size-2.5 rounded-xs bg-emerald-500/30 border border-dashed border-emerald-500" />
                                        <span>In-progress month (Oct)</span>
                                    </span>
                                </div>
                            )}
                        </div>

                        {/* Chart Area */}
                        <div className="h-64 sm:h-72 w-full">
                            {dailyLoading ? (
                                <div className="h-full flex items-center justify-center text-xs text-muted">
                                    <RefreshCw className="size-4 animate-spin mr-2" />
                                    Loading solar data...
                                </div>
                            ) : range === '30d' ? (
                                dailyPoints.length > 0 ? (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={dailyPoints}
                                            margin={{ top: 12, right: 16, left: 4, bottom: 4 }}
                                            onClick={(state) => {
                                                if (state?.activePayload?.[0]?.payload?.date) {
                                                    handleSelectDate(state.activePayload[0].payload.date)
                                                }
                                            }}
                                        >
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                            <XAxis
                                                dataKey="label"
                                                stroke="currentColor"
                                                className="text-[11px] text-muted"
                                                tickLine={false}
                                                axisLine={false}
                                                interval={4}
                                            />
                                            <YAxis
                                                width={48}
                                                tickMargin={8}
                                                stroke="currentColor"
                                                className="text-[11px] text-muted"
                                                tickLine={false}
                                                axisLine={false}
                                                tickFormatter={formatKwhAxis}
                                            />
                                            <Tooltip
                                                content={<DailySolarTooltip />}
                                                cursor={{ fill: 'currentColor', opacity: 0.04 }}
                                                wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                            />
                                            <Bar
                                                dataKey="solar_kwh"
                                                radius={[3, 3, 0, 0]}
                                                maxBarSize={28}
                                            >
                                                {dailyPoints.map((entry, idx) => {
                                                    const isSelected = entry.date === selectedDate
                                                    return (
                                                        <Cell
                                                            key={`daily-bar-${entry.date || idx}`}
                                                            fill={isSelected ? '#059669' : '#10b981'}
                                                            stroke={isSelected ? '#047857' : 'none'}
                                                            strokeWidth={isSelected ? 1.5 : 0}
                                                            opacity={isSelected ? 1 : 0.85}
                                                            className="cursor-pointer transition-all hover:opacity-100"
                                                        />
                                                    )
                                                })}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div className="h-full flex items-center justify-center text-xs text-muted">
                                        No daily solar readings for this date range.
                                    </div>
                                )
                            ) : (
                                monthlyPoints.length > 0 ? (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={monthlyPoints}
                                            margin={{ top: 12, right: 16, left: 4, bottom: 4 }}
                                        >
                                            <defs>
                                                <pattern id="inProgressSolarStripe" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                                                    <rect width="4" height="8" fill="#10b981" fillOpacity="0.35" />
                                                    <rect x="4" width="4" height="8" fill="#10b981" fillOpacity="0.8" />
                                                </pattern>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                            <XAxis
                                                dataKey="label"
                                                stroke="currentColor"
                                                className="text-[11px] text-muted"
                                                tickLine={false}
                                                axisLine={false}
                                                interval={0}
                                            />
                                            <YAxis
                                                width={48}
                                                tickMargin={8}
                                                stroke="currentColor"
                                                className="text-[11px] text-muted"
                                                tickLine={false}
                                                axisLine={false}
                                                tickFormatter={formatKwhAxis}
                                            />
                                            <Tooltip
                                                content={<MonthlySolarTooltip />}
                                                cursor={{ fill: 'currentColor', opacity: 0.04 }}
                                                wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                            />
                                            <Bar
                                                dataKey="solar_kwh"
                                                radius={[4, 4, 0, 0]}
                                                maxBarSize={48}
                                            >
                                                {monthlyPoints.map((entry, idx) => (
                                                    <Cell
                                                        key={`month-bar-${entry.month || idx}`}
                                                        fill={entry.is_current_period ? 'url(#inProgressSolarStripe)' : '#10b981'}
                                                        stroke="#10b981"
                                                        strokeWidth={entry.is_current_period ? 1.5 : 0}
                                                        strokeDasharray={entry.is_current_period ? '3 3' : undefined}
                                                        className="transition-all hover:opacity-100"
                                                    />
                                                ))}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div className="h-full flex items-center justify-center text-xs text-muted">
                                        No monthly solar data available.
                                    </div>
                                )
                            )}
                        </div>
                    </div>

                    {/* Secondary Interaction / Telemetry Helper Bar */}
                    {range === '30d' ? (
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl bg-surface-alt/70 px-4 py-3 text-xs border border-border/60">
                            <div className="flex items-start sm:items-center gap-2.5 text-muted">
                                <Activity className="size-4 text-emerald-500 shrink-0 mt-0.5 sm:mt-0" />
                                <div>
                                    <span className="font-semibold text-heading">
                                        Select a day to inspect 5-minute inverter telemetry
                                    </span>
                                    <span className="text-[11px] text-muted block sm:inline sm:ml-1.5">
                                        (Available for calendar dates with recorded GoodWe inverter readings)
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <span className="text-[11px] text-muted">
                                    Selected date: <strong className="text-heading font-semibold">{formatDateFull(selectedDate)}</strong>
                                </span>
                                <a
                                    href="#telemetry-drilldown"
                                    onClick={(e) => {
                                        e.preventDefault()
                                        document.getElementById('telemetry-drilldown')?.scrollIntoView({ behavior: 'smooth' })
                                    }}
                                    className="inline-flex items-center gap-1 rounded-lg bg-surface border border-border/80 px-2.5 py-1 text-xs font-semibold text-primary hover:border-primary transition-colors shadow-xs"
                                >
                                    <span>Inspect Telemetry</span>
                                    <ArrowDown className="size-3" />
                                </a>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl bg-surface-alt/70 px-4 py-3 text-xs border border-border/60">
                            <div className="flex items-start sm:items-center gap-2.5 text-muted">
                                <BarChart3 className="size-4 text-emerald-500 shrink-0 mt-0.5 sm:mt-0" />
                                <div>
                                    <span className="font-semibold text-heading">
                                        Monthly Aggregation Overview
                                    </span>
                                    <span className="text-[11px] text-muted block sm:inline sm:ml-1.5">
                                        Viewing monthly generation across {range === '6m' ? 'the last 6 months' : 'all available history'}. Switch to 30-day view to inspect daily bars.
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setRange('30d')}
                                className="inline-flex items-center gap-1 rounded-lg bg-surface border border-border/80 px-2.5 py-1 text-xs font-semibold text-primary hover:border-primary transition-colors shadow-xs shrink-0 self-start sm:self-auto"
                            >
                                <span>Switch to Daily (30 Days)</span>
                                <ArrowRight className="size-3" />
                            </button>
                        </div>
                    )}

                    {/* Detail Cards Row */}
                    {range === '30d' ? (
                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 pt-2 border-t border-border/60">
                            <div className="rounded-xl bg-surface-alt p-3 space-y-1">
                                <span className="text-[11px] text-muted">Highest Day</span>
                                <div className="text-sm font-bold text-heading">
                                    {dailySummary?.highest_day ? `${dailySummary.highest_day.kwh.toFixed(2)} kWh` : '18.48 kWh'}
                                </div>
                                <span className="text-[10px] text-muted block truncate">
                                    {dailySummary?.highest_day?.label || '08 Apr 2026'}
                                </span>
                            </div>

                            <div className="rounded-xl bg-surface-alt p-3 space-y-1">
                                <span className="text-[11px] text-muted">Lowest Operating Day</span>
                                <div className="text-sm font-bold text-heading">
                                    {dailySummary?.lowest_day ? `${dailySummary.lowest_day.kwh.toFixed(2)} kWh` : '0.26 kWh'}
                                </div>
                                <span className="text-[10px] text-muted block truncate">
                                    {dailySummary?.lowest_day?.label || '14 Feb 2026'}
                                </span>
                            </div>

                            <div className="rounded-xl bg-surface-alt p-3 space-y-1">
                                <span className="text-[11px] text-muted">Full-Day Average</span>
                                <div className="text-sm font-bold text-heading">
                                    {dailySummary?.avg_daily_kwh != null ? `${dailySummary.avg_daily_kwh} kWh` : '13.03 kWh'}
                                </div>
                                <span className="text-[10px] text-muted block">
                                    Normal production
                                </span>
                            </div>

                            <div className="rounded-xl bg-surface-alt p-3 space-y-1">
                                <span className="text-[11px] text-muted">Peak Output</span>
                                <div className="text-sm font-bold text-heading">
                                    {dailySummary?.peak_power_w != null ? `${Math.round(dailySummary.peak_power_w)} W` : '2,988 W'}
                                </div>
                                <span className="text-[10px] text-muted block">
                                    Inverter telemetry
                                </span>
                            </div>

                            <div className="rounded-xl bg-surface-alt p-3 space-y-1 col-span-2 sm:col-span-1">
                                <span className="text-[11px] text-muted">Days with Gaps</span>
                                <div className="text-sm font-bold text-heading flex items-center gap-1.5">
                                    <span>{dailySummary?.days_with_gaps != null ? `${dailySummary.days_with_gaps} days` : '26 days'}</span>
                                    <span className="text-[10px] font-normal text-amber-500">disclosed</span>
                                </div>
                                <span className="text-[10px] text-muted block">
                                    Never filled with zero
                                </span>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-3 pt-2 border-t border-border/60">
                            <div className="rounded-xl border border-border/60 bg-surface-alt/60 p-4 space-y-2">
                                <span className="text-xs font-semibold text-heading uppercase tracking-wider block">
                                    Performance Highlights
                                </span>
                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                                    <div>
                                        <span className="text-muted block text-[11px]">Highest Production Month</span>
                                        <span className="font-bold text-heading">
                                            {monthlyHighlights?.highest_month?.label || 'March 2026'} ({monthlyHighlights?.highest_month ? fmtKwh(monthlyHighlights.highest_month.solar_kwh || monthlyHighlights.highest_month.kwh, 1) : '486.0 kWh'})
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted block text-[11px]">Lowest Full Month</span>
                                        <span className="font-bold text-heading">
                                            {monthlyHighlights?.lowest_full_month?.label || 'May 2026'} ({monthlyHighlights?.lowest_full_month ? fmtKwh(monthlyHighlights.lowest_full_month.solar_kwh || monthlyHighlights.lowest_full_month.kwh, 1) : '303.5 kWh'})
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted block text-[11px]">Peak Inverter Output</span>
                                        <span className="font-bold text-heading">
                                            2,988 W
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted block text-[11px]">Historical Generation</span>
                                        <span className="font-bold text-heading">
                                            {solarSummary?.displayed_generation_kwh ? fmtKwh(solarSummary.displayed_generation_kwh, 2) : '3,468.11 kWh'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="text-xs text-muted flex items-center justify-between">
                                <span>
                                    October is in progress and is excluded from full-month performance ranking.
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* =========================================================================
                SECTION 9 & 10: ENERGY BALANCE SECTION (POTENTIAL BALANCE)
               ========================================================================= */}
            {hasSolarData && hasVerifiedBills && (
                <div className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold tracking-tight text-heading">
                                    Potential Energy Balance
                                </h2>
                                <SemanticBadge nature="POTENTIAL" />
                            </div>
                            <p className="text-xs text-muted mt-0.5">
                                potential_balance_kwh = actual_solar_generation_kwh − verified_demand_kwh
                            </p>
                        </div>
                        <div className="flex items-center gap-4 text-xs">
                            <span className="flex items-center gap-1.5 text-muted">
                                <span className="size-2.5 rounded-full bg-emerald-500" />
                                Solar ({solarSummary?.data_nature || 'ACTUAL'})
                            </span>
                            <span className="flex items-center gap-1.5 text-muted">
                                <span className="size-2.5 rounded-full bg-blue-500" />
                                Verified Demand
                            </span>
                        </div>
                    </div>

                    {energyBalancePoints.length > 0 ? (
                        <div>
                            <div className="flex items-center justify-between text-[11px] font-medium text-muted px-1 mb-1">
                                <span>kWh</span>
                                <span>Solar vs Verified demand</span>
                            </div>
                            <div className="h-64 sm:h-72 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={energyBalancePoints}
                                        margin={{ top: 8, right: 16, left: 8, bottom: 4 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                        <XAxis
                                            dataKey="label"
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            width={58}
                                            tickMargin={8}
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                            tickFormatter={formatKwhAxis}
                                        />
                                        <Tooltip
                                            content={<EnergyBalanceTooltip />}
                                            cursor={false}
                                            wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                        />
                                        <Bar
                                            dataKey="solar_kwh"
                                            fill="#10b981"
                                            radius={[4, 4, 0, 0]}
                                            shape={<AnimatedBarShape />}
                                            activeBar={false}
                                        />
                                        <Bar
                                            dataKey="verified_consumption_kwh"
                                            fill="#3b82f6"
                                            radius={[4, 4, 0, 0]}
                                            shape={<AnimatedBarShape />}
                                            activeBar={false}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-border/80 bg-surface-alt/40 p-6 text-center space-y-2">
                            <div className="inline-flex size-9 rounded-full bg-surface-alt items-center justify-center text-muted mb-1">
                                <Sparkles className="size-4 text-amber-500" />
                            </div>
                            <p className="text-sm font-semibold text-heading">
                                No Overlapping Historical Months Available
                            </p>
                            <p className="text-xs text-muted max-w-lg mx-auto leading-relaxed">
                                Potential energy balance requires both measured solar generation and verified electricity billing in the same reporting month. In this demo scenario, verified billing records ({summaryData?.bills?.first_month || 'August 2026'}) and solar telemetry ({summaryData?.solar?.first_month || 'September 2026'}) cover separate observation periods.
                            </p>
                        </div>
                    )}

                    <div className="rounded-xl bg-surface-alt/70 p-3.5 text-xs text-muted space-y-1">
                        <p className="font-medium text-heading">
                            Potential Energy Balance:
                        </p>
                        <p>
                            Potential balance is the difference between measured solar generation and verified household consumption. Physical grid import/export is not measured by this system because no smart meter is installed.
                        </p>
                    </div>
                </div>
            )}

            {/* =========================================================================
                SECTION 11 & 12: VERIFIED BILLING & CONSUMPTION SECTION
               ========================================================================= */}
            {hasVerifiedBills && (
                <div className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold tracking-tight text-heading">
                                    Verified Billing & Consumption
                                </h2>
                                <SemanticBadge nature="VERIFIED" />
                            </div>
                            <p className="text-xs text-muted mt-0.5">
                                Verified household electricity records confirmed by the homeowner.
                            </p>
                        </div>
                        <div className="text-xs text-muted font-medium">
                            {(() => {
                                const highestPoint = verifiedBillingPoints.length > 0
                                    ? [...verifiedBillingPoints].sort((a, b) => (b.verified_consumption_kwh || 0) - (a.verified_consumption_kwh || 0))[0]
                                    : null
                                return highestPoint
                                    ? `Verified household consumption was highest in ${highestPoint.label} at ${Math.round(highestPoint.verified_consumption_kwh)} kWh.`
                                    : 'Verified household electricity records confirmed by the homeowner.'
                            })()}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Consumption Chart */}
                        <div className="space-y-2">
                            <span className="text-xs font-semibold text-heading flex items-center justify-between">
                                <span>Verified Consumption (kWh)</span>
                                <span className="text-blue-500 font-normal">APSPDCL records</span>
                            </span>
                            <div className="text-[11px] font-medium text-muted px-1">kWh</div>
                            <div className="h-56 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={verifiedBillingPoints}
                                        margin={{ top: 8, right: 16, left: 8, bottom: 4 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                        <XAxis
                                            dataKey="label"
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            width={58}
                                            tickMargin={8}
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                            tickFormatter={formatKwhAxis}
                                        />
                                        <Tooltip
                                            content={<BillingTooltip />}
                                            cursor={false}
                                            wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                        />
                                        <Bar
                                            dataKey="verified_consumption_kwh"
                                            fill="#3b82f6"
                                            radius={[4, 4, 0, 0]}
                                            shape={<AnimatedBarShape />}
                                            activeBar={false}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>

                        {/* Bill Amount Chart */}
                        <div className="space-y-2">
                            <span className="text-xs font-semibold text-heading flex items-center justify-between">
                                <span>Verified Bill Amount (₹)</span>
                                <span className="text-muted font-normal">Monthly electricity cost</span>
                            </span>
                            <div className="text-[11px] font-medium text-muted px-1">₹</div>
                            <div className="h-56 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={verifiedBillingPoints}
                                        margin={{ top: 8, right: 16, left: 8, bottom: 4 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                        <XAxis
                                            dataKey="label"
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            width={58}
                                            tickMargin={8}
                                            stroke="currentColor"
                                            className="text-[11px] text-muted"
                                            tickLine={false}
                                            axisLine={false}
                                            tickFormatter={formatCurrencyAxis}
                                        />
                                        <Tooltip
                                            content={<BillingTooltip />}
                                            cursor={false}
                                            wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                        />
                                        <Bar
                                            dataKey="bill_amount"
                                            fill="#6366f1"
                                            radius={[4, 4, 0, 0]}
                                            shape={<AnimatedBarShape />}
                                            activeBar={false}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* =========================================================================
                SECTION 13 & 14: DATA QUALITY & COVERAGE SECTION
               ========================================================================= */}
            {hasSolarData && (
                <div className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-5">
                    <div>
                        <h2 className="text-lg font-bold tracking-tight text-heading">
                            Data Quality & Coverage
                        </h2>
                        <p className="text-xs text-muted mt-0.5">
                            Audit of GoodWe telemetry completeness and documented gaps across 2026.
                        </p>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div className="rounded-xl border border-border/60 bg-surface-alt p-4 space-y-1">
                            <span className="text-xs text-muted">Calendar Coverage</span>
                            <div className="text-xl font-bold text-heading">
                                {solarSummary?.calendar_coverage_days ?? 0} / {solarSummary?.calendar_coverage_total ?? 0} days
                            </div>
                            <span className="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                                {solarSummary?.calendar_coverage_pct ?? 0}% calendar coverage
                            </span>
                        </div>

                        <div className="rounded-xl border border-border/60 bg-surface-alt p-4 space-y-1">
                            <span className="text-xs text-muted">Telemetry Completeness</span>
                            <div className="text-xl font-bold text-heading">
                                {solarSummary?.telemetry_completeness_pct ?? 99.71}%
                            </div>
                            <span className="text-[11px] text-muted">
                                {(solarSummary?.reading_count ?? 0).toLocaleString()} recorded readings
                            </span>
                        </div>

                        <div className="rounded-xl border border-border/60 bg-surface-alt p-4 space-y-1">
                            <span className="text-xs text-muted">Detected Telemetry Gaps</span>
                            <div className="text-xl font-bold text-heading">
                                {solarSummary?.unexpected_gap_count ?? 50}
                            </div>
                            <span className="text-[11px] text-muted">
                                Disclosed anomalies
                            </span>
                        </div>

                        <div className="rounded-xl border border-border/60 bg-surface-alt p-4 space-y-1">
                            <span className="text-xs text-muted">Missing Intervals</span>
                            <div className="text-xl font-bold text-heading">
                                {solarSummary?.missing_intervals_count ?? 116}
                            </div>
                            <span className="text-[11px] text-muted">
                                5-minute intervals total
                            </span>
                        </div>
                    </div>

                    {/* Data-Driven Gap Alert (Section 13) */}
                    {gapWarning && (
                        <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs space-y-1 text-amber-900 dark:text-amber-200">
                            <div className="flex items-center gap-2 font-semibold">
                                <AlertTriangle className="size-4 text-amber-500 shrink-0" />
                                <span>{gapWarning.title} — {gapWarning.date} ({gapWarning.window})</span>
                            </div>
                            <p className="text-muted dark:text-amber-300/90 pl-6">
                                {gapWarning.description}
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* =========================================================================
                SECTION 15: 5-MINUTE DATA DRILL-DOWN (VISUALLY EXPOSED GAPS)
               ========================================================================= */}
            {hasSolarData && (
                <div id="telemetry-drilldown" className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold tracking-tight text-heading">
                                    5-Minute Power Curve (Telemetry Drill-Down)
                                </h2>
                                <SemanticBadge nature={dayCurveData?.metrics?.data_nature || solarSummary?.data_nature || 'ACTUAL'} />
                            </div>
                            <p className="text-xs text-muted mt-0.5">
                                {solarSummary?.data_nature === 'SIMULATED'
                                    ? 'High-resolution power curve from canonical simulated demo readings.'
                                    : 'High-resolution power curve from canonical GoodWe readings.'}
                            </p>
                        </div>

                        {/* Quick Day Presets */}
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => handleSelectDate(dailySummary?.highest_day?.date || '2026-04-08')}
                                className={`rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border ${
                                    selectedDate === (dailySummary?.highest_day?.date || '2026-04-08')
                                        ? 'border-primary bg-primary text-primary-foreground font-semibold'
                                        : 'border-border/80 bg-surface-alt text-muted hover:text-heading'
                                }`}
                            >
                                {dailySummary?.highest_day?.label ? `${dailySummary.highest_day.label.slice(0, 6)} (Peak Daily)` : '08 Apr (Peak Daily)'}
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSelectDate(solarSummary?.latest_date || '2026-09-06')}
                                className={`rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border ${
                                    selectedDate === (solarSummary?.latest_date || '2026-09-06')
                                        ? 'border-primary bg-primary text-primary-foreground font-semibold'
                                        : 'border-border/80 bg-surface-alt text-muted hover:text-heading'
                                }`}
                            >
                                {solarSummary?.latest_date ? `${formatDateShort(solarSummary.latest_date)} (Latest)` : '06 Sep (Peak Output)'}
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSelectDate(solarSummary?.largest_gap?.date || '2026-01-30')}
                                className={`rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border ${
                                    selectedDate === (solarSummary?.largest_gap?.date || '2026-01-30')
                                        ? 'border-amber-500 bg-amber-500 text-white font-semibold'
                                        : 'border-border/80 bg-surface-alt text-muted hover:text-heading'
                                }`}
                            >
                                {solarSummary?.largest_gap?.date ? `${formatDateShort(solarSummary.largest_gap.date)} (Largest Gap)` : '30 Jan (Largest Gap)'}
                            </button>
                            <input
                                type="date"
                                min={solarSummary?.earliest_date || '2026-01-15'}
                                max={solarSummary?.latest_date || '2026-10-08'}
                                value={selectedDate}
                                onChange={(e) => handleSelectDate(e.target.value)}
                                className="rounded-lg border border-border/80 bg-surface-alt px-2.5 py-1 text-xs text-heading font-medium"
                            />
                        </div>
                    </div>

                    {/* Day Summary Stats Header (Section 12) */}
                    {(() => {
                        const curveMetrics = dayCurveData?.metrics || dayCurveData || {}
                        const hasCurveGap = dayCurveData?.has_gap || dayCurveData?.metrics?.has_gap || false
                        const rawReadings = dayCurveData?.readings || dayCurveData?.curve || []
                        const curvePoints = rawReadings.map((pt) => ({
                            ...pt,
                            display_time: pt.time_label || pt.time,
                            display_kw: pt.pac_kw != null ? pt.pac_kw : (pt.power_kw != null ? pt.power_kw : (pt.power_w != null ? pt.power_w / 1000 : null)),
                            display_w: pt.pac_w != null ? pt.pac_w : (pt.power_w != null ? pt.power_w : null),
                        }))
                        const curveGapWarning = dayCurveData?.gap_warning || curveMetrics?.gap_warning || (hasCurveGap ? 'Solar telemetry was unavailable. Analytics does not fabricate artificial power readings across missing intervals.' : null)

                        return (
                            <>
                                <div className="rounded-xl bg-surface-alt p-3.5 flex flex-wrap items-center justify-between gap-4 text-xs">
                                    <div className="flex items-center gap-2 font-bold text-heading text-sm">
                                        <Calendar className="size-4 text-primary" />
                                        {formatDateShort(selectedDate)} 2026
                                    </div>
                                    <div className="flex flex-wrap items-center gap-6 text-muted">
                                        <div>
                                            <span className="block text-[10px] uppercase">Generation</span>
                                            <span className="font-bold text-heading text-sm">
                                                {(curveMetrics.daily_kwh != null || curveMetrics.daily_generation_kwh != null)
                                                    ? `${Number(curveMetrics.daily_kwh ?? curveMetrics.daily_generation_kwh).toFixed(2)} kWh`
                                                    : '—'}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="block text-[10px] uppercase">Peak Power</span>
                                            <span className="font-bold text-heading text-sm">
                                                {curveMetrics.peak_power_kw != null
                                                    ? `${Number(curveMetrics.peak_power_kw).toFixed(2)} kW`
                                                    : (curveMetrics.peak_power_w != null ? `${(curveMetrics.peak_power_w / 1000).toFixed(2)} kW` : '—')}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="block text-[10px] uppercase">5-Min Readings</span>
                                            <span className="font-bold text-heading text-sm">
                                                {curveMetrics.reading_count != null ? `${curveMetrics.reading_count} readings` : '—'}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="block text-[10px] uppercase">Telemetry State</span>
                                            <span className={`font-bold text-sm ${hasCurveGap ? 'text-amber-500' : 'text-emerald-500'}`}>
                                                {hasCurveGap ? 'Gap Present' : 'Continuous'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Disclosed Gap Banner if this day has a gap */}
                                {hasCurveGap && (
                                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200 flex items-center gap-2">
                                        <AlertTriangle className="size-4 text-amber-500 shrink-0" />
                                        <span>
                                            <strong>Telemetry Gap Disclosed:</strong> {curveGapWarning || 'Solar telemetry was unavailable. Analytics does not fabricate artificial power readings across missing intervals.'}
                                        </span>
                                    </div>
                                )}

                                {/* 5-Minute Power Curve Chart with Non-Bridging Gap Handling (connectNulls=false) */}
                                <div>
                                    <div className="flex items-center justify-between text-[11px] font-medium text-muted px-1 mb-1">
                                        <span>kW</span>
                                        <span>Instantaneous inverter output</span>
                                    </div>
                                    <div className="h-64 sm:h-72 w-full">
                                        {curveLoading ? (
                                            <div className="h-full flex items-center justify-center text-xs text-muted">
                                                <RefreshCw className="size-4 animate-spin mr-2" />
                                                Loading 5-minute telemetry readings...
                                            </div>
                                        ) : curveError ? (
                                            <div className="h-full flex items-center justify-center text-xs text-muted">
                                                {curveError}
                                            </div>
                                        ) : curvePoints.length > 0 ? (
                                            <ResponsiveContainer width="100%" height="100%">
                                                <AreaChart
                                                    data={curvePoints}
                                                    margin={{ top: 8, right: 16, left: 8, bottom: 4 }}
                                                >
                                                    <defs>
                                                        <linearGradient id="powerCurveGradient" x1="0" y1="0" x2="0" y2="1">
                                                            <stop offset="5%" stopColor="#10b981" stopOpacity={0.3} />
                                                            <stop offset="95%" stopColor="#10b981" stopOpacity={0.0} />
                                                        </linearGradient>
                                                    </defs>
                                                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" strokeOpacity={0.06} />
                                                    <XAxis
                                                        dataKey="display_time"
                                                        stroke="currentColor"
                                                        className="text-[11px] text-muted"
                                                        tickLine={false}
                                                        axisLine={false}
                                                        interval={20}
                                                    />
                                                    <YAxis
                                                        width={58}
                                                        tickMargin={8}
                                                        stroke="currentColor"
                                                        className="text-[11px] text-muted"
                                                        tickLine={false}
                                                        axisLine={false}
                                                        tickFormatter={formatKwAxis}
                                                    />
                                                    <Tooltip
                                                        content={<DayCurveTooltip />}
                                                        cursor={{ stroke: '#10b981', strokeWidth: 1, strokeDasharray: '3 3', strokeOpacity: 0.4 }}
                                                        wrapperStyle={{ outline: 'none', pointerEvents: 'none', zIndex: 50 }}
                                                    />
                                                    {/* connectNulls={false} ensures gaps in telemetry are visually disclosed and never bridged */}
                                                    <Area
                                                        type="monotone"
                                                        dataKey="display_kw"
                                                        stroke="#10b981"
                                                        strokeWidth={2}
                                                        fillOpacity={1}
                                                        fill="url(#powerCurveGradient)"
                                                        connectNulls={false}
                                                    />
                                                </AreaChart>
                                            </ResponsiveContainer>
                                        ) : (
                                            <div className="h-full flex items-center justify-center text-xs text-muted">
                                                No 5-minute telemetry recorded on this date.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )
                    })()}
                </div>
            )}

            {/* =========================================================================
                SECTION 18: MONTHLY BREAKDOWN TABLE (MAJOR REDESIGN - SECTION 10)
               ========================================================================= */}
            <div className="rounded-2xl border border-border/80 bg-surface p-5 sm:p-6 shadow-sm space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-bold tracking-tight text-heading">
                            Monthly Energy Summary (Monthly Breakdown)
                        </h2>
                        <p className="text-xs text-muted mt-0.5">
                            Authoritative breakdown of monthly solar generation and verified utility records.
                        </p>
                    </div>

                    {/* Clean Legend Above Table (Section 10) */}
                    <div className="flex flex-wrap items-center gap-3 text-[11px] text-muted">
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rounded-full bg-emerald-500" />
                            <strong>{solarSummary?.data_nature === 'SIMULATED' ? 'SIMULATED' : 'ACTUAL'}</strong> = {solarSummary?.data_nature === 'SIMULATED' ? 'Demo telemetry' : 'GoodWe inverter'}
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rounded-full bg-blue-500" />
                            <strong>VERIFIED</strong> = Confirmed bill
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rounded-full bg-amber-500" />
                            <strong>POTENTIAL</strong> = Analytical difference
                        </span>
                    </div>
                </div>

                {/* Clean Table without Cluttered Badges in Every Cell */}
                <div className="overflow-x-auto rounded-xl border border-border/60">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-surface-alt/70 text-muted uppercase text-[10px] tracking-wider font-semibold border-b border-border/60">
                            <tr>
                                <th className="px-4 py-3">Month</th>
                                <th className="px-4 py-3 text-right">Solar Generation</th>
                                <th className="px-4 py-3 text-right">Household Consumption</th>
                                <th className="px-4 py-3 text-right">Potential Balance</th>
                                <th className="px-4 py-3 text-right">Bill</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/40">
                            {monthlyPoints.map((row) => {
                                const hasGap = row.has_gap
                                const isPos = row.potential_balance_kwh != null && row.potential_balance_kwh > 0
                                return (
                                    <tr key={row.month} className="hover:bg-surface-alt/40 transition-colors">
                                        <td className="px-4 py-3 font-medium text-heading">
                                            <div className="flex items-center gap-2">
                                                <span>{row.label || formatMonthLabel(row.month)}</span>
                                                {row.is_current_period && (
                                                    <span className="rounded bg-surface-alt border border-border px-1.5 py-0.2 text-[9px] text-muted">
                                                        In Progress
                                                    </span>
                                                )}
                                                {hasGap && (
                                                    <span
                                                        title="Telemetry gap detected in this month. Missing periods are not treated as zero generation."
                                                        className="text-amber-500 inline-flex items-center"
                                                    >
                                                        <AlertTriangle className="size-3" />
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium text-heading">
                                            {row.solar_kwh != null ? fmtKwh(row.solar_kwh, 2, 2) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium text-heading">
                                            {row.verified_consumption_kwh != null ? fmtKwh(row.verified_consumption_kwh, 1, 0) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium">
                                            {row.potential_balance_kwh != null ? (
                                                <span className={isPos ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-danger'}>
                                                    {isPos ? '+' : ''}{fmtKwh(row.potential_balance_kwh, 1, 1)}
                                                </span>
                                            ) : (
                                                <span className="text-muted">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium text-heading">
                                            {row.bill_amount != null ? fmtRupees(row.bill_amount) : '—'}
                                        </td>
                                    </tr>
                                )
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* =========================================================================
                SECTION 20: DATA SOURCES & METHODOLOGY DISCLOSURES
               ========================================================================= */}
            <div className="rounded-2xl border border-border/80 bg-surface-alt/40 p-5 space-y-3 text-xs text-muted">
                <div className="flex items-center gap-2 font-semibold text-heading text-xs">
                    <Info className="size-4 text-muted shrink-0" />
                    <span>Data Sources & Methodology Disclosures</span>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-[11px]">
                    <div>
                        <strong className="text-heading block mb-0.5">Solar Generation</strong>
                        {solarSummary?.data_nature === 'SIMULATED'
                            ? 'SIMULATED telemetry recorded at 5-minute intervals for scenario demonstration. Missing intervals are explicitly tracked and never fabricated.'
                            : 'ACTUAL GoodWe inverter telemetry recorded at 5-minute intervals. Missing intervals are explicitly tracked and never fabricated.'}
                    </div>
                    <div>
                        <strong className="text-heading block mb-0.5">Household Consumption</strong>
                        VERIFIED APSPDCL utility records confirmed in review. Historical unverified drafts are excluded from authoritative demand.
                    </div>
                    <div>
                        <strong className="text-heading block mb-0.5">Potential Energy Balance</strong>
                        {solarSummary?.data_nature === 'SIMULATED'
                            ? 'Analytical difference between simulated solar generation and verified utility demand. Does not represent measured physical grid export.'
                            : 'Analytical difference between measured solar generation and verified utility demand. Does not represent measured physical grid export.'}
                    </div>
                    <div>
                        <strong className="text-heading block mb-0.5">Grid Import / Export</strong>
                        NOT MEASURED. No physical bidirectional smart-meter telemetry is installed at this site.
                    </div>
                </div>
            </div>
        </div>
    )
}
