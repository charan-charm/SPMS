import { CONFIDENCE_LEVEL } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * ConfidenceIndicator (docs/12 §10) — presents a backend-provided
 * confidence level. The level text is always rendered (never color
 * alone); the bar is a supporting visual.
 *
 * Props:
 * - level: HIGH | MODERATE | LOW | VERY_LOW | INSUFFICIENT
 * - score: optional backend-provided numeric measure (0–1). Not fabricated
 *   by the frontend — only shown when the backend supplies it.
 */
export function ConfidenceIndicator({ level, score = null, className }) {
    const semantic = CONFIDENCE_LEVEL[level]

    if (!semantic) {
        return null
    }

    const Icon = semantic.icon
    const barWidth = score !== null ? Math.round(Math.min(Math.max(score, 0), 1) * 100) : null

    return (
        <div className={cn('flex items-center gap-2 text-xs text-body', className)}>
            <Icon className="size-3.5 shrink-0 text-muted" aria-hidden="true" />
            <span className="font-medium">{semantic.label}</span>
            {barWidth !== null && (
                <span
                    role="img"
                    aria-label={`Confidence ${Math.round(barWidth)} percent`}
                    className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-alt"
                >
                    <span className={cn('block h-full rounded-full', semantic.bar)} style={{ width: `${barWidth}%` }} />
                </span>
            )}
        </div>
    )
}
