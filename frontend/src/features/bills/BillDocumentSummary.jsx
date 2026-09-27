import { FileSearch, ShieldCheck } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { TONE_CLASS } from '../../lib/semantics'
import { ProvenanceBadge } from './ProvenanceBadge'
import { ASSESSMENT_LABEL, BILL_STATUS, NOT_AVAILABLE, formatDate } from './presentation'
import { cn } from '../../lib/utils'

/**
 * BillDocumentSummary — the compact "where is my bill" card for the
 * workspace side column (desktop) / top (mobile). Status arrives from
 * the backend lifecycle only; VERIFIED is rendered exclusively from a
 * backend-reported VERIFIED status + verified_at stamp.
 */
export function BillDocumentSummary({ detail }) {
    if (!detail) {
        return null
    }

    const isStructured =
        detail.source === 'APSPDCL_EXPORT' ||
        Object.values(detail.provenance ?? {}).some((p) => p?.source === 'STRUCTURED_IMPORT')
    const primarySource = isStructured ? 'STRUCTURED_IMPORT' : 'AI_EXTRACTION'
    const sourceLabel = isStructured
        ? 'Values from APSPDCL structured statement'
        : 'Values come from the AI extraction'
    const status = BILL_STATUS[detail.status] ?? { label: detail.status, tone: 'muted' }
    const assessment = detail.extraction ? (ASSESSMENT_LABEL[detail.extraction.assessment] ?? null) : null

    return (
        <Card aria-label="Bill status">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <FileSearch className="size-4 text-secondary" aria-hidden="true" />
                    Bill status
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <p className="flex items-center gap-2">
                    <span
                        className={cn(
                            'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                            TONE_CLASS[status.tone],
                        )}
                    >
                        {status.label}
                    </span>
                </p>
                {assessment && <p className="text-sm text-body">{assessment}</p>}
                {detail.status === 'PROCESSING' && (
                    <p className="text-xs text-muted">
                        Reading has not completed yet — you can continue the analysis below whenever you are ready.
                    </p>
                )}
                {detail.extraction && (
                    <div className="flex flex-wrap items-center gap-2">
                        <ProvenanceBadge source={primarySource} />
                        <span className="text-xs text-muted">{sourceLabel}</span>
                    </div>
                )}
                {detail.status === 'VERIFIED' && (
                    <p className="flex items-center gap-2 text-sm text-primary">
                        <ShieldCheck className="size-4" aria-hidden="true" />
                        Verified
                        {detail.verified_at ? ` on ${formatDate(detail.verified_at.slice(0, 10)) ?? NOT_AVAILABLE}` : ''}
                    </p>
                )}
            </CardContent>
        </Card>
    )
}
