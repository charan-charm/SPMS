import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../../services/api/client'
import { loadServerSession } from '../../services/api/session'
import { getBill, listBills, processBill, uploadBill, verifyBill } from '../../services/api/bills'
import { BillsWorkspace } from '../../features/bills/BillsWorkspace'
import { sortBillsChronologically } from '../../features/bills/presentation'

/**
 * BillsPage — the /bills container (B4).
 *
 * Owns fetching and the upload → process → review orchestration; all
 * rendering lives in BillsWorkspace. The flow uses the ratified
 * endpoints only: B2 intake hands the bill over as PROCESSING, B3's
 * application processing path is then invoked for that bill, and every
 * displayed state comes back from the backend reads — never from the
 * request that produced it, and never from a local guess. After a
 * failure the UI simply re-reads the honest server state; nothing is
 * re-derived or patched in the browser. No polling loop exists: the
 * processing call is the application path, and a still-PROCESSING bill
 * offers an explicit "Continue analysis" the user controls.
 */
export function BillsPage() {
    const [sessionPhase, setSessionPhase] = useState('checking')
    const [accountMode, setAccountMode] = useState('REAL')
    const [bills, setBills] = useState([])
    const [selectedId, setSelectedId] = useState(null)
    const [detail, setDetail] = useState(null)
    const [detailPhase, setDetailPhase] = useState('ready')
    const [pipelineStage, setPipelineStage] = useState(null)
    const [processFailure, setProcessFailure] = useState(null)
    const [duplicateNotice, setDuplicateNotice] = useState(null)
    const [verifying, setVerifying] = useState(false)
    const [importSummary, setImportSummary] = useState(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    const pollTimerRef = useRef(null)
    const pollCountRef = useRef(0)
    const MAX_POLL_ATTEMPTS = 30 // 60s at 2s interval

    const stopPolling = useCallback(() => {
        if (pollTimerRef.current) {
            clearInterval(pollTimerRef.current)
            pollTimerRef.current = null
        }
        pollCountRef.current = 0
    }, [])

    useEffect(() => {
        mountedRef.current = true
        abortRef.current = new AbortController()

        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
            stopPolling()
        }
    }, [stopPolling])

    const signal = () => abortRef.current?.signal

    const isAbort = (err) => err instanceof DOMException && err.name === 'AbortError'

    const handleUnauthorized = useCallback((err) => {
        if (err instanceof ApiError && err.status === 401) {
            setSessionPhase('signedOut')
            return true
        }
        return false
    }, [])

    const openBill = useCallback(
        async (billId) => {
            setSelectedId(billId)
            setDetailPhase('loading')
            try {
                const { data } = await getBill(billId, signal())
                if (!mountedRef.current) return
                setDetail(data.bill)
                setDetailPhase('ready')
            } catch (err) {
                if (!mountedRef.current || isAbort(err)) return
                if (handleUnauthorized(err)) return
                setDetail(null)
                setDetailPhase('error')
            }
        },
        [handleUnauthorized],
    )

    /** Initial active bill: prefer actionable extracted/review bills, then verified, avoiding stale failed unless nothing else. */
    const pickActive = (rows) => {
        const reviewable = rows.find((row) => row.status === 'EXTRACTED' || row.status === 'NEEDS_REVIEW')
        if (reviewable) return reviewable.bill_id
        const processing = rows.find((row) => row.status === 'PROCESSING')
        if (processing) return processing.bill_id
        const verified = rows.find((row) => row.status === 'VERIFIED')
        if (verified) return verified.bill_id
        return rows[0]?.bill_id ?? null
    }

    // Terminal-state aware bounded polling for active processing bills
    useEffect(() => {
        const activeStatus = detail?.status
        const activeId = detail?.bill_id

        const isProcessingState = activeStatus === 'PROCESSING' || activeStatus === 'UPLOADED'

        // If not in active processing or no bill, ensure timer is stopped
        if (!isProcessingState || !activeId) {
            stopPolling()
            return
        }

        // Avoid starting duplicate polling loops for the same bill
        if (pollTimerRef.current) {
            return
        }

        pollCountRef.current = 0
        pollTimerRef.current = setInterval(async () => {
            if (!mountedRef.current) {
                stopPolling()
                return
            }

            pollCountRef.current += 1
            if (pollCountRef.current > MAX_POLL_ATTEMPTS) {
                stopPolling()
                setProcessFailure({ retryable: true })
                return
            }

            try {
                const { data } = await getBill(activeId, signal())
                if (!mountedRef.current) return

                const fetchedBill = data?.bill
                if (!fetchedBill || fetchedBill.bill_id !== activeId) return

                const newStatus = fetchedBill.status
                const isTerminal = [
                    'EXTRACTED',
                    'NEEDS_REVIEW',
                    'VERIFIED',
                    'NOT_A_BILL',
                    'UNREADABLE',
                    'UNCERTAIN',
                    'PROCESSING_FAILED',
                ].includes(newStatus)

                if (isTerminal) {
                    stopPolling()
                    setDetail(fetchedBill)
                    setPipelineStage(null)
                    void listBills(signal()).then(({ data: listData }) => {
                        if (mountedRef.current) {
                            setBills(sortBillsChronologically(listData.bills ?? []))
                        }
                    })
                }
            } catch (err) {
                if (isAbort(err) || !mountedRef.current) return
                if (handleUnauthorized(err)) {
                    stopPolling()
                    return
                }
            }
        }, 2000)

        return () => {
            stopPolling()
        }
    }, [detail?.bill_id, detail?.status, handleUnauthorized, stopPolling])

    const refreshList = useCallback(
        async (preferId = undefined) => {
            try {
                const { data } = await listBills(signal())
                if (!mountedRef.current) return null
                const rows = sortBillsChronologically(data.bills ?? [])
                setBills(rows)
                const target = preferId ?? pickActive(rows)
                if (target) {
                    await openBill(target)
                } else {
                    setSelectedId(null)
                    setDetail(null)
                    setDetailPhase('ready')
                }
                return rows
            } catch (err) {
                if (!mountedRef.current || isAbort(err)) return null
                if (handleUnauthorized(err)) return null
                setSessionPhase('error')
                return null
            }
        },
        [handleUnauthorized, openBill],
    )

    /**
     * Session bootstrap: GET /auth/session first (the ratified token
     * handoff of D-052), then the list. State updates arrive in
     * promise callbacks — the effect only starts the flow.
     */
    const bootstrap = useCallback(() => {
        loadServerSession(signal())
            .then((session) => {
                if (!mountedRef.current) return
                if (!session.authenticated) {
                    setSessionPhase('signedOut')
                    return
                }
                // D-071: the backend-provided account mode (never a
                // client claim) labels whether this workspace shows DEMO
                // records; the database boundary itself is server-side.
                setAccountMode(session.account?.mode ?? 'REAL')
                setSessionPhase('ready')

                return refreshList()
            })
            .catch((err) => {
                if (!mountedRef.current || isAbort(err)) return
                setSessionPhase('error')
            })
    }, [refreshList])

    useEffect(() => {
        bootstrap()
    }, [bootstrap])

    /**
     * Runs B3's application processing path for one bill and lands on
     * the honest server state whatever happens (D-069: provider trouble
     * keeps the bill PROCESSING and retryable; a vanished document ends
     * it PROCESSING_FAILED server-side).
     */
    const runProcess = useCallback(
        async (billId) => {
            setPipelineStage('processing')
            setProcessFailure(null)
            setImportSummary(null)
            let processedSummary = null
            try {
                const { data } = await processBill(billId, signal())
                // APSPDCL XLS imports return import_summary with all monthly rows.
                if (data?.import_summary) {
                    processedSummary = data.import_summary
                }
            } catch (err) {
                if (!mountedRef.current || isAbort(err)) return
                if (handleUnauthorized(err)) return
                if (err instanceof ApiError && err.code === 'INVALID_BILL_STATE') {
                    // Already processed (repeat click / race) — the gate
                    // held. Read the state the server actually has.
                } else if (
                    err instanceof ApiError &&
                    (err.code === 'DUPLICATE_BILL_PERIOD' || err.code === 'DUPLICATE_BILL_FILE')
                ) {
                    const existingBillId = err.context?.existing_bill_id || null
                    const monthLabel = err.context?.month_label || null
                    setDuplicateNotice({
                        type: err.context?.duplicate_type || 'PERIOD',
                        title: monthLabel ? `${monthLabel} bill already exists` : 'Bill already exists',
                        message: monthLabel
                            ? `${monthLabel} is already recorded for your household.`
                            : (err.message || 'A bill for this period is already recorded for your household.'),
                        existingBillId,
                        monthLabel,
                    })
                    if (existingBillId) {
                        await refreshList(existingBillId)
                    }
                } else if (
                    err instanceof ApiError &&
                    (err.code === 'EXTRACTION_FAILED' || err.code === 'PERSISTENCE_FAILED')
                ) {
                    setProcessFailure({ retryable: true, reason: err.context?.reason ?? null })
                } else if (err instanceof ApiError) {
                    // DOCUMENT_UNAVAILABLE (bill is now PROCESSING_FAILED
                    // server-side) and every stable error code: re-read.
                    if (!['DOCUMENT_UNAVAILABLE', 'NOT_FOUND'].includes(err.code)) {
                        setProcessFailure({ retryable: true, reason: err.context?.reason ?? null })
                    }
                } else {
                    setProcessFailure({ retryable: true })
                }
            }
            if (!mountedRef.current) return
            if (processedSummary) {
                // XLS import: refresh without preferred id so all new historical
                // bills appear and pickActive selects the newest unverified one.
                await refreshList()
                setImportSummary(processedSummary)
            } else {
                await refreshList(billId)
            }
            if (!mountedRef.current) return
            setPipelineStage(null)
        },
        [handleUnauthorized, refreshList],
    )

    const handleUpload = useCallback(
        async (file) => {
            setPipelineStage('uploading')
            setProcessFailure(null)
            setImportSummary(null)
            setDuplicateNotice(null)
            try {
                const { data } = await uploadBill(file, signal())
                if (!mountedRef.current) return { uploaded: true }
                await refreshList(data.bill_id)
                if (!mountedRef.current) return { uploaded: true }
                setPipelineStage('processing')
                setProcessFailure(null)
                await runProcess(data.bill_id)
                return { uploaded: true }
            } catch (err) {
                if (!mountedRef.current) return { uploaded: true }
                setPipelineStage(null)
                if (isAbort(err)) return { uploaded: false, error: 'The upload was cancelled.' }
                if (handleUnauthorized(err)) {
                    return { uploaded: false, error: 'Your session ended. Please sign in again and retry.' }
                }
                if (err instanceof ApiError && err.code === 'CSRF_REJECTED') {
                    // Re-bootstrap the synchronizer token so the next
                    // attempt works; the session itself is still valid.
                    void loadServerSession(signal())

                    return { uploaded: false, error: 'Your session refreshed — please try the upload once more.' }
                }
                if (
                    err instanceof ApiError &&
                    (err.code === 'DUPLICATE_BILL_FILE' || err.code === 'DUPLICATE_BILL_PERIOD')
                ) {
                    const existingBillId = err.context?.existing_bill_id || null
                    const monthLabel = err.context?.month_label || null
                    setDuplicateNotice({
                        type: err.context?.duplicate_type || 'FILE',
                        title: monthLabel ? `${monthLabel} bill already uploaded` : 'Bill already uploaded',
                        message: monthLabel
                            ? `${monthLabel} is already recorded for your household.`
                            : (err.message || 'This bill file is already recorded for your household.'),
                        existingBillId,
                        monthLabel,
                    })
                    if (existingBillId) {
                        await refreshList(existingBillId)
                    }
                    return { uploaded: false, duplicate: true }
                }
                return { uploaded: false, error: uploadProblemMessage(err) }
            }
        },
        [handleUnauthorized, refreshList, runProcess],
    )

    const handleSelectBill = useCallback(
        (billId) => {
            if (billId === selectedId) return
            stopPolling()
            setProcessFailure(null)
            setDuplicateNotice(null)
            void openBill(billId)
        },
        [openBill, selectedId, stopPolling],
    )

    const handleRetryProcess = useCallback(() => {
        if (selectedId) {
            void runProcess(selectedId)
        }
    }, [runProcess, selectedId])

    const handleRetryDetail = useCallback(() => {
        if (selectedId) {
            void openBill(selectedId)
        }
    }, [openBill, selectedId])

    const handleVerify = useCallback(
        async (billId, { acceptGap = false } = {}) => {
            setVerifying(true)
            try {
                await verifyBill(billId, { acceptGap }, signal())
                await refreshList(billId)
            } catch (err) {
                if (!mountedRef.current || isAbort(err)) return
                if (handleUnauthorized(err)) return
                await refreshList(billId)
            } finally {
                if (mountedRef.current) {
                    setVerifying(false)
                }
            }
        },
        [handleUnauthorized, refreshList],
    )

    return (
        <BillsWorkspace
            sessionPhase={sessionPhase}
            accountMode={accountMode}
            bills={bills}
            detail={detail}
            detailPhase={detailPhase}
            pipelineStage={pipelineStage}
            processFailure={processFailure}
            duplicateNotice={duplicateNotice}
            onDismissDuplicateNotice={() => setDuplicateNotice(null)}
            importSummary={importSummary}
            onUpload={handleUpload}
            onSelectBill={handleSelectBill}
            onRetryProcess={handleRetryProcess}
            onRetryDetail={handleRetryDetail}
            onRetrySession={bootstrap}
            onVerify={handleVerify}
            verifying={verifying}
        />
    )
}

/** Safe, human messages for B2 upload rejections (never raw internals). */
function uploadProblemMessage(err) {
    if (err instanceof ApiError) {
        if (err.code === 'TIMEOUT' || err.code === 'NETWORK_ERROR') {
            return 'The upload could not reach SPMS. Check your connection and try again.'
        }
        // B2 codes (UNSUPPORTED_FILE_TYPE, FILE_TOO_LARGE, INVALID_FILE_SIGNATURE,
        // INVALID_UPLOAD, EMPTY_FILE, STORAGE_FAILED, UPLOAD_FAILED,
        // VALIDATION_ERROR) all carry deliberate, user-safe server messages.
        return err.message
    }
    return 'The upload failed. Please try again.'
}
