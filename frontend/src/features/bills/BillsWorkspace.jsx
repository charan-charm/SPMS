import { useMemo, useRef, useState } from 'react'
import { motion } from 'motion/react'
import { FileCheck, Plus } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { LoadingState } from '../../components/feedback/LoadingState'
import { BillHistory } from './BillHistory'
import { BillOutcomeCard } from './BillOutcomeCard'
import { BillProcessingCard } from './BillProcessingCard'
import { BillReviewPanel } from './BillReviewPanel'
import { BillUploadPanel } from './BillUploadPanel'
import { ApspdclImportSummary } from './ApspdclImportSummary'
import { StatementNavigator } from './StatementNavigator'
import { sortBillsChronologically } from './presentation'
import { DURATION } from '../../lib/motion'

/**
 * BillsWorkspace — unified, desktop-optimized bills workspace.
 *
 * Implements:
 * - Clean responsive desktop layout (~70/30 selected bill review + full-width history table below)
 * - Source-aware bill navigation with StatementNavigator (month + year)
 * - Shared review UI between single PDF/image bills and multi-statement XLS imports
 * - Explicit distinction between total household history and statements from current file
 */
export function BillsWorkspace({
    sessionPhase = 'ready',
    bills = [],
    selectedBillId = null,
    detail = null,
    detailPhase = 'ready',
    pipelineStage = null,
    processFailure = null,
    duplicateNotice = null,
    onDismissDuplicateNotice = () => {},
    importSummary = null,
    accountMode = 'REAL',
    onUpload = async () => ({}),
    onSelectBill = () => {},
    onRetryProcess = () => {},
    onRetryDetail = null,
    onRetrySession = () => {},
    onVerify = null,
    verifying = false,
    onCorrect = null,
    uploadOpen: uploadOpenProp,
    onUploadOpenChange,
    children,
}) {
    const uploadPanelRef = useRef(null)
    const reviewSectionRef = useRef(null)
    const [internalUploadOpen, setInternalUploadOpen] = useState(false)
    const [manualEntryId, setManualEntryId] = useState(null)
    const uploadOpen = uploadOpenProp ?? internalUploadOpen
    const setUploadOpen = (value) => {
        if (uploadOpenProp === undefined) {
            setInternalUploadOpen(value)
        }
        onUploadOpenChange?.(value)
    }

    const scrollToReview = () => {
        try {
            if (reviewSectionRef.current) {
                reviewSectionRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' })
            } else {
                const el = document.getElementById('selected-bill-review-section')
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
                }
            }
        } catch {
            // Ignore in test / headless environments
        }
    }

    const sortedBills = useMemo(() => sortBillsChronologically(bills), [bills])

    if (sessionPhase === 'checking') {
        return <LoadingState label="Loading your bills" />
    }

    if (sessionPhase === 'signedOut') {
        return (
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">
                <PageHeading />
                <EmptyState
                    variant="unavailable"
                    explanation="Sign in to upload electricity bills and review what SPMS extracted for your household."
                    limitation="Bills belong to your household — the backend enforces this, the page cannot show them without a session."
                    action={
                        <Button type="button" onClick={onRetrySession}>
                            Check again
                        </Button>
                    }
                />
            </div>
        )
    }

    if (sessionPhase === 'error') {
        return (
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">
                <PageHeading />
                <ErrorState
                    title="Bills unavailable"
                    message="Your bills could not be loaded. Other parts of the application are unaffected."
                    retryLabel="Try again"
                    onRetry={onRetrySession}
                />
            </div>
        )
    }

    const showUploadPanel = uploadOpen || (sortedBills.length === 0 && !processFailure && !duplicateNotice)
    const activeDetail = detail

    return (
        <div className="mx-auto flex w-full max-w-7xl flex-col gap-8">
            {/* Header */}
            <div className="flex flex-wrap items-end justify-between gap-4">
                <PageHeading demo={accountMode === 'DEMO_TEST'} />
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        onClick={() => {
                            onDismissDuplicateNotice()
                            setUploadOpen(true)
                        }}
                        disabled={pipelineStage !== null}
                        aria-controls={showUploadPanel ? 'bill-upload-panel' : undefined}
                        aria-expanded={showUploadPanel}
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        Upload bill
                    </Button>
                </div>
            </div>

            {/* Pipeline Stage Processing */}
            {pipelineStage !== null && (
                <motion.div
                    key="pipeline"
                    id="bill-workspace-region"
                    aria-live="polite"
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: DURATION.page / 1000, ease: 'easeInOut' }}
                >
                    <BillProcessingCard stage={pipelineStage} />
                </motion.div>
            )}

            {/* Multi-statement APSPDCL Import Summary */}
            {importSummary !== null && pipelineStage === null && (
                <motion.div
                    key="import-summary"
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: DURATION.page / 1000, ease: 'easeInOut' }}
                >
                    <ApspdclImportSummary
                        summary={importSummary}
                        totalHouseholdBills={sortedBills.length}
                        onSelectBill={(id) => {
                            setUploadOpen(false)
                            onSelectBill(id)
                            scrollToReview()
                        }}
                    />
                </motion.div>
            )}

            {/* Informational Duplicate Notice (Clean Non-Error State) */}
            {duplicateNotice && pipelineStage === null && (
                <motion.div
                    key="duplicate-notice"
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: DURATION.page / 1000, ease: 'easeInOut' }}
                    role="status"
                    aria-live="polite"
                    className="flex flex-col gap-3 rounded-xl border border-sky-500/20 bg-sky-500/5 p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-sky-400/20 dark:bg-sky-950/20"
                >
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 rounded-lg bg-sky-500/10 p-2 text-sky-600 dark:text-sky-400">
                            <FileCheck className="size-5" aria-hidden="true" />
                        </div>
                        <div className="flex flex-col gap-0.5">
                            <h3 className="text-sm font-semibold text-foreground">
                                {duplicateNotice.title || 'Bill already uploaded'}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {duplicateNotice.message || 'This bill is already recorded for your household.'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 self-end sm:self-center">
                        {duplicateNotice.existingBillId && (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() => {
                                    onDismissDuplicateNotice()
                                    setUploadOpen(false)
                                    onSelectBill(duplicateNotice.existingBillId)
                                    scrollToReview()
                                }}
                            >
                                View existing bill
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onDismissDuplicateNotice}
                        >
                            Cancel
                        </Button>
                    </div>
                </motion.div>
            )}

            {/* Upload Panel */}
            {showUploadPanel ? (
                <motion.div
                    key="upload"
                    id="bill-upload-panel"
                    ref={uploadPanelRef}
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: DURATION.page / 1000, ease: 'easeInOut' }}
                >
                    <BillUploadPanel
                        busy={pipelineStage !== null}
                        onUpload={async (file) => {
                            const outcome = await onUpload(file)
                            if (outcome?.uploaded || outcome?.duplicate) {
                                setUploadOpen(false)
                            }
                            return outcome
                        }}
                    />
                </motion.div>
            ) : (
                <motion.div
                    key="workspace"
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: DURATION.page / 1000, ease: 'easeInOut' }}
                    className="flex flex-col gap-6"
                >
                    {processFailure && !activeDetail && (
                        <BillOutcomeCard
                            status="PROCESSING_FAILED"
                            retry={processFailure.retryable === true}
                            reason={processFailure.reason}
                            onRetry={onRetryProcess}
                            onUploadAnother={() => setUploadOpen(true)}
                        />
                    )}

                    {detailPhase === 'loading' && (
                        <LoadingState label="Loading this bill" className="py-8" />
                    )}

                    {detailPhase === 'error' && (
                        <ErrorState
                            title="Bill unavailable"
                            message="This bill could not be loaded. Other parts of the application are unaffected."
                            retryLabel="Try again"
                            onRetry={onRetryDetail ?? undefined}
                        />
                    )}

                    {detailPhase === 'ready' && activeDetail && (
                        <div id="selected-bill-review-section" ref={reviewSectionRef} className="flex flex-col gap-5">
                            {/* Statement Navigator (month + year) when multiple bills exist */}
                            {sortedBills.length > 1 && (
                                <StatementNavigator
                                    bills={sortedBills}
                                    selectedId={selectedBillId ?? activeDetail.bill_id}
                                    onSelect={(id) => {
                                        setUploadOpen(false)
                                        onSelectBill(id)
                                    }}
                                />
                            )}

                            {/* Unified Selected Bill Review Component */}
                            <BillWorkspaceDetail
                                detail={activeDetail}
                                processFailure={processFailure}
                                onContinue={onRetryProcess}
                                onUploadAnother={() => setUploadOpen(true)}
                                onVerify={onVerify}
                                verifying={verifying}
                                onCorrect={onCorrect}
                                isManualEntry={manualEntryId === activeDetail.bill_id}
                                onEnterManually={() => setManualEntryId(activeDetail.bill_id)}
                            />
                        </div>
                    )}

                    {detailPhase === 'ready' && !activeDetail && sortedBills.length > 0 && (
                        <EmptyState
                            variant="noSelection"
                            title="Select a bill"
                            explanation="Choose one of your bills in the billing history below to view its details."
                        />
                    )}
                </motion.div>
            )}

            {/* Full-width Responsive Billing History Table */}
            <div className="flex flex-col gap-4">
                <BillHistory
                    bills={sortedBills}
                    selectedId={selectedBillId ?? activeDetail?.bill_id ?? null}
                    onSelect={(id) => {
                        setUploadOpen(false)
                        onSelectBill(id)
                        scrollToReview()
                    }}
                    onUpload={() => setUploadOpen(true)}
                />
            </div>

            {children}
        </div>
    )
}

function PageHeading({ demo = false }) {
    return (
        <div>
            <h1 className="flex flex-wrap items-center gap-2 font-display text-2xl font-semibold text-heading">
                Your electricity bills
                {demo && (
                    <span className="rounded-full border border-chart-demo/50 bg-chart-demo/10 px-2.5 py-0.5 font-body text-xs font-semibold text-chart-demo">
                        DEMO data
                    </span>
                )}
            </h1>
            <p className="mt-1 text-sm text-muted">
                Add your monthly electricity bill to help SPMS understand your household&apos;s energy usage and
                improve future analysis.
            </p>
        </div>
    )
}

/** Detail content router for a non-pipeline moment. */
function BillWorkspaceDetail({
    detail,
    processFailure,
    onContinue,
    onUploadAnother,
    onVerify,
    verifying,
    onCorrect = null,
    isManualEntry = false,
    onEnterManually = null,
}) {
    if (isManualEntry) {
        return (
            <BillReviewPanel
                detail={detail}
                onVerify={onVerify}
                verifying={verifying}
                onCorrectField={onCorrect ? (field, val) => onCorrect(detail.bill_id, { [field]: val }) : null}
            />
        )
    }

    // Definitive terminal failure: if bill is PROCESSING_FAILED or processing failed, show ONLY failure card
    if (detail.status === 'PROCESSING_FAILED' || (processFailure && (detail.status === 'PROCESSING' || detail.status === 'UPLOADED'))) {
        return (
            <BillOutcomeCard
                status="PROCESSING_FAILED"
                retry={processFailure?.retryable ?? true}
                reason={processFailure?.reason}
                onRetry={onContinue}
                onUploadAnother={onUploadAnother}
                onEnterManually={onEnterManually}
            />
        )
    }

    if (detail.status === 'PROCESSING') {
        return (
            <div className="flex flex-col gap-4">
                <BillProcessingCard stage="processing" />
                <Button type="button" variant="secondary" onClick={onContinue}>
                    Continue analysis
                </Button>
            </div>
        )
    }

    if (detail.status === 'UPLOADED') {
        return (
            <div className="flex flex-col gap-4">
                <BillProcessingCard stage="uploading" />
            </div>
        )
    }

    if (['NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN'].includes(detail.status)) {
        return (
            <BillOutcomeCard
                status={detail.status}
                retry={false}
                onRetry={onContinue}
                onUploadAnother={onUploadAnother}
                onEnterManually={onEnterManually}
            />
        )
    }

    if (['EXTRACTED', 'NEEDS_REVIEW', 'VERIFIED'].includes(detail.status)) {
        return (
            <BillReviewPanel
                detail={detail}
                onVerify={onVerify}
                verifying={verifying}
                onCorrectField={onCorrect ? (field, val) => onCorrect(detail.bill_id, { [field]: val }) : null}
            />
        )
    }

    return null
}
