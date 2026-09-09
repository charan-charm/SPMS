import { cn } from '../../lib/utils'

/**
 * EmptyState (docs/12 §16): context → title → explanation → limitation
 * → action. States like no-data never invent placeholder values.
 */

const VARIANTS = {
    noData: { title: 'No data yet' },
    insufficient: { title: 'Not enough data yet' },
    unavailable: { title: 'Currently unavailable' },
    stale: { title: 'Showing older information' },
    degraded: { title: 'Reduced capability' },
    processing: { title: 'Preparing information' },
    error: { title: 'Could not load' },
    noResults: { title: 'No matching results' },
    noSelection: { title: 'Nothing selected' },
    noInsight: { title: 'No insight right now' },
}

export function EmptyState({
    variant = 'noData',
    title,
    explanation,
    limitation,
    action = null,
    icon: Icon = null,
    className,
}) {
    const variantSemantic = VARIANTS[variant] ?? VARIANTS.noData

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-surface px-6 py-10 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="mb-1 grid size-10 place-items-center rounded-md bg-surface-alt text-muted">
                    <Icon className="size-5" aria-hidden="true" />
                </span>
            )}
            <p className="font-medium text-heading">{title ?? variantSemantic.title}</p>
            {explanation && <p className="max-w-sm text-sm text-muted">{explanation}</p>}
            {limitation && <p className="max-w-sm text-xs text-muted">{limitation}</p>}
            {action && <div className="mt-3">{action}</div>}
        </div>
    )
}
