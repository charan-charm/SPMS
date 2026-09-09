import { OPERATION_STATUS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * OperationStatus (docs/12 §9, docs/06 §19) — presents a backend-
 * authoritative operation lifecycle state (QUEUED, PROCESSING,
 * COMPLETED, FAILED, CANCELLED, RECOVERING, RECOVERED). The frontend
 * never converts queued/accepted into completed.
 *
 * Props:
 * - status: one of OPERATION_STATUS keys
 * - detail: optional short backend-provided context line
 */
export function OperationStatus({ status, detail = null, className }) {
    const semantic = OPERATION_STATUS[status]

    if (!semantic) {
        return null
    }

    return (
        <div className={cn('flex flex-col items-start gap-1', className)}>
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                    semantic.tone === 'success' && 'border-success/40 bg-success/10 text-success',
                    semantic.tone === 'warning' && 'border-warning/40 bg-warning/10 text-warning',
                    semantic.tone === 'danger' && 'border-danger/40 bg-danger/10 text-danger',
                    semantic.tone === 'info' && 'border-info/40 bg-info/10 text-info',
                    semantic.tone === 'muted' && 'border-muted/40 bg-surface-alt text-muted',
                )}
            >
                {semantic.label}
            </span>
            {detail && <span className="text-xs text-muted">{detail}</span>}
        </div>
    )
}
