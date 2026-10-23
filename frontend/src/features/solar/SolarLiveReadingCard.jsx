import { Card, CardHeader, CardTitle, CardContent } from '../../components/ui/card'
import { Sun, Activity, Gauge, Thermometer, Zap, Info, AlarmClockOff, Moon } from 'lucide-react'
import { DATA_STATUS, DATA_NATURE } from '../../lib/semantics'
import { deriveTelemetryState, formatObservationTimestamp, formatTelemetryMetric } from './solarStatus'

/**
 * SolarLiveReadingCard (SPMS O4 / O4.7 — docs/10 D-059, D-060, D-061, D-062).
 *
 * Displays high-precision live solar telemetry and electrical parameters.
 * Strictly adheres to SPMS data honesty:
 * - Differentiates instantaneous Power (W/kW) from cumulative Energy (kWh).
 * - Honest stale vs live status semantics.
 * - Shows last valid observation with exact observation timestamp and STALE state when fresh data is unavailable.
 * - Never coerces missing telemetry to zero (0 V, 0 A, 0 kW, 0 Hz).
 * - Distinguishes actual measured zero (0 V, 0 A, 0 W) from unavailable/missing.
 * - No fabrication of AC/smart meter metrics when unavailable (preserves NULL / Unavailable).
 */
export function SolarLiveReadingCard({ reading }) {
    if (!reading) {
        return (
            <Card className="border-border bg-surface">
                <CardContent className="py-12 text-center text-muted">
                    <Sun className="size-8 mx-auto mb-2 text-muted/60" />
                    <p className="font-medium text-heading text-sm">No telemetry records recorded yet.</p>
                    <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
                        Click "Sync Now" on your connection card to ingest the latest telemetry snapshot from GoodWe SEMS+.
                    </p>
                </CardContent>
            </Card>
        )
    }

    const hasValidPower = reading.power_w !== null && reading.power_w !== undefined && !isNaN(Number(reading.power_w))
    const powerW = hasValidPower ? Number(reading.power_w) : null
    const powerKw = powerW !== null ? (powerW / 1000).toFixed(2) : null
    const dailyKwh = reading.daily_generation_kwh !== null && reading.daily_generation_kwh !== undefined
        ? parseFloat(reading.daily_generation_kwh).toFixed(3)
        : '0.000'
    const totalKwh = reading.total_generation_kwh ? parseFloat(reading.total_generation_kwh).toFixed(1) : null

    const formatMeasurementTime = (iso) => {
        if (!iso) return 'Recent'
        try {
            const d = new Date(iso)
            const isToday = d.toDateString() === new Date().toDateString()
            return d.toLocaleString(undefined, {
                dateStyle: isToday ? undefined : 'short',
                timeStyle: 'medium',
            })
        } catch {
            return iso
        }
    }

    const measurementTime = formatMeasurementTime(reading.measurement_time)
    const obsTime = formatObservationTimestamp(reading.measurement_time)
    const isStale = reading.data_status === 'STALE'
    const isOffline = reading.inverter_status === 'OFFLINE'
    const statusMeta = DATA_STATUS[reading.data_status] || DATA_STATUS.FRESH
    const natureMeta = DATA_NATURE[reading.data_nature] || DATA_NATURE.ACTUAL

    const telemetryState = deriveTelemetryState(reading)

    const renderOperatingBadge = () => {
        if (isOffline) {
            return (
                <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400">
                    OFFLINE
                </span>
            )
        }

        if (isStale) {
            const staleLabel = (powerW !== null && powerW > 0)
                ? 'LAST OBSERVED GENERATING'
                : (reading.inverter_status && reading.inverter_status !== 'WAITING' ? reading.inverter_status : 'STANDBY')
            return (
                <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                    <AlarmClockOff className="size-2.5 text-amber-500" />
                    {staleLabel}
                </span>
            )
        }

        if (telemetryState === 'LIVE') {
            return (
                <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" />
                    LIVE GENERATING
                </span>
            )
        }

        if (telemetryState === 'NO_GENERATION') {
            return (
                <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-500/20">
                    <Moon className="size-2.5 text-slate-500" />
                    NO GENERATION
                </span>
            )
        }

        return (
            <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-muted/20 text-muted border border-border/60">
                {reading.inverter_status || 'UNKNOWN'}
            </span>
        )
    }

    return (
        <div data-testid="solar-live-card" className="space-y-4">
            {/* Stale Telemetry Notice */}
            {isStale && (
                <div className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2.5">
                    <AlarmClockOff className="size-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
                    <div>
                        <span className="font-semibold">Telemetry is STALE: </span>
                        Last observed at {obsTime || measurementTime}{(powerW !== null && powerW > 0) ? ` (inverter was actively generating at ${powerKw} kW)` : ' (inverter was in standby / 0 W)'}. Integration connection remains configured and will record fresh data on next poll.
                    </div>
                </div>
            )}

            {/* Primary Generation KPI Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-slate-800 dark:text-muted text-xs font-bold dark:font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Zap className="size-4 text-amber-500" />
                            {isStale ? 'Last Observed Active Power' : 'Active Power'}
                        </span>
                        <span className="text-[11px] font-mono text-slate-700 dark:text-muted font-semibold">
                            {isStale
                                ? (obsTime ? `Last: ${obsTime}` : `Last: ${measurementTime}`)
                                : (obsTime ? `Live · ${obsTime}` : measurementTime)}
                        </span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        {hasValidPower ? (
                            <>
                                <span className="text-3xl font-display font-bold text-heading tracking-tight">
                                    {powerW.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
                                </span>
                                <span className="text-sm font-bold text-slate-700 dark:text-muted">W</span>
                                <span className="text-xs text-slate-700 dark:text-muted ml-auto font-semibold">
                                    ({powerKw} kW)
                                </span>
                            </>
                        ) : (
                            <div className="flex flex-col">
                                <span className="text-2xl font-display font-bold text-heading tracking-tight">
                                    Unavailable
                                </span>
                                <span className="text-[11px] text-muted font-medium mt-0.5">
                                    Awaiting first valid telemetry
                                </span>
                            </div>
                        )}
                    </div>
                    <div className="mt-3 flex items-center gap-2">
                        {renderOperatingBadge()}
                        <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold ${statusMeta.className}`}>
                            {reading.data_status || 'FRESH'}
                        </span>
                        <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold ${natureMeta.className}`}>
                            {reading.data_nature || 'ACTUAL'}
                        </span>
                    </div>
                </Card>

                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-slate-800 dark:text-muted text-xs font-bold dark:font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Sun className="size-4 text-amber-500" />
                            Today's Generation
                        </span>
                        <span className="text-[11px] text-slate-700 dark:text-muted font-semibold">Cumulative Energy</span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-display font-bold text-heading tracking-tight">
                            {dailyKwh}
                        </span>
                        <span className="text-sm font-bold text-slate-700 dark:text-muted">kWh</span>
                    </div>
                    <p className="text-[11px] text-slate-700 dark:text-muted mt-3 font-medium">
                        Provider-reported daily generation from GoodWe inverter.
                    </p>
                </Card>

                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-slate-800 dark:text-muted text-xs font-bold dark:font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Gauge className="size-4 text-primary" />
                            Lifetime Generation
                        </span>
                        <span className="text-[11px] text-slate-700 dark:text-muted font-semibold">Cumulative Energy</span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-display font-bold text-heading tracking-tight">
                            {totalKwh ? parseFloat(totalKwh).toLocaleString() : '—'}
                        </span>
                        <span className="text-sm font-bold text-slate-700 dark:text-muted">kWh</span>
                    </div>
                    <p className="text-[11px] text-slate-700 dark:text-muted mt-3 font-medium">
                        Cumulative lifetime generation reported by inverter.
                    </p>
                </Card>
            </div>

            {/* Electrical Telemetry Grid */}
            <Card className="border-border bg-surface">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-semibold text-heading flex items-center gap-2">
                        <Activity className="size-4 text-primary" />
                        Inverter Electrical Telemetry
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
                        {[
                            {
                                key: 'voltage',
                                label: 'AC Voltage',
                                val: reading.ac_voltage_v,
                                unit: 'V',
                                ts: reading.electrical_measurement_time || reading.measurement_time,
                                status: reading.electrical_data_status || reading.data_status,
                            },
                            {
                                key: 'current',
                                label: 'AC Current',
                                val: reading.ac_current_a,
                                unit: 'A',
                                ts: reading.electrical_measurement_time || reading.measurement_time,
                                status: reading.electrical_data_status || reading.data_status,
                            },
                            {
                                key: 'freq',
                                label: 'Grid Frequency',
                                val: reading.ac_frequency_hz,
                                unit: 'Hz',
                                ts: reading.electrical_measurement_time || reading.measurement_time,
                                status: reading.electrical_data_status || reading.data_status,
                            },
                            {
                                key: 'pv',
                                label: 'DC Array (PV)',
                                val: reading.pv_power_w,
                                unit: 'W',
                                ts: reading.measurement_time,
                                status: reading.data_status,
                            },
                            {
                                key: 'temp',
                                label: 'Temperature',
                                icon: <Thermometer className="size-3 text-slate-700 dark:text-muted" />,
                                val: reading.temperature_c,
                                unit: '°C',
                                ts: reading.electrical_measurement_time || reading.measurement_time,
                                status: reading.electrical_data_status || reading.data_status,
                            },
                        ].map((item) => {
                            const formatted = formatTelemetryMetric(item.val, item.unit, {
                                timestamp: item.ts,
                                dataStatus: item.status,
                            })
                            return (
                                <div
                                    key={item.key}
                                    data-testid={`telemetry-${item.key}`}
                                    className="rounded-md border border-slate-200 bg-slate-50/70 dark:border-border/60 dark:bg-muted/10 p-3 flex flex-col justify-between"
                                >
                                    <div>
                                        <div className="flex items-center justify-between gap-1">
                                            <span className="text-slate-800 dark:text-muted block text-[11px] font-bold dark:font-medium flex items-center gap-1">
                                                {item.icon}
                                                {item.label}
                                            </span>
                                            {formatted.statusBadge && (
                                                <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-400 border border-amber-500/30">
                                                    {formatted.statusBadge}
                                                </span>
                                            )}
                                        </div>
                                        <span className="font-display text-base font-semibold text-heading mt-1 block">
                                            {formatted.valueDisplay}
                                        </span>
                                    </div>
                                    <div className="mt-2 pt-1.5 border-t border-border/40 text-[10px] text-slate-600 dark:text-slate-400 font-medium">
                                        {formatted.hasValue ? formatted.timestampLabel : formatted.subtext}
                                    </div>
                                </div>
                            )
                        })}
                    </div>

                    {/* Data Honesty: Smart Meter Distinction */}
                    <div className="mt-4 rounded-md border border-cyan-200 bg-cyan-50/70 dark:border-border/60 dark:bg-muted/20 p-3 text-xs text-slate-800 dark:text-muted flex items-start gap-2.5">
                        <Info className="size-4 text-cyan-600 dark:text-primary shrink-0 mt-0.5" />
                        <div>
                            <span className="font-bold text-slate-900 dark:text-heading">Smart Meter Distinction: </span>
                            {reading.grid_power_w === null && reading.consumption_power_w === null ? (
                                <span>
                                    No bidirectional smart meter is detected for this solar installation. Active grid export/import
                                    and household load power are reported as <code className="text-heading font-mono bg-cyan-100/70 dark:bg-muted/30 px-1 py-0.5 rounded font-bold">NULL</code>,
                                    strictly in accordance with SPMS data integrity rules. Values are never fabricated from solar generation.
                                </span>
                            ) : (
                                <span>
                                    Smart meter active: Grid Power = {reading.grid_power_w} W, Household Load = {reading.consumption_power_w} W.
                                </span>
                            )}
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    )
}

