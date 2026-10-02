import { useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, History } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { TONE_CLASS } from '../../lib/semantics'
import { formatMoney } from '../../lib/money'
import {
    BILL_SOURCE,
    BILL_STATUS,
    NOT_AVAILABLE,
    formatEnergy,
    formatEnergyBalance,
    formatPeriod,
    formatBillingMonthYear,
    sortBillsChronologically,
    getBillDocumentSource,
} from './presentation'
import { cn } from '../../lib/utils'

/**
 * BillHistory (B4 §17) — chronological billing-period history table.
 *
 * Implements:
 * - Canonical ordering: newest to oldest
 * - Month-first primary identifier (e.g. "Sep 2026")
 * - Secondary metadata: actual period range + statement serial
 * - Truthful status semantics: exact counts of recorded, verified, extracted, and awaiting review
 * - Subtle selected row styling
 * - Compact pagination
 */
export function BillHistory({ bills = [], selectedId = null, onSelect = () => {}, onUpload = () => {}, pageSize = 8 }) {
    const [page, setPage] = useState(1)
    const [showAll, setShowAll] = useState(false)

    const statementBills = useMemo(() => {
        return (bills || []).filter(
            (bill) =>
                !['PROCESSING_FAILED', 'NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', 'UPLOADED', 'PROCESSING'].includes(bill.status) &&
                Boolean(bill.billing_period_start && bill.billing_period_end)
        )
    }, [bills])

    const sortedBills = useMemo(() => sortBillsChronologically(statementBills), [statementBills])

    const verified = sortedBills.filter((bill) => bill.status === 'VERIFIED')
    const extracted = sortedBills.filter((bill) => bill.status === 'EXTRACTED')
    const needsReview = sortedBills.filter((bill) => bill.status === 'NEEDS_REVIEW')
    const totalBills = sortedBills.length

    const totalPages = Math.max(1, Math.ceil(totalBills / pageSize))
    const currentPage = Math.min(page, totalPages)
    const displayedBills = showAll
        ? sortedBills
        : sortedBills.slice((currentPage - 1) * pageSize, currentPage * pageSize)

    // Truthful status description
    let statusDescription = 'No verified bills yet. Historical billing archive.'
    if (totalBills > 0) {
        if (verified.length > 0) {
            statusDescription = `${totalBills} recorded · ${verified.length} verified · ${extracted.length} extracted · ${needsReview.length} awaiting review`
        } else {
            statusDescription = `No verified bills yet. ${totalBills} recorded · ${extracted.length} extracted · ${needsReview.length} awaiting review`
        }
    }

    return (
        <Card aria-labelledby="history-title" className="overflow-hidden shadow-2xs">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle id="history-title" className="flex items-center gap-2 text-base font-semibold text-heading">
                        <History className="size-4 text-secondary" aria-hidden="true" />
                        Billing history
                    </CardTitle>
                    {totalBills > 0 && (
                        <span className="rounded-full border border-border bg-surface-alt px-2.5 py-0.5 text-xs font-medium text-muted">
                            {totalBills} {totalBills === 1 ? 'statement' : 'statements'} recorded
                        </span>
                    )}
                </div>
                <CardDescription className="text-xs">
                    {statusDescription}
                </CardDescription>
            </CardHeader>
            <CardContent className="p-0">
                {bills.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 py-8 text-center">
                        <p className="text-sm text-muted">No bills uploaded yet.</p>
                        <Button type="button" onClick={onUpload}>
                            Upload bill
                        </Button>
                    </div>
                ) : totalBills === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-8 text-center text-sm text-muted">
                        No recognized statements yet.
                    </div>
                ) : (
                    <div className="flex flex-col">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm" role="table">
                                <thead className="border-b border-border bg-surface-alt text-xs font-semibold text-muted">
                                    <tr>
                                        <th scope="col" className="px-4 py-2.5">Billing period</th>
                                        <th scope="col" className="px-3 py-2.5 text-right">Consumption</th>
                                        <th scope="col" className="px-3 py-2.5 text-right">Solar credited</th>
                                        <th scope="col" className="px-3 py-2.5 text-right">Energy balance</th>
                                        <th scope="col" className="px-3 py-2.5 text-right">Bill amount</th>
                                        <th scope="col" className="px-3 py-2.5 text-left">Source</th>
                                        <th scope="col" className="px-4 py-2.5 text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {displayedBills.map((bill) => {
                                        const status = BILL_STATUS[bill.status] ?? { label: bill.status, tone: 'muted' }
                                        const sourceMeta = BILL_SOURCE[bill.source] ?? BILL_SOURCE.ELECTRICITY_BILL
                                        const docSource = getBillDocumentSource(bill)
                                        const DocIcon = docSource.icon
                                        const isSelected = bill.bill_id === selectedId
                                        const isPreSolar = bill.solar_applicability === 'BEFORE_SOLAR_INSTALLATION'
                                        const monthLabel = formatBillingMonthYear(
                                            bill.billing_period_start,
                                            bill.billing_period_end,
                                            bill.bill_date
                                        )
                                        const periodRange = formatPeriod(bill.billing_period_start, bill.billing_period_end)

                                        return (
                                            <tr
                                                key={bill.bill_id}
                                                tabIndex={0}
                                                role="button"
                                                aria-current={isSelected ? 'row' : undefined}
                                                onClick={() => onSelect(bill.bill_id)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' || e.key === ' ') {
                                                        e.preventDefault()
                                                        onSelect(bill.bill_id)
                                                    }
                                                }}
                                                className={cn(
                                                    'cursor-pointer transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-primary',
                                                    isSelected
                                                        ? 'bg-primary/8 ring-1 ring-inset ring-primary/25 font-medium text-heading'
                                                        : 'hover:bg-surface-alt'
                                                )}
                                            >
                                                {/* Column 1: Month-first primary label with secondary range + serial */}
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-col gap-0.5">
                                                        <span className="font-semibold text-heading">
                                                            {monthLabel}
                                                        </span>
                                                        <div className="flex items-center gap-1.5 text-[11px] text-muted">
                                                            {periodRange && <span>{periodRange}</span>}
                                                            {bill.source_statement_serial && (
                                                                  <span>· #{bill.source_statement_serial}</span>
                                                            )}
                                                            <span className="text-muted/60">·</span>
                                                            <span>{sourceMeta.label}</span>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Consumption */}
                                                <td className="px-3 py-3 text-right font-display text-sm">
                                                    {bill.consumption_kwh !== null
                                                        ? `${formatEnergy(bill.consumption_kwh)} kWh`
                                                        : <span className="text-muted">{NOT_AVAILABLE}</span>}
                                                </td>

                                                {/* Solar generation */}
                                                <td className="px-3 py-3 text-right">
                                                    {bill.solar_units_kwh !== null ? (
                                                        <span className="font-display text-sm font-semibold text-primary">
                                                            {formatEnergy(bill.solar_units_kwh)} kWh
                                                        </span>
                                                    ) : isPreSolar ? (
                                                        <span className="inline-flex rounded-full bg-surface-alt px-1.5 py-0.5 text-[11px] text-muted">
                                                            Pre-solar
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                </td>

                                                {/* Energy balance */}
                                                <td className="px-3 py-3 text-right font-display text-sm">
                                                    {bill.net_units_kwh !== null ? (
                                                        <span className={cn(
                                                            Number(bill.net_units_kwh) < 0 ? 'text-primary font-semibold' : 'text-body'
                                                        )}>
                                                            {formatEnergyBalance(bill.net_units_kwh, {
                                                                solarUnits: bill.solar_units_kwh,
                                                                consumption: bill.consumption_kwh,
                                                            })}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                </td>

                                                {/* Bill amount */}
                                                <td className="px-3 py-3 text-right font-display text-sm font-medium text-heading">
                                                    {bill.bill_amount !== null
                                                        ? formatMoney(bill.bill_amount)
                                                        : <span className="text-muted">{NOT_AVAILABLE}</span>}
                                                </td>

                                                {/* Source */}
                                                <td className="px-3 py-3 text-left">
                                                    <span className="inline-flex items-center gap-1 text-xs text-muted">
                                                        <DocIcon className="size-3 text-secondary shrink-0" aria-hidden="true" />
                                                        <span className="truncate max-w-[120px]">{docSource.badge}</span>
                                                    </span>
                                                </td>

                                                {/* Exact Status */}
                                                <td className="px-4 py-3 text-right">
                                                    <span
                                                        className={cn(
                                                            'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                                                            TONE_CLASS[status.tone],
                                                        )}
                                                    >
                                                        {status.label}
                                                    </span>
                                                </td>
                                            </tr>
                                        )
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination Footer */}
                        {totalPages > 1 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 text-xs text-muted">
                                <div>
                                    Showing {displayedBills.length} of {totalBills} statements
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setShowAll(!showAll)}
                                        className="h-7 px-2 text-xs"
                                    >
                                        {showAll ? 'Show paginated' : 'Show all'}
                                    </Button>
                                    {!showAll && (
                                        <div className="flex items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={currentPage <= 1}
                                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                                className="size-7 p-0"
                                                aria-label="Previous history page"
                                            >
                                                <ChevronLeft className="size-3.5" aria-hidden="true" />
                                            </Button>
                                            <span className="px-2 text-xs font-medium text-heading">
                                                {currentPage} / {totalPages}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={currentPage >= totalPages}
                                                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                                                className="size-7 p-0"
                                                aria-label="Next history page"
                                            >
                                                <ChevronRight className="size-3.5" aria-hidden="true" />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
