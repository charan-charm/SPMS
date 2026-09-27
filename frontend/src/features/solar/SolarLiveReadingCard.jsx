import { Card, CardHeader, CardTitle, CardContent } from '../../components/ui/card'
import { Sun, Activity, Gauge, Thermometer, Zap, Info, AlarmClockOff } from 'lucide-react'
import { DATA_STATUS, DATA_NATURE } from '../../lib/semantics'

/**
 * SolarLiveReadingCard (SPMS O4 / O4.7 — docs/10 D-059, D-060, D-061, D-062).
 *
 * Displays high-precision live solar telemetry and electrical parameters.
 * Strictly adheres to SPMS data honesty: when a smart meter is absent,
 * grid and consumption power are displayed honestly as unmetered (never fabricated).
 */
export function SolarLiveReadingCard({ reading }) {
    if (!reading) {
        return (
            <Card className="border-border bg-surface">
                <CardContent className="py-12 text-center text-muted">
                    <Sun className="size-8 mx-auto mb-2 text-muted/60" />
                    <p className="font-medium text-heading text-sm">No telemetry points recorded yet.</p>
                    <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
                        Click "Sync Now" on your connection card to ingest the latest 5-minute telemetry point from SEMS+.
                    </p>
                </CardContent>
            </Card>
        )
    }

    const powerW = parseFloat(reading.power_w || '0')
    const powerKw = (powerW / 1000).toFixed(2)
    const dailyKwh = parseFloat(reading.daily_generation_kwh || '0').toFixed(3)
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
    const isStale = reading.data_status === 'STALE'
    const isOffline = reading.inverter_status === 'OFFLINE'
    const statusMeta = DATA_STATUS[reading.data_status] || DATA_STATUS.FRESH
    const natureMeta = DATA_NATURE[reading.data_nature] || DATA_NATURE.ACTUAL

    return (
        <div className="space-y-4">
            {/* Stale Telemetry Notice */}
            {isStale && (
                <div className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2.5">
                    <AlarmClockOff className="size-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
                    <div>
                        <span className="font-semibold">Telemetry is STALE: </span>
                        Last reported by inverter at {measurementTime}. Inverter may be in night standby or offline. Values will refresh when generation resumes.
                    </div>
                </div>
            )}

            {/* Primary Generation KPI Card */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-muted text-xs font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Zap className="size-4 text-amber-500" />
                            Current Active Power
                        </span>
                        <span className="text-[11px] font-mono text-muted">{measurementTime}</span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-display font-bold text-heading tracking-tight">
                            {powerW.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
                        </span>
                        <span className="text-sm font-medium text-muted">W</span>
                        <span className="text-xs text-muted ml-auto font-medium">
                            ({powerKw} kW)
                        </span>
                    </div>
                    <div className="mt-3 flex items-center gap-2">
                        <span className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium ${
                            isOffline ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                        }`}>
                            {reading.inverter_status || 'UNKNOWN'}
                        </span>
                        <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-medium ${statusMeta.className}`}>
                            {reading.data_status || 'FRESH'}
                        </span>
                        <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-medium ${natureMeta.className}`}>
                            {reading.data_nature || 'ACTUAL'}
                        </span>
                    </div>
                </Card>

                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-muted text-xs font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Sun className="size-4 text-amber-500" />
                            Today's Generation
                        </span>
                        <span className="text-[11px] text-muted">Cumulative</span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-display font-bold text-heading tracking-tight">
                            {dailyKwh}
                        </span>
                        <span className="text-sm font-medium text-muted">kWh</span>
                    </div>
                    <p className="text-[11px] text-muted mt-3">
                        Total energy produced by rooftop array today.
                    </p>
                </Card>

                <Card className="border-border bg-surface p-5 shadow-sm">
                    <div className="flex items-center justify-between text-muted text-xs font-medium mb-2">
                        <span className="flex items-center gap-1.5">
                            <Gauge className="size-4 text-primary" />
                            Lifetime Energy
                        </span>
                        <span className="text-[11px] text-muted">Inverter Total</span>
                    </div>
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-display font-bold text-heading tracking-tight">
                            {totalKwh ? parseFloat(totalKwh).toLocaleString() : '—'}
                        </span>
                        <span className="text-sm font-medium text-muted">kWh</span>
                    </div>
                    <p className="text-[11px] text-muted mt-3">
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
                        <div className="rounded-md border border-border/60 bg-muted/10 p-3">
                            <span className="text-muted block text-[11px] font-medium">AC Voltage</span>
                            <span className="font-display text-base font-semibold text-heading mt-1 block">
                                {reading.ac_voltage_v ? `${reading.ac_voltage_v} V` : '—'}
                            </span>
                        </div>

                        <div className="rounded-md border border-border/60 bg-muted/10 p-3">
                            <span className="text-muted block text-[11px] font-medium">AC Current</span>
                            <span className="font-display text-base font-semibold text-heading mt-1 block">
                                {reading.ac_current_a ? `${reading.ac_current_a} A` : '—'}
                            </span>
                        </div>

                        <div className="rounded-md border border-border/60 bg-muted/10 p-3">
                            <span className="text-muted block text-[11px] font-medium">Grid Frequency</span>
                            <span className="font-display text-base font-semibold text-heading mt-1 block">
                                {reading.ac_frequency_hz ? `${reading.ac_frequency_hz} Hz` : '—'}
                            </span>
                        </div>

                        <div className="rounded-md border border-border/60 bg-muted/10 p-3">
                            <span className="text-muted block text-[11px] font-medium">DC Array (PV)</span>
                            <span className="font-display text-base font-semibold text-heading mt-1 block">
                                {reading.pv_power_w ? `${reading.pv_power_w} W` : '—'}
                            </span>
                        </div>

                        <div className="rounded-md border border-border/60 bg-muted/10 p-3">
                            <span className="text-muted block text-[11px] font-medium flex items-center gap-1">
                                <Thermometer className="size-3 text-muted" /> Temperature
                            </span>
                            <span className="font-display text-base font-semibold text-heading mt-1 block">
                                {reading.temperature_c ? `${reading.temperature_c} °C` : '—'}
                            </span>
                        </div>
                    </div>

                    {/* Data Honesty: Smart Meter Distinction */}
                    <div className="mt-4 rounded-md border border-border/60 bg-muted/20 p-3 text-xs text-muted flex items-start gap-2.5">
                        <Info className="size-4 text-primary shrink-0 mt-0.5" />
                        <div>
                            <span className="font-semibold text-heading">Smart Meter Distinction: </span>
                            {reading.grid_power_w === null && reading.consumption_power_w === null ? (
                                <span>
                                    No bidirectional smart meter is detected for this solar installation. Active grid export/import
                                    and household load power are reported as <code className="text-heading font-mono bg-muted/30 px-1 py-0.5 rounded">NULL</code>,
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
