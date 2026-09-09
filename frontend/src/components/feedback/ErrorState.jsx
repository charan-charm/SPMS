import { AlertTriangle, RotateCcw } from 'lucide-react'
import { Button } from '../ui/button'
import { cn } from '../../lib/utils'

/**
 * ErrorState (docs/12 §16 structure: context → title → explanation →
 * limitation → action). Shows the actual problem safely; never exposes
 * internal diagnostics. Retry is optional and user-initiated.
 */
export function ErrorState({
    title = 'Something went wrong',
    message = 'This information could not be loaded. Other parts of the application are unaffected.',
    retryLabel = null,
    onRetry = null,
    className,
}) {
    return (
        <div
            role="alert"
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-lg border border-danger/30 bg-danger/5 px-6 py-10 text-center',
                className,
            )}
        >
            <span className="grid size-10 place-items-center rounded-md bg-danger/10 text-danger">
                <AlertTriangle className="size-5" aria-hidden="true" />
            </span>
            <div>
                <p className="font-medium text-heading">{title}</p>
                <p className="mt-1 text-sm text-muted">{message}</p>
            </div>
            {retryLabel && onRetry && (
                <Button variant="secondary" size="sm" onClick={onRetry}>
                    <RotateCcw className="size-3.5" aria-hidden="true" />
                    {retryLabel}
                </Button>
            )}
        </div>
    )
}
