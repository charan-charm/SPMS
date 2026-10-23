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
export function BillReviewPanel({
    detail,
    actions = null,
    onVerify = null,
    verifying = false,
    onCorrectField = null,
}) {
    const [gapAcknowledged, setGapAcknowledged] = useState(false)
    const needsReview = detail.status === 'NEEDS_REVIEW'
    const provenance = detail.provenance ?? {}
    const hasCorrections = Object.values(provenance).some((entry) => entry.source === 'USER_CORRECTION')
    const documentConfidence = detail.extraction?.document_confidence ?? null

    const docSource = getBillDocumentSource(detail)
    const isStructured = docSource.id === 'STRUCTURED_XLS'
    const primaryProvenance = isStructured ? 'STRUCTURED_IMPORT' : 'AI_EXTRACTION'
    const SourceIcon = docSource.icon

    const conflictCount = (detail.validations ?? []).filter((v) => v.status === 'CONFLICT').length
    const hasCriticalFlags = (detail.quality_flags ?? []).some((q) =>
        ['INCONSISTENT_NET_UNITS', 'CONFLICTING_METER_READINGS', 'CONFLICTING_SOLAR_READINGS'].includes(q.code)
    )
    const hasConflicts = needsReview || conflictCount > 0 || hasCriticalFlags
    const hasRequiredFields = Boolean(
        detail.values?.billing_period_start &&
        detail.values?.billing_period_end &&
        detail.values?.consumption_kwh !== null &&
        detail.values?.consumption_kwh !== undefined
    )

    const isDuplicateOrOverlap = ['DUPLICATE_PERIOD', 'OVERLAPPING_PERIOD', 'INVALID_REVERSED'].includes(
        detail.sequence_validation?.status
    )
    const isOwnershipMismatch = detail.ownership?.status === 'MISMATCH'
    const isGapDetected = detail.sequence_validation?.status === 'GAP_DETECTED'
    const canVerify =
        !isDuplicateOrOverlap &&
        !isOwnershipMismatch &&
        (!isGapDetected || gapAcknowledged) &&
        !hasConflicts

    return (
        <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(340px,1fr)] xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,1fr)]">
            {/* LEFT COLUMN: Core billing details, readings, costs, context & technical details */}
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

                {needsReview || conflictCount > 0 ? (
                    <div
                        role="alert"
                        className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-body"
                    >
                        <TriangleAlert className="mt-0.5 size-4.5 shrink-0 text-warning" aria-hidden="true" />
                        <div className="flex-1">
                            <p className="font-semibold text-heading">
                                {conflictCount === 1 ? '1 value needs your attention' : `${conflictCount || 'Some'} values need your attention`}
                            </p>
                            <p className="mt-0.5 text-xs text-muted">
                                SPMS detected inconsistent readings. Inspect the highlighted sections below and correct them using your physical or digital statement before verification.
                            </p>
                        </div>
                    </div>
                ) : (
                    <div
                        role="status"
                        className="flex items-start gap-2.5 rounded-lg border border-primary/30 bg-primary/5 px-4 py-3 text-sm text-body"
                    >
                        <CircleCheck className="mt-0.5 size-4.5 shrink-0 text-primary" aria-hidden="true" />
                        <div className="flex-1">
                            <p className="font-semibold text-heading">
                                All values validated
                            </p>
                            <p className="mt-0.5 text-xs text-muted">
                                SPMS read this bill without finding anything that needs your review — please still check the values before verification.
                            </p>
                        </div>
                    </div>
                )}

                {/* 2. Grouped Information Sections (Identity, Energy, Meter, Solar, Cost, Context, Technical) */}
                <BillInformationSections detail={detail} onCorrectField={onCorrectField} />
            </div>

            {/* RIGHT SIDEBAR (~30% desktop width): Contextual status, source, ownership, actions */}
            <aside className="flex min-w-0 flex-col gap-5">
                {/* Primary Action / Verification State */}
                {actions ??
                    (detail.status === 'VERIFIED' ? (
                        <div className="flex flex-col gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-2xs">
                            <div className="flex items-center gap-2 font-medium text-primary">
                                <ShieldCheck className="size-5 shrink-0" aria-hidden="true" />
                                <span className="font-semibold">This bill has been verified</span>
                                {detail.verified_at && (
                                    <span className="ml-auto text-xs text-muted">
                                        {formatDate(detail.verified_at.slice(0, 10))}
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-body">
                                This statement is locked into your household energy baseline as authoritative data.
                            </p>
                            <div className="border-t border-primary/20 pt-2.5">
                                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted">
                                    Downstream SPMS usage
                                </p>
                                <ul className="mt-1.5 space-y-1 text-xs text-muted">
                                    <li className="flex items-center gap-1.5">
                                        <CircleCheck className="size-3.5 text-primary shrink-0" />
                                        <span>Demand baseline calibrated</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <CircleCheck className="size-3.5 text-primary shrink-0" />
                                        <span>Consumption & solar generation forecasts updated</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <CircleCheck className="size-3.5 text-primary shrink-0" />
                                        <span>Energy balance & solar self-consumption verified</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <CircleCheck className="size-3.5 text-primary shrink-0" />
                                        <span>Cost & tariff analysis records updated</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <CircleCheck className="size-3.5 text-primary shrink-0" />
                                        <span>Energy-saving recommendations refined</span>
                                    </li>
                                </ul>
                            </div>
                            {detail.quality_flags?.some((f) => f.code === 'INCOMPLETE_HISTORY') && (
                                <p className="text-xs text-warning pt-1">
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
                                    Your corrections will become the trusted bill data used by SPMS.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 pt-0">
                                {/* Pre-verification checklist */}
                                <div className="rounded-lg border border-border/70 bg-surface-alt/40 p-3 text-xs">
                                    <p className="font-semibold text-heading mb-2">Pre-verification checklist</p>
                                    <ul className="space-y-1.5 text-muted">
                                        <li className="flex items-center gap-2">
                                            {hasRequiredFields ? (
                                                <CircleCheck className="size-3.5 text-primary shrink-0" />
                                            ) : (
                                                <TriangleAlert className="size-3.5 text-warning shrink-0" />
                                            )}
                                            <span className={hasRequiredFields ? 'text-heading font-medium' : ''}>
                                                Required fields complete
                                            </span>
                                        </li>
                                        <li className="flex items-center gap-2">
                                            {!isOwnershipMismatch ? (
                                                <CircleCheck className="size-3.5 text-primary shrink-0" />
                                            ) : (
                                                <TriangleAlert className="size-3.5 text-danger shrink-0" />
                                            )}
                                            <span className={!isOwnershipMismatch ? 'text-heading font-medium' : ''}>
                                                Service number matches household
                                            </span>
                                        </li>
                                        <li className="flex items-center gap-2">
                                            {!isDuplicateOrOverlap && (!isGapDetected || gapAcknowledged) ? (
                                                <CircleCheck className="size-3.5 text-primary shrink-0" />
                                            ) : (
                                                <TriangleAlert className="size-3.5 text-warning shrink-0" />
                                            )}
                                            <span className={!isDuplicateOrOverlap && (!isGapDetected || gapAcknowledged) ? 'text-heading font-medium' : ''}>
                                                Billing period sequence valid
                                            </span>
                                        </li>
                                        <li className="flex items-center gap-2">
                                            {conflictCount === 0 && !hasCriticalFlags ? (
                                                <CircleCheck className="size-3.5 text-primary shrink-0" />
                                            ) : (
                                                <TriangleAlert className="size-3.5 text-warning shrink-0" />
                                            )}
                                            <span className={conflictCount === 0 && !hasCriticalFlags ? 'text-heading font-medium' : ''}>
                                                No unresolved critical inconsistencies
                                            </span>
                                        </li>
                                    </ul>
                                </div>

                                <Button
                                    type="button"
                                    className="w-full"
                                    onClick={() => onVerify?.(detail.bill_id, { acceptGap: gapAcknowledged })}
                                    disabled={!canVerify || verifying}
                                    loading={verifying}
                                >
                                    Verify bill
                                </Button>
                                {(conflictCount > 0 || hasCriticalFlags) && (
                                    <p className="text-xs text-warning">
                                        Resolve the {conflictCount} flagged inconsistencies before verifying.
                                    </p>
                                )}
                                {!hasRequiredFields && (
                                    <p className="text-xs text-warning">
                                        Please provide all required bill values (period, consumption, bill amount).
                                    </p>
                                )}
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
                                    <div className="mt-1 flex flex-col max-h-60 sm:max-h-64 overflow-y-auto overscroll-contain pr-2 pt-1 pb-4 rounded-lg border border-border/40 bg-surface-alt/30 p-2.5">
                                        <dl className="flex flex-col divide-y divide-border/20">
                                            {Object.entries(detail.values)
                                                .filter(([field, value]) => value !== null && FIELD_LABEL[field])
                                                .map(([field]) => (
                                                    <div key={field} className="flex items-center justify-between gap-3 py-1.5 text-xs leading-normal first:pt-0 last:pb-0">
                                                        <dt className="text-muted truncate font-medium">{FIELD_LABEL[field].label}</dt>
                                                        <dd className="text-body font-mono text-[11px] shrink-0 rounded bg-surface px-1.5 py-0.5 border border-border/40">
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

                {/* Bill validation summary (moved to right column for balanced layout) */}
                <BillValidationSummary validations={detail.validations} />

                {/* Conflict Alert (heading: "Review needed") — ordered strictly after validation */}
                {needsReview && <BillReviewAlert detail={detail} />}

                <p className="flex items-center gap-2 text-xs text-muted">
                    <ShieldCheck className="size-3.5 shrink-0" aria-hidden="true" />
                    Verification is an explicit step: extracted is never the same as verified.
                </p>
            </aside>
        </div>
    )
}
