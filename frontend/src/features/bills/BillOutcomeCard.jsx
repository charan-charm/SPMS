import { RotateCcw, UploadCloud } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { TERMINAL_OUTCOME } from './presentation'
import { cn } from '../../lib/utils'

/**
 * BillOutcomeCard (B4 §13-§16) — honest cards for lifecycle outcomes
 * that carry no bill data (NOT_A_BILL / UNREADABLE / UNCERTAIN /
 * PROCESSING_FAILED). No fabricated fields, no raw provider or exception
 * text — the copy is fixed per state, and the primary action always
 * returns the user to the upload path.
 */
export function BillOutcomeCard({ status, onUploadAnother, retry = false, onRetry = null }) {
    const outcome = TERMINAL_OUTCOME[status]

    if (!outcome) {
        return null
    }

    const Icon = outcome.icon

    return (
        <Card className={cn(status === 'PROCESSING_FAILED' ? 'border-warning/40' : 'border-border')}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon
                        className={cn('size-5', status === 'PROCESSING_FAILED' ? 'text-warning' : 'text-muted')}
                        aria-hidden="true"
                    />
                    {outcome.title}
                </CardTitle>
                <CardDescription>{outcome.body}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap items-center gap-3">
                {retry && onRetry && (
                    <Button type="button" variant="secondary" onClick={onRetry}>
                        <RotateCcw className="size-4" aria-hidden="true" />
                        Try again
                    </Button>
                )}
                <Button type="button" onClick={onUploadAnother}>
                    <UploadCloud className="size-4" aria-hidden="true" />
                    Upload another bill
                </Button>
            </CardContent>
        </Card>
    )
}
