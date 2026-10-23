import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { Sun, Home, Scale, Zap, Info } from 'lucide-react'
import { formatKwh, formatBillingMonth } from './energyPeriodUtils'

/**
 * EnergyOverviewCard (SPMS Energy V1 — Section 1).
 *
 * Answers: "What do I know about my energy right now?"
 *
 * Strict Data Integrity Rules:
 * - Solar Generation: ACTUAL — sourced directly from GoodWe inverter telemetry (today's total).
 * - Household Consumption: HISTORICAL — latest verified billing period value. Never called
 *   "today's consumption". Explicitly labelled with billing period.
 * - Live Energy Balance: NOT MEASURABLE — incompatible periods (today's solar vs monthly bill).
 *   Never performs today solar minus monthly bill subtraction.
 * - NEVER derives live household load from billing data.
 * - NEVER fabricates a smart-meter reading.
 */
export function EnergyOverviewCard({ solarLatest = null, latestBill = null }) {
    const hasSolar = Boolean(solarLatest)
    const rawSolarKwh = solarLatest?.daily_generation_kwh ?? solarLatest?.today_kwh ?? null
    const solarKwh = rawSolarKwh !== null && rawSolarKwh !== undefined ? parseFloat(rawSolarKwh) : null

    // Latest verified billing period values — HISTORICAL (never live)
    const hasBillData = Boolean(latestBill)
    const billConsumptionKwh = latestBill?.consumption_kwh !== null && latestBill?.consumption_kwh !== undefined
        ? parseFloat(latestBill.consumption_kwh)
        : null
    const billPeriodLabel = hasBillData
        ? formatBillingMonth(latestBill.billing_period_start, latestBill.billing_period_end)
        : null

    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="energy-overview-card">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <Zap className="size-4 text-primary" />
                            Energy Overview
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            What you actually know about your energy right now.
                        </CardDescription>
                    </div>
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted/10 px-2.5 py-0.5 text-[11px] font-medium text-muted">
                        <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" />
                        Current Status
                    </span>
                </div>
            </CardHeader>

            <CardContent>
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* 1. Solar Generation — ACTUAL (GoodWe inverter, today's total) */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                    <Sun className="size-3.5 text-amber-500" />
                                    Solar Generation
                                </span>
                                <span className="inline-flex items-center rounded border border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                                    ACTUAL
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1">
                                <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                    {hasSolar && solarKwh !== null ? formatKwh(solarKwh, 2) : '—'}
                                </span>
                                <span className="text-xs font-semibold text-muted">kWh</span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-muted">
                            <span className="font-medium text-heading">GoodWe SEMS</span>
                            <span className="text-muted/60"> · </span>
                            <span>Measured today</span>
                        </div>
                    </div>

                    {/* 2. Household Consumption — HISTORICAL (latest billing period, never "today") */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                    <Home className="size-3.5 text-sky-500" />
                                    Household Consumption
                                </span>
                                {hasBillData && billConsumptionKwh !== null ? (
                                    <span className="inline-flex items-center rounded border border-sky-500/30 bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-sky-600 dark:text-sky-400">
                                        HISTORICAL
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center rounded border border-border/80 bg-muted/20 px-1.5 py-0.5 text-[10px] font-semibold text-muted">
                                        UNAVAILABLE
                                    </span>
                                )}
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1">
                                {hasBillData && billConsumptionKwh !== null ? (
                                    <>
                                        <span className="text-2xl sm:text-3xl font-display font-bold text-heading tracking-tight">
                                            {formatKwh(billConsumptionKwh, 0)}
                                        </span>
                                        <span className="text-xs font-semibold text-muted">kWh</span>
                                    </>
                                ) : (
                                    <span className="text-xl sm:text-2xl font-display font-semibold text-muted tracking-tight">
                                        Unavailable
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-muted">
                            {hasBillData && billPeriodLabel ? (
                                <>
                                    <span className="font-medium text-heading">{billPeriodLabel} billing period</span>
                                    <span className="text-muted/60"> · </span>
                                    <span>Monthly statement</span>
                                </>
                            ) : (
                                <span>No live household-load meter is connected.</span>
                            )}
                        </div>
                    </div>

                    {/* 3. Live Energy Balance — NOT MEASURABLE (incompatible periods) */}
                    <div className="rounded-lg border border-border/80 bg-surface p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                    <Scale className="size-3.5 text-muted" />
                                    Energy Balance
                                </span>
                                <span className="inline-flex items-center rounded border border-border/80 bg-muted/20 px-1.5 py-0.5 text-[10px] font-semibold text-muted">
                                    NOT MEASURABLE
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5 mt-1">
                                <span className="text-xl sm:text-2xl font-display font-semibold text-muted tracking-tight">
                                    Not measurable
                                </span>
                            </div>
                        </div>
                        <div className="pt-3 mt-3 border-t border-border/50 text-[11px] text-muted">
                            <span>Cannot calculate live balance without real-time household load data.</span>
                        </div>
                    </div>
                </div>

                <div className="mt-4 rounded-md border border-border/60 bg-muted/5 px-3 py-2 text-xs text-muted flex items-center gap-2">
                    <Info className="size-3.5 shrink-0 text-muted" />
                    <span>
                        Household consumption is available from historical electricity bills. Live load data requires a connected household meter.
                    </span>
                </div>
            </CardContent>
        </Card>
    )
}
