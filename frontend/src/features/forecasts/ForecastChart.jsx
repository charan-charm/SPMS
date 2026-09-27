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
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { getNowMarkerInfo, computePillX } from './forecastMarkerUtils'

/**
 * Custom accessible tooltip for ForecastChart.
 */
function ForecastTooltip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) {
        return null
    }

    const dataPoint = payload[0]?.payload || {}

    return (
        <div className="rounded-xl border border-border/80 bg-surface/95 p-3.5 shadow-xl backdrop-blur-md text-xs space-y-2 min-w-[220px]">
            <div className="font-semibold text-text-primary border-b border-border/50 pb-1.5 flex items-center justify-between">
                <span>{label}</span>
                {dataPoint.hour !== undefined && dataPoint.hour !== null && (
                    <span className="text-text-muted font-normal">Hour {dataPoint.hour}:00</span>
                )}
            </div>

            {/* Solar Forecast details */}
            {dataPoint.predicted_solar_kwh !== null && dataPoint.predicted_solar_kwh !== undefined && (
                <div className="space-y-0.5">
                    <div className="flex items-center justify-between font-medium text-emerald-400">
                        <span>Solar Forecast:</span>
                        <span>{dataPoint.predicted_solar_kwh.toFixed(3)} kWh</span>
                    </div>
                    {dataPoint.lower_solar_kwh !== null && dataPoint.upper_solar_kwh !== null && (
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Uncertainty Range:</span>
                            <span>{dataPoint.lower_solar_kwh.toFixed(2)} – {dataPoint.upper_solar_kwh.toFixed(2)} kWh</span>
                        </div>
                    )}
                </div>
            )}

            {/* Consumption Forecast details */}
            {dataPoint.predicted_consumption_kwh !== null && dataPoint.predicted_consumption_kwh !== undefined && (
                <div className="space-y-0.5">
                    <div className="flex items-center justify-between font-medium text-sky-400">
                        <span>Consumption Forecast:</span>
                        <span>{dataPoint.predicted_consumption_kwh.toFixed(3)} kWh</span>
                    </div>
                    {dataPoint.lower_consumption_kwh !== null && dataPoint.upper_consumption_kwh !== null && (
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Uncertainty Range:</span>
                            <span>{dataPoint.lower_consumption_kwh.toFixed(2)} – {dataPoint.upper_consumption_kwh.toFixed(2)} kWh</span>
                        </div>
                    )}
                </div>
            )}

            {/* Projected Net Balance / Deficit */}
            {dataPoint.projected_balance_kwh !== null && dataPoint.projected_balance_kwh !== undefined && (
                <div className="flex items-center justify-between font-medium border-t border-border/40 pt-1 text-text-secondary">
                    <span>{dataPoint.projected_balance_kwh < 0 ? 'Projected Deficit:' : (dataPoint.projected_balance_kwh > 0 ? 'Projected Surplus:' : 'Projected Balance:')}</span>
                    <span className={dataPoint.projected_balance_kwh >= 0 ? 'text-emerald-400' : 'text-amber-400'}>
                        {`${Math.abs(dataPoint.projected_balance_kwh).toFixed(3)} kWh`}
                    </span>
                </div>
            )}

            {/* Data Nature and Status badges */}
            <div className="flex items-center gap-1.5 pt-1 border-t border-border/40">
                <DataNatureBadge nature={dataPoint.solar_data_nature || dataPoint.consumption_data_nature || 'PREDICTED'} />
            </div>
        </div>
    )
}

/**
 * Compact pill label for the NOW reference marker (Requirement 2 & 7).
 * Renders a restrained teal pill near the top of the chart plot area,
 * horizontally centered over the reference line's actual X coordinate.
 */
export function NowMarkerLabel(props) {
    const plotArea = usePlotArea()
    const { viewBox, value } = props
    if (!value) return null

    // Determine the reference line's X coordinate (x1 coord calculated by Recharts)
    const lineX = props.x ?? viewBox?.x ?? 0
    const topY = props.y ?? viewBox?.y ?? (plotArea?.y ?? 12)

    const pillWidth = 76
    const pillHeight = 18

    // Prefer plotArea for boundary clamping; otherwise viewBox if it has valid width > pillWidth
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
                rx={4}
                fill="#0f172a"
                fillOpacity="0.92"
                stroke="#14b8a6"
                strokeWidth={1}
                strokeOpacity={0.6}
            />
            <text
                x={pillX + pillWidth / 2}
                y={pillY + 12.5}
                textAnchor="middle"
                fill="#2dd4bf"
                fontSize="10"
                fontFamily="ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
                fontWeight="600"
                letterSpacing="0.025em"
                className="fill-teal-400 font-mono text-[10px] font-semibold"
            >
                {value}
            </text>
        </g>
    )
}

/**
 * SPMS Forecast Chart (F3 — docs/04 §250, docs/04 §1495-1496).
 *
 * Visual semantics:
 * - Actual = solid
 * - Predicted = dashed ('6 4')
 * - Estimated = dotted ('2 4')
 * - Uncertainty = shaded area band (fillOpacity 0.12)
 * - Solar = #86EFAC / Emerald
 * - Consumption = #93C5FD / Sky
 * - NOW reference marker = #14B8A6 / Subtle Teal dashed vertical line with compact pill
 */
export function ForecastChart({
    timeSeries = [],
    horizon = 'DAY_AHEAD',
    solarConfidence = 'HIGH',
    consumptionConfidence = 'MODERATE',
    isLoading = false,
    referenceTime = null,
    timezone = 'Asia/Kolkata',
    period = null,
}) {
    const solarGradId = useId()
    const consumptionGradId = useId()

    // Format label based on horizon
    const formattedData = timeSeries.map((pt) => {
        let label = pt.timestamp
        if (horizon === 'DAY_AHEAD' && pt.hour !== undefined && pt.hour !== null) {
            label = `${String(pt.hour).padStart(2, '0')}:00`
        } else if (pt.date) {
            label = pt.date.slice(5) // MM-DD
        } else if (pt.timestamp) {
            label = pt.timestamp.slice(11, 16)
        }

        return {
            ...pt,
            displayLabel: label,
            solarRange: [pt.lower_solar_kwh ?? 0, pt.upper_solar_kwh ?? 0],
            consumptionRange: [pt.lower_consumption_kwh ?? 0, pt.upper_consumption_kwh ?? 0],
        }
    })

    const hasSolar = formattedData.some((d) => d.predicted_solar_kwh !== null && d.predicted_solar_kwh > 0)
    const hasConsumption = formattedData.some((d) => d.predicted_consumption_kwh !== null && d.predicted_consumption_kwh > 0)
    const nowInfo = getNowMarkerInfo(referenceTime, horizon, formattedData, timezone, period)

    return (
        <ChartContainer
            title="Solar Generation & Consumption Outlook"
            description={
                horizon === 'DAY_AHEAD'
                    ? '24-hour hourly projection with uncertainty intervals'
                    : horizon === 'SEVEN_DAY'
                        ? '7-day daily trend projection'
                        : '30-day monthly planning projection'
            }
            loading={isLoading}
            empty={!isLoading && formattedData.length === 0}
            actions={
                <div className="flex items-center gap-3">
                    <div className="flex items-center gap-1.5 text-xs text-text-secondary">
                        <span className="inline-block w-2.5 h-2.5 rounded-full bg-emerald-400" />
                        <span>Solar</span>
                        <ConfidenceIndicator level={solarConfidence} confidence={solarConfidence} />
                    </div>
                    <div className="flex items-center gap-1.5 text-xs text-text-secondary">
                        <span className="inline-block w-2.5 h-2.5 rounded-full bg-sky-400" />
                        <span>Consumption</span>
                        <ConfidenceIndicator level={consumptionConfidence} confidence={consumptionConfidence} />
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
                            <linearGradient id={consumptionGradId} x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor="#93C5FD" stopOpacity={0.2} />
                                <stop offset="95%" stopColor="#93C5FD" stopOpacity={0.02} />
                            </linearGradient>
                        </defs>

                        <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border/40" />

                        <XAxis
                            dataKey="displayLabel"
                            stroke="currentColor"
                            className="text-text-muted text-xs"
                            tickLine={false}
                            interval="preserveStartEnd"
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

                        {/* Uncertainty Bands as semi-transparent Areas */}
                        {hasSolar && (
                            <Area
                                name="Solar Uncertainty Interval"
                                dataKey="upper_solar_kwh"
                                stroke="none"
                                fill={`url(#${solarGradId})`}
                                legendType="none"
                            />
                        )}

                        {hasConsumption && (
                            <Area
                                name="Consumption Uncertainty Interval"
                                dataKey="upper_consumption_kwh"
                                stroke="none"
                                fill={`url(#${consumptionGradId})`}
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

                        {/* Consumption Forecast curve (dashed per design system) */}
                        {hasConsumption && (
                            <Line
                                type="monotone"
                                name="Predicted Consumption (kWh)"
                                dataKey="predicted_consumption_kwh"
                                stroke="#3B82F6"
                                strokeWidth={2.5}
                                strokeDasharray="6 4"
                                dot={false}
                                activeDot={{ r: 5, strokeWidth: 2, fill: '#3B82F6' }}
                            />
                        )}

                        {/* Backend-authoritative NOW reference marker (Requirement 2) */}
                        {nowInfo && (
                            <ReferenceLine
                                x={nowInfo.x}
                                stroke="#14B8A6"
                                strokeWidth={1.5}
                                strokeDasharray="3 3"
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
