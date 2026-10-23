import { useState, useMemo, useEffect, useRef } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { ResponsiveContainer, ComposedChart, Area, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts'
import { Sun, Activity, Database, Calendar } from 'lucide-react'
import { parseSafeDate, buildSingleDayCurveData } from './solarCurveData'
import { SolarDateSelector } from './SolarDateSelector'

function SolarCurveTooltip({ active, payload }) {
    if (!active || !payload || !payload.length) return null
    const entry = payload.find((p) => p.value !== null && p.value !== undefined)
    if (!entry) return null
    const data = entry.payload
    if (data._isGap) return null
    const powerW = data._powerW
    const powerKw = data._powerKw
    const dailyKwh = data._dailyKwh
    const dataStatus = data._dataStatus
    const isStale = dataStatus === 'STALE'
    if (powerW === null || powerW === undefined) return null

    let formattedDate = data._measurementTime
    try {
        const d = parseSafeDate(data._measurementTime)
        if (!isNaN(d.getTime())) {
            formattedDate = d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'Asia/Kolkata' })
        }
    } catch { /* fall back */ }

    return (
        <div
            data-testid="solar-curve-tooltip"
            className="rounded-lg border border-border bg-surface/95 p-3.5 shadow-lg backdrop-blur text-xs min-w-[240px]"
        >
            <div className="flex items-center justify-between gap-2 border-b border-border/50 pb-2 mb-2">
                <span className="font-semibold text-heading">{formattedDate}</span>
                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ${
                    isStale
                        ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30'
                        : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                }`}>
                    {dataStatus || 'FRESH'}
                </span>
            </div>
            <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                    <span className="text-slate-600 dark:text-slate-400">Instantaneous Power:</span>
                    <span className="font-mono font-bold text-heading">
                        {powerKw} kW ({powerW.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} W)
                    </span>
                </div>
                {dailyKwh !== null && (
                    <div className="flex items-center justify-between">
                        <span className="text-slate-600 dark:text-slate-400">Curve Energy (Running Total):</span>
                        <span className="font-mono font-semibold text-heading">{dailyKwh} kWh</span>
                    </div>
                )}
                <div className="flex items-center justify-between pt-1 border-t border-border/40 text-[11px] text-slate-500 dark:text-slate-400">
                    <span>Source:</span>
                    <span className="font-semibold text-heading flex items-center gap-1">
                        <Database className="size-3 text-primary" />
                        {data._dataNature === 'SIMULATED' ? 'GoodWe SEMS (Simulated Demo)' : 'GoodWe SEMS'}
                    </span>
                </div>
            </div>
        </div>
    )
}

/**
 * SolarPowerCurve — SPMS Final V1 Date-Based Solar Telemetry Graph.
 *
 * Implements:
 * - Part D: Real date-based historical selector (newest first).
 * - Part E: Selected-day data only. Power mode (kW) | Energy mode (running kWh).
 * - Part F: Compact selected-date summary attributes.
 * - Part G: 250-350ms subtle transition on date changes.
 * - Part J: Decimal kW Y-axis formatter (never truncating leading zero/decimal).
 * - Part K: Strictly distinguishes provider-reported generation from curve integrals.
 */
export function SolarPowerCurve({
    history = [],
    readings = null,
    availableDays = [],
    selectedDate = null,
    onSelectDate = null,
    daySummary = null,
}) {
    // Mode toggle: 'power' (kW vs time) | 'energy' (running cumulative kWh vs time)
    const [mode, setMode] = useState('power')

    // Local selected date state when controlled or uncontrolled
    const effectiveDays = useMemo(() => {
        if (availableDays && availableDays.length > 0) return availableDays
        if (!history || history.length === 0) return []

        const map = {}
        for (const r of history) {
            if (!r.measurement_time) continue
            const dStr = typeof r.measurement_time === 'string' ? r.measurement_time.slice(0, 10) : ''
            if (!dStr) continue
            if (!map[dStr]) {
                const dt = parseSafeDate(dStr)
                const isToday = !isNaN(dt.getTime()) && dt.toDateString() === new Date().toDateString()
                map[dStr] = {
                    date: dStr,
                    date_label: !isNaN(dt.getTime()) ? dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : dStr,
                    is_today: isToday,
                    generation_kwh: parseFloat(r.daily_generation_kwh || '0'),
                    peak_power_w: parseFloat(r.power_w || '0'),
                    peak_power_kw: parseFloat(r.power_w || '0') / 1000,
                    observation_count: 1,
                    sampling_interval_min: 5,
                    source: 'GoodWe SEMS',
                    status: isToday ? 'LIVE' : 'COMPLETE',
                    status_label: isToday ? 'Today / Live' : 'Complete',
                }
            } else {
                map[dStr].observation_count++
                const p = parseFloat(r.power_w || '0')
                if (p > map[dStr].peak_power_w) {
                    map[dStr].peak_power_w = p
                    map[dStr].peak_power_kw = parseFloat((p / 1000).toFixed(2))
                }
                const g = parseFloat(r.daily_generation_kwh || '0')
                if (g > map[dStr].generation_kwh) {
                    map[dStr].generation_kwh = g
                }
            }
        }
        return Object.values(map).sort((a, b) => b.date.localeCompare(a.date))
    }, [availableDays, history])

    const initialDate = selectedDate || (effectiveDays.length > 0 ? effectiveDays[0].date : '')
    const [internalDate, setInternalDate] = useState(initialDate)
    const currentDate = selectedDate || internalDate

    // Transition animation state for smooth 250-350ms fade
    const [isTransitioning, setIsTransitioning] = useState(false)
    const prevDateRef = useRef(currentDate)

    useEffect(() => {
        if (prevDateRef.current !== currentDate) {
            setIsTransitioning(true)
            const timer = setTimeout(() => setIsTransitioning(false), 280)
            prevDateRef.current = currentDate
            return () => clearTimeout(timer)
        }
    }, [currentDate])

    // Find summary for current date
    const activeSummary = useMemo(() => {
        if (daySummary && daySummary.date === currentDate) return daySummary
        const found = effectiveDays.find((d) => d.date === currentDate)
        if (found) return found
        return effectiveDays[0] || null
    }, [daySummary, effectiveDays, currentDate])

    // Filter readings for current date
    const dayReadings = useMemo(() => {
        if (readings && readings.length > 0) return readings
        if (!history || history.length === 0) return []
        if (!currentDate) return history
        return history.filter((r) => {
            if (!r.measurement_time) return false
            const dPrefix = typeof r.measurement_time === 'string' ? r.measurement_time.slice(0, 10) : ''
            return dPrefix === currentDate
        })
    }, [readings, history, currentDate])

    const { flatData, segmentCount, peakKw, totalEnergyKwh, observations } = useMemo(() => {
        return buildSingleDayCurveData(dayReadings, mode)
    }, [dayReadings, mode])

    const isSimulated = activeSummary?.data_nature === 'SIMULATED' || dayReadings?.[0]?.data_nature === 'SIMULATED' || history?.[0]?.data_nature === 'SIMULATED'

    const realPointCount = flatData.filter((r) => !r._isGap).length
    const hasSufficientData = realPointCount >= 2

    const handleDateChange = (newDate) => {
        if (!newDate || newDate === currentDate) return
        setInternalDate(newDate)
        if (onSelectDate) {
            onSelectDate(newDate)
        }
    }

    const yAxisUnit = mode === 'energy' ? ' kWh' : ' kW'
    const chartStroke = mode === 'energy' ? '#10B981' : '#F59E0B'
    const gradientId = mode === 'energy' ? 'solarEnergyGradient' : 'solarPowerGradient'

    return (
        <Card id="solar-curve-section" className="border-border bg-surface scroll-mt-20" data-testid="solar-power-curve">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-base font-bold text-heading flex items-center gap-2">
                            <Sun className="size-4 text-amber-500" />
                            Solar Generation Curve
                        </CardTitle>
                        <CardDescription className="text-xs text-slate-600 dark:text-slate-400 mt-0.5 font-medium">
                            Real instantaneous power (kW) and cumulative energy (kWh) from persisted GoodWe inverter telemetry.
                        </CardDescription>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* Power vs Energy Mode Toggle */}
                        <div className="inline-flex rounded-lg border border-border bg-muted/20 p-0.5">
                            <button
                                type="button"
                                onClick={() => setMode('power')}
                                className={`px-2.5 py-1 text-xs font-semibold rounded-md transition-colors ${
                                    mode === 'power'
                                        ? 'bg-surface text-heading shadow-xs border border-border/80'
                                        : 'text-slate-600 dark:text-slate-400 hover:text-heading'
                                }`}
                            >
                                Power (kW)
                            </button>
                            <button
                                type="button"
                                onClick={() => setMode('energy')}
                                className={`px-2.5 py-1 text-xs font-semibold rounded-md transition-colors ${
                                    mode === 'energy'
                                        ? 'bg-surface text-heading shadow-xs border border-border/80'
                                        : 'text-slate-600 dark:text-slate-400 hover:text-heading'
                                }`}
                            >
                                Energy (kWh)
                            </button>
                        </div>

                        {/* Date-Based Historical Selector Dropdown */}
                        {effectiveDays.length > 0 && (
                            <div className="flex items-center gap-1.5">
                                <span className="text-xs font-medium text-slate-600 dark:text-slate-400 hidden sm:inline">
                                    Selected date:
                                </span>
                                <SolarDateSelector
                                    availableDays={effectiveDays}
                                    selectedDate={currentDate}
                                    onSelectDate={handleDateChange}
                                />
                            </div>
                        )}
                    </div>
                </div>

                {/* Compact Selected-Date Summary Attributes (Part F) */}
                {activeSummary && (
                    <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2.5 p-3 mt-3 rounded-lg border border-border/80 bg-muted/10 text-xs">
                        <div>
                            <div className="text-[11px] font-medium text-slate-600 dark:text-slate-400">Selected date</div>
                            <div className="font-bold text-heading font-mono text-xs">{activeSummary.date_label || currentDate}</div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Generation</div>
                            <div className="font-bold text-emerald-800 dark:text-emerald-400 font-mono text-xs">
                                {activeSummary.generation_kwh !== undefined ? `${Number(activeSummary.generation_kwh).toFixed(2)} kWh` : `${totalEnergyKwh} kWh`}
                            </div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Peak power</div>
                            <div className="font-bold text-amber-800 dark:text-amber-400 font-mono text-xs">
                                {activeSummary.peak_power_kw !== undefined ? `${Number(activeSummary.peak_power_kw).toFixed(2)} kW` : `${peakKw} kW`}
                            </div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Observations</div>
                            <div className="font-bold text-slate-900 dark:text-heading font-mono text-xs">{activeSummary.observation_count ?? observations}</div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Sampling</div>
                            <div className="font-bold text-slate-900 dark:text-heading font-mono text-xs">{activeSummary.sampling_interval_min ? `${activeSummary.sampling_interval_min} min` : '5 min'}</div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Source</div>
                            <div className="font-bold text-slate-900 dark:text-heading text-xs flex items-center gap-1">
                                <Database className="size-3 text-primary" />
                                {isSimulated ? 'GoodWe SEMS (Simulated Demo)' : 'GoodWe SEMS'}
                            </div>
                        </div>
                        <div>
                            <div className="text-[11px] font-bold text-slate-800 dark:text-slate-400 dark:font-medium">Status</div>
                            <div>
                                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ${
                                    activeSummary.status === 'LIVE'
                                        ? 'bg-emerald-100 text-emerald-900 border border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/30'
                                        : activeSummary.status === 'STALE'
                                            ? 'bg-amber-100 text-amber-900 border border-amber-400 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/30'
                                            : 'bg-emerald-50 text-emerald-800 border border-emerald-300 dark:bg-slate-500/10 dark:text-slate-300 dark:border-slate-500/20'
                                }`}>
                                    {activeSummary.status_label || (activeSummary.is_today ? 'Live / In Progress' : 'Complete')}
                                </span>
                            </div>
                        </div>
                    </div>
                )}
            </CardHeader>

            <CardContent className="pt-2">
                {!hasSufficientData ? (
                    <div
                        data-testid="solar-curve-empty"
                        className="py-12 px-4 rounded-lg border border-dashed border-border/80 bg-muted/5 text-center"
                    >
                        <Activity className="size-8 mx-auto mb-2 text-slate-400" />
                        <p className="text-sm font-semibold text-heading">
                            Not enough historical solar telemetry for this date.
                        </p>
                        <p className="text-xs text-slate-600 dark:text-slate-400 mt-1.5 max-w-md mx-auto">
                            SPMS only renders genuine telemetry recorded from your GoodWe inverter.
                            Synthetic or interpolated curve points are never fabricated.
                        </p>
                    </div>
                ) : (
                    <div
                        className={`w-full h-72 pt-2 transition-opacity duration-300 motion-reduce:transition-none ${
                            isTransitioning ? 'opacity-40' : 'opacity-100'
                        }`}
                    >
                        <ResponsiveContainer width="100%" height="100%" minHeight={280}>
                            <ComposedChart
                                data={flatData}
                                margin={{ top: 10, right: 16, left: 16, bottom: 4 }}
                            >
                                <defs>
                                    <linearGradient id="solarPowerGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%"  stopColor="#F59E0B" stopOpacity={0.4} />
                                        <stop offset="95%" stopColor="#F59E0B" stopOpacity={0.02} />
                                    </linearGradient>
                                    <linearGradient id="solarEnergyGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%"  stopColor="#10B981" stopOpacity={0.4} />
                                        <stop offset="95%" stopColor="#10B981" stopOpacity={0.02} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border/40" />
                                <XAxis
                                    dataKey="displayTime"
                                    stroke="currentColor"
                                    className="text-slate-600 dark:text-slate-400 text-[11px] font-mono"
                                    tickLine={false}
                                    interval="preserveStartEnd"
                                />
                                <YAxis
                                    width={56}
                                    stroke="currentColor"
                                    className="text-slate-600 dark:text-slate-400 text-[11px] font-mono"
                                    tickLine={false}
                                    domain={[0, 'auto']}
                                    tickFormatter={(val) => {
                                        if (val === 0) return `0${yAxisUnit}`
                                        return `${Number(val).toFixed(2)}${yAxisUnit}`
                                    }}
                                />
                                <Tooltip content={<SolarCurveTooltip />} />
                                {Array.from({ length: segmentCount }, (_, i) => (
                                    <Area
                                        key={`seg_${i}`}
                                        name={mode === 'energy' ? 'Solar Energy' : 'Solar Power'}
                                        type="monotone"
                                        dataKey={`seg_${i}`}
                                        stroke={chartStroke}
                                        strokeWidth={2}
                                        fill={`url(#${gradientId})`}
                                        connectNulls={false}
                                        dot={(dotProps) => {
                                            if (
                                                dotProps &&
                                                dotProps.cx !== undefined &&
                                                dotProps.cy !== undefined &&
                                                dotProps.value !== null &&
                                                dotProps.value !== undefined &&
                                                dotProps.payload &&
                                                (dotProps.payload._isShortSegment || observations <= 30) &&
                                                dotProps.payload._segIdx === i
                                            ) {
                                                return (
                                                    <circle
                                                        key={`dot-${dotProps.cx}-${dotProps.cy}`}
                                                        cx={dotProps.cx}
                                                        cy={dotProps.cy}
                                                        r={3}
                                                        fill={chartStroke}
                                                        stroke={chartStroke}
                                                        strokeWidth={1}
                                                    />
                                                )
                                            }
                                            return null
                                        }}
                                        activeDot={{ r: 5, stroke: chartStroke, strokeWidth: 1.5, fill: '#FFFFFF' }}
                                        isAnimationActive={false}
                                        legendType="none"
                                    />
                                ))}
                            </ComposedChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
