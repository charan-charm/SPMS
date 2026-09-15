import { CircleCheck, ShieldCheck } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { ProvenanceBadge } from './ProvenanceBadge'
import { Disclosure } from './Disclosure'
import { BillInformationSections } from './BillInformationSections'
import { BillReviewAlert, BillValidationSummary } from './BillReviewAlert'
import {
    ASSESSMENT_LABEL,
    BILL_STATUS,
    FIELD_LABEL,
    OWNERSHIP_STATUS,
    confidenceLevelFromScore,
    formatEnergy,
} from './presentation'
import { cn } from '../../lib/utils'

/**
 * Ownership line (D-071 §Q) — the third question, kept visually apart
 * from document recognition ("is this a bill?") and validation ("do the
 * readings agree?"). The status arrives from the backend read model;
 * this component only translates it into words. A MATCH is evidence-
 * backed ("matches the registered service number"), and no state here
 * ever says "verified" — verification remains the separate B5 act.
 */
function BillOwnershipNotice({ ownership }) {
    if (!ownership || !OWNERSHIP_STATUS[ownership.status]) {
        return null
    }

    const { label, tone, icon: Icon } = OWNERSHIP_STATUS[ownership.status]
    const toneClass =
        tone === 'positive' ? 'text-primary' : tone === 'warning' ? 'text-warning' : 'text-muted'

    return (
        <div
            className="flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-sm"
            aria-label="Bill ownership check"
        >
            <Icon className={cn('size-4 shrink-0', toneClass)} aria-hidden="true" />
            <span className={cn('font-medium', toneClass)}>{label}</span>
            {ownership.household_service_number_masked && (
                <span className="ml-auto text-xs text-muted">
                    Registered: {ownership.household_service_number_masked}
                </span>
            )}
        </div>
    )
}

/**
 * BillReviewPanel (B4 §5/§7/§12; refinement §11) — the review stage for
 * EXTRACTED and NEEDS_REVIEW bills, ordered as the review journey:
 * what was read → how the document was recognized → the extracted
 * information → SPMS's validation of it → any specific conflict →
 * review-before-verification.
 *
 * B4 is a review screen, not a verification screen: the calm extracted
 * notice never claims correctness ("Review it before verification"),
 * conflicts render through the review treatment, and the footer action
 * slot is exactly where B5 wires the real verification operation —
 * there is no local "verified" simulation anywhere in this file.
 */
export function BillReviewPanel({ detail, actions = null }) {
    const needsReview = detail.status === 'NEEDS_REVIEW'
    const provenance = detail.provenance ?? {}
    const hasCorrections = Object.values(provenance).some((entry) => entry.source === 'USER_CORRECTION')
    const documentConfidence = detail.extraction?.document_confidence ?? null

    return (
        <div className="flex flex-col gap-4">
            {/* 1. What this page is. */}
            <div>
                <h2 className="font-display text-xl font-semibold text-heading">
                    {needsReview ? 'Review your bill' : 'Bill information extracted'}
                </h2>
                <p className="mt-1 text-sm text-muted">
                    {needsReview
                        ? 'SPMS extracted the information below from your bill, and part of it needs your attention.'
                        : 'The information below was read from your bill. Please review it before verification.'}
                </p>
            </div>

            {!needsReview && (
                <div
                    role="status"
                    className="flex items-start gap-2 rounded-lg border border-info/30 bg-info/5 px-4 py-3 text-sm text-body"
                >
                    <CircleCheck className="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                    SPMS read this bill without finding anything that needs your review — please still check
                    the values before verification.
                </div>
            )}

            {/* 2. Document assessment — deliberately separate from the
                validation section below: one answers "was the document
                recognized", the other "do its readings agree". */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Document recognition</CardTitle>
                    <CardDescription>
                        Confidence belongs to the document as a whole. It is not a per-field guarantee — values
                        without a confidence were extracted without the reader stating one.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <p className="text-sm font-medium text-heading">
                            {detail.extraction
                                ? (ASSESSMENT_LABEL[detail.extraction.assessment] ?? detail.extraction.assessment)
                                : 'No extraction recorded'}
                        </p>
                        {documentConfidence !== null && (
                            <ConfidenceIndicator
                                level={confidenceLevelFromScore(documentConfidence)}
                                score={Number(documentConfidence)}
                            />
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ProvenanceBadge source="AI_EXTRACTION" />
                        {hasCorrections && <ProvenanceBadge source="USER_CORRECTION" />}
                        <span className="text-xs text-muted">
                            {hasCorrections
                                ? 'Values include one or more corrections you confirmed.'
                                : 'All values shown are the AI extraction as recorded — nothing here is verified yet.'}
                        </span>
                    </div>
                    <div className="text-sm">
                        <Disclosure label="Extraction details">
                            {detail.extraction ? (
                                <div className="flex flex-col gap-2">
                                    <dl className="grid gap-x-8 sm:grid-cols-2">
                                        {Object.entries(detail.values)
                                            .filter(([field, value]) => value !== null && FIELD_LABEL[field])
                                            .map(([field]) => (
                                                <div key={field} className="flex justify-between gap-3 py-1 text-sm">
                                                    <dt className="text-muted">{FIELD_LABEL[field].label}</dt>
                                                    <dd className="text-body">
                                                        {provenance[field]?.confidence
                                                            ? `${formatEnergy(provenance[field].confidence, { signed: false })} · AI extracted`
                                                            : provenance[field]
                                                              ? 'AI extracted · no confidence stated'
                                                              : '—'}
                                                    </dd>
                                                </div>
                                            ))}
                                    </dl>
                                    <p className="text-xs text-muted">
                                        Extraction details are kept for transparency — they are not part of your
                                        energy records.
                                    </p>
                                </div>
                            ) : (
                                <p className="text-sm text-muted">Extraction has not completed for this bill yet.</p>
                            )}
                        </Disclosure>
                    </div>
                </CardContent>
            </Card>

            {/* 3. The extracted information itself. */}
            <BillInformationSections detail={detail} />

            {/* 4. D-071: ownership — "does this bill belong to this
                household's registered service?" — its own answer, before
                the number-agreement checks and never phrased as
                verification. */}
            <BillOwnershipNotice ownership={detail.ownership} />

            {/* 5. SPMS's deterministic validation of those values. */}
            <BillValidationSummary validations={detail.validations} />

            {/* 6. The specific readings that need attention. */}
            {needsReview && <BillReviewAlert detail={detail} />}

            {/* 7. Review before verification — the action slot is the B5 seam. */}
            {actions}

            <p className="flex items-center gap-2 text-xs text-muted">
                <ShieldCheck className="size-3.5 shrink-0" aria-hidden="true" />
                Verification is a separate, explicit step: {BILL_STATUS.EXTRACTED.label.toLowerCase()} and{' '}
                {BILL_STATUS.NEEDS_REVIEW.label.toLowerCase()} are never the same as verified.
            </p>
        </div>
    )
}
