import { AlertCircle, Info, ShieldCheck, Database, Calendar, Cpu } from 'lucide-react'

/**
 * F3 — Forecast Limitations & Provenance Card (docs/01 §14, §26; docs/04 §240).
 *
 * Explicitly surfaces backend-authoritative forecast limitations, model version,
 * ML backtesting evaluation metrics, and provenance so users understand the degree
 * of uncertainty and what additional historical data would improve accuracy.
 */
export function ForecastLimitationsCard({
    limitations = [],
    provenance = {},
    combinedConfidence = 'MODERATE',
    forecastSource = 'DETERMINISTIC',
    mlProvenance = null,
}) {
    const hasLimitations = limitations.length > 0
    const solarProvenance = provenance.solar || {}
    const consumptionProvenance = provenance.consumption || {}

    // Normalize source label
    const isML = forecastSource === 'ML'
    const isHybrid = forecastSource === 'HYBRID_ML'

    return (
        <div className="rounded-2xl border border-border/80 bg-surface/70 p-5 shadow-sm backdrop-blur-sm space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-border/50 pb-3">
                <div className="flex items-center gap-2">
                    <ShieldCheck className="h-5 w-5 text-emerald-400" />
                    <h3 className="font-semibold text-text-primary text-sm">Forecast Provenance & Limitations</h3>
                </div>
                <div className="flex items-center gap-2 text-xs">
                    {isML ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                            <Cpu className="h-3 w-3" />
                            <span>Forecast source: ML</span>
                        </span>
                    ) : isHybrid ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-500/15 text-sky-400 border border-sky-500/30">
                            <Cpu className="h-3 w-3" />
                            <span>Forecast source: Hybrid ML</span>
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-text-muted border border-border/40">
                            <Database className="h-3.5 w-3.5" />
                            <span>Forecast source: Deterministic</span>
                        </span>
                    )}
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

            {/* ML Model & Evaluation Strip (when ML or Hybrid is active) */}
            {(isML || isHybrid || mlProvenance) && (
                <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs space-y-2">
                    <div className="flex items-center justify-between font-medium text-emerald-300">
                        <div className="flex items-center gap-1.5">
                            <Cpu className="h-3.5 w-3.5 text-emerald-400" />
                            <span>ML Model & Backtest Performance</span>
                        </div>
                        <span className="text-[11px] text-text-muted">Chronological Backtest Validated</span>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px]">
                        <div className="bg-surface/60 rounded-lg p-2 border border-border/30">
                            <span className="text-text-muted">Solar Model: </span>
                            <span className="font-semibold text-text-primary">
                                {mlProvenance?.solar?.model || solarProvenance.model_name || 'Ridge Regression v1'}
                            </span>
                            {mlProvenance?.solar?.mae !== undefined && mlProvenance?.solar?.mae !== null && (
                                <div className="text-text-muted mt-0.5">
                                    Evaluation: MAE {Number(mlProvenance.solar.mae).toFixed(2)} W
                                    {mlProvenance.solar.baseline_mae ? ` (Baseline MAE: ${Number(mlProvenance.solar.baseline_mae).toFixed(2)} W)` : ''}
                                </div>
                            )}
                        </div>
                        <div className="bg-surface/60 rounded-lg p-2 border border-border/30">
                            <span className="text-text-muted">Consumption Model: </span>
                            <span className="font-semibold text-text-primary">
                                {mlProvenance?.consumption?.model || consumptionProvenance.model_name || 'Seasonal Regression v1'}
                            </span>
                            {mlProvenance?.consumption?.mae !== undefined && mlProvenance?.consumption?.mae !== null && (
                                <div className="text-text-muted mt-0.5">
                                    Evaluation: MAE {Number(mlProvenance.consumption.mae).toFixed(2)} kWh
                                    {mlProvenance.consumption.baseline_mae ? ` (Baseline MAE: ${Number(mlProvenance.consumption.baseline_mae).toFixed(2)} kWh)` : ''}
                                </div>
                            )}
                        </div>
                    </div>
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
                            {isML ? 'ML Validated' : isHybrid ? 'Hybrid' : 'Deterministic'}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    )
}
