import { Card } from '../ui/card'
import { Skeleton } from '../feedback/Skeleton'
import { StatusBadge } from './StatusBadge'
import { DataNatureBadge } from './DataNatureBadge'
import { cn } from '../../lib/utils'

/**
 * MetricCard (docs/12 §7) — presents one backend-authoritative metric.
 * Loading preserves structure (skeleton, no fake numbers — docs/12 §15).
 *
 * Props:
 * - label: metric name
 * - value: pre-formatted value string from the backend/presentation layer
 * - unit: optional unit string (presented after value)
 * - nature/status: optional semantic identifiers rendered as badges
 * - comparison: optional short backend-provided comparison line
 * - loading: renders skeleton while preserving layout
 */
export function MetricCard({
    label,
    value = null,
    unit = null,
    nature = null,
    status = null,
    comparison = null,
    loading = false,
    className,
}) {
    return (
        <Card className={cn('flex flex-col gap-2', className)} aria-busy={loading || undefined}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-muted">{label}</p>
                <div className="flex flex-wrap justify-end gap-1.5">
                    {nature && <DataNatureBadge nature={nature} />}
                    {status && <StatusBadge status={status} />}
                </div>
            </div>

            {loading ? (
                <>
                    <Skeleton className="h-8 w-24" />
                    <Skeleton className="h-3 w-32" />
                </>
            ) : (
                <>
                    <p className="font-display text-2xl font-semibold leading-none text-heading">
                        {value}
                        {unit && <span className="ml-1 text-sm font-medium text-muted">{unit}</span>}
                    </p>
                    {comparison && <p className="text-xs text-muted">{comparison}</p>}
                </>
            )}
        </Card>
    )
}
