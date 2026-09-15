import { useRef, useState } from 'react'
import { motion } from 'motion/react'
import { Plus } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { LoadingState } from '../../components/feedback/LoadingState'
import { BillDocumentSummary } from './BillDocumentSummary'
import { BillHistory } from './BillHistory'
import { BillOutcomeCard } from './BillOutcomeCard'
import { BillProcessingCard } from './BillProcessingCard'
import { BillReviewPanel } from './BillReviewPanel'
import { BillUploadPanel } from './BillUploadPanel'
import { DURATION } from '../../lib/motion'

/**
 * BillsWorkspace — the unified Bills page workspace (B4 §5).
 *
 * One page, one journey: upload → processing → review → (verification,
 * later in B5) → history. Never split into sidebar items; the single
 * "Bills" navigation entry lands here (docs/04 §10, docs/11 §9).
 *
 * The component is a pure renderer: every fact — lifecycle status, values,
 * validation outcomes, quality flags, provenance — arrives from the
 * backend and is displayed as-is (missing is "Not available", never 0,
 * never "Estimated"). It cannot verify, cannot correct, and never
 * pretends a state it was not given. State ownership lives in the
 * pages/app BillsPage container; the DEV-only visual preview mounts
 * this same component with sample payloads.
 */
export function BillsWorkspace({
    sessionPhase = 'ready',
    bills = [],
    detail = null,
    detailPhase = 'ready',
    pipelineStage = null,
    processFailure = null,
    accountMode = 'REAL',
    onUpload = async () => ({}),
    onSelectBill = () => {},
    onRetryProcess = () => {},
    onRetryDetail = null,
    onRetrySession = () => {},
    uploadOpen: uploadOpenProp,
    onUploadOpenChange,
    children,
}) {
    const uploadPanelRef = useRef(null)
    const [internalUploadOpen, setInternalUploadOpen] = useState(false)
    const uploadOpen = uploadOpenProp ?? internalUploadOpen
    const setUploadOpen = (value) => {
        if (uploadOpenProp === undefined) {
            setInternalUploadOpen(value)
        }
        onUploadOpenChange?.(value)
    }

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

    const showUploadPanel = uploadOpen || (bills.length === 0 && !processFailure)
    const activeDetail = detail

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <PageHeading demo={accountMode === 'DEMO_TEST'} />
                <Button
                    type="button"
                    onClick={() => setUploadOpen(true)}
                    disabled={pipelineStage !== null}
                    aria-controls={showUploadPanel ? 'bill-upload-panel' : undefined}
                    aria-expanded={showUploadPanel}
                >
                    <Plus className="size-4" aria-hidden="true" />
                    Upload bill
                </Button>
            </div>

            <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                {/* Main column: the journey itself. */}
                <div className="flex min-w-0 flex-col gap-6">
                    {/* §5: the processing area is its own block — it sits
                        above the workspace, never replaces the upload
                        panel mid-flow (an upload failure must stay
                        visible where it happened). */}
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
                                    if (outcome?.uploaded) {
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
                                {processFailure && (
                                    <BillOutcomeCard
                                        status="PROCESSING_FAILED"
                                        retry={processFailure.retryable === true}
                                        onRetry={onRetryProcess}
                                        onUploadAnother={() => setUploadOpen(true)}
                                    />
                                )}

                                {detailPhase === 'loading' && (
                                    <LoadingState label="Loading this bill" className="py-6" />
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
                                    <BillWorkspaceDetail
                                        detail={activeDetail}
                                        processFailure={processFailure}
                                        onContinue={onRetryProcess}
                                        onUploadAnother={() => setUploadOpen(true)}
                                    />
                                )}

                                {detailPhase === 'ready' && !activeDetail && bills.length > 0 && (
                                    <EmptyState
                                        variant="noSelection"
                                        title="Select a bill"
                                        explanation="Choose one of your bills on the right to see its extracted information."
                                    />
                                )}
                            </motion.div>
                        )}
                </div>

                {/* Side column: context that never competes with the journey. */}
                <aside className="flex min-w-0 flex-col gap-6">
                    {activeDetail && <BillDocumentSummary detail={activeDetail} />}
                    <BillHistory
                        bills={bills}
                        selectedId={activeDetail?.bill_id ?? null}
                        onSelect={(id) => {
                            setUploadOpen(false)
                            onSelectBill(id)
                        }}
                        onUpload={() => setUploadOpen(true)}
                    />
                </aside>

                {children}
            </div>
        </div>
    )
}

function PageHeading({ demo = false }) {
    return (
        <div>
            <h1 className="flex flex-wrap items-center gap-2 font-display text-2xl font-semibold text-heading">
                Your electricity bills
                {demo && (
                    // D-071 §S: demo-environment records live in a
                    // physically separate database; the account mode makes
                    // that boundary VISIBLE wherever demo data is shown.
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

/** Detail column content for a non-pipeline moment. */
function BillWorkspaceDetail({ detail, processFailure, onContinue, onUploadAnother }) {
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

    if (['NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', 'PROCESSING_FAILED'].includes(detail.status)) {
        return (
            <BillOutcomeCard
                status={detail.status}
                retry={detail.status === 'PROCESSING_FAILED' && processFailure?.retryable === true}
                onRetry={onContinue}
                onUploadAnother={onUploadAnother}
            />
        )
    }

    if (['EXTRACTED', 'NEEDS_REVIEW'].includes(detail.status)) {
        return <BillReviewPanel detail={detail} />
    }

    if (detail.status === 'VERIFIED') {
        return <BillReviewPanel detail={detail} />
    }

    return null
}
