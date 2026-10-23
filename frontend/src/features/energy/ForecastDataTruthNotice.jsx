import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { AlertCircle, Wifi, Home, Gauge } from 'lucide-react'

/**
 * ForecastDataTruthNotice (SPMS Energy V1 — Section 4).
 *
 * Replaces the artificial synthetic hourly household demand curve.
 *
 * Data Integrity Declaration:
 * - Sub-hourly household load measurements are not available.
 * - Monthly billing data does NOT justify fabricating an hourly demand curve.
 * - This component honestly explains the limitation and what IS monitored.
 * - NEVER renders a synthetic demand trend curve from bill-based models.
 */
export function ForecastDataTruthNotice() {
    return (
        <Card className="border-border bg-surface shadow-sm" data-testid="forecast-data-truth-notice">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                            <Home className="size-4 text-muted" />
                            Sub-Hourly Household Demand
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Availability of live household load measurements.
                        </CardDescription>
                    </div>
                    <span className="inline-flex items-center rounded border border-border/80 bg-muted/20 px-2 py-0.5 text-[10px] font-semibold text-muted">
                        UNAVAILABLE
                    </span>
                </div>
            </CardHeader>

            <CardContent className="space-y-4">
                {/* Primary notice */}
                <div className="rounded-lg border border-border/60 bg-muted/5 p-4">
                    <div className="flex items-start gap-3">
                        <AlertCircle className="size-5 text-muted/60 shrink-0 mt-0.5" />
                        <div>
                            <p className="text-sm font-medium text-heading mb-1">
                                Hourly household demand data is not available
                            </p>
                            <p className="text-xs text-muted leading-relaxed">
                                Hourly household demand is not available because SPMS does not have sub-hourly household-load
                                measurements. Rooftop solar telemetry is recorded from your GoodWe inverter,
                                and household consumption is tracked via verified monthly bills. Sub-hourly load profiling
                                requires an installed bidirectional smart meter or CT load monitor.
                            </p>
                        </div>
                    </div>
                </div>

                {/* What IS monitored */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3.5">
                        <div className="flex items-center gap-2 mb-1.5">
                            <Wifi className="size-3.5 text-emerald-500" />
                            <span className="text-xs font-semibold text-emerald-700 dark:text-emerald-400">GoodWe Telemetry Recorded</span>
                        </div>
                        <p className="text-xs text-muted leading-relaxed">
                            Rooftop solar generation is captured in 5-minute intervals from your GoodWe SEMS+ inverter.
                        </p>
                    </div>

                    <div className="rounded-lg border border-border/60 bg-muted/5 p-3.5">
                        <div className="flex items-center gap-2 mb-1.5">
                            <Gauge className="size-3.5 text-muted/60" />
                            <span className="text-xs font-semibold text-muted">Not Connected</span>
                        </div>
                        <p className="text-xs text-muted leading-relaxed">
                            Live household load is not measured. A bidirectional smart meter or CT clamp monitor
                            would enable real-time demand tracking.
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}
