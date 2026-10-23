import { Link } from 'react-router'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { FileText, ArrowDownRight, ArrowUpRight, CheckCircle2, Clock, ExternalLink } from 'lucide-react'
import { formatKwh, formatBillingPeriod, formatNetEnergy } from './energyPeriodUtils'

/**
 * HistoricalEnergyTable (SPMS Energy V1 — Section 2).
 *
 * Displays genuine recorded historical energy evidence from verified utility bills.
 *
 * Data Integrity Rules:
 * - Shows recent 6–8 billing periods (most recent first).
 * - Net balance uses precise terminology: "X kWh surplus" or "X kWh deficit".
 * - Never fabricates a continuous intraday time series from monthly bills.
 * - Always links to /bills for full billing history.
 */
export function HistoricalEnergyTable({ bills = [], timezone = 'Asia/Kolkata' }) {
    // Only display bills that have genuine consumption or solar data
    const validBills = bills
        .filter(
            (b) => b && (b.consumption_kwh !== null || b.solar_units_kwh !== null || b.net_units_kwh !== null)
        )
        .slice(0, 8) // Limit to 8 most recent

    const hasBills = validBills.length > 0

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="historical-energy-card">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <FileText className="size-4 text-primary" />
                            Historical Energy
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Recorded evidence from verified utility bills and meter statements.
                        </CardDescription>
                    </div>
                    <span className="inline-flex items-center rounded border border-border/80 bg-muted/20 px-2 py-0.5 text-[10px] font-semibold text-muted">
                        HISTORICAL EVIDENCE
                    </span>
                </div>
            </CardHeader>

            <CardContent>
                {!hasBills ? (
                    <div className="py-8 px-4 text-center rounded-lg border border-dashed border-border/70 bg-muted/5">
                        <FileText className="size-7 mx-auto mb-2 text-muted/50" />
                        <p className="text-sm font-medium text-heading">Historical comparison unavailable</p>
                        <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
                            Upload and verify electricity bills to see historical household consumption and solar generation.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border border-border/60">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-border/60 bg-muted/10 text-muted font-medium">
                                <tr>
                                    <th className="py-2.5 px-3 whitespace-nowrap">Billing Period</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Consumption</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Solar Units</th>
                                    <th className="py-2.5 px-3 text-right whitespace-nowrap">Net Balance</th>
                                    <th className="py-2.5 px-3 whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/40 font-mono">
                                {validBills.map((bill) => {
                                    const consumption = bill.consumption_kwh !== null ? parseFloat(bill.consumption_kwh) : null
                                    const solar = bill.solar_units_kwh !== null ? parseFloat(bill.solar_units_kwh) : null
                                    const net = bill.net_units_kwh !== null ? parseFloat(bill.net_units_kwh) : null
                                    const isVerified = bill.status === 'VERIFIED'

                                    const { text: netText, isSurplus } = formatNetEnergy(consumption, solar, net)

                                    const periodLabel = formatBillingPeriod(
                                        bill.billing_period_start,
                                        bill.billing_period_end,
                                        timezone
                                    )

                                    return (
                                        <tr key={bill.id || bill.bill_id} className="hover:bg-muted/10 transition-colors">
                                            <td className="py-2.5 px-3 text-heading font-sans font-medium whitespace-nowrap">
                                                {periodLabel}
                                            </td>
                                            <td className="py-2.5 px-3 text-right text-heading">
                                                {consumption !== null ? `${formatKwh(consumption, 1)} kWh` : '—'}
                                            </td>
                                            <td className="py-2.5 px-3 text-right text-emerald-600 dark:text-emerald-400">
                                                {solar !== null ? `${formatKwh(solar, 1)} kWh` : '—'}
                                            </td>
                                            <td className="py-2.5 px-3 text-right font-semibold">
                                                {netText !== '—' ? (
                                                    <span
                                                        className={`inline-flex items-center gap-1 ${
                                                            isSurplus === true
                                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                                : isSurplus === false
                                                                    ? 'text-amber-600 dark:text-amber-400'
                                                                    : 'text-muted'
                                                        }`}
                                                    >
                                                        {isSurplus === true ? (
                                                            <ArrowDownRight className="size-3" />
                                                        ) : isSurplus === false ? (
                                                            <ArrowUpRight className="size-3" />
                                                        ) : null}
                                                        {netText}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="py-2.5 px-3 font-sans whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                        isVerified
                                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                            : 'bg-muted/20 text-muted'
                                                    }`}
                                                >
                                                    {isVerified ? (
                                                        <CheckCircle2 className="size-2.5" />
                                                    ) : (
                                                        <Clock className="size-2.5" />
                                                    )}
                                                    {bill.status || 'UNVERIFIED'}
                                                </span>
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="mt-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                    <p className="text-[11px] text-muted">
                        Historical energy sourced from verified electricity bills. Live load requires a connected household meter.
                    </p>
                    <Link
                        to="/bills"
                        data-testid="bills-history-link"
                        className="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline whitespace-nowrap shrink-0"
                    >
                        View all billing history in Bills
                        <ExternalLink className="size-3" />
                    </Link>
                </div>
            </CardContent>
        </Card>
    )
}
