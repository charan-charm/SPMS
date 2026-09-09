import { cn } from '../../lib/utils'

/**
 * Skeleton (docs/12 §15) — structural loading placeholder.
 * Preserves eventual layout without implying progress percentage.
 */
export function Skeleton({ className, ...props }) {
    return (
        <div
            aria-hidden="true"
            className={cn('animate-pulse rounded-md bg-surface-alt', className)}
            {...props}
        />
    )
}
