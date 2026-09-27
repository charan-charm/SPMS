import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { History } from 'lucide-react'

/**
 * SolarHistoryTable (SPMS O4 — docs/10 D-059, D-060).
 *
 * Displays chronological telemetry reading intervals with high-precision metrics.
 */
export function SolarHistoryTable({ history = [] }) {
    if (!history || history.length === 0) {
        return null
    }

    const formatTime = (iso) => {
        if (!iso) return '—'
        try {
            const d = new Date(iso)
            return d.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            })
        } catch {
            return iso
        }
    }

    return (
        <Card className="border-border bg-surface">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div>
                        <CardTitle className="text-sm font-semibold text-heading flex items-center gap-2">
                            <History className="size-4 text-primary" />
                            Recent Ingestion History
                        </CardTitle>
                        <CardDescription className="text-xs text-muted">
                            Recent 5-minute normalized telemetry intervals recorded in database.
                        </CardDescription>
                    </div>
                    <span className="text-xs font-mono text-muted bg-muted/20 px-2 py-0.5 rounded">
                        {history.length} interval{history.length === 1 ? '' : 's'}
                    </span>
                </div>
            </CardHeader>

            <CardContent>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-border/60 text-muted font-medium bg-muted/10">
                            <tr>
                                <th className="py-2.5 px-3">Interval Time</th>
                                <th className="py-2.5 px-3 text-right">Power (W)</th>
                                <th className="py-2.5 px-3 text-right">Daily (kWh)</th>
                                <th className="py-2.5 px-3 text-right">AC Volts</th>
                                <th className="py-2.5 px-3">Status</th>
                                <th className="py-2.5 px-3">Nature</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/40">
                            {history.map((row) => {
                                const power = parseFloat(row.power_w || '0')
                                const daily = parseFloat(row.daily_generation_kwh || '0')
                                const isOffline = row.inverter_status === 'OFFLINE'

                                return (
                                    <tr key={row.id} className="hover:bg-muted/10 transition-colors">
                                        <td className="py-2.5 px-3 font-mono text-heading">
                                            {formatTime(row.measurement_time)}
                                        </td>
                                        <td className="py-2.5 px-3 text-right font-mono font-medium text-heading">
                                            {power.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
                                        </td>
                                        <td className="py-2.5 px-3 text-right font-mono text-muted">
                                            {daily.toFixed(3)}
                                        </td>
                                        <td className="py-2.5 px-3 text-right font-mono text-muted">
                                            {row.ac_voltage_v ? `${row.ac_voltage_v} V` : '—'}
                                        </td>
                                        <td className="py-2.5 px-3">
                                            <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                isOffline
                                                    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                                    : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                            }`}>
                                                {row.inverter_status || 'NORMAL'}
                                            </span>
                                        </td>
                                        <td className="py-2.5 px-3">
                                            <span className="inline-flex items-center rounded bg-muted/20 px-1.5 py-0.5 text-[10px] font-medium text-muted">
                                                {row.data_nature || 'ACTUAL'}
                                            </span>
                                        </td>
                                    </tr>
                                )
                            })}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    )
}
