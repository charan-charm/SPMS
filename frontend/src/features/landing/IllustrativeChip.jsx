import { cn } from '../../lib/utils'

/**
 * IllustrativeChip (O1 landing) — the honest "this is presented as an
 * example" marker used wherever the landing shows conceptual values or
 * sample structures. Landing visuals are presentational by nature; the
 * chip keeps that clear without borrowing the product's SIMULATED
 * (demo-data) badge semantics, which belong to real demo-environment
 * data.
 */
export function IllustrativeChip({ className, label = 'Illustrative example' }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-secondary/40 bg-secondary-soft px-2.5 py-0.5 text-xs font-semibold text-secondary-active',
                className,
            )}
        >
            <span aria-hidden="true" className="size-1.5 rounded-full bg-secondary" />
            {label}
        </span>
    )
}
