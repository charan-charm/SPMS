import { useMemo } from 'react'
import { ChevronLeft, ChevronRight, Calendar } from 'lucide-react'
import { Button } from '../../components/ui/button'
import {
    formatBillingMonthYear,
    formatBillingFullMonthYear,
    formatPeriod,
    sortBillsChronologically,
} from './presentation'
import { cn } from '../../lib/utils'

/**
 * StatementNavigator — month-first chronological navigation for multi-statement bill collections.
 *
 * Implements:
 * - Canonical chronological ordering (newest to oldest)
 * - Breadcrumb & month-first selector: [Bills] → [September 2026 ▼]
 * - Secondary metadata: 1 Aug 2026 – 1 Sep 2026 · Statement #1
 * - Previous/Next statement buttons
 * - Deduplication: duplicate periods do not create duplicate navigation entries
 * - Horizontally scrollable period strip (Sep 2026 | Aug 2026 | Jul 2026 ...) with active highlight
 */
export function StatementNavigator({ bills = [], selectedId = null, onSelect = () => {} }) {
    // 1. Statements in navigator must be recognized bills with valid billing periods
    const statementBills = useMemo(() => {
        return (bills || []).filter(
            (bill) =>
                !['PROCESSING_FAILED', 'NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', 'UPLOADED', 'PROCESSING'].includes(bill.status) &&
                Boolean(bill.billing_period_start && bill.billing_period_end)
        )
    }, [bills])

    // 2. Sort bills canonically: billing_period_start DESC, billing_period_end DESC, id DESC
    const sortedBills = useMemo(() => sortBillsChronologically(statementBills), [statementBills])

    // 2. Deduplicate unique chronological periods (Requirement 5)
    const { periods, activePeriodIndex, selectedBill } = useMemo(() => {
        if (!sortedBills || sortedBills.length === 0) {
            return { periods: [], activePeriodIndex: -1, selectedBill: null }
        }

        const map = new Map()
        for (const bill of sortedBills) {
            const periodLabel = formatBillingFullMonthYear(
                bill.billing_period_start,
                bill.billing_period_end,
                bill.bill_date
            )
            // Use date range as unique key if available, else formatted month-year
            const key = bill.billing_period_start && bill.billing_period_end
                ? `${bill.billing_period_start}_${bill.billing_period_end}`
                : periodLabel

            if (!map.has(key)) {
                map.set(key, {
                    key,
                    label: periodLabel,
                    shortLabel: formatBillingMonthYear(
                        bill.billing_period_start,
                        bill.billing_period_end,
                        bill.bill_date
                    ),
                    periodRange: formatPeriod(bill.billing_period_start, bill.billing_period_end),
                    representativeBill: bill,
                    bills: [bill],
                })
            } else {
                const entry = map.get(key)
                entry.bills.push(bill)
                // If current selectedId is in this group, prefer it as representative
                if (bill.bill_id === selectedId) {
                    entry.representativeBill = bill
                }
            }
        }

        const uniquePeriods = Array.from(map.values())

        let currentBill = sortedBills.find((b) => b.bill_id === selectedId) ?? null
        let activeIdx = uniquePeriods.findIndex((p) =>
            p.bills.some((b) => b.bill_id === selectedId)
        )

        if (activeIdx < 0 && uniquePeriods.length > 0) {
            activeIdx = 0
            currentBill = uniquePeriods[0].representativeBill
        }

        return {
            periods: uniquePeriods,
            activePeriodIndex: activeIdx,
            selectedBill: currentBill ?? uniquePeriods[0]?.representativeBill ?? null,
        }
    }, [sortedBills, selectedId])

    if (!periods || periods.length <= 1) {
        return null
    }

    const currentPeriod = periods[activePeriodIndex] ?? periods[0]
    const representativeBill = currentPeriod?.representativeBill ?? selectedBill

    // Navigation: periods are sorted newest first (descending by period)
    // "Previous statement" goes to earlier period (higher index in descending list)
    // "Next statement" goes to later period (lower index in descending list)
    const canGoEarlier = activePeriodIndex < periods.length - 1
    const canGoLater = activePeriodIndex > 0

    const handleEarlier = () => {
        if (canGoEarlier) {
            const earlier = periods[activePeriodIndex + 1]
            onSelect(earlier.representativeBill.bill_id)
        }
    }

    const handleLater = () => {
        if (canGoLater) {
            const later = periods[activePeriodIndex - 1]
            onSelect(later.representativeBill.bill_id)
        }
    }

    return (
        <nav
            aria-label="Billing statement navigation"
            className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4 shadow-xs"
        >
            {/* Top row: Breadcrumb + Month selector & Previous/Next buttons */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                    <div className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Calendar className="size-4" aria-hidden="true" />
                    </div>

                    {/* [Bills] → [September 2026 ▼] */}
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-muted">
                        <span className="uppercase tracking-wider">Bills</span>
                        <span className="text-muted/60" aria-hidden="true">→</span>
                    </div>

                    <div className="relative">
                        <select
                            id="statement-period-select"
                            aria-label="Select billing statement by month"
                            value={representativeBill?.bill_id ?? ''}
                            onChange={(e) => onSelect(e.target.value)}
                            className="appearance-none rounded-lg border border-border bg-surface-alt py-1.5 pl-3 pr-8 font-display text-sm font-semibold text-heading shadow-2xs transition-colors hover:bg-surface focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer"
                        >
                            {periods.map((p) => {
                                const serialText = p.representativeBill.source_statement_serial
                                    ? ` · Statement #${p.representativeBill.source_statement_serial}`
                                    : ''
                                return (
                                    <option key={p.key} value={p.representativeBill.bill_id}>
                                        {p.label}{serialText}
                                    </option>
                                )
                            })}
                        </select>
                        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted text-xs">
                            ▼
                        </span>
                    </div>

                    {/* Secondary metadata: 1 Aug 2026 – 1 Sep 2026 · Statement #1 */}
                    {currentPeriod && (
                        <div className="hidden md:flex items-center gap-1.5 text-xs text-muted">
                            {currentPeriod.periodRange && (
                                <span>{currentPeriod.periodRange}</span>
                            )}
                            {representativeBill?.source_statement_serial && (
                                <>
                                    <span className="text-muted/50">·</span>
                                    <span className="font-mono">
                                        Statement #{representativeBill.source_statement_serial}
                                    </span>
                                </>
                            )}
                        </div>
                    )}
                </div>

                {/* [‹ Previous] [Next ›] */}
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canGoEarlier}
                        onClick={handleEarlier}
                        className="h-8 px-2.5 text-xs text-body hover:text-heading cursor-pointer"
                        aria-label="Previous statement (earlier period)"
                    >
                        <ChevronLeft className="mr-1 size-3.5" aria-hidden="true" />
                        <span>Previous<span className="hidden sm:inline"> statement</span></span>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canGoLater}
                        onClick={handleLater}
                        className="h-8 px-2.5 text-xs text-body hover:text-heading cursor-pointer"
                        aria-label="Next statement (later period)"
                    >
                        <span>Next<span className="hidden sm:inline"> statement</span></span>
                        <ChevronRight className="ml-1 size-3.5" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            {/* Compact non-wrapping horizontal month strip: Sep 2026 | Aug 2026 | Jul 2026 ... */}
            <div
                className="flex items-center gap-1.5 overflow-x-auto whitespace-nowrap pt-1 pb-1 scrollbar-thin text-xs"
                role="tablist"
                aria-label="Chronological billing periods"
            >
                {periods.map((p, idx) => {
                    const isSelected = idx === activePeriodIndex
                    const isPreSolar = p.representativeBill.solar_applicability === 'BEFORE_SOLAR_INSTALLATION'

                    return (
                        <button
                            key={p.key}
                            type="button"
                            role="tab"
                            aria-selected={isSelected}
                            onClick={() => onSelect(p.representativeBill.bill_id)}
                            className={cn(
                                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 font-medium transition-all cursor-pointer select-none',
                                isSelected
                                    ? 'bg-primary text-primary-contrast shadow-xs ring-1 ring-primary/30 font-semibold'
                                    : 'border border-border bg-surface-alt text-muted hover:border-border-strong hover:bg-surface hover:text-heading'
                            )}
                            title={isPreSolar ? `${p.shortLabel} (Pre-solar)` : p.shortLabel}
                        >
                            <span>{p.shortLabel}</span>
                            {isPreSolar && (
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full shrink-0',
                                        isSelected ? 'bg-primary-contrast/80' : 'bg-muted/60'
                                    )}
                                    aria-hidden="true"
                                />
                            )}
                        </button>
                    )
                })}
            </div>
        </nav>
    )
}
