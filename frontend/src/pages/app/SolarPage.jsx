import { useCallback, useEffect, useRef, useState } from 'react'
import { motion, AnimatePresence } from 'motion/react'
import { solarApi } from '../../services/api/solar'
import { useSolar } from '../../features/solar/useSolar'
import { useAuth } from '../../features/auth/useAuth'
import { SolarConnectForm } from '../../features/solar/SolarConnectForm'
import { SolarConnectionCard } from '../../features/solar/SolarConnectionCard'
import { SolarLiveReadingCard } from '../../features/solar/SolarLiveReadingCard'
import { SolarPowerCurve } from '../../features/solar/SolarPowerCurve'
import { SolarHistoryTable } from '../../features/solar/SolarHistoryTable'
import { SPMSLoadingState } from '../../components/feedback/SPMSLoadingState'
import { Sun, LoaderCircle, AlertCircle } from 'lucide-react'

/**
 * GoodWe connection status messages — user-facing, no backend internals.
 * Displayed by SPMSLoadingState's statusSequence cycle while the real
 * connect + sync operation is in flight.
 */
const CONNECT_STATUS_SEQUENCE = [
    'Connecting to GoodWe',
    'Discovering your solar station',
    'Finding your inverter',
    'Syncing solar data',
    'Preparing your solar telemetry',
]

/**
 * SolarPage — the /solar container (SPMS O4.5 / O4.7 — docs/10 D-059, D-060, D-061).
 *
 * Owns fetching and live telemetry state management for the household's solar
 * integration. Inverts rendering between SolarConnectForm (when unconfigured)
 * and live telemetry panels (when connected).
 *
 * Synchronizes authoritative solar connection status with the global app header
 * via the shared useSolar context.
 */
export function SolarPage() {
    const { updateConnection } = useSolar()
    const { account } = useAuth()
    const isDemo = account?.mode === 'DEMO_TEST'
    const [loading, setLoading] = useState(true)
    const [connection, setConnection] = useState(null)
    const [latestReading, setLatestReading] = useState(null)
    const [history, setHistory] = useState([])
    const [availableDays, setAvailableDays] = useState([])
    const [selectedDate, setSelectedDate] = useState('')
    const [dayReadingsCache, setDayReadingsCache] = useState({})
    const [isSyncing, setIsSyncing] = useState(false)
    const [error, setError] = useState(null)

    // Connection loading state — driven by the real connect operation only.
    // `isConnecting` shows the SPMS loading experience; `connectingExited`
    // tracks when the departure animation has completed so we reveal the UI.
    const [isConnecting, setIsConnecting] = useState(false)
    const [connectingExited, setConnectingExited] = useState(true)

    // Pending data that will be committed once the loading orb departs.
    const pendingStateRef = useRef(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    const loadData = useCallback(async () => {
        try {
            abortRef.current?.abort()
            abortRef.current = new AbortController()
            const signal = abortRef.current.signal

            const connResp = await solarApi.getConnection(signal)
            if (!mountedRef.current) return

            setError(null)
            const conn = connResp.connection
            setConnection(conn)
            updateConnection(conn)

            if (conn && conn.configured) {
                const [latestResp, historyResp, daysResp] = await Promise.all([
                    solarApi.getLatest(signal).catch(() => ({ latest: null })),
                    solarApi.getHistory({ limit: 500 }, signal).catch(() => ({ history: [] })),
                    solarApi.getDays(signal).catch(() => ({ days: [] })),
                ])

                if (!mountedRef.current) return
                setLatestReading(latestResp.latest)
                setHistory(historyResp.history || [])

                const days = daysResp.days || []
                setAvailableDays(days)
                if (days.length > 0) {
                    const todayDay = days.find((d) => d.is_today)
                    const defaultDate = todayDay ? todayDay.date : days[0].date
                    setSelectedDate((prev) => prev || defaultDate)
                }
            } else {
                setLatestReading(null)
                setHistory([])
                setAvailableDays([])
                setSelectedDate('')
            }
        } catch (err) {
            if (err.name !== 'AbortError' && mountedRef.current) {
                setError(err.message || 'Failed to load solar connection status.')
            }
        } finally {
            if (mountedRef.current) {
                setLoading(false)
            }
        }
    }, [updateConnection])

    const handleSelectDate = useCallback(async (dateStr) => {
        if (!dateStr) return
        setSelectedDate(dateStr)

        if (dayReadingsCache[dateStr]) {
            return
        }

        try {
            const resp = await solarApi.getHistory({ date: dateStr })
            if (resp?.history && mountedRef.current) {
                setDayReadingsCache((prev) => ({
                    ...prev,
                    [dateStr]: resp.history,
                }))
            }
        } catch (err) {
            console.error(`Failed to load readings for date ${dateStr}:`, err)
        }
    }, [dayReadingsCache])

    useEffect(() => {
        mountedRef.current = true
        let active = true

        const init = async () => {
            if (active) {
                await loadData()
            }
        }
        init()

        return () => {
            active = false
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [loadData])

    const handleSync = async () => {
        setIsSyncing(true)
        setError(null)
        try {
            const resp = await solarApi.syncTelemetry()
            if (resp.latest) {
                setLatestReading(resp.latest)
            }
            const [hist, daysResp] = await Promise.all([
                solarApi.getHistory({ limit: 500 }),
                solarApi.getDays(),
            ])
            setHistory(hist.history || [])
            setAvailableDays(daysResp.days || [])

            const connResp = await solarApi.getConnection()
            setConnection(connResp.connection)
            updateConnection(connResp.connection)
        } catch (err) {
            setError(err.message || 'Failed to sync solar telemetry.')
            try {
                const connResp = await solarApi.getConnection()
                setConnection(connResp.connection)
                updateConnection(connResp.connection)
            } catch {
                // Ignore secondary connection fetch failure
            }
        } finally {
            setIsSyncing(false)
        }
    }

    const handleDisconnect = async () => {
        try {
            await solarApi.disconnect()
            const disconnectedState = { configured: false }
            setConnection(disconnectedState)
            updateConnection(disconnectedState)
            setLatestReading(null)
            setHistory([])
        } catch (err) {
            setError(err.message || 'Failed to disconnect solar integration.')
        }
    }


    /**
     * Called by SolarConnectForm when the user clicks Connect Inverter.
     * Receives a Promise that resolves with { connection } on success, or
     * rejects / resolves with { multipleStations: true, stations } when
     * discovery requires selection.
     *
     * The loading orb is active until the promise settles. On success, the
     * orb departs and the data is revealed via onExited. On failure / multi-
     * station, the loading stops immediately so the form can display the result.
     */
    const handleConnecting = useCallback((connectPromise) => {
        if (isConnecting) return // prevent duplicate

        pendingStateRef.current = null
        setConnectingExited(false)
        setIsConnecting(true)

        connectPromise.then(
            (result) => {
                if (!mountedRef.current) return
                // Store the resolved data; reveal it once the orb finishes departing.
                pendingStateRef.current = result
                setIsConnecting(false)
            },
            () => {
                // Error or multi-station: stop loading immediately so form is visible.
                if (!mountedRef.current) return
                setIsConnecting(false)
                setConnectingExited(true)
            }
        )
    }, [isConnecting])

    /**
     * Called by SPMSLoadingState when the departure animation fully completes.
     * Now it is safe to commit the pending connection data and reveal the UI.
     */
    const handleLoadingExited = useCallback(() => {
        setConnectingExited(true)
        const pending = pendingStateRef.current
        if (!pending) return

        if (pending.connection) {
            setConnection(pending.connection)
            updateConnection(pending.connection)
            // Kick off a background data load to fetch readings.
            loadData()
        }
    }, [loadData, updateConnection])

    // While connecting OR while the departure animation is still running,
    // the form should not be visible.
    const showConnectionLoading = isConnecting || !connectingExited

    if (loading) {
        return (
            <div className="flex min-h-[400px] items-center justify-center">
                <div className="flex items-center gap-2.5 text-sm text-muted">
                    <LoaderCircle className="size-5 animate-spin text-primary" />
                    Loading solar telemetry...
                </div>
            </div>
        )
    }

    return (
        <div className="max-w-5xl mx-auto space-y-6 px-4 py-8">
            {/* ── Page Header ────────────────────────────────────────────── */}
            <div>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 mt-0.5">
                            <Sun className="size-5" />
                        </div>
                        <div>
                            <h1 className="font-display text-2xl font-bold text-heading tracking-tight leading-tight">
                                Solar Telemetry
                            </h1>
                            <p className="text-sm text-muted mt-1 leading-snug">
                                High-precision GoodWe inverter integration and live rooftop generation.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Page-level error ────────────────────────────────────────── */}
            {error && (
                <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-950 dark:text-rose-200 flex items-center gap-2.5">
                    <AlertCircle className="size-4 text-rose-500 shrink-0" />
                    <span>{error}</span>
                </div>
            )}

            {/* ── Main Content Area ────────────────────────────────────────── */}
            {!connection || !connection.configured ? (
                <div className="relative py-4">
                    {isDemo && (
                        <div className="mb-4 rounded-xl border border-sky-500/30 bg-sky-500/10 p-4 text-sky-950 dark:text-sky-200 flex items-start gap-3">
                            <Sun className="size-5 text-sky-600 dark:text-sky-400 shrink-0 mt-0.5" />
                            <div>
                                <div className="font-semibold text-xs uppercase tracking-wider">DEMO Mode Solar Notice</div>
                                <div className="text-xs text-sky-800 dark:text-sky-300 mt-0.5 leading-relaxed">
                                    Pre-seeded solar curves and intelligence are available via the scenario selector in the header. To connect physical hardware, enter your GoodWe SEMS+ portal credentials below.
                                </div>
                            </div>
                        </div>
                    )}
                    {/* SolarConnectForm is ALWAYS mounted so its internal state
                        (discovered stations, form field values) survives through
                        the loading lifecycle. The loading overlay is layered above
                        it. aria-hidden during loading removes it from the a11y tree
                        and prevents interaction. */}
                    <div
                        aria-hidden={showConnectionLoading || undefined}
                        style={showConnectionLoading ? { visibility: 'hidden' } : undefined}
                    >
                        <SolarConnectForm
                            onConnected={(newConn) => {
                                setConnection(newConn)
                                loadData()
                            }}
                            onConnecting={handleConnecting}
                        />
                    </div>

                    {/* SPMS loading experience — absolutely positioned above the form
                        so the form's DOM (and React state) is never destroyed during
                        the connection/discovery flow. */}
                    <AnimatePresence>
                        {showConnectionLoading && (
                            <motion.div
                                key="solar-connecting-overlay"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.2 }}
                                className="absolute inset-0 flex items-center justify-center min-h-[480px]"
                                data-testid="solar-connecting-overlay"
                            >
                                <SPMSLoadingState
                                    title="Connecting your solar system"
                                    subtitle="Establishing your GoodWe inverter integration"
                                    status={CONNECT_STATUS_SEQUENCE[0]}
                                    statusSequence={CONNECT_STATUS_SEQUENCE}
                                    isLoading={isConnecting}
                                    onExited={handleLoadingExited}
                                    className="w-full"
                                />
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            ) : (
                <div className="space-y-6">
                    {isDemo && (
                        <div className="rounded-xl border border-sky-500/30 bg-sky-500/10 p-4 text-sky-950 dark:text-sky-200 flex items-start gap-3">
                            <Sun className="size-5 text-sky-600 dark:text-sky-400 shrink-0 mt-0.5" />
                            <div>
                                <div className="font-semibold text-sm">SIMULATED SOLAR TELEMETRY (DEMO)</div>
                                <div className="text-xs text-sky-800 dark:text-sky-300 mt-0.5 leading-relaxed">
                                    Demonstration telemetry generated for the active residential scenario. No physical inverter connection is required to inspect generation curves, energy balances, or recommendations.
                                </div>
                            </div>
                        </div>
                    )}

                    <SolarConnectionCard
                        connection={connection}
                        latestReading={latestReading}
                        onSync={handleSync}
                        onDisconnect={handleDisconnect}
                        isSyncing={isSyncing}
                        isDemo={isDemo}
                    />

                    <SolarLiveReadingCard reading={latestReading} />

                    <SolarPowerCurve
                        history={history}
                        readings={dayReadingsCache[selectedDate] || null}
                        availableDays={availableDays}
                        selectedDate={selectedDate}
                        onSelectDate={handleSelectDate}
                    />

                    <SolarHistoryTable
                        days={availableDays}
                        history={history}
                        selectedDate={selectedDate}
                        onSelectDate={handleSelectDate}
                    />
                </div>
            )}
        </div>
    )
}
