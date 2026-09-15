import { PROVENANCE_SOURCE } from './presentation'
import { cn } from '../../lib/utils'

/**
 * ProvenanceBadge — where a displayed value came from, as the backend
 * reports it (docs/10 D-067 §9). Renders nothing for sources the API
 * never sent: "User corrected" appears only when a USER_CORRECTION
 * provenance row exists, and verification is a lifecycle state shown
 * elsewhere (BILL_STATUS), never implied here.
 */
export function ProvenanceBadge({ source, className }) {
    const semantic = PROVENANCE_SOURCE[source]
    if (!semantic) {
        return null
    }
    const Icon = semantic.icon

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-alt px-2 py-0.5 text-xs font-medium text-body',
                className,
            )}
        >
            <Icon className="size-3" aria-hidden="true" />
            {semantic.label}
        </span>
    )
}
