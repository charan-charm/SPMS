import { useState } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { FileCheck, Clock, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { useAuth } from '../auth/useAuth'

/**
 * RecentForecastValidationsTable (SPMS Energy — Section 6 / Section 12).
 *
 * Displays genuine historical forecast runs that have completed their target period
 * and were evaluated against authentic GoodWe inverter telemetry.
 * Supports pagination across 30+ completed historical days and interactive date inspection.
 */
export function RecentForecastValidationsTable({
    performanceHistory = [],
    selectedDate = null,
    onSelectDate = null,
}) {
    const { account } = useAuth()
    const isDemo = Boolean(account?.is_demo || account?.role === 'DEMO_VIEWER')
    const [page, setPage] = useState(1)
    const pageSize = 10

    const hasHistory = Array.isArray(performanceHistory) && performanceHistory.length > 0
    const totalItems = performanceHistory.length
    const totalPages = Math.ceil(totalItems / pageSize) || 1

    const startIndex = (page - 1) * pageSize
    const paginatedItems = hasHistory ? performanceHistory.slice(startIndex, startIndex + pageSize) : []

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="recent-validations-card">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <FileCheck className="size-4 text-primary" />
                            Recent Forecast Results
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            {isDemo ? 'Compare predicted solar generation with simulated GoodWe generation.' : 'Compare predicted solar generation with actual GoodWe generation.'}
                        </CardDescription>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center rounded border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:border-border/80 dark:bg-muted/20 dark:text-muted">
                            {totalItems} COMPLETED DAYS
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-3">
                {!hasHistory ? (
                    <div className="py-10 text-center text-xs text-muted rounded-lg border border-dashed border-border/70 bg-muted/5">
                        <Clock className="size-6 mx-auto mb-2 text-muted/40" />
                        <p className="font-medium text-heading">Validation records pending</p>
                        <p className="mt-1 max-w-sm mx-auto">
                            {isDemo
                                ? 'Completed daily forecast periods will appear here once full 24-hour simulated telemetry has been evaluated.'
                                : 'Completed daily forecast periods will appear here once full 24-hour GoodWe telemetry has been evaluated.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-md border border-border/60">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b border-slate-300 dark:border-border/60 bg-slate-100 dark:bg-muted/20 text-slate-900 dark:text-slate-300 font-bold">
                                    <tr>
                                        <th className="py-2.5 px-3 whitespace-nowrap">Date</th>
                                        <th className="py-2.5 px-3 text-right whitespace-nowrap">Day-Ahead Forecast</th>
                                        <th className="py-2.5 px-3 text-right whitespace-nowrap">{isDemo ? 'GoodWe (Simulated)' : 'Actual GoodWe'}</th>
                                        <th className="py-2.5 px-3 text-right whitespace-nowrap">Difference</th>
                                        <th className="py-2.5 px-3 text-right whitespace-nowrap">Capacity-Norm. Accuracy</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/40 font-mono">
                                    {paginatedItems.map((item, idx) => {
                                        const predKwh = item.predicted_kwh !== null && item.predicted_kwh !== undefined ? parseFloat(item.predicted_kwh) : null
                                        const actKwh = item.actual_kwh !== null && item.actual_kwh !== undefined ? parseFloat(item.actual_kwh) : null
                                        const diffKwh = (actKwh !== null && predKwh !== null) ? (actKwh - predKwh) : null
                                        const accPct = item.accuracy_pct !== null && item.accuracy_pct !== undefined ? parseFloat(item.accuracy_pct) : null

                                        const isSelected = selectedDate === item.date

                                        return (
                                            <tr
                                                key={item.date || idx}
                                                onClick={() => onSelectDate && onSelectDate(item.date)}
                                                className={`transition-colors cursor-pointer ${
                                                    isSelected
                                                        ? 'bg-primary/10 border-l-2 border-l-primary'
                                                        : 'hover:bg-muted/10'
                                                }`}
                                                title="Click to view full day-ahead validation curve"
                                            >
                                                <td className="py-2.5 px-3 font-sans font-medium text-heading whitespace-nowrap">
                                                    {item.date_label || item.date}
                                                </td>
                                                <td className="py-2.5 px-3 text-right text-sky-700 dark:text-sky-400 font-semibold whitespace-nowrap">
                                                    {predKwh !== null ? `${predKwh.toFixed(2)} kWh` : '—'}
                                                </td>
                                                <td className="py-2.5 px-3 text-right text-emerald-700 dark:text-emerald-400 font-semibold whitespace-nowrap">
                                                    {actKwh !== null ? `${actKwh.toFixed(2)} kWh` : '—'}
                                                </td>
                                                <td className="py-2.5 px-3 text-right text-slate-900 dark:text-slate-200 font-semibold whitespace-nowrap">
                                                    {diffKwh !== null ? `${diffKwh >= 0 ? '+' : ''}${diffKwh.toFixed(2)} kWh` : '—'}
                                                </td>
                                                <td className="py-2.5 px-3 text-right text-emerald-700 dark:text-emerald-400 font-bold whitespace-nowrap">
                                                    {accPct !== null ? `${accPct.toFixed(1)}%` : '—'}
                                                </td>
                                            </tr>
                                        )
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination Footer */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-1 text-xs text-slate-700 dark:text-muted">
                            <div>
                                Showing <strong className="text-slate-900 dark:text-heading">{startIndex + 1}</strong>–<strong className="text-slate-900 dark:text-heading">{Math.min(startIndex + pageSize, totalItems)}</strong> of <strong className="text-slate-900 dark:text-heading">{totalItems}</strong> completed validations
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    disabled={page <= 1}
                                    className="h-7 px-2 text-xs gap-1"
                                >
                                    <ChevronLeft className="size-3.5" />
                                    Prev
                                </Button>
                                <span className="px-2 font-mono text-[11px]">
                                    Page {page} of {totalPages}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                                    disabled={page >= totalPages}
                                    className="h-7 px-2 text-xs gap-1"
                                >
                                    Next
                                    <ChevronRight className="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    )
}
