import { QUALITY_LEVEL } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * QualityIndicator (docs/04 §15) — presents a backend-assessed data
 * quality level. Label is always rendered; color supports, never carries.
 *
 * Props:
 * - level: VERIFIED | GOOD | PARTIAL | UNVERIFIED | POOR
 */
export function QualityIndicator({ level, className }) {
    const semantic = QUALITY_LEVEL[level]

    if (!semantic) {
        return null
    }

    const Icon = semantic.icon

    return (
        <div className={cn('flex items-center gap-2 text-xs text-body', className)}>
            <Icon className="size-3.5 shrink-0 text-muted" aria-hidden="true" />
            <span className="font-medium">Data quality: {semantic.label}</span>
            <span aria-hidden="true" className="h-1.5 w-12 overflow-hidden rounded-full bg-surface-alt">
                <span className={cn('block h-full rounded-full', semantic.bar)} style={{ width: '100%' }} />
            </span>
        </div>
    )
}
