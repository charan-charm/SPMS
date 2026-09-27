import { AlertCircle, Info, ShieldCheck, Database, Calendar } from 'lucide-react'

/**
 * F3 — Forecast Limitations & Provenance Card (docs/01 §14, §26; docs/04 §240).
 *
 * Explicitly surfaces backend-authoritative forecast limitations, model version,
 * and provenance so users understand the degree of uncertainty and what additional
 * historical data would improve accuracy.
 */
export function ForecastLimitationsCard({
    limitations = [],
    provenance = {},
    combinedConfidence = 'MODERATE',
}) {
    const hasLimitations = limitations.length > 0
    const solarProvenance = provenance.solar || {}
    const consumptionProvenance = provenance.consumption || {}

    return (
        <div className="rounded-2xl border border-border/80 bg-surface/70 p-5 shadow-sm backdrop-blur-sm space-y-4">
            <div className="flex items-center justify-between border-b border-border/50 pb-3">
                <div className="flex items-center gap-2">
                    <ShieldCheck className="h-5 w-5 text-emerald-400" />
                    <h3 className="font-semibold text-text-primary text-sm">Forecast Provenance & Limitations</h3>
                </div>
                <div className="flex items-center gap-2 text-xs text-text-muted">
                    <Database className="h-3.5 w-3.5" />
                    <span>Deterministic Backend Model</span>
                </div>
            </div>

            {/* Active Limitations List */}
            {hasLimitations ? (
                <div className="space-y-2">
                    <div className="text-xs font-medium text-text-secondary">Active Model Limitations & Caveats:</div>
                    <ul className="space-y-1.5">
                        {limitations.map((limitation, idx) => (
                            <li
                                key={idx}
                                className="flex items-start gap-2 text-xs text-text-muted bg-surface-subtle/40 rounded-lg p-2.5 border border-border/30"
                            >
                                <AlertCircle className="h-4 w-4 text-amber-400 shrink-0 mt-0.5" />
                                <span>{limitation}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <div className="flex items-center gap-2 text-xs text-emerald-400 bg-emerald-500/10 rounded-lg p-3 border border-emerald-500/20">
                    <Info className="h-4 w-4 shrink-0" />
                    <span>High-integrity inputs: sufficient verified bill history and active solar telemetry are available for this forecast.</span>
                </div>
            )}

            {/* Provenance Metadata Grid */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 text-xs border-t border-border/40">
                <div className="bg-surface-subtle/30 p-2.5 rounded-lg border border-border/30 space-y-1">
                    <div className="text-text-muted font-medium">Solar Evidence</div>
                    <div className="text-text-primary font-medium flex items-center justify-between">
                        <span>Readings: {solarProvenance.evidence_count ?? 0}</span>
                        <span className="text-[11px] text-text-muted">
                            {solarProvenance.weather_available ? 'Weather: Active' : 'Weather: Baseline'}
                        </span>
                    </div>
                </div>

                <div className="bg-surface-subtle/30 p-2.5 rounded-lg border border-border/30 space-y-1">
                    <div className="text-text-muted font-medium">Consumption Evidence</div>
                    <div className="text-text-primary font-medium flex items-center justify-between">
                        <span>Verified Bills: {consumptionProvenance.verified_bills_count ?? 0}</span>
                        {consumptionProvenance.average_daily_kwh && (
                            <span className="text-[11px] text-text-muted">
                                ~{consumptionProvenance.average_daily_kwh} kWh/day
                            </span>
                        )}
                    </div>
                </div>

                <div className="bg-surface-subtle/30 p-2.5 rounded-lg border border-border/30 space-y-1">
                    <div className="text-text-muted font-medium">Confidence Level</div>
                    <div className="text-text-primary font-medium flex items-center justify-between">
                        <span>Overall: {combinedConfidence}</span>
                        <span className="text-[11px] text-text-muted flex items-center gap-1">
                            <Calendar className="h-3 w-3" />
                            Deterministic
                        </span>
                    </div>
                </div>
            </div>
        </div>
    )
}
