import { useState } from 'react'
import { CircleCheck, ShieldCheck, TriangleAlert } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
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
    SEQUENCE_STATUS,
    confidenceLevelFromScore,
    formatDate,
    formatBillingMonthYear,
    getBillDocumentSource,
} from './presentation'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * Ownership notice — displays evidence-backed household ownership check.
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
            className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3.5 py-2.5 text-sm"
            aria-label="Bill ownership check"
        >
            <Icon className={cn('size-4 shrink-0', toneClass)} aria-hidden="true" />
            <span className={cn('text-xs font-medium', toneClass)}>{label}</span>
            {ownership.household_service_number_masked && (
                <span className="ml-auto text-[11px] text-muted font-mono">
                    {ownership.household_service_number_masked}
                </span>
            )}
        </div>
    )
}

/**
 * Sequence validation notice — compact warning banner for billing history gaps.
 */
function BillSequenceNotice({ sequence, gapAcknowledged, onGapAcknowledgeChange }) {
    if (!sequence || !sequence.status) {
        return null
    }

    const { status, message, missing_period } = sequence
    const meta = SEQUENCE_STATUS[status]

    if (status === 'FIRST_BILL') {
        return (
            <div className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3.5 py-2 text-xs text-muted">
                <CircleCheck className="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                <span>First bill in sequence — baseline statement.</span>
            </div>
        )
    }

    if (status === 'CONTIGUOUS') {
        return (
            <div className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3.5 py-2 text-xs text-primary">
                <CircleCheck className="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                <span className="font-medium">Continuous billing period</span>
                <span className="text-body">— follows previous verified bill.</span>
            </div>
        )
    }

    if (status === 'GAP_DETECTED') {
        const gapPeriod = missing_period
            ? `${formatBillingMonthYear(missing_period.start, missing_period.start)} → ${formatBillingMonthYear(missing_period.end, missing_period.end)}`
            : null

        return (
            <div className="flex flex-col gap-2 rounded-lg border border-warning/40 bg-warning/5 p-3 text-sm text-body">
                <div className="flex items-start gap-2 text-warning">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div>
                        <p className="font-semibold text-xs text-heading uppercase tracking-wide">
                            Billing history gap
                        </p>
                        <p className="mt-0.5 text-xs text-muted">
                            {gapPeriod
                                ? `No statement found between: ${gapPeriod}`
                                : message}
                        </p>
                    </div>
                </div>
                <label className="flex items-center gap-2 pt-1 text-xs font-medium text-heading cursor-pointer select-none">
                    <input
                        type="checkbox"
                        checked={gapAcknowledged}
                        onChange={(e) => onGapAcknowledgeChange(e.target.checked)}
                        className="size-3.5 rounded border-border text-primary focus:ring-primary"
                    />
                    <span>Acknowledge gap and proceed without historical data</span>
                </label>
            </div>
        )
    }

    if (status === 'DUPLICATE_PERIOD' || status === 'OVERLAPPING_PERIOD' || status === 'INVALID_REVERSED') {
        return (
            <div className="flex items-start gap-2 rounded-lg border border-danger/40 bg-danger/5 p-3 text-xs text-danger">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div>
                    <p className="font-semibold">{meta?.title ?? 'Billing period conflict'}</p>
                    <p className="mt-0.5 text-body">{message}</p>
                </div>
            </div>
        )
    }

    return null
}

/**
 * BillReviewPanel — main desktop review surface for selected bills.
 *
 * Implements:
 * - 70/30 desktop responsive layout
 * - Source-aware bill identification (PDF, Image, Structured XLS)
 * - Honest confidence presentation (never faking confidence for XLS)
 * - Preserves strict test heading ordering:
 *     solar readings < bill validation < review needed
 */
export function BillReviewPanel({ detail, actions = null, onVerify = null, verifying = false }) {
    const [gapAcknowledged, setGapAcknowledged] = useState(false)
    const needsReview = detail.status === 'NEEDS_REVIEW'
    const provenance = detail.provenance ?? {}
    const hasCorrections = Object.values(provenance).some((entry) => entry.source === 'USER_CORRECTION')
    const documentConfidence = detail.extraction?.document_confidence ?? null

    const docSource = getBillDocumentSource(detail)
    const isStructured = docSource.id === 'STRUCTURED_XLS'
    const primaryProvenance = isStructured ? 'STRUCTURED_IMPORT' : 'AI_EXTRACTION'
    const SourceIcon = docSource.icon

    const isDuplicateOrOverlap = ['DUPLICATE_PERIOD', 'OVERLAPPING_PERIOD', 'INVALID_REVERSED'].includes(
        detail.sequence_validation?.status
    )
    const isOwnershipMismatch = detail.ownership?.status === 'MISMATCH'
    const isGapDetected = detail.sequence_validation?.status === 'GAP_DETECTED'
    const canVerify = !isDuplicateOrOverlap && !isOwnershipMismatch && (!isGapDetected || gapAcknowledged)

    return (
        <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            {/* LEFT MAIN COLUMN (~70% desktop width): Core billing information & validations */}
            <div className="flex min-w-0 flex-col gap-6">
                {/* 1. Header & review notice */}
                <div className="flex flex-col gap-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-display text-xl font-semibold text-heading">
                            {needsReview ? 'Review this bill' : 'Bill information extracted'}
                        </h2>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                isStructured
                                    ? 'border-secondary/40 bg-secondary/10 text-secondary'
                                    : 'border-info/40 bg-info/10 text-info'
                            )}
                        >
                            <SourceIcon className="size-3.5" aria-hidden="true" />
                            {docSource.badge}
                        </span>
                        {detail.source_statement_serial && (
                            <span className="rounded-full border border-border bg-surface-alt px-2.5 py-0.5 text-xs font-mono font-medium text-muted">
                                Statement #{detail.source_statement_serial}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted">
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

                {/* 2. Grouped Information Sections (Identity, Energy, Meter, Solar, Cost, Context) */}
                <BillInformationSections detail={detail} />

                {/* 3. Validation checks summary (heading: "Bill validation") */}
                <BillValidationSummary validations={detail.validations} />

                {/* 4. Conflict Alert (heading: "Review needed") — ordered strictly after validation */}
                {needsReview && <BillReviewAlert detail={detail} />}
            </div>

            {/* RIGHT SIDEBAR (~30% desktop width): Contextual status, source, ownership, actions */}
            <aside className="flex min-w-0 flex-col gap-4">
                {/* Primary Action / Verification State */}
                {actions ??
                    (detail.status === 'VERIFIED' ? (
                        <div className="flex flex-col gap-2 rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-2xs">
                            <div className="flex items-center gap-2 font-medium text-primary">
                                <ShieldCheck className="size-5 shrink-0" aria-hidden="true" />
                                <span>This bill has been verified</span>
                                {detail.verified_at && (
                                    <span className="ml-auto text-xs text-muted">
                                        {formatDate(detail.verified_at.slice(0, 10))}
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-muted">
                                This statement is locked into your household energy baseline.
                            </p>
                            {detail.quality_flags?.some((f) => f.code === 'INCOMPLETE_HISTORY') && (
                                <p className="text-xs text-warning">
                                    Verified with acknowledged billing gap. Future forecasts account for this incomplete historical sequence.
                                </p>
                            )}
                        </div>
                    ) : (
                        <Card className="border-border bg-surface shadow-2xs">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base font-semibold text-heading">
                                    Ready to verify this bill?
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Verification locks this bill into your household energy records and updates your consumption baseline.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 pt-0">
                                <Button
                                    type="button"
                                    className="w-full"
                                    onClick={() => onVerify?.(detail.bill_id, { acceptGap: gapAcknowledged })}
                                    disabled={!canVerify || verifying}
                                    loading={verifying}
                                >
                                    Verify bill
                                </Button>
                                {isOwnershipMismatch && (
                                    <p className="text-xs text-warning">
                                        Verification is unavailable because the service number does not match this household.
                                    </p>
                                )}
                                {isDuplicateOrOverlap && (
                                    <p className="text-xs text-danger">
                                        Verification is blocked due to a conflicting billing period.
                                    </p>
                                )}
                                {isGapDetected && !gapAcknowledged && (
                                    <p className="text-xs text-muted">
                                        Check the acknowledgment box below to verify despite the billing gap.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ))}

                {/* Source & Ingestion provenance */}
                <Card className="shadow-2xs">
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between gap-2">
                            <CardTitle className="text-sm font-semibold flex items-center gap-1.5">
                                <SourceIcon className="size-4 text-secondary" aria-hidden="true" />
                                <span>Source & ingestion</span>
                            </CardTitle>
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                    isStructured
                                        ? 'border-secondary/40 bg-secondary/10 text-secondary'
                                        : 'border-info/40 bg-info/10 text-info'
                                )}
                            >
                                {docSource.badge}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2 pt-0 text-xs">
                        <div className="flex items-center justify-between border-t border-border/60 pt-2">
                            <span className="text-muted">Processing</span>
                            <span className="font-medium text-heading">{docSource.processing}</span>
                        </div>
                        {detail.source_statement_serial && (
                            <div className="flex items-center justify-between border-t border-border/60 pt-1.5">
                                <span className="text-muted">Statement serial</span>
                                <span className="font-mono font-medium text-heading">
                                    Statement #{detail.source_statement_serial}
                                </span>
                            </div>
                        )}
                        <p className="text-muted text-[11px] pt-1">
                            {docSource.description}
                        </p>
                    </CardContent>
                </Card>

                {/* Ownership Notice */}
                <BillOwnershipNotice ownership={detail.ownership} />

                {/* Sequence validation notice */}
                <BillSequenceNotice
                    sequence={detail.sequence_validation}
                    gapAcknowledged={gapAcknowledged}
                    onGapAcknowledgeChange={setGapAcknowledged}
                />

                {/* Document assessment & Provenance */}
                <Card className="shadow-2xs">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold">Document recognition</CardTitle>
                        <CardDescription className="text-xs">
                            {isStructured
                                ? 'Structured data from utility export. No OCR confidence scores apply.'
                                : 'Confidence belongs to the document as a whole. It is not a per-field guarantee.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-medium text-heading">
                                {detail.extraction
                                    ? ASSESSMENT_LABEL[detail.extraction.assessment] ?? detail.extraction.assessment
                                    : 'No extraction recorded'}
                            </p>
                            {/* NEVER show fake OCR/AI confidence for structured spreadsheet rows */}
                            {!isStructured && documentConfidence !== null && (
                                <ConfidenceIndicator
                                    level={confidenceLevelFromScore(documentConfidence)}
                                    score={Number(documentConfidence)}
                                />
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <ProvenanceBadge source={primaryProvenance} />
                            {hasCorrections && <ProvenanceBadge source="USER_CORRECTION" />}
                        </div>
                        <p className="text-xs text-muted">
                            {hasCorrections
                                ? 'Values include one or more corrections you confirmed.'
                                : isStructured
                                    ? 'Values from APSPDCL structured statement — review before verification.'
                                    : 'All values shown are the extraction as recorded — nothing here is verified yet.'}
                        </p>
                        <div className="text-sm">
                            <Disclosure label="Extraction details">
                                {detail.extraction ? (
                                    <div className="flex flex-col gap-2">
                                        <dl className="grid gap-x-4 gap-y-1">
                                            {Object.entries(detail.values)
                                                .filter(([field, value]) => value !== null && FIELD_LABEL[field])
                                                .map(([field]) => (
                                                    <div key={field} className="flex justify-between gap-2 py-0.5 text-xs">
                                                        <dt className="text-muted truncate">{FIELD_LABEL[field].label}</dt>
                                                        <dd className="text-body font-mono text-[11px] shrink-0">
                                                            {provenance[field]?.confidence && !isStructured
                                                                ? `${Math.round(provenance[field].confidence * 100)}% conf`
                                                                : 'recorded'}
                                                        </dd>
                                                    </div>
                                                ))}
                                        </dl>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted">Extraction has not completed for this bill yet.</p>
                                )}
                            </Disclosure>
                        </div>
                    </CardContent>
                </Card>

                <p className="flex items-center gap-2 text-xs text-muted">
                    <ShieldCheck className="size-3.5 shrink-0" aria-hidden="true" />
                    Verification is an explicit step: extracted is never the same as verified.
                </p>
            </aside>
        </div>
    )
}
