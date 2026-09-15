import { CircleDollarSign, History, Sun, Zap } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { TONE_CLASS } from '../../lib/semantics'
import { formatMoney } from '../../lib/money'
import { BILL_STATUS, NOT_AVAILABLE, formatEnergy, formatPeriod } from './presentation'
import { cn } from '../../lib/utils'

/**
 * BillHistory (B4 §17) — the workspace's submission list, built only
 * from what GET /api/v1/bills actually provides (period, headline
 * numbers, lifecycle status). Unverified drafts are listed as uploads
 * awaiting review; they are never labeled "history" in the verified
 * sense, and the verified section states honestly that nothing is
 * verified yet until the backend says otherwise.
 */
export function BillHistory({ bills, selectedId, onSelect, onUpload }) {
    const verified = bills.filter((bill) => bill.status === 'VERIFIED')

    return (
        <Card aria-labelledby="history-title">
            <CardHeader>
                <CardTitle id="history-title" className="flex items-center gap-2 text-base">
                    <History className="size-4 text-secondary" aria-hidden="true" />
                    Bill history
                </CardTitle>
                <CardDescription>
                    {verified.length > 0
                        ? `${verified.length} verified ${verified.length === 1 ? 'bill' : 'bills'} feed your household analysis.`
                        : 'No verified bills yet. Once you review and verify a bill, it will appear here.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {bills.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 py-4 text-center">
                        <p className="text-sm text-muted">No bills uploaded yet.</p>
                        <Button type="button" onClick={onUpload}>
                            Upload bill
                        </Button>
                    </div>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {bills.map((bill) => {
                            const status = BILL_STATUS[bill.status] ?? { label: bill.status, tone: 'muted' }
                            const isSelected = bill.bill_id === selectedId

                            return (
                                <li key={bill.bill_id}>
                                    <button
                                        type="button"
                                        onClick={() => onSelect(bill.bill_id)}
                                        aria-current={isSelected ? 'true' : undefined}
                                        className={cn(
                                            'w-full rounded-md border px-3 py-2.5 text-left transition-colors duration-150',
                                            isSelected
                                                ? 'border-primary/50 bg-primary-soft/40'
                                                : 'border-border bg-surface hover:bg-surface-alt',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="truncate text-sm font-medium text-heading">
                                                {formatPeriod(bill.billing_period_start, bill.billing_period_end) ??
                                                    'Period not available'}
                                            </p>
                                            <span
                                                className={cn(
                                                    'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-semibold',
                                                    TONE_CLASS[status.tone],
                                                )}
                                            >
                                                {status.label}
                                            </span>
                                        </div>
                                        <dl className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                                            <div className="flex items-center gap-1">
                                                <dt className="sr-only">Consumption</dt>
                                                <Zap className="size-3" aria-hidden="true" />
                                                <dd>
                                                    {bill.consumption_kwh !== null
                                                        ? `${formatEnergy(bill.consumption_kwh)} kWh`
                                                        : NOT_AVAILABLE}
                                                </dd>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <dt className="sr-only">Solar units</dt>
                                                <Sun className="size-3" aria-hidden="true" />
                                                <dd>
                                                    {bill.solar_units_kwh !== null
                                                        ? `${formatEnergy(bill.solar_units_kwh)} kWh`
                                                        : NOT_AVAILABLE}
                                                </dd>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <dt className="sr-only">Bill amount</dt>
                                                <CircleDollarSign className="size-3" aria-hidden="true" />
                                                <dd>
                                                    {bill.bill_amount !== null
                                                        ? formatMoney(bill.bill_amount)
                                                        : NOT_AVAILABLE}
                                                </dd>
                                            </div>
                                        </dl>
                                    </button>
                                </li>
                            )
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    )
}
