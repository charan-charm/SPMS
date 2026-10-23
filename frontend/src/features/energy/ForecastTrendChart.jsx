import { useMemo } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { ResponsiveContainer, ComposedChart, Area, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts'
import { Activity, Sun, Home, Calendar } from 'lucide-react'
import { formatKwh, parseSafeDate } from './energyPeriodUtils'

function EnergyTrendTooltip({ active, payload, label }) {
    if (!active || !payload || !payload.length) return null
    const data = payload[0]?.payload || {}

    const solarKwh = data.predicted_solar_kwh ?? null
    const demandKwh = data.predicted_consumption_kwh ?? null
    const balanceKwh = solarKwh !== null && demandKwh !== null ? solarKwh - demandKwh : null

    return (
        <div className="rounded-lg border border-border bg-surface/95 p-3.5 shadow-lg backdrop-blur text-xs min-w-[210px]">
            <div className="flex items-center justify-between border-b border-border/50 pb-1.5 mb-2 font-medium text-heading">
                <span>{data.displayTime || label}</span>
                <span className="text-[10px] text-muted">Hour {data.hour !== undefined ? `${data.hour}:00` : ''}</span>
            </div>
            <div className="space-y-1.5 font-mono">
                {solarKwh !== null && (
                    <div className="flex items-center justify-between text-amber-500">
                        <span className="font-sans text-muted flex items-center gap-1">
                            <Sun className="size-3 text-amber-500" />
                            Expected Solar:
                        </span>
                        <span>{formatKwh(solarKwh, 3)} kWh</span>
                    </div>
                )}
                {demandKwh !== null && (
                    <div className="flex items-center justify-between text-sky-500">
                        <span className="font-sans text-muted flex items-center gap-1">
                            <Home className="size-3 text-sky-500" />
                            Expected Demand:
                        </span>
                        <span>{formatKwh(demandKwh, 3)} kWh</span>
                    </div>
                )}
                {balanceKwh !== null && (
                    <div className="flex items-center justify-between pt-1 border-t border-border/40 font-semibold text-heading">
                        <span className="font-sans text-muted">Net Projection:</span>
                        <span className={balanceKwh >= 0 ? 'text-emerald-500' : 'text-amber-500'}>
                            {balanceKwh > 0 ? '+' : ''}{formatKwh(balanceKwh, 3)} kWh
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

/**
 * ForecastTrendChart (SPMS Energy V1 — Section 4).
 *
 * Visualizes the 24-hour Day-Ahead hourly forecast curve:
 * - Expected Solar (kW / kWh) in amber
 * - Expected Household Demand (kWh) in sky-blue
 * - Clearly badged as PREDICTED (never masquerades as actual or measured history)
 * - Includes honest limitation notice regarding live sub-metering.
 */
export function ForecastTrendChart({ timeSeries = [], timezone = 'Asia/Kolkata' }) {
    const formattedData = useMemo(() => {
        if (!timeSeries || timeSeries.length === 0) return []
        return timeSeries.map((point) => {
            let displayTime = ''
            if (point.timestamp) {
                const d = parseSafeDate(point.timestamp)
                if (!isNaN(d.getTime())) {
                    displayTime = d.toLocaleTimeString([], {
                        timeZone: timezone,
                        hour: '2-digit',
                        minute: '2-digit',
                    })
                }
            } else if (point.hour !== undefined) {
                displayTime = `${String(point.hour).padStart(2, '0')}:00`
            }

            return {
                ...point,
                displayTime,
                predicted_solar_kwh: point.predicted_solar_kwh !== undefined ? parseFloat(point.predicted_solar_kwh) : null,
                predicted_consumption_kwh: point.predicted_consumption_kwh !== undefined ? parseFloat(point.predicted_consumption_kwh) : null,
            }
        })
    }, [timeSeries, timezone])

    const hasData = formattedData.length >= 2

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="forecast-trend-chart">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <Activity className="size-4 text-primary" />
                            Solar vs Household Demand (Forecast Curve)
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Hourly Day-Ahead expected solar generation compared with estimated household load.
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 text-xs text-muted font-medium bg-muted/20 px-2.5 py-1 rounded-md">
                            <span className="inline-block size-2 rounded-full bg-amber-500" /> Solar
                            <span className="text-muted/60">·</span>
                            <span className="inline-block size-2 rounded-full bg-sky-500" /> Demand
                        </span>
                        <span className="inline-flex items-center rounded border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold text-sky-600 dark:text-sky-400">
                            PREDICTED
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent>
                {!hasData ? (
                    <div className="py-12 px-4 text-center rounded-lg border border-dashed border-border/70 bg-muted/5">
                        <Activity className="size-8 mx-auto mb-2 text-muted/50" />
                        <p className="text-sm font-medium text-heading">Not enough matching energy data</p>
                        <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
                            Household consumption data is not available at the same time resolution. Live load curve requires an in-home consumption meter.
                        </p>
                    </div>
                ) : (
                    <div className="w-full h-64 pt-2">
                        <ResponsiveContainer width="100%" height="100%">
                            <ComposedChart data={formattedData} margin={{ top: 10, right: 16, left: -15, bottom: 4 }}>
                                <defs>
                                    <linearGradient id="energySolarGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="#F59E0B" stopOpacity={0.3} />
                                        <stop offset="95%" stopColor="#F59E0B" stopOpacity={0.0} />
                                    </linearGradient>
                                    <linearGradient id="energyDemandGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="#0EA5E9" stopOpacity={0.25} />
                                        <stop offset="95%" stopColor="#0EA5E9" stopOpacity={0.0} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border/40" />
                                <XAxis
                                    dataKey="displayTime"
                                    stroke="currentColor"
                                    className="text-muted text-[11px] font-mono"
                                    tickLine={false}
                                    interval="preserveStartEnd"
                                />
                                <YAxis
                                    stroke="currentColor"
                                    className="text-muted text-[11px] font-mono"
                                    tickLine={false}
                                    unit=" kWh"
                                    domain={[0, 'auto']}
                                />
                                <Tooltip content={<EnergyTrendTooltip />} />
                                <Area
                                    name="Expected Solar"
                                    type="monotone"
                                    dataKey="predicted_solar_kwh"
                                    stroke="#F59E0B"
                                    strokeWidth={2}
                                    fill="url(#energySolarGradient)"
                                    dot={false}
                                    isAnimationActive={false}
                                />
                                <Area
                                    name="Expected Demand"
                                    type="monotone"
                                    dataKey="predicted_consumption_kwh"
                                    stroke="#0EA5E9"
                                    strokeWidth={2}
                                    fill="url(#energyDemandGradient)"
                                    dot={false}
                                    isAnimationActive={false}
                                />
                            </ComposedChart>
                        </ResponsiveContainer>
                    </div>
                )}

                <div className="mt-3 text-[11px] text-muted flex items-center justify-between">
                    <span>Forecast curve based on Open-Meteo solar irradiance and verified historical billing demand models.</span>
                    <span className="font-medium text-heading">Horizon: Day-Ahead</span>
                </div>
            </CardContent>
        </Card>
    )
}
