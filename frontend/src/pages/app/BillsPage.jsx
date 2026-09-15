import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../../services/api/client'
import { loadServerSession } from '../../services/api/session'
import { getBill, listBills, processBill, uploadBill } from '../../services/api/bills'
import { BillsWorkspace } from '../../features/bills/BillsWorkspace'

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

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    useEffect(() => {
        mountedRef.current = true
        abortRef.current = new AbortController()

        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [])

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

    /** Newest non-verified bill is the active one; otherwise the newest overall. */
    const pickActive = (rows) => {
        const pending = rows.find((row) => row.status !== 'VERIFIED')
        return (pending ?? rows[0])?.bill_id ?? null
    }

    const refreshList = useCallback(
        async (preferId = undefined) => {
            try {
                const { data } = await listBills(signal())
                if (!mountedRef.current) return null
                const rows = data.bills ?? []
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
            try {
                await processBill(billId, signal())
            } catch (err) {
                if (!mountedRef.current || isAbort(err)) return
                if (handleUnauthorized(err)) return
                if (err instanceof ApiError && err.code === 'INVALID_BILL_STATE') {
                    // Already processed (repeat click / race) — the gate
                    // held. Read the state the server actually has.
                } else if (
                    err instanceof ApiError &&
                    (err.code === 'EXTRACTION_FAILED' || err.code === 'PERSISTENCE_FAILED')
                ) {
                    setProcessFailure({ retryable: true })
                } else if (err instanceof ApiError) {
                    // DOCUMENT_UNAVAILABLE (bill is now PROCESSING_FAILED
                    // server-side) and every stable error code: re-read.
                    if (!['DOCUMENT_UNAVAILABLE', 'NOT_FOUND'].includes(err.code)) {
                        setProcessFailure({ retryable: true })
                    }
                } else {
                    setProcessFailure({ retryable: true })
                }
            }
            if (!mountedRef.current) return
            await refreshList(billId)
            if (!mountedRef.current) return
            setPipelineStage(null)
        },
        [handleUnauthorized, refreshList],
    )

    const handleUpload = useCallback(
        async (file) => {
            setPipelineStage('uploading')
            setProcessFailure(null)
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
                return { uploaded: false, error: uploadProblemMessage(err) }
            }
        },
        [handleUnauthorized, refreshList, runProcess],
    )

    const handleSelectBill = useCallback(
        (billId) => {
            if (billId === selectedId) return
            setProcessFailure(null)
            void openBill(billId)
        },
        [openBill, selectedId],
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

    return (
        <BillsWorkspace
            sessionPhase={sessionPhase}
            accountMode={accountMode}
            bills={bills}
            detail={detail}
            detailPhase={detailPhase}
            pipelineStage={pipelineStage}
            processFailure={processFailure}
            onUpload={handleUpload}
            onSelectBill={handleSelectBill}
            onRetryProcess={handleRetryProcess}
            onRetryDetail={handleRetryDetail}
            onRetrySession={bootstrap}
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
