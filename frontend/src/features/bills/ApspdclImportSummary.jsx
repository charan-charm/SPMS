import { useState } from 'react'
import { CheckCircle2, Clock, SkipForward, XCircle, ChevronDown, ChevronUp, Calendar } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card'
import { cn } from '../../lib/utils'
import { formatPeriod, formatBillingMonthYear, maskServiceNumber } from './presentation'

/**
 * ApspdclImportSummary — compact post-import summary card for APSPDCL XLS multi-month
 * uploads (Section 5).
 *
 * Communicates: "This was ONE historical import containing MANY billing periods."
 * Displays:
 *   APSPDCL Excel import
 *   26 statements found · 24 imported · 2 skipped · 0 rejected
 *   Period: Oct 2024 – Sep 2026
 *   Solar coverage: 16 solar-active · 8 pre-solar
 */
export function ApspdclImportSummary({ summary, onSelectBill }) {
    const [expanded, setExpanded] = useState(false)

    if (!summary) return null

    const {
        filename = null,
        total_rows,
        total_detected = total_rows,
        newly_imported,
        already_existing,
        duplicates_skipped,
        rejected_rows = 0,
        coverage_range = null,
        solar_coverage = null,
        historical = [],
        service_number = null,
    } = summary

    const created = historical.filter((r) => r.result === 'created')
    const skipped = historical.filter((r) => r.result === 'skipped')
    const historicalTotal = historical.length
    const computedNewlyImported = newly_imported ?? (created.length > 0 ? created.length + 1 : 0)
    const computedExisting = already_existing ?? skipped.length
    const computedDuplicates = duplicates_skipped ?? 0
    const computedSkipped = computedExisting + computedDuplicates
    const computedRejected = rejected_rows ?? historical.filter((r) => r.result === 'failed').length
    const solarActive = solar_coverage?.solar_active_periods ?? 0
    const preSolar = solar_coverage?.pre_solar_periods ?? 0

    const maskedSn = service_number ? maskServiceNumber(service_number) : null

    // Period range formatted
    let periodRangeText = null
    if (coverage_range?.start && coverage_range?.end) {
        const startMonth = formatBillingMonthYear(coverage_range.start, coverage_range.start)
        const endMonth = formatBillingMonthYear(coverage_range.end, coverage_range.end)
        periodRangeText = `${startMonth} – ${endMonth}`
    }

    return (
        <Card className="border-secondary/40 bg-surface shadow-xs">
            <CardHeader className="p-4 sm:p-5 pb-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2.5">
                        <CheckCircle2 className="size-5 shrink-0 text-secondary" aria-hidden="true" />
                        <div>
                            <CardTitle className="text-base font-semibold text-heading">
                                APSPDCL Excel import
                            </CardTitle>
                            <p className="text-xs text-muted">
                                Historical billing import · Multiple billing periods detected
                                {filename && <span className="font-mono ml-1.5 text-muted/70">({filename})</span>}
                            </p>
                        </div>
                    </div>
                    {maskedSn && (
                        <span className="font-mono text-xs text-muted">
                            Account: {maskedSn}
                        </span>
                    )}
                </div>

                {/* Compact 4-metric strip */}
                <div className="mt-3.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div className="rounded-lg border border-border bg-surface-alt/60 px-3 py-2 text-center">
                        <p className="font-display text-lg font-bold text-heading">{total_detected}</p>
                        <p className="text-[11px] text-muted">Statements found</p>
                    </div>
                    <div className="rounded-lg border border-border bg-surface-alt/60 px-3 py-2 text-center">
                        <p className="font-display text-lg font-bold text-primary">{computedNewlyImported}</p>
                        <p className="text-[11px] text-muted">Imported</p>
                    </div>
                    <div className="rounded-lg border border-border bg-surface-alt/60 px-3 py-2 text-center">
                        <p className="font-display text-lg font-bold text-muted">{computedSkipped}</p>
                        <p className="text-[11px] text-muted">Skipped</p>
                    </div>
                    <div className="rounded-lg border border-border bg-surface-alt/60 px-3 py-2 text-center">
                        <p className={cn(
                            'font-display text-lg font-bold',
                            computedRejected > 0 ? 'text-danger' : 'text-muted'
                        )}>
                            {computedRejected}
                        </p>
                        <p className="text-[11px] text-muted">Rejected</p>
                    </div>
                </div>

                {/* Period range & Solar coverage summary */}
                <div className="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 border-t border-border/60 pt-2 text-xs text-muted">
                    {periodRangeText && (
                        <div className="flex items-center gap-1.5">
                            <Calendar className="size-3.5 text-secondary" aria-hidden="true" />
                            <span>Period: <strong className="text-heading font-medium">{periodRangeText}</strong></span>
                        </div>
                    )}
                    {(solarActive > 0 || preSolar > 0) && (
                        <div className="flex items-center gap-1.5">
                            <span>Solar coverage: <strong className="text-primary font-medium">{solarActive} solar-active</strong> · <span className="text-muted">{preSolar} pre-solar</span></span>
                        </div>
                    )}
                </div>
            </CardHeader>

            {historicalTotal > 0 && (
                <CardContent className="px-4 sm:px-5 pb-3 pt-0">
                    <button
                        type="button"
                        className="flex w-full items-center justify-between rounded-md px-1 py-1.5 text-xs text-muted transition-colors hover:text-body cursor-pointer"
                        onClick={() => setExpanded((e) => !e)}
                        aria-expanded={expanded}
                    >
                        <span>{expanded ? 'Hide' : 'Show'} statement breakdown ({historicalTotal} statements)</span>
                        {expanded
                            ? <ChevronUp className="size-3.5 shrink-0" aria-hidden="true" />
                            : <ChevronDown className="size-3.5 shrink-0" aria-hidden="true" />
                        }
                    </button>

                    {expanded && (
                        <ul className="mt-2 divide-y divide-border rounded-md border border-border bg-surface text-xs">
                            {historical.map((row, idx) => (
                                <ImportRow
                                    key={idx}
                                    row={row}
                                    onSelect={onSelectBill}
                                />
                            ))}
                        </ul>
                    )}
                </CardContent>
            )}
        </Card>
    )
}

function ImportRow({ row, onSelect }) {
    const { period_start, period_end, bill_id, result, reason, status } = row

    const periodLabel = period_start && period_end
        ? formatPeriod(period_start, period_end)
        : 'Unknown period'

    const { icon: Icon, colorClass } = RESULT_DISPLAY[result] ?? RESULT_DISPLAY.failed

    const isClickable = (result === 'created' || result === 'skipped') && bill_id

    return (
        <li className="flex items-center gap-3 px-3 py-2 text-xs">
            <Icon className={cn('size-3.5 shrink-0', colorClass)} aria-hidden="true" />

            <span className="flex-1 font-medium text-body">{periodLabel}</span>

            {result === 'skipped' && reason === 'already_exists' && (
                <span className="text-muted">Already recorded</span>
            )}
            {result === 'skipped' && reason === 'missing_billing_period' && (
                <span className="text-warning">Missing dates</span>
            )}
            {result === 'failed' && (
                <span className="text-danger">
                    {FAILURE_REASON_LABEL[reason] ?? 'Import failed'}
                </span>
            )}
            {result === 'created' && status && (
                <span className="text-muted">{STATUS_SHORT[status] ?? status}</span>
            )}

            {isClickable && (
                <button
                    type="button"
                    className="ml-1 rounded px-2 py-0.5 font-medium text-primary underline-offset-2 hover:underline cursor-pointer"
                    onClick={() => onSelect?.(bill_id)}
                >
                    View
                </button>
            )}
        </li>
    )
}

const RESULT_DISPLAY = {
    created: {
        icon: CheckCircle2,
        colorClass: 'text-primary',
        label: 'Imported',
    },
    skipped: {
        icon: SkipForward,
        colorClass: 'text-muted',
        label: 'Skipped',
    },
    failed: {
        icon: XCircle,
        colorClass: 'text-danger',
        label: 'Failed',
    },
    pending: {
        icon: Clock,
        colorClass: 'text-warning',
        label: 'Pending',
    },
}

const FAILURE_REASON_LABEL = {
    PERSISTENCE_FAILED: 'Could not save',
    OWNERSHIP_MISMATCH: 'Service number mismatch',
    INVALID_BILL_STATE: 'Invalid state',
    VALIDATION_ERROR: 'Validation failed',
    missing_billing_period: 'Missing dates',
}

const STATUS_SHORT = {
    EXTRACTED:    'Ready to review',
    NEEDS_REVIEW: 'Needs review',
    VERIFIED:     'Verified',
    UNCERTAIN:    'Uncertain',
    NOT_A_BILL:   'Not a bill',
    UNREADABLE:   'Unreadable',
}
