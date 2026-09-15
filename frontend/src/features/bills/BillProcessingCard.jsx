import { LoaderCircle } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card'
import { StatusBadge } from '../../components/data-display/StatusBadge'

/**
 * BillProcessingCard (B4 §5) — the in-flight state of one bill.
 *
 * Deliberately free of fake progress (docs/12 §15): extraction is real
 * work with a real duration, so the card says what is happening and
 * nothing about percentage completion. role="status" + aria-live keeps
 * assistive tech informed when the stage changes.
 */
export function BillProcessingCard({ stage = 'uploading' }) {
    return (
        <Card role="status" aria-live="polite" className="border-info/30">
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-3">
                    <LoaderCircle className="size-5 animate-spin text-info" aria-hidden="true" />
                    <span>{stage === 'uploading' ? 'Uploading your bill' : 'Analyzing your bill'}</span>
                    <StatusBadge status="PROCESSING" />
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm text-body">
                    {stage === 'uploading'
                        ? 'Your bill is being sent to SPMS for reading.'
                        : 'SPMS is reading the bill and preparing the information for your review. This can take a minute.'}
                </p>
            </CardContent>
        </Card>
    )
}
