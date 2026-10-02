import { useMemo, useState, useRef, useEffect } from 'react'
import { ChevronLeft, ChevronRight, Calendar, ChevronDown, Check } from 'lucide-react'
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
    const [dropdownOpen, setDropdownOpen] = useState(false)
    const dropdownRef = useRef(null)

    useEffect(() => {
        if (!dropdownOpen) return
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setDropdownOpen(false)
            }
        }
        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                setDropdownOpen(false)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        document.addEventListener('keydown', handleKeyDown)
        return () => {
            document.removeEventListener('mousedown', handleClickOutside)
            document.removeEventListener('keydown', handleKeyDown)
        }
    }, [dropdownOpen])

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

                    <div className="relative" ref={dropdownRef}>
                        {/* Accessible select retained for testing and screen readers */}
                        <select
                            id="statement-period-select"
                            aria-label="Select billing statement by month"
                            value={representativeBill?.bill_id ?? ''}
                            onChange={(e) => onSelect(e.target.value)}
                            className="sr-only"
                            tabIndex={-1}
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

                        {/* Custom SPMS-styled interactive dropdown trigger */}
                        <button
                            type="button"
                            id="statement-period-dropdown-trigger"
                            aria-haspopup="listbox"
                            aria-expanded={dropdownOpen}
                            aria-label={`Current statement: ${currentPeriod?.label ?? 'Select period'}. Click to browse other months.`}
                            onClick={() => setDropdownOpen((prev) => !prev)}
                            className={cn(
                                'flex items-center gap-2 rounded-lg border border-border bg-surface-alt px-3 py-1.5',
                                'text-sm font-semibold text-heading shadow-2xs transition-all cursor-pointer',
                                'hover:border-border-strong hover:bg-surface focus:outline-none focus:ring-2 focus:ring-primary/20',
                                dropdownOpen && 'border-primary ring-2 ring-primary/20 bg-surface'
                            )}
                        >
                            <span className="font-display font-semibold">
                                {currentPeriod?.label ?? 'Select statement'}
                            </span>

                            {representativeBill?.status === 'VERIFIED' && (
                                <span className="rounded-full bg-primary/10 px-1.5 py-0.2 text-[10px] font-semibold text-primary">
                                    Verified
                                </span>
                            )}

                            {representativeBill?.solar_applicability === 'BEFORE_SOLAR_INSTALLATION' && (
                                <span className="rounded-full bg-surface px-1.5 py-0.2 text-[10px] font-medium text-muted border border-border/80">
                                    Pre-solar
                                </span>
                            )}

                            {representativeBill?.source_statement_serial && (
                                <span className="rounded bg-surface px-1.5 py-0.2 font-mono text-[10px] font-medium text-muted border border-border/80">
                                    #{representativeBill.source_statement_serial}
                                </span>
                            )}

                            <ChevronDown
                                className={cn(
                                    'size-3.5 text-muted transition-transform duration-150',
                                    dropdownOpen && 'rotate-180 text-heading'
                                )}
                                aria-hidden="true"
                            />
                        </button>

                        {/* Custom SPMS-styled scrollable popover menu */}
                        {dropdownOpen && (
                            <div
                                role="listbox"
                                aria-label="Available billing statements"
                                className="absolute left-0 top-full z-50 mt-1.5 w-72 sm:w-80 rounded-xl border border-border bg-surface p-1.5 shadow-lg ring-1 ring-black/5 animate-in fade-in-0 zoom-in-95 duration-100"
                            >
                                <div className="px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted border-b border-border/50 flex items-center justify-between">
                                    <span>Billing statements</span>
                                    <span className="font-mono text-[10px] font-normal">{periods.length} months</span>
                                </div>
                                <div className="max-h-72 overflow-y-auto divide-y divide-border/30 scrollbar-thin py-1">
                                    {periods.map((p, idx) => {
                                        const isSelected = idx === activePeriodIndex
                                        const bill = p.representativeBill
                                        const isPreSolar = bill.solar_applicability === 'BEFORE_SOLAR_INSTALLATION'

                                        return (
                                            <button
                                                key={p.key}
                                                type="button"
                                                role="option"
                                                aria-selected={isSelected}
                                                onClick={() => {
                                                    onSelect(bill.bill_id)
                                                    setDropdownOpen(false)
                                                }}
                                                className={cn(
                                                    'w-full flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-left text-xs transition-colors cursor-pointer',
                                                    isSelected
                                                        ? 'bg-primary/10 text-primary font-semibold'
                                                        : 'text-body hover:bg-surface-alt hover:text-heading'
                                                )}
                                            >
                                                <div className="flex flex-col min-w-0">
                                                    <span className={cn('font-display text-sm font-semibold truncate', isSelected ? 'text-primary' : 'text-heading')}>
                                                        {p.label}
                                                    </span>
                                                    <span className="text-[11px] text-muted truncate">
                                                        {p.periodRange}
                                                        {bill.source_statement_serial ? ` · #${bill.source_statement_serial}` : ''}
                                                    </span>
                                                </div>

                                                <div className="flex items-center gap-1.5 shrink-0">
                                                    {bill.status === 'VERIFIED' && (
                                                        <span className="rounded-full bg-primary/15 px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                                                            Verified
                                                        </span>
                                                    )}
                                                    {isPreSolar && (
                                                        <span className="rounded-full border border-border bg-surface-alt px-1.5 py-0.5 text-[10px] text-muted">
                                                            Pre-solar
                                                        </span>
                                                    )}
                                                    {isSelected && (
                                                        <Check className="size-3.5 text-primary shrink-0" aria-hidden="true" />
                                                    )}
                                                </div>
                                            </button>
                                        )
                                    })}
                                </div>
                            </div>
                        )}
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
