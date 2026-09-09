import { DATA_NATURE } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * DataNatureBadge (docs/12 §9) — presents a backend-authoritative data
 * nature value (ACTUAL | PREDICTED | ESTIMATED | SIMULATED).
 * Icon + text + styling: color is never the only signal.
 */
export function DataNatureBadge({ nature, className }) {
    const semantic = DATA_NATURE[nature]

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
