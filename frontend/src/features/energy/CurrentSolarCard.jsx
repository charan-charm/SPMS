import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { Sun, Activity, Database, Zap } from 'lucide-react'
import { formatObservationTimestamp } from '../solar/solarStatus'
import { useAuth } from '../auth/useAuth'

/**
 * CurrentSolarCard (SPMS Energy — Section 1).
 *
 * Shows actual GoodWe telemetry (or simulated telemetry in DEMO mode):
 * - Today's measured generation (kWh)
 * - Instantaneous active power (kW / W) — preserves last valid measurement when stale, never forces to zero.
 * - Hardware inverter status & freshness
 * - Explicit ACTUAL or SIMULATED data nature
 */
function getLocalDateString(date = new Date()) {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Kolkata',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(date)
    } catch {
        const y = date.getFullYear()
        const m = String(date.getMonth() + 1).padStart(2, '0')
        const d = String(date.getDate()).padStart(2, '0')
        return `${y}-${m}-${d}`
    }
}

function parseMeasurementDateString(rawStr) {
    if (!rawStr || typeof rawStr !== 'string') return null
    const trimmed = rawStr.trim()
    const match = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (match) {
        return `${match[1]}-${match[2]}-${match[3]}`
    }
    const dt = new Date(trimmed)
    if (!isNaN(dt.getTime())) {
        return getLocalDateString(dt)
    }
    return null
}

function formatDayMonthYear(dateStr) {
    if (!dateStr) return ''
    try {
        const [y, m, d] = dateStr.split('-').map(Number)
        const dt = new Date(y, m - 1, d)
        return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    } catch {
        return dateStr
    }
}

export function CurrentSolarCard({ solarLatest = null }) {
    const { account } = useAuth()
    const isDemo = Boolean(account?.is_demo || account?.role === 'DEMO_VIEWER')
    const hasSolar = Boolean(solarLatest)
    const isSimulated = solarLatest?.data_nature === 'SIMULATED' || isDemo
    const todayDateStr = getLocalDateString(new Date())
    const measurementDateStr = parseMeasurementDateString(solarLatest?.measurement_time)
    const isTodayMeasurement = Boolean(hasSolar && measurementDateStr && measurementDateStr === todayDateStr)

    const rawKwh = solarLatest?.daily_generation_kwh ?? solarLatest?.today_kwh ?? null
    const parsedKwh = rawKwh !== null && rawKwh !== undefined ? parseFloat(rawKwh) : null

    // Date semantics: "Today's Generation" strictly represents the current calendar date only.
    // If telemetry belongs to a previous completed day, current day is 0.00 kWh until fresh sun generation.
    const todayKwh = hasSolar
        ? (isTodayMeasurement ? (parsedKwh ?? 0.0) : 0.0)
        : null

    const lastCompletedDayLabel = (!isTodayMeasurement && measurementDateStr && parsedKwh !== null)
        ? `Last completed day — ${formatDayMonthYear(measurementDateStr)} · ${parsedKwh.toFixed(2)} kWh`
        : null

    const rawPowerW = solarLatest?.power_w ?? solarLatest?.pv_power_w ?? null
    const hasValidPower = rawPowerW !== null && rawPowerW !== undefined && !isNaN(Number(rawPowerW))
    const powerW = hasValidPower ? Number(rawPowerW) : null
    const powerKw = powerW !== null ? (powerW / 1000.0).toFixed(2) : null

    const inverterStatus = solarLatest?.inverter_status || (hasSolar ? 'NORMAL' : 'DISCONNECTED')
    const isOnline = inverterStatus === 'NORMAL' || inverterStatus === 'GENERATING' || inverterStatus === 'CONNECTED'
    const dataStatus = solarLatest?.data_status || 'FRESH'
    const isStale = dataStatus === 'STALE'
    const isPowerStale = isStale || !isTodayMeasurement
    const obsTimeStr = formatObservationTimestamp(solarLatest?.measurement_time)

    let formattedTime = 'Standby'
    if (solarLatest?.measurement_time) {
        try {
            const dt = new Date(solarLatest.measurement_time)
            if (!isNaN(dt.getTime())) {
                formattedTime = dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' })
            }
        } catch {
            // Keep default
        }
    }

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="current-solar-card">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <Sun className="size-4 text-amber-500" />
                            Current Solar Generation
                        </CardTitle>
                        <CardDescription className="text-xs text-slate-700 dark:text-muted mt-0.5 font-medium">
                            {isSimulated ? 'Simulated solar generation telemetry for demonstration.' : 'Latest recorded GoodWe solar generation for your household.'}
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Primary Provenance Badge */}
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-sky-400 bg-sky-100 dark:border-sky-500/40 dark:bg-sky-500/15 px-2.5 py-0.5 text-xs font-bold text-sky-900 dark:text-sky-300 shadow-xs">
                            <span className="size-1.5 rounded-full bg-sky-500" />
                            {isSimulated ? 'SIMULATED (DEMO)' : 'ACTUAL · GoodWe SEMS'}
                        </span>

                        {/* Secondary Hardware Link */}
                        <span className="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-emerald-50 dark:border-border/80 dark:bg-muted/20 px-2 py-0.5 text-[11px] font-bold text-emerald-900 dark:text-slate-300">
                            Solar: {hasSolar ? 'Connected' : 'Disconnected'}
                        </span>

                        {/* Secondary Freshness Indicator */}
                        {!isTodayMeasurement && measurementDateStr ? (
                            <span className="inline-flex items-center gap-1 rounded-full border border-sky-300 bg-sky-50 dark:border-sky-500/20 dark:bg-sky-500/5 px-2 py-0.5 text-[10px] font-semibold text-sky-900 dark:text-sky-400">
                                <span className="size-1.5 rounded-full bg-sky-500/80" />
                                Standby · Last reading {formatDayMonthYear(measurementDateStr)} {obsTimeStr || formattedTime}
                            </span>
                        ) : isStale ? (
                            <span className="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/5 px-2 py-0.5 text-[10px] font-bold text-amber-900 dark:text-amber-400">
                                <span className="size-1.5 rounded-full bg-amber-500/80" />
                                Telemetry: STALE · {obsTimeStr || formattedTime}
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/5 px-2 py-0.5 text-[10px] font-bold text-emerald-900 dark:text-emerald-400">
                                <span className="size-1.5 rounded-full bg-emerald-500" />
                                Telemetry: Recorded · {obsTimeStr || formattedTime}
                            </span>
                        )}
                    </div>
                </div>
            </CardHeader>

            <CardContent>
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* 1. Today's Total Generation */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <span className="text-xs font-bold text-amber-900 dark:text-slate-300 flex items-center gap-1.5 mb-2">
                                <Zap className="size-3.5 text-amber-500" />
                                {isTodayMeasurement ? 'Generated so far (Today)' : "Today's Generation"}
                            </span>
                            <div className="flex items-baseline gap-1.5 mt-1">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {hasSolar && todayKwh !== null ? todayKwh.toFixed(2) : '—'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kWh</span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-slate-700 dark:text-slate-400 flex flex-col gap-0.5 font-medium">
                            {isTodayMeasurement ? (
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold text-slate-800 dark:text-slate-300">Measured today</span>
                                    <span className="font-mono text-[10px] text-slate-800 dark:text-slate-400 font-bold">{obsTimeStr || `${formattedTime} IST`}</span>
                                </div>
                            ) : lastCompletedDayLabel ? (
                                <span className="text-slate-800 dark:text-slate-300 font-semibold">
                                    {lastCompletedDayLabel}
                                </span>
                            ) : (
                                <span className="font-medium text-slate-700 dark:text-slate-400">Awaiting today&apos;s sunrise generation</span>
                            )}
                        </div>
                    </div>

                    {/* 2. Instantaneous Power Output */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between" data-testid="current-active-power">
                        <div>
                            <span className="text-xs font-bold text-emerald-900 dark:text-slate-300 flex items-center gap-1.5 mb-2">
                                <Activity className="size-3.5 text-emerald-500" />
                                {isPowerStale ? 'Last Observed Active Power' : 'Active Power Output'}
                            </span>
                            <div className="flex items-baseline gap-1.5 mt-1">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {hasSolar && powerKw !== null ? powerKw : '—'}
                                </span>
                                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">kW</span>
                                {powerW !== null && powerW > 0 && (
                                    <span className="text-[11px] font-mono text-slate-700 dark:text-slate-400 font-bold ml-1">
                                        ({Math.round(powerW)} W)
                                    </span>
                                )}
                            </div>
                            {hasSolar && powerW === 0 && (
                                <div className="text-[11px] font-bold text-slate-700 dark:text-slate-400 mt-1">
                                    {isPowerStale ? 'Last observed standby (0 W)' : 'Night / standby (0 W)'}
                                </div>
                            )}
                            {hasSolar && powerW !== null && powerW > 0 && isPowerStale && (
                                <div className="text-[11px] font-bold text-amber-700 dark:text-amber-400 mt-1">
                                    Last observed generating · {obsTimeStr || formattedTime}
                                </div>
                            )}
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-slate-700 dark:text-slate-400 flex items-center justify-between font-medium">
                            <span className="font-semibold text-slate-800 dark:text-slate-300">
                                {isPowerStale ? 'Last recorded power' : 'Inverter telemetry state'}
                            </span>
                            <span className={isPowerStale ? 'text-amber-800 dark:text-amber-400 font-bold' : (isOnline ? 'text-emerald-800 dark:text-emerald-400 font-bold' : 'text-amber-800 dark:text-amber-400 font-bold')}>
                                {powerW === null
                                    ? 'Awaiting first valid telemetry'
                                    : (isPowerStale
                                        ? (powerW > 0 ? `STALE · Last observed ${powerKw} kW` : `STALE · Standby`)
                                        : (powerW === 0 ? 'Night Standby' : inverterStatus))}
                            </span>
                        </div>
                    </div>

                    {/* 3. Hardware Inverter Provenance */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <span className="text-xs font-bold text-emerald-900 dark:text-slate-300 flex items-center gap-1.5 mb-2">
                                <Database className="size-3.5 text-primary" />
                                Inverter Telemetry Link
                            </span>
                            <div className="flex flex-col gap-0.5 mt-1">
                                <span className="text-base sm:text-lg font-display font-bold text-heading tracking-tight">
                                    Solar: {hasSolar ? 'Connected' : 'Disconnected'}
                                </span>
                                <span className="text-xs text-slate-700 dark:text-slate-300 font-semibold">
                                    Telemetry: {isStale ? `STALE · Last observed ${obsTimeStr || formattedTime}` : `LIVE · ${obsTimeStr || formattedTime}`}
                                </span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-slate-700 dark:text-slate-400 flex items-center justify-between font-medium">
                            <span className="font-bold text-slate-900 dark:text-heading">GoodWe SEMS Portal</span>
                            <span className={isStale ? 'text-amber-800 dark:text-amber-400 font-bold' : 'text-emerald-800 dark:text-emerald-400 font-bold'}>
                                {isStale ? (powerW !== null && powerW > 0 ? 'Last observed generating' : 'Standby / paused') : '5-min stream'}
                            </span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}

