import { useId, useState } from 'react'
import { ChevronDown, Lightbulb } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card'
import { DataNatureBadge } from './DataNatureBadge'
import { StatusBadge } from './StatusBadge'
import { ConfidenceIndicator } from './ConfidenceIndicator'
import { QualityIndicator } from './QualityIndicator'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * InsightCard (F6 — docs/10 D-043..D-048) — presents one backend
 * insight. Every value arrives from the API; nothing is derived,
 * upgraded, or decorated here.
 *
 * Honesty rules mirrored from the backend contract:
 * - Significance and confidence are always rendered as text, never
 *   color alone.
 * - The SIMULATED data nature is shown whenever the backend marks the
 *   insight's evidence as simulated.
 * - "Why this insight?" expands the deterministic explanation, the
 *   supporting facts, and the stated limitations — no recommendations
 *   exist at this stage (F7 is a later milestone).
 */

const SIGNIFICANCE_TONE = {
    HIGH: TONE_CLASS.warning,
    MODERATE: TONE_CLASS.info,
    LOW: TONE_CLASS.muted,
}

const SIGNIFICANCE_LABEL = {
    HIGH: 'High significance',
    MODERATE: 'Moderate significance',
    LOW: 'Low significance',
}

function humanizeToken(value) {
    const words = value.toLowerCase().split('_')
    return words.map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
}

function periodLabel(period) {
    if (!period) {
        return 'Not period-scoped'
    }
    const start = period.start ?? null
    const end = period.end ?? null
    if (start && end) {
        return `${start} → ${end}`
    }
    return start ?? end ?? 'Not period-scoped'
}

export function InsightCard({ insight }) {
    const [expanded, setExpanded] = useState(false)
    const detailsId = useId()

    if (!insight) {
        return null
    }

    const significanceTone = SIGNIFICANCE_TONE[insight.significance] ?? TONE_CLASS.muted
    const significanceLabel = SIGNIFICANCE_LABEL[insight.significance] ?? insight.significance

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <CardTitle className="flex items-start gap-2 text-base">
                        <Lightbulb className="mt-0.5 size-4 shrink-0 text-secondary" aria-hidden="true" />
                        {insight.title}
                    </CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
                        {insight.data_nature && <DataNatureBadge nature={insight.data_nature} />}
                        {insight.data_status && <StatusBadge status={insight.data_status} />}
                        <span
                            className={cn(
                                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                significanceTone,
                            )}
                        >
                            {significanceLabel}
                        </span>
                    </div>
                </div>
                <p className="text-xs uppercase tracking-wide text-muted">
                    {humanizeToken(insight.category)} · {humanizeToken(insight.type)}
                    {insight.observation_count > 1 ? ` · observed ${insight.observation_count} times` : ''}
                </p>
                <p className="text-sm text-body">{insight.summary}</p>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                    {insight.confidence && <ConfidenceIndicator level={insight.confidence} />}
                    {insight.quality && <QualityIndicator level={insight.quality} />}
                </div>

                <dl className="grid gap-x-6 gap-y-1 text-xs text-muted sm:grid-cols-2">
                    <div className="flex gap-1.5">
                        <dt className="font-medium">Affected period:</dt>
                        <dd>{periodLabel(insight.affected_period)}</dd>
                    </div>
                    <div className="flex gap-1.5">
                        <dt className="font-medium">Lifecycle:</dt>
                        <dd>{humanizeToken(insight.status)}</dd>
                    </div>
                    <div className="flex gap-1.5">
                        <dt className="font-medium">Last observed:</dt>
                        <dd>{insight.last_observed_at} UTC</dd>
                    </div>
                    <div className="flex gap-1.5">
                        <dt className="font-medium">First detected:</dt>
                        <dd>{insight.first_detected_at} UTC</dd>
                    </div>
                </dl>

                <div>
                    <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={detailsId}
                        onClick={() => setExpanded((current) => !current)}
                        className="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-primary-active hover:underline"
                    >
                        Why this insight?
                        <ChevronDown
                            className={cn('size-4 transition-transform', expanded && 'rotate-180')}
                            aria-hidden="true"
                        />
                    </button>

                    {expanded && (
                        <div id={detailsId} className="mt-3 flex flex-col gap-3 rounded-md border border-border bg-surface-alt px-4 py-3">
                            {insight.explanation && (
                                <p className="text-sm text-body">{insight.explanation}</p>
                            )}

                            {Array.isArray(insight.supporting_facts) && insight.supporting_facts.length > 0 && (
                                <div>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
                                        Supporting facts
                                    </p>
                                    <ul className="space-y-1">
                                        {insight.supporting_facts.map((fact) => (
                                            <li key={fact} className="flex items-start gap-2 text-sm text-body">
                                                <span aria-hidden="true" className="mt-2 size-1 shrink-0 rounded-full bg-muted" />
                                                {fact}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {Array.isArray(insight.limitations) && insight.limitations.length > 0 && (
                                <div>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
                                        Limitations
                                    </p>
                                    <ul className="space-y-1">
                                        {insight.limitations.map((limitation) => (
                                            <li key={limitation} className="flex items-start gap-2 text-sm text-body">
                                                <span aria-hidden="true" className="mt-2 size-1 shrink-0 rounded-full bg-muted" />
                                                {limitation}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <p className="text-xs text-muted">
                                Generated deterministically by the backend ({insight.explanation_source}) from the
                                evidence available at the time. No recommendations are attached to insights.
                            </p>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    )
}
