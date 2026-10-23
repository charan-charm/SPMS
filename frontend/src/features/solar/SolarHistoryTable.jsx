import { useMemo } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { History, Calendar, Zap, CheckCircle2, Clock } from 'lucide-react'
import { parseSafeDate } from './solarCurveData'

/**
 * SolarHistoryTable — SPMS Final V1 Solar Generation History Table (Part H & I).
 *
 * Implements:
 * - Real GoodWe historical dates (newest first).
 * - Columns: Date | Generation | Peak Power | Observations | Status.
 * - Bounded internal scroll area (~10 rows visible, no excessive page expansion).
 * - Row click -> selects date, updates graph, updates summary, highlights row, scrolls to graph.
 * - Accessible keyboard interaction.
 */
export function SolarHistoryTable({
    days = [],
    history = [],
    selectedDate = null,
    onSelectDate = null,
}) {
    // Derive days list from days prop or fallback from history
    const effectiveDays = useMemo(() => {
        if (days && days.length > 0) return days
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
                    date_label: !isNaN(dt.getTime())
                        ? dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
                        : dStr,
                    is_today: isToday,
                    generation_kwh: parseFloat(r.daily_generation_kwh || '0'),
                    peak_power_w: parseFloat(r.power_w || '0'),
                    peak_power_kw: parseFloat((parseFloat(r.power_w || '0') / 1000).toFixed(2)),
                    observation_count: 1,
                    status: isToday ? 'LIVE' : 'COMPLETE',
                    status_label: isToday ? 'Live / In Progress' : 'Complete',
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
    }, [days, history])

    if (!effectiveDays || effectiveDays.length === 0) {
        return null
    }

    const handleRowClick = (dateStr) => {
        if (!dateStr) return
        if (onSelectDate) {
            onSelectDate(dateStr)
        }

        // Smooth scroll to graph anchor if not already nicely visible in viewport
        try {
            const graphEl = document.getElementById('solar-curve-section')
            if (graphEl) {
                const rect = graphEl.getBoundingClientRect()
                const isVisible = rect.top >= 70 && rect.bottom <= window.innerHeight
                if (!isVisible) {
                    graphEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
                }
            }
        } catch {
            // Silently ignore in unsupported test runners
        }
    }

    return (
        <Card className="border-border bg-surface" data-testid="solar-history-table">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div>
                        <CardTitle className="text-sm font-bold text-heading flex items-center gap-2">
                            <History className="size-4 text-primary" />
                            Solar Generation History
                        </CardTitle>
                        <CardDescription className="text-xs text-slate-700 dark:text-slate-400 mt-0.5 font-medium">
                            Real daily generation records from GoodWe SEMS inverter telemetry.
                        </CardDescription>
                    </div>
                    <span className="text-xs font-mono font-bold text-emerald-800 dark:text-slate-300 bg-emerald-50 dark:bg-muted/30 px-2.5 py-1 rounded-md border border-emerald-300 dark:border-border/60">
                        {effectiveDays.length} available {effectiveDays.length === 1 ? 'day' : 'days'}
                    </span>
                </div>
            </CardHeader>

            {/* Bounded internal scroll area */}
            <CardContent className="px-4 pb-4 pt-0">
                <div className="overflow-x-auto rounded-lg border border-border/60">
                    <div className="max-h-[26rem] overflow-y-auto">
                        <table className="w-full text-left text-xs">
                            <thead
                                className="border-b border-border/80 text-slate-900 dark:text-slate-200 font-bold sticky top-0 z-10 bg-slate-100/90 dark:bg-surface shadow-xs"
                            >
                                <tr>
                                    <th className="py-2.5 px-3 whitespace-nowrap">Date</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Generation</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Peak Power</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Observations</th>
                                    <th className="py-2.5 px-3 whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/40">
                                {effectiveDays.map((row) => {
                                    const isSelected = selectedDate === row.date
                                    const genKwh = row.generation_kwh !== undefined
                                        ? Number(row.generation_kwh).toFixed(2)
                                        : '0.00'
                                    const peakKw = row.peak_power_kw !== undefined
                                        ? Number(row.peak_power_kw).toFixed(2)
                                        : (row.peak_power_w ? (row.peak_power_w / 1000).toFixed(2) : '0.00')

                                    const isToday = Boolean(row.is_today)
                                    const isLive = row.status === 'LIVE'
                                    const isStale = row.status === 'STALE'

                                    return (
                                        <tr
                                            key={row.date}
                                            onClick={() => handleRowClick(row.date)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault()
                                                    handleRowClick(row.date)
                                                }
                                            }}
                                            tabIndex={0}
                                            role="button"
                                            aria-label={`Select date ${row.date_label || row.date}`}
                                            data-testid={`solar-history-row-${row.date}`}
                                            className={`cursor-pointer transition-colors outline-hidden ${
                                                isSelected
                                                    ? 'bg-primary/10 border-l-4 border-l-primary font-semibold text-heading'
                                                    : 'hover:bg-muted/15 text-slate-800 dark:text-slate-200'
                                            }`}
                                        >
                                            {/* Date Column */}
                                            <td className="py-2.5 px-3 whitespace-nowrap font-mono">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-semibold text-heading">
                                                        {row.date_label || row.date}
                                                    </span>
                                                    {isToday && (
                                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-sky-100 text-sky-900 dark:bg-sky-500/15 dark:text-sky-400 border border-sky-300 dark:border-sky-500/30">
                                                            Today
                                                        </span>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Generation Column */}
                                            <td className="py-2.5 px-3 text-right font-mono font-bold text-heading whitespace-nowrap">
                                                {genKwh} kWh
                                            </td>

                                            {/* Peak Power Column */}
                                            <td className="py-2.5 px-3 text-right font-mono font-bold text-cyan-800 dark:text-cyan-300 whitespace-nowrap">
                                                {peakKw} kW
                                            </td>

                                            {/* Observations Column */}
                                            <td className="py-2.5 px-3 text-right font-mono font-medium text-slate-800 dark:text-slate-400 whitespace-nowrap">
                                                {row.observation_count ?? '—'}
                                            </td>

                                            {/* Status Column */}
                                            <td className="py-2.5 px-3 whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold border ${
                                                        isLive
                                                            ? 'bg-emerald-100 text-emerald-900 border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/30'
                                                            : isStale
                                                                ? 'bg-amber-100 text-amber-900 border-amber-400 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/30'
                                                                : 'bg-emerald-50 text-emerald-800 border-emerald-300 dark:bg-slate-500/10 dark:text-slate-300 dark:border-slate-500/20'
                                                    }`}
                                                >
                                                    {isLive && <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" />}
                                                    {isStale && <Clock className="size-2.5 text-amber-700 dark:text-amber-500" />}
                                                    {!isLive && !isStale && <CheckCircle2 className="size-2.5 text-emerald-600 dark:text-slate-500" />}
                                                    {row.status === 'LIVE'
                                                        ? 'LIVE'
                                                        : row.status === 'STALE'
                                                            ? 'LIVE (STALE)'
                                                            : 'COMPLETE'}
                                                </span>
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}
