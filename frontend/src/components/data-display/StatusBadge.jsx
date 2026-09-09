import { DATA_STATUS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * StatusBadge (docs/12 §9) — presents a backend-authoritative data
 * status value (FRESH | STALE | DEGRADED | UNAVAILABLE | PROCESSING | ERROR).
 */
export function StatusBadge({ status, className }) {
    const semantic = DATA_STATUS[status]

    if (!semantic) {
        return null
    }

    const Icon = semantic.icon

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                semantic.className,
                className,
            )}
        >
            <Icon className="size-3" aria-hidden="true" />
            {semantic.label}
        </span>
    )
}
