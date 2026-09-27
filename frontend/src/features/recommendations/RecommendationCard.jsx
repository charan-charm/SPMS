import { useId, useState } from 'react'
import { Link } from 'react-router'
import {
    Sun,
    Zap,
    Clock,
    AlertCircle,
    ChevronDown,
    ExternalLink,
    X,
    CheckCircle2,
    Database,
    Compass,
    TrendingDown,
    IndianRupee,
} from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { QualityIndicator } from '../../components/data-display/QualityIndicator'
import { cn } from '../../lib/utils'

const PRIORITY_BADGE = {
    HIGH: 'border-primary/40 bg-primary-soft text-primary-active',
    MEDIUM: 'border-warning/40 bg-warning/10 text-warning',
    LOW: 'border-border bg-surface-alt text-muted',
}

const CONFIDENCE_BADGE = {
    HIGH: 'border-success/40 bg-success/10 text-success',
    MODERATE: 'border-primary/40 bg-primary-soft text-primary-active',
    LOW: 'border-warning/40 bg-warning/10 text-warning',
    VERY_LOW: 'border-danger/40 bg-danger/10 text-danger',
    INSUFFICIENT: 'border-muted/40 bg-surface-alt text-muted',
}

const TYPE_ICON = {
    SHIFT_TO_SOLAR_WINDOW: Sun,
    USE_AVAILABLE_SOLAR: Sun,
    AVOID_DEFICIT_WINDOW: Zap,
    EVENING_DEFICIT_PREPARATION: Clock,
    HIGH_DEMAND_REDUCTION: TrendingDown,
    COST_AWARE_SHIFT: IndianRupee,
    SOLAR_DECLINE_RESPONSE: TrendingDown,
    DATA_LIMITATION: AlertCircle,
}

export function RecommendationCard({ recommendation, onDismiss, isDismissing = false }) {
    const [expanded, setExpanded] = useState(false)
    const detailsId = useId()

    if (!recommendation) {
        return null
    }

    const {
        id,
        type,
        priority = 'MEDIUM',
        title,
        summary,
        action,
        reason,
        timing_window,
        potential_impact,
        impact,
        evidence,
        confidence,
        quality,
        data_nature,
        provenance = [],
        limitations = [],
        is_actionable = true,
    } = recommendation

    const Icon = TYPE_ICON[type] ?? Compass
    const priorityClass = PRIORITY_BADGE[priority] ?? PRIORITY_BADGE.LOW
    const energyKwh = impact?.energy_kwh ?? impact?.kwh ?? null

    const isSecondary = type === 'EVENING_DEFICIT_PREPARATION' || type === 'AVOID_DEFICIT_WINDOW'

    return (
        <Card className={cn(
            'relative overflow-hidden transition-all duration-200',
            isSecondary
                ? 'border-border/70 bg-surface/60 hover:border-amber-500/30'
                : 'border-border hover:border-primary/40'
        )}>
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className={cn(
                            'mt-0.5 rounded-lg border p-2',
                            isSecondary
                                ? 'border-amber-500/20 bg-amber-500/10 text-amber-500'
                                : 'border-primary/20 bg-primary-soft text-primary'
                        )}>
                            <Icon className="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <CardTitle className="text-base font-semibold text-heading sm:text-lg">
                                    {title}
                                </CardTitle>
                                {isSecondary && (
                                    <span className="rounded bg-surface-alt px-1.5 py-0.5 text-[10px] font-medium text-muted uppercase">
                                        Secondary
                                    </span>
                                )}
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <span className={cn('rounded-full border px-2 py-0.5 font-medium uppercase', priorityClass)}>
                                    {priority} PRIORITY
                                </span>
                                {confidence && (
                                    <span className={cn('rounded-full border px-2 py-0.5 font-semibold uppercase', CONFIDENCE_BADGE[confidence] ?? CONFIDENCE_BADGE.LOW)}>
                                        {confidence} CONFIDENCE
                                    </span>
                                )}
                                {data_nature && <DataNatureBadge nature={data_nature} />}
                                {timing_window && (
                                    <span className="flex items-center gap-1 font-medium text-muted">
                                        <Clock className="size-3.5 text-primary" aria-hidden="true" />
                                        {timing_window}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {is_actionable && onDismiss && (
                            <Button
                                variant="tertiary"
                                size="sm"
                                onClick={() => onDismiss(id)}
                                disabled={isDismissing}
                                className="text-xs text-muted hover:text-danger"
                                aria-label={`Dismiss recommendation: ${title}`}
                            >
                                <X className="size-3.5" aria-hidden="true" />
                                {isDismissing ? 'Dismissing...' : 'Dismiss'}
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>

            <CardContent className="flex flex-col gap-3 text-sm">
                {/* 1. What to do (Action) — Immediate Visibility */}
                <div className={cn(
                    'rounded-lg border p-3.5',
                    isSecondary
                        ? 'border-amber-500/20 bg-amber-500/5 text-body'
                        : 'border-primary/20 bg-primary-soft/50 text-primary-active'
                )}>
                    <p className="flex items-start gap-2 font-medium">
                        <CheckCircle2 className={cn(
                            'mt-0.5 size-4 shrink-0',
                            isSecondary ? 'text-amber-500' : 'text-primary'
                        )} aria-hidden="true" />
                        <span>{action}</span>
                    </p>
                </div>

                {/* 2. Potential Impact / Opportunity (Immediate Subtitle) */}
                {energyKwh != null ? (
                    <div className="flex flex-col gap-1 rounded-md border border-border bg-surface-alt px-3 py-2 text-xs">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-semibold text-heading">
                                {type === 'USE_AVAILABLE_SOLAR'
                                    ? `Potential solar-window opportunity · ≈${Number(energyKwh).toFixed(1)} kWh`
                                    : (potential_impact || `Potential opportunity · ≈${Number(energyKwh).toFixed(1)} kWh`)}
                            </span>
                            <span className="rounded bg-surface px-1.5 py-0.5 text-[11px] font-medium text-muted">
                                ≈{Number(energyKwh).toFixed(1)} kWh
                            </span>
                        </div>
                        <p className="text-[11px] text-muted">
                            Forecast-derived potential; not guaranteed savings.
                        </p>
                    </div>
                ) : (
                    potential_impact && !potential_impact.toLowerCase().includes('kwh') && (
                        <div className="flex items-center justify-between rounded-md border border-border bg-surface-alt px-3 py-2 text-xs">
                            <span className="font-semibold text-heading">Potential Impact:</span>
                            <span className="font-medium text-muted">{potential_impact}</span>
                        </div>
                    )
                )}

                {/* 3. Limitation: Only most relevant limitation in primary card */}
                {limitations && limitations.length > 0 && (
                    <div className="flex items-start gap-2 rounded-md border border-warning/30 bg-warning/10 p-2 text-xs text-warning">
                        <AlertCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <div className="flex-1">
                            <span className="font-semibold">Contextual Limitation: </span>
                            <span>{limitations[0]}</span>
                            {limitations.length > 1 && (
                                <span className="ml-1 text-[11px] opacity-80">
                                    (+{limitations.length - 1} more in details)
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {/* 4. Structured Rationale & Details Toggle */}
                <div className="border-t border-border pt-2.5">
                    <button
                        type="button"
                        onClick={() => setExpanded((prev) => !prev)}
                        aria-expanded={expanded}
                        aria-controls={detailsId}
                        className="flex w-full cursor-pointer items-center justify-between text-xs font-medium text-muted hover:text-heading focus:outline-none"
                    >
                        <span className="flex items-center gap-1.5">
                            <Database className="size-3.5 text-primary" aria-hidden="true" />
                            {expanded ? 'Hide evidence & details' : 'Why this recommendation? · View evidence & details'}
                        </span>
                        <ChevronDown
                            className={cn('size-4 text-muted transition-transform duration-200', expanded && 'rotate-180')}
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        id={detailsId}
                        className={cn(
                            'mt-3 space-y-3 rounded-md border border-border/80 bg-surface-alt p-3.5 text-xs',
                            !expanded && 'hidden'
                        )}
                    >
                        {/* Plain-English Explanation / Rationale */}
                        {reason && (
                            <div className="space-y-1">
                                <p className="font-semibold text-heading">Why this recommendation?</p>
                                <p className="text-body leading-relaxed">{reason}</p>
                            </div>
                        )}
                            {/* Rule ID & Timestamps */}
                            <div className="grid gap-2 sm:grid-cols-2 text-muted">
                                <div>
                                    <span className="font-semibold text-heading">Rule ID: </span>
                                    <span className="font-mono">{type}</span>
                                </div>
                                {recommendation.valid_until && (
                                    <div>
                                        <span className="font-semibold text-heading">Valid Until: </span>
                                        <span>{recommendation.valid_until}</span>
                                    </div>
                                )}
                            </div>

                            {/* Summary if distinct from reason */}
                            {summary && summary !== reason && (
                                <div className="rounded border border-border bg-surface p-2 text-muted">
                                    <span className="font-semibold text-heading">Context Summary: </span>
                                    <span>{summary}</span>
                                </div>
                            )}

                            {/* Underlying Insight Evidence */}
                            {evidence?.insights && evidence.insights.length > 0 && (
                                <div className="space-y-1.5 rounded border border-border bg-surface p-2">
                                    <p className="font-semibold text-heading">Source Insight Evidence</p>
                                    {evidence.insights.map((ins, idx) => (
                                        <div key={ins.id || idx} className="text-muted">
                                            <span className="font-medium text-heading">{ins.type || 'INSIGHT'}: </span>
                                            <span>{ins.summary || ins.title}</span>
                                            {ins.confidence && (
                                                <span className="ml-1 text-[11px] text-muted">
                                                    (Confidence: {ins.confidence})
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Forecast & Grid Values */}
                            <div className="grid gap-2 sm:grid-cols-2">
                                {evidence?.solar_forecast && (
                                    <div className="rounded border border-border bg-surface p-2">
                                        <p className="font-semibold text-heading">Solar Availability</p>
                                        <p className="text-muted">
                                            {evidence.solar_forecast.value_kwh !== undefined ? `${evidence.solar_forecast.value_kwh} kWh` : 'Active pattern'}
                                            {evidence.solar_forecast.confidence && ` (Confidence: ${evidence.solar_forecast.confidence})`}
                                        </p>
                                    </div>
                                )}
                                {evidence?.consumption_forecast && (
                                    <div className="rounded border border-border bg-surface p-2">
                                        <p className="font-semibold text-heading">Household Demand</p>
                                        <p className="text-muted">
                                            {evidence.consumption_forecast.value_kwh !== undefined ? `${evidence.consumption_forecast.value_kwh} kWh` : 'Active baseline'}
                                            {evidence.consumption_forecast.confidence && ` (Confidence: ${evidence.consumption_forecast.confidence})`}
                                        </p>
                                    </div>
                                )}
                                {evidence?.energy_balance && (
                                    <div className="rounded border border-border bg-surface p-2">
                                        <p className="font-semibold text-heading">Energy Situation</p>
                                        <p className="text-muted">
                                            State: {evidence.energy_balance.state || evidence.energy_balance.classification || 'Estimated'}
                                            {evidence.energy_balance.balance_kwh !== undefined && ` (${evidence.energy_balance.balance_kwh > 0 ? '+' : ''}${evidence.energy_balance.balance_kwh} kWh)`}
                                        </p>
                                    </div>
                                )}
                                {evidence?.cost?.estimated_cost_inr !== undefined && (
                                    <div className="rounded border border-border bg-surface p-2">
                                        <p className="font-semibold text-heading">Cost Impact</p>
                                        <p className="text-muted">
                                            Estimated variable cost: ₹{evidence.cost.estimated_cost_inr}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* All limitations if multiple */}
                            {limitations && limitations.length > 1 && (
                                <div className="rounded border border-border bg-surface p-2">
                                    <p className="font-semibold text-heading">Detailed Contextual Limitations</p>
                                    <ul className="mt-1 list-disc pl-4 space-y-0.5 text-muted">
                                        {limitations.map((lim, idx) => (
                                            <li key={idx}>{lim}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {/* Provenance Lineage */}
                            {provenance.length > 0 && (
                                <div className="flex flex-wrap items-center gap-1.5 pt-1 text-[11px] text-muted">
                                    <span className="font-semibold text-heading">Sources:</span>
                                    {provenance.map((src) => (
                                        <span key={src} className="rounded border border-border bg-surface px-2 py-0.5">
                                            {src}
                                        </span>
                                    ))}
                                    {quality && (
                                        <span className="ml-auto">
                                            <QualityIndicator level={quality} />
                                        </span>
                                    )}
                                </div>
                            )}

                            {/* Contextual Link */}
                            <div className="pt-1">
                                <Link
                                    to="/forecasts"
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    <span>View solar vs consumption forecast outlook</span>
                                    <ExternalLink className="size-3" aria-hidden="true" />
                                </Link>
                            </div>
                        </div>
                    </div>
            </CardContent>
        </Card>
    )
}
