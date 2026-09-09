import { Card } from '../ui/card'
import { EmptyState } from '../feedback/EmptyState'
import { LoadingState } from '../feedback/LoadingState'
import { ErrorState } from '../feedback/ErrorState'
import { cn } from '../../lib/utils'

/**
 * ChartContainer (docs/12 §11) — the shared wrapper every future SPMS
 * chart renders inside. Owns the card chrome, title, description,
 * action slot, semantic footer, responsive sizing, accessible summary,
 * and loading/error/empty presentation. The chart body is provided by
 * the caller as children (Recharts content arrives in later phases).
 *
 * Props:
 * - title, description: presented verbatim from backend semantic dataset
 * - actions: control slot (range pickers etc. — backend-validated later)
 * - footer: semantic note line (e.g. illustrative marking, limitations)
 * - accessibleSummary: text alternative for the visualization
 * - loading / error props: delegated state presentation
 */
export function ChartContainer({
    title,
    description = null,
    actions = null,
    footer = null,
    accessibleSummary = null,
    loading = false,
    error = null,
    empty = false,
    onRetry = null,
    className,
    children,
}) {
    return (
        <Card className={cn('flex flex-col gap-4', className)}>
            {(title || actions) && (
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        {title && <h3 className="font-display text-base font-semibold text-heading">{title}</h3>}
                        {description && <p className="mt-1 text-xs text-muted">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>
            )}

            {accessibleSummary && <p className="sr-only">{accessibleSummary}</p>}

            <div className="min-w-0">
                {loading ? (
                    <LoadingState label="Preparing visualization" />
                ) : error ? (
                    <ErrorState
                        title="Chart unavailable"
                        message={error.message ?? 'The chart data could not be loaded.'}
                        retryLabel={onRetry ? 'Retry' : null}
                        onRetry={onRetry}
                    />
                ) : empty ? (
                    <EmptyState
                        variant="noData"
                        explanation="There is no visualization data for this selection yet."
                        limitation="The chart will appear once the underlying information exists."
                    />
                ) : (
                    children
                )}
            </div>

            {footer && <p className="text-xs text-muted">{footer}</p>}
        </Card>
    )
}
