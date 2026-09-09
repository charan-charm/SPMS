import { LoaderCircle } from 'lucide-react'
import { Skeleton } from './Skeleton'
import { cn } from '../../lib/utils'

/**
 * LoadingState (docs/12 §15) — spinner variant for unknown-duration
 * waits, and skeleton variant for known structure. No fake progress.
 */

export function LoadingState({ label = 'Loading', className }) {
    return (
        <div
            role="status"
            className={cn('flex flex-col items-center justify-center gap-3 py-10', className)}
        >
            <LoaderCircle className="size-6 animate-spin text-primary" aria-hidden="true" />
            <p className="text-sm text-muted">{label}…</p>
        </div>
    )
}

export function LoadingSkeleton({ rows = 3, className }) {
    return (
        <div role="status" aria-label="Loading" className={cn('space-y-3', className)}>
            {Array.from({ length: rows }, (_, index) => (
                <Skeleton key={index} className="h-12 w-full" />
            ))}
        </div>
    )
}
