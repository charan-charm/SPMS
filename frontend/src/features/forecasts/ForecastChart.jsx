import { useId } from 'react'
import {
    ResponsiveContainer,
    ComposedChart,
    Line,
    Area,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
    Legend,
    ReferenceLine,
    usePlotArea,
} from 'recharts'
import { ChartContainer } from '../../components/data-display/ChartContainer'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { getNowMarkerInfo, computePillX } from './forecastMarkerUtils'

/**
 * Concise, accessible tooltip for ForecastChart (Section 9).
 */
function ForecastTooltip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) {
        return null
    }

    const dataPoint = payload[0]?.payload || {}
    const solarVal = dataPoint.predicted_solar_kwh !== null && dataPoint.predicted_solar_kwh !== undefined
        ? Number(dataPoint.predicted_solar_kwh).toFixed(2)
        : null
    const lowerVal = dataPoint.lower_solar_kwh !== null && dataPoint.lower_solar_kwh !== undefined
        ? Number(dataPoint.lower_solar_kwh).toFixed(2)
        : null
    const upperVal = dataPoint.upper_solar_kwh !== null && dataPoint.upper_solar_kwh !== undefined
        ? Number(dataPoint.upper_solar_kwh).toFixed(2)
        : null

    return (
        <div className="rounded-xl border border-border/80 bg-surface/95 p-2.5 shadow-xl backdrop-blur-md text-xs space-y-1 min-w-[140px]">
            <div className="font-semibold text-text-primary border-b border-border/40 pb-1 flex items-center justify-between">
                <span>{label}{label && label.includes(':') ? ' IST' : ''}</span>
                <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    PREDICTED
                </span>
            </div>

            {solarVal !== null && (
                <div className="space-y-0.5">
                    <div className="flex items-center justify-between gap-3 text-emerald-400 font-medium">
                        <span>Solar forecast</span>
                        <span className="font-mono">{solarVal} kWh</span>
                    </div>
                    {lowerVal !== null && upperVal !== null && (
                        <div className="text-[11px] text-text-muted">
                            Range: {lowerVal}–{upperVal} kWh
                        </div>
                    )}
                </div>
            )}
        </div>
    )
}

/**
 * Compact, visually secondary label for NOW reference marker (Section 2).
 * Styled as a subtle, unobtrusive slate marker so it does not distort
 * the calendar-day forecast interpretation.
 */
export function NowMarkerLabel(props) {
    const plotArea = usePlotArea()
    const { viewBox, value } = props
    if (!value) return null

    // Reference line X coordinate
    const lineX = props.x ?? viewBox?.x ?? 0
    const topY = props.y ?? viewBox?.y ?? (plotArea?.y ?? 12)

    const pillWidth = 76
    const pillHeight = 18

    const bounds = plotArea && typeof plotArea.width === 'number' && plotArea.width > pillWidth
        ? plotArea
        : (viewBox && typeof viewBox.width === 'number' && viewBox.width > pillWidth ? viewBox : null)

    const pillX = computePillX(lineX, pillWidth, bounds, 4)
    const pillY = Math.max(2, topY + 2)

    return (
        <g data-testid="now-marker-label" className="pointer-events-none select-none">
            <rect
                x={pillX}
                y={pillY}
                width={pillWidth}
                height={pillHeight}
                rx={3}
                fill="#1e293b"
                fillOpacity="0.85"
                stroke="#475569"
                strokeWidth={1}
            />
            <text
                x={pillX + pillWidth / 2}
                y={pillY + 11}
                textAnchor="middle"
                fill="#94a3b8"
                fontSize="9"
                fontFamily="ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
                fontWeight="500"
                className="fill-slate-400 font-mono text-[9px]"
            >
                {value}
            </text>
        </g>
    )
}

function resolvePointHour(pt) {
    if (pt.local_hour !== undefined && pt.local_hour !== null) {
        return Number(pt.local_hour)
    }
    if (pt.local_time) {
        try {
            const h = parseInt(pt.local_time.split(' ')[1].split(':')[0], 10)
            if (!isNaN(h)) return h
        } catch {
            // ignore
        }
    }
    if (pt.hour !== undefined && pt.hour !== null) {
        return Number(pt.hour)
    }
    if (pt.timestamp) {
        try {
            const d = new Date(pt.timestamp)
            const istDate = new Date(d.getTime() + (5.5 * 3600 * 1000))
            return istDate.getUTCHours()
        } catch {
            // ignore
        }
    }
    return 0
}

/**
 * SPMS Forecast Chart (F3).
 *
 * Visual semantics:
 * - Day-Ahead: Calendar-day solar forecast from 00:00 -> 23:00 (06:00–18:00 daylight generation window)
 * - 7-Day: Weekly trend with clear day labels (Mon, Tue, Wed...)
 * - Monthly: 30-day outlook
 * - Predicted = dashed ('6 4')
 * - Uncertainty = shaded area band (fillOpacity 0.12)
 * - Solar = #10B981 / Emerald
 * - NOW reference marker = visually secondary slate dashed line with compact pill
 */
export function ForecastChart({
    timeSeries = [],
    horizon = 'DAY_AHEAD',
    solarConfidence = 'HIGH',
    isLoading = false,
    referenceTime = null,
    timezone = 'Asia/Kolkata',
    period = null,
}) {
    const solarGradId = useId()

    // 1. For DAY_AHEAD, present as a calendar-day forecast (00:00 -> 23:00) using local daylight hours
    let sortedSeries = [...timeSeries]
    if (horizon === 'DAY_AHEAD' && sortedSeries.length > 0) {
        sortedSeries.sort((a, b) => {
            const hA = resolvePointHour(a)
            const hB = resolvePointHour(b)
            return hA - hB
        })
    }

    // 2. Format labels based on horizon
    const formattedData = sortedSeries.map((pt) => {
        let label = pt.timestamp
        let localHour = undefined
        if (horizon === 'DAY_AHEAD') {
            const h = resolvePointHour(pt)
            localHour = h
            label = `${String(h).padStart(2, '0')}:00`
        } else if (horizon === 'SEVEN_DAY') {
            const d = pt.date ? new Date(pt.date + 'T00:00:00') : (pt.timestamp ? new Date(pt.timestamp) : null)
            label = d && !isNaN(d.getTime())
                ? d.toLocaleDateString('en-US', { weekday: 'short' })
                : (pt.date ? pt.date.slice(5) : label)
        } else {
            // MONTHLY
            const d = pt.date ? new Date(pt.date + 'T00:00:00') : (pt.timestamp ? new Date(pt.timestamp) : null)
            label = d && !isNaN(d.getTime())
                ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                : (pt.date ? pt.date.slice(5) : label)
        }

        return {
            ...pt,
            hour: localHour ?? pt.hour,
            displayLabel: label,
            solarRange: [pt.lower_solar_kwh ?? 0, pt.upper_solar_kwh ?? 0],
        }
    })

    const hasSolar = formattedData.some((d) => d.predicted_solar_kwh !== null && d.predicted_solar_kwh > 0)
    const nowInfo = getNowMarkerInfo(referenceTime, horizon, formattedData, timezone, period)

    // Expected calendar day ticks (every 2 hours) for clean DAY_AHEAD presentation
    const dayAheadTicks = ['00:00', '02:00', '04:00', '06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00']
    const hasAllDayTicks = horizon === 'DAY_AHEAD' && dayAheadTicks.every((t) => formattedData.some((d) => d.displayLabel === t))

    return (
        <ChartContainer
            title="Solar Generation Forecast"
            description={
                horizon === 'DAY_AHEAD'
                    ? 'Tomorrow · 24-hour solar forecast'
                    : horizon === 'SEVEN_DAY'
                        ? '7-day daily trend projection with forecast uncertainty'
                        : '30-day monthly solar generation outlook'
            }
            loading={isLoading}
            empty={!isLoading && formattedData.length === 0}
            actions={
                <div className="flex items-center gap-3">
                    {horizon === 'DAY_AHEAD' && (
                        <span className="hidden sm:inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            Tomorrow · 24-hour solar forecast
                        </span>
                    )}
                    <div className="flex items-center gap-1.5 text-xs text-text-secondary">
                        <span className="inline-block w-2.5 h-2.5 rounded-full bg-emerald-400" />
                        <span>Predicted Solar</span>
                        <ConfidenceIndicator level={solarConfidence} confidence={solarConfidence} />
                    </div>
                </div>
            }
        >
            <div className="w-full h-80 pt-2">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={formattedData} margin={{ top: 12, right: 16, left: -16, bottom: 4 }}>
                        <defs>
                            <linearGradient id={solarGradId} x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor="#86EFAC" stopOpacity={0.25} />
                                <stop offset="95%" stopColor="#86EFAC" stopOpacity={0.02} />
                            </linearGradient>
                        </defs>

                        <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border/40" />

                        <XAxis
                            dataKey="displayLabel"
                            stroke="currentColor"
                            className="text-text-muted text-xs"
                            tickLine={false}
                            ticks={hasAllDayTicks ? dayAheadTicks : undefined}
                            interval={horizon === 'SEVEN_DAY' ? 0 : (hasAllDayTicks ? undefined : 'preserveStartEnd')}
                        />
                        <YAxis
                            stroke="currentColor"
                            className="text-text-muted text-xs"
                            tickLine={false}
                            unit=" kWh"
                        />

                        <Tooltip content={<ForecastTooltip />} />

                        <Legend
                            wrapperStyle={{ paddingTop: '12px' }}
                            formatter={(value) => (
                                <span className="text-xs font-medium text-text-secondary">{value}</span>
                            )}
                        />

                        {/* Solar Uncertainty Band as semi-transparent Area */}
                        {hasSolar && (
                            <Area
                                name="Solar Uncertainty Interval"
                                dataKey="upper_solar_kwh"
                                stroke="none"
                                fill={`url(#${solarGradId})`}
                                legendType="none"
                            />
                        )}

                        {/* Solar Forecast curve (dashed per design system) */}
                        {hasSolar && (
                            <Line
                                type="monotone"
                                name="Predicted Solar (kWh)"
                                dataKey="predicted_solar_kwh"
                                stroke="#10B981"
                                strokeWidth={2.5}
                                strokeDasharray="6 4"
                                dot={false}
                                activeDot={{ r: 5, strokeWidth: 2, fill: '#10B981' }}
                            />
                        )}

                        {/* Backend-authoritative NOW reference marker (visually secondary) */}
                        {nowInfo && (
                            <ReferenceLine
                                x={nowInfo.x}
                                stroke="#64748b"
                                strokeWidth={1}
                                strokeDasharray="3 3"
                                strokeOpacity={0.5}
                                isFront={true}
                                className="pointer-events-none"
                                label={<NowMarkerLabel value={nowInfo.label} />}
                            />
                        )}
                    </ComposedChart>
                </ResponsiveContainer>
            </div>
        </ChartContainer>
    )
}
