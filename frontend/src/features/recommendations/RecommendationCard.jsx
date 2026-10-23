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
    Compass,
    TrendingDown,
    IndianRupee,
    ArrowRight,
} from 'lucide-react'
import { Card, CardContent } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { QualityIndicator } from '../../components/data-display/QualityIndicator'
import { RecommendationFeedbackControl } from './RecommendationFeedbackControl'
import { formatConsumerCopy } from './recommendationFormatting'
import { getRecommendationCategory } from './recommendationCategories'
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

function getBasedOnItems(evidence, provenance = []) {
    const items = []
    const provStr = provenance.join(' ').toLowerCase()

    if (evidence?.solar_forecast || provStr.includes('forecast') || provStr.includes('f3') || provStr.includes('solar')) {
        items.push('Solar forecast')
    }
    if (evidence?.consumption_forecast || provStr.includes('bill') || provStr.includes('demand') || provStr.includes('consumption')) {
        items.push('Household demand estimate')
    }
    if (provStr.includes('goodwe') || provStr.includes('sems') || provStr.includes('insights') || evidence?.insights) {
        items.push('Recent solar generation')
    }
    if (evidence?.cost || provStr.includes('cost')) {
        items.push('Electricity billing structure')
    }
    if (evidence?.weather || provStr.includes('open_meteo') || provStr.includes('open-meteo') || provStr.includes('weather')) {
        items.push('Atmospheric weather evidence (Open-Meteo)')
    }

    if (items.length === 0) {
        return ['Solar forecast', 'Recent solar generation', 'Household demand estimate']
    }
    return items
}

function checkIsStale(quality, limitations = [], evidence = {}) {
    if (quality === 'STALE') return true
    if (evidence?.solar_forecast?.status === 'STALE') return true
    const limStr = limitations.join(' ').toUpperCase()
    return limStr.includes('STALE_SOLAR') || limStr.includes('STALE TELEMETRY') || limStr.includes('IS STALE')
}

function checkIsWeatherUnavailable(limitations = []) {
    const limStr = limitations.join(' ').toLowerCase()
    return limStr.includes('weather') || limStr.includes('geocoding') || limStr.includes('open-meteo') || limStr.includes('network error')
}

export function RecommendationCard({
    recommendation,
    onDismiss,
    isDismissing = false,
    compact = false,
    isHistorical = false,
}) {
    const [expanded, setExpanded] = useState(false)
    const detailsId = useId()

    if (!recommendation) {
        return null
    }

    const {
        id,
        type,
        status = 'ACTIVE',
        priority = 'MEDIUM',
        title,
        summary,
        reason,
        timing_window,
        potential_impact,
        impact,
        evidence,
        confidence,
        quality,
        provenance = [],
        limitations = [],
        is_actionable = true,
        user_feedback = null,
    } = recommendation

    const Icon = TYPE_ICON[type] ?? Compass
    const priorityClass = PRIORITY_BADGE[priority] ?? PRIORITY_BADGE.LOW
    const energyKwh = impact?.energy_kwh ?? impact?.kwh ?? null
    const costInr = impact?.cost_inr ?? impact?.inr ?? null
    const isSecondary = type === 'EVENING_DEFICIT_PREPARATION' || type === 'AVOID_DEFICIT_WINDOW'
    const isStaleTelemetry = checkIsStale(quality, limitations, evidence)
    const isWeatherUnavailable = checkIsWeatherUnavailable(limitations)
    const basedOnItems = getBasedOnItems(evidence, provenance)

    const { displayTitle, displayAction } = formatConsumerCopy(recommendation)
    const category = getRecommendationCategory(recommendation)
    const CategoryIcon = category.icon

    if (compact) {
        return (
            <Card
                data-testid="recommendation-card"
                className={cn(
                    'relative overflow-hidden transition-all duration-200 border-border bg-surface hover:border-primary/40',
                    isSecondary && 'bg-surface/80 hover:border-amber-500/30'
                )}
            >
                <CardContent className="p-4 sm:p-5 flex flex-col gap-3">
                    {/* Header: Icon + Title + Priority Badge */}
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-start gap-2.5 min-w-0">
                            <div className={cn(
                                'mt-0.5 rounded-lg border p-1.5 shrink-0',
                                isSecondary
                                    ? 'border-amber-500/20 bg-amber-500/10 text-amber-500'
                                    : 'border-primary/20 bg-primary-soft text-primary'
                            )}>
                                <Icon className="size-4" aria-hidden="true" />
                            </div>
                            <div className="min-w-0">
                                <h3 className="text-sm font-semibold text-heading truncate sm:text-base">
                                    {displayTitle}
                                </h3>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[10px]">
                                    <span
                                        data-testid="recommendation-category-badge"
                                        className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-semibold uppercase tracking-wider', category.badgeClass)}
                                    >
                                        <CategoryIcon className="size-2.5" aria-hidden="true" />
                                        <span>{category.label}</span>
                                    </span>
                                    <span className={cn('rounded-full border px-2 py-0.5 font-semibold uppercase tracking-wider', priorityClass)}>
                                        {priority} PRIORITY
                                    </span>
                                    {confidence && (
                                        <span className={cn('rounded-full border px-2 py-0.5 font-semibold uppercase tracking-wider', CONFIDENCE_BADGE[confidence] ?? CONFIDENCE_BADGE.LOW)}>
                                            {confidence} CONFIDENCE
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {is_actionable && onDismiss && status === 'ACTIVE' && !isHistorical && (
                            <Button
                                variant="tertiary"
                                size="sm"
                                onClick={() => onDismiss(id)}
                                disabled={isDismissing}
                                className="text-xs text-muted hover:text-danger shrink-0"
                                aria-label={`Dismiss recommendation: ${displayTitle}`}
                            >
                                <X className="size-3.5 mr-1" aria-hidden="true" />
                                <span>{isDismissing ? 'Dismissing...' : 'Dismiss'}</span>
                            </Button>
                        )}
                    </div>

                    {/* Action */}
                    <p className="text-xs sm:text-sm text-body leading-relaxed font-medium">
                        {displayAction}
                    </p>

                    {/* Best Time */}
                    {timing_window && (
                        <div className="flex items-center justify-between rounded-md border border-border/60 bg-surface-alt/40 px-3 py-2 text-xs">
                            <span className="font-semibold text-muted text-[10px] uppercase tracking-wider">
                                Best Time:
                            </span>
                            <span className="font-semibold text-heading flex items-center gap-1.5">
                                <Clock className="size-3 text-primary shrink-0" aria-hidden="true" />
                                <span>{timing_window}</span>
                            </span>
                        </div>
                    )}

                    {/* Link to full recommendations */}
                    <div className="flex justify-end pt-1">
                        <Link
                            to="/recommendations"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                        >
                            <span>View full recommendations &amp; explanation</span>
                            <ArrowRight className="size-3" aria-hidden="true" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        )
    }

    return (
        <Card
            data-testid="recommendation-card"
            className={cn(
                'relative overflow-hidden transition-all duration-200 border-border bg-surface hover:border-primary/40',
                isSecondary && 'bg-surface/80 hover:border-amber-500/30'
            )}
        >
            <CardContent className="p-5 sm:p-6 flex flex-col gap-4">
                {/* 1. Header: Icon + Title + Badges + Dismiss button */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3 min-w-0">
                        <div className={cn(
                            'mt-0.5 rounded-lg border p-2 shrink-0',
                            isSecondary
                                ? 'border-amber-500/20 bg-amber-500/10 text-amber-500'
                                : 'border-primary/20 bg-primary-soft text-primary'
                        )}>
                            <Icon className="size-5" aria-hidden="true" />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-base font-semibold text-heading sm:text-lg leading-snug">
                                {displayTitle}
                            </h3>
                            {/* Restrained badges: Category + Priority + Confidence */}
                            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                                <span
                                    data-testid="recommendation-category-badge"
                                    className={cn('inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider', category.badgeClass)}
                                >
                                    <CategoryIcon className="size-3" aria-hidden="true" />
                                    <span>{category.label}</span>
                                </span>
                                <span className={cn('rounded-full border px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider', priorityClass)}>
                                    {priority} PRIORITY
                                </span>
                                {confidence && (
                                    <span className={cn('rounded-full border px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider', CONFIDENCE_BADGE[confidence] ?? CONFIDENCE_BADGE.LOW)}>
                                        {confidence} CONFIDENCE
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    {is_actionable && onDismiss && status === 'ACTIVE' && !isHistorical && (
                        <Button
                            variant="tertiary"
                            size="sm"
                            onClick={() => onDismiss(id)}
                            disabled={isDismissing}
                            className="text-xs text-muted hover:text-danger shrink-0"
                            aria-label={`Dismiss recommendation: ${displayTitle}`}
                        >
                            <X className="size-3.5 mr-1" aria-hidden="true" />
                            <span>{isDismissing ? 'Dismissing...' : 'Dismiss'}</span>
                        </Button>
                    )}
                </div>

                {/* 2. Action: One short explanation describing what the user should do */}
                <p className="text-sm font-medium text-body leading-relaxed">
                    {displayAction}
                </p>

                {/* 3. Best Time: Dedicated prominent clean window */}
                {timing_window && (
                    <div className="flex flex-col gap-0.5 rounded-lg border border-border/70 bg-surface-alt/40 px-3.5 py-2.5">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-muted">
                            Best Time
                        </span>
                        <span className="text-sm font-semibold text-heading flex items-center gap-1.5 mt-0.5">
                            <Clock className="size-3.5 text-primary shrink-0" aria-hidden="true" />
                            <span>{timing_window}</span>
                        </span>
                    </div>
                )}

                {/* 4. Estimated Opportunity (Quiet, conservative subtext if kWh/₹ exists) */}
                {energyKwh != null ? (
                    <div className="flex flex-wrap items-center justify-between gap-1 text-xs text-muted pt-1">
                        <span>
                            {type === 'USE_AVAILABLE_SOLAR'
                                ? `Potential solar-window opportunity · ≈${Number(energyKwh).toFixed(1)} kWh`
                                : (potential_impact || (costInr != null
                                    ? `Estimated variable-cost exposure reduction · up to ₹${Number(costInr).toFixed(2)}`
                                    : `Potential opportunity · ≈${Number(energyKwh).toFixed(1)} kWh`))}
                        </span>
                        <div className="flex items-center gap-1.5">
                            {costInr != null && (
                                <span className="rounded bg-surface-alt px-1.5 py-0.5 text-[11px] font-medium text-heading">
                                    ₹{Number(costInr).toFixed(2)}
                                </span>
                            )}
                            <span className="rounded bg-surface-alt px-1.5 py-0.5 text-[11px] font-medium text-muted">
                                ≈{Number(energyKwh).toFixed(1)} kWh
                            </span>
                        </div>
                        <p className="w-full text-[11px] text-muted">
                            {costInr != null
                                ? 'Estimated from canonical tariff; not guaranteed savings.'
                                : 'Forecast-derived potential; not guaranteed savings.'}
                        </p>
                    </div>
                ) : (
                    potential_impact && !potential_impact.toLowerCase().includes('kwh') && (
                        <div className="flex items-center justify-between text-xs text-muted pt-1">
                            <span className="font-semibold text-heading">Potential Impact:</span>
                            <span className="font-medium text-muted">{potential_impact}</span>
                        </div>
                    )
                )}

                {/* Supporting Insight Relationship Link */}
                {evidence?.insights && evidence.insights.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-1.5 rounded-lg border border-border/60 bg-surface-alt/40 px-3 py-2 text-xs">
                        <div className="flex items-center gap-1.5 min-w-0">
                            <span className="font-semibold text-text-primary text-[11px] shrink-0">Supporting Insight:</span>
                            <span className="text-text-secondary truncate text-[11px]">
                                {evidence.insights[0].type ? `${evidence.insights[0].type.replace(/_/g, ' ')} Evidence` : 'Household Analysis'}
                            </span>
                        </div>
                        <Link
                            to="/insights"
                            className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline shrink-0 ml-auto"
                        >
                            <span>View in Insights →</span>
                        </Link>
                    </div>
                )}

                {/* 5. Stale Telemetry Notice in Primary Card if Stale */}
                {isStaleTelemetry && (
                    <div className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-warning space-y-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-semibold flex items-center gap-1.5">
                                <AlertCircle className="size-4 shrink-0" aria-hidden="true" />
                                <span>Solar data is out of date</span>
                            </span>
                            <Link
                                to="/solar"
                                className="text-[11px] font-semibold underline hover:text-warning-active text-warning shrink-0"
                            >
                                Check connection →
                            </Link>
                        </div>
                        <p className="leading-relaxed text-warning/90">
                            Your inverter has not sent a fresh reading recently. Recommendations that depend on live solar data may be less reliable.
                        </p>
                    </div>
                )}

                {/* 6. Weather Limitation Notice in Primary Card if Weather Unavailable */}
                {!isStaleTelemetry && isWeatherUnavailable && (
                    <div className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-warning space-y-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-semibold flex items-center gap-1.5">
                                <AlertCircle className="size-4 shrink-0" aria-hidden="true" />
                                <span>Weather data unavailable</span>
                            </span>
                        </div>
                        <p className="leading-relaxed text-warning/90">
                            This recommendation is currently based on the expected solar pattern rather than live weather conditions.
                        </p>
                    </div>
                )}

                {/* Contextual Limitation if provided */}
                {limitations.length > 0 && !isStaleTelemetry && !isWeatherUnavailable && (
                    <div className="rounded border border-border/80 bg-surface-alt/40 p-2.5 text-xs text-muted">
                        <span className="font-semibold text-heading">Contextual Limitation: </span>
                        <span>{limitations.join('; ')}</span>
                    </div>
                )}

                {/* 7. Expandable "Why this recommendation?" Section */}
                <div className="border-t border-border/70 pt-3">
                    <button
                        type="button"
                        onClick={() => setExpanded((prev) => !prev)}
                        aria-expanded={expanded}
                        aria-controls={detailsId}
                        className="flex w-full cursor-pointer items-center justify-between text-xs font-semibold text-slate-800 dark:text-slate-200 hover:text-primary focus:outline-none transition-colors"
                    >
                        <span className="flex items-center gap-1.5">
                            <span>Why this recommendation?</span>
                        </span>
                        <span className="flex items-center gap-1 font-normal text-muted">
                            <span className="text-[11px]">{expanded ? 'Hide evidence & details' : 'View evidence & details'}</span>
                            <ChevronDown
                                className={cn('size-3.5 transition-transform duration-200', expanded && 'rotate-180')}
                                aria-hidden="true"
                            />
                        </span>
                    </button>

                    {/* Collapsible Content with Smooth Slide-down Transition */}
                    <div
                        id={detailsId}
                        className={cn(
                            'overflow-hidden transition-all duration-300 ease-in-out',
                            expanded ? 'max-h-[1400px] opacity-100 mt-3 pt-2' : 'max-h-0 opacity-0'
                        )}
                    >
                        <div className="space-y-3 rounded-lg border border-border/80 bg-surface-alt/60 p-4 text-xs">
                            {/* Plain-English Household Explanation */}
                            <div className="space-y-1">
                                <p className="font-bold text-[11px] uppercase tracking-wider text-muted">
                                    Why This Recommendation?
                                </p>
                                <p className="text-body text-xs sm:text-sm leading-relaxed">
                                    {reason || 'Solar generation is expected to be strongest between 9:00 AM and 1:00 PM. Your estimated household demand is lower than expected solar generation during this period.'}
                                </p>
                            </div>

                            {/* Summary context if distinct from reason */}
                            {summary && summary !== reason && (
                                <div className="rounded border border-border bg-surface p-2 text-muted">
                                    <span className="font-semibold text-heading">Context: </span>
                                    <span>{summary}</span>
                                </div>
                            )}

                            {/* Based on Bullets */}
                            <div className="space-y-1 border-t border-border/50 pt-2.5">
                                <p className="font-semibold text-heading">Based on:</p>
                                <ul className="list-disc list-inside space-y-0.5 text-muted pl-1">
                                    {basedOnItems.map((item, idx) => (
                                        <li key={idx}>{item}</li>
                                    ))}
                                </ul>
                            </div>

                            {/* Weather Supporting Evidence Box */}
                            {evidence?.weather && (
                                <div className="rounded-lg border border-sky-500/20 bg-sky-500/5 p-3 space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-1.5 font-semibold text-heading text-xs">
                                            <span>Weather Evidence ({evidence.weather.source || 'Open-Meteo'})</span>
                                        </div>
                                        {evidence.weather.solar_impact && (
                                            <span className={cn(
                                                'text-[10px] font-semibold px-2 py-0.5 rounded-full border uppercase',
                                                evidence.weather.solar_impact === 'STRONG'
                                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                    : evidence.weather.solar_impact === 'LOW'
                                                        ? 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                                        : 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400'
                                            )}>
                                                {evidence.weather.solar_impact} SOLAR IMPACT
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted leading-relaxed">
                                        {evidence.weather.explanation || 'Weather conditions support the predicted solar window.'}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-3 text-[11px] font-mono text-muted pt-0.5">
                                        {evidence.weather.cloud_cover_pct !== undefined && evidence.weather.cloud_cover_pct !== null && (
                                            <span>Cloud: {Math.round(evidence.weather.cloud_cover_pct)}%</span>
                                        )}
                                        {evidence.weather.irradiance_wm2 !== undefined && evidence.weather.irradiance_wm2 !== null && (
                                            <span>Irradiance: {Math.round(evidence.weather.irradiance_wm2)} W/m²</span>
                                        )}
                                        {evidence.weather.temperature_c !== undefined && evidence.weather.temperature_c !== null && (
                                            <span>Temp: {Math.round(evidence.weather.temperature_c)}°C</span>
                                        )}
                                    </div>
                                    <p className="text-[10px] text-muted/80 italic pt-0.5">
                                        Environmental evidence informs predicted solar availability; {recommendation?.data_nature === 'SIMULATED' ? 'inverter telemetry simulates demo solar generation.' : 'GoodWe measures actual solar generation.'}
                                    </p>
                                </div>
                            )}

                            {/* Stale Telemetry Notice if Stale */}
                            {isStaleTelemetry && (
                                <div className="rounded border border-warning/40 bg-warning/10 p-2.5 text-warning space-y-0.5">
                                    <p className="font-semibold">Notice on Solar Connection:</p>
                                    <p className="leading-relaxed">
                                        Your solar connection has not sent a fresh reading recently. This recommendation is based on the forecast and recent data.
                                    </p>
                                </div>
                            )}

                            {/* Honest Consumer Limitation Note */}
                            <div className="rounded border border-border bg-surface p-2.5 text-muted space-y-0.5">
                                <p className="font-semibold text-heading">Important:</p>
                                <p className="leading-relaxed">
                                    Household electricity use is estimated from verified bills and is not measured hour by hour. Savings are not guaranteed.
                                </p>
                            </div>

                            {/* Secondary Disclosure: Technical details for audit/debugging */}
                            <details className="mt-2 pt-2 border-t border-border/50 text-[11px] text-muted">
                                <summary className="cursor-pointer text-muted hover:text-heading font-medium">
                                    Technical details &amp; audit evidence
                                </summary>
                                <div className="mt-2 space-y-2 pl-1 font-mono text-[10px]">
                                    <div>
                                        <span className="font-sans font-semibold text-heading">Rule ID: </span>
                                        <span>{type}</span>
                                    </div>
                                    {displayTitle !== title && (
                                        <div>
                                            <span className="font-sans font-semibold text-heading">Backend Title: </span>
                                            <span>{title}</span>
                                        </div>
                                    )}
                                    {recommendation.valid_until && (
                                        <div>
                                            <span className="font-sans font-semibold text-heading">Valid Until: </span>
                                            <span>{recommendation.valid_until}</span>
                                        </div>
                                    )}

                                    {/* Source Insights */}
                                    {evidence?.insights && evidence.insights.length > 0 && (
                                        <div className="rounded border border-border bg-surface p-2 space-y-1 font-sans">
                                            <div className="flex items-center justify-between">
                                                <p className="font-semibold text-heading">Source Insight Evidence</p>
                                                <Link
                                                    to="/insights"
                                                    className="inline-flex items-center gap-1 text-[10px] font-semibold text-primary hover:underline"
                                                >
                                                    <span>View in Insights</span>
                                                    <ExternalLink className="size-2.5" aria-hidden="true" />
                                                </Link>
                                            </div>
                                            {evidence.insights.map((ins, idx) => (
                                                <div key={ins.id || idx} className="text-muted text-[11px]">
                                                    <span className="font-medium text-heading">{ins.type || 'INSIGHT'}: </span>
                                                    <span>{ins.summary || ins.title}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Provenance */}
                                    {provenance.length > 0 && (
                                        <div className="flex flex-wrap items-center gap-1.5 pt-1 font-sans">
                                            <span className="font-semibold text-heading">Sources:</span>
                                            {provenance.map((src) => (
                                                <span key={src} className="rounded border border-border bg-surface px-1.5 py-0.5 text-[10px]">
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

                                    {/* Raw limitations / diagnostics if technical or fallback */}
                                    {limitations.length > 0 && (isWeatherUnavailable || isStaleTelemetry || limitations.some((l) => String(l).toLowerCase().includes('error') || String(l).toLowerCase().includes('failed'))) && (
                                        <div className="pt-1 font-sans text-[11px] text-warning/90">
                                            <span className="font-semibold text-heading">Raw Diagnostics: </span>
                                            <span>{limitations.join('; ')}</span>
                                        </div>
                                    )}

                                    {/* Contextual Link to Forecasts */}
                                    <div className="pt-1 font-sans">
                                        <Link
                                            to="/forecasts"
                                            className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                        >
                                            <span>View solar vs consumption forecast outlook</span>
                                            <ExternalLink className="size-3" aria-hidden="true" />
                                        </Link>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>

                {/* 8. Recommendation Feedback Control (Requirement 6, 10, 11) */}
                <RecommendationFeedbackControl
                    recommendationId={id}
                    initialFeedback={user_feedback}
                    isHistorical={isHistorical || status !== 'ACTIVE'}
                />
            </CardContent>
        </Card>
    )
}
