import { useState } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { RefreshCw, Unlink, Moon, AlertTriangle, Clock, WifiOff } from 'lucide-react'
import { deriveNormalizedStatus } from './solarStatus'

/**
 * SolarConnectionCard (SPMS O4 / O4.7 — docs/10 D-059).
 *
 * Displays the current active solar provider connection status and controls.
 * Strictly separates Provider Connection status from Telemetry Freshness.
 */
export function SolarConnectionCard({
    connection,
    latestReading = null,
    onSync,
    onDisconnect,
    isSyncing = false,
    isDemo = false,
}) {
    const [isDisconnecting, setIsDisconnecting] = useState(false)
    const [confirmDisconnect, setConfirmDisconnect] = useState(false)

    if (!connection || !connection.configured) {
        return null
    }

    const normalizedStatus = deriveNormalizedStatus(connection)

    const renderStatusBadge = () => {
        switch (normalizedStatus) {
            case 'CONNECTED':
                if (isDemo) {
                    return (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-sky-300 bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                            <span className="size-1.5 rounded-full bg-sky-500 animate-pulse" />
                            Simulated (DEMO)
                        </span>
                    )
                }
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                        <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" />
                        Connected
                    </span>
                )
            case 'OFFLINE':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <Moon className="size-3 text-amber-500" />
                        Offline (Night / Standby)
                    </span>
                )
            case 'DEGRADED':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <AlertTriangle className="size-3 text-amber-500" />
                        Degraded
                    </span>
                )
            case 'AUTHENTICATION_ERROR':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                        <AlertTriangle className="size-3 text-rose-500" />
                        Auth Error
                    </span>
                )
            case 'API_ERROR':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                        <AlertTriangle className="size-3 text-rose-500" />
                        API Error
                    </span>
                )
            case 'TIMEOUT':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                        <Clock className="size-3 text-rose-500" />
                        Timeout
                    </span>
                )
            case 'NETWORK_ERROR':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                        <WifiOff className="size-3 text-rose-500" />
                        Network Error
                    </span>
                )
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-medium text-muted">
                        <AlertTriangle className="size-3 text-muted" />
                        {connection.connection_status || 'UNKNOWN'}
                    </span>
                )
        }
    }

    const renderTelemetryFreshnessBadge = () => {
        if (!latestReading) return null
        if (isDemo) {
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-sky-300 bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                    <span className="size-1.5 rounded-full bg-sky-500" />
                    Simulated
                </span>
            )
        }
        const isStale = (latestReading.data_status || '').toUpperCase() === 'STALE'

        if (isStale) {
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <Clock className="size-3 text-amber-500" />
                    Stale
                </span>
            )
        }

        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <span className="size-1.5 rounded-full bg-emerald-500" />
                Live
            </span>
        )
    }

    const handleDisconnect = async () => {
        if (!confirmDisconnect) {
            setConfirmDisconnect(true)
            return
        }
        setIsDisconnecting(true)
        try {
            await onDisconnect()
        } finally {
            setIsDisconnecting(false)
            setConfirmDisconnect(false)
        }
    }

    const formatTimestamp = (iso) => {
        if (!iso) return 'Not yet synced'
        try {
            const d = new Date(iso)
            return d.toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            })
        } catch {
            return iso
        }
    }

    return (
        <Card className="border-border bg-surface">
            <CardHeader className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <CardTitle className="text-base font-semibold text-heading">
                            {isDemo ? 'GoodWe SEMS+ Connection (Simulated Demo)' : 'GoodWe SEMS+ Connection'}
                        </CardTitle>
                        <div className="flex items-center gap-1.5">
                            {renderStatusBadge()}
                        </div>
                        {latestReading && (
                            <div className="flex items-center gap-1.5 ml-1">
                                <span className="text-[11px] font-bold text-slate-800 dark:text-muted">Telemetry:</span>
                                {renderTelemetryFreshnessBadge()}
                            </div>
                        )}
                    </div>
                    <CardDescription className="text-xs text-muted mt-1">
                        {isDemo
                            ? 'Simulated inverter integration delivering demonstration telemetry for active scenario.'
                            : 'Encrypted integration delivering GoodWe inverter telemetry.'}
                    </CardDescription>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={onSync}
                        disabled={isSyncing}
                    >
                        <RefreshCw className={`size-3.5 mr-1.5 ${isSyncing ? 'animate-spin' : ''}`} />
                        {isSyncing ? 'Syncing…' : 'Sync Now'}
                    </Button>

                    <Button
                        variant={confirmDisconnect ? 'destructive' : 'ghost'}
                        size="sm"
                        onClick={handleDisconnect}
                        disabled={isDisconnecting}
                        loading={isDisconnecting}
                    >
                        <Unlink className="size-3.5 mr-1.5" />
                        {confirmDisconnect ? 'Confirm Disconnect?' : 'Disconnect'}
                    </Button>
                </div>
            </CardHeader>

            <CardContent className="pt-2">
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 rounded-lg border border-slate-200 bg-slate-50/70 dark:border-border/60 dark:bg-muted/10 p-4 text-xs">
                    <div>
                        <span className="text-slate-800 dark:text-muted block text-[11px] font-bold dark:font-medium">Station ID</span>
                        <span className="font-mono text-heading text-xs truncate block mt-0.5" title={connection.station_id}>
                            {connection.station_id}
                        </span>
                    </div>

                    <div>
                        <span className="text-slate-800 dark:text-muted block text-[11px] font-bold dark:font-medium">Inverter Serial</span>
                        <span className="font-mono text-heading text-xs truncate block mt-0.5">
                            {connection.inverter_sn || 'Primary Inverter'}
                        </span>
                    </div>

                    <div>
                        <span className="text-slate-800 dark:text-muted block text-[11px] font-bold dark:font-medium">Account</span>
                        <span className="text-heading text-xs truncate block mt-0.5">
                            {connection.account_identifier}
                        </span>
                    </div>

                    <div>
                        <span className="text-slate-800 dark:text-muted block text-[11px] font-bold dark:font-medium">Last Successful Sync</span>
                        <span className="text-heading text-xs truncate block mt-0.5">
                            {formatTimestamp(connection.last_successful_sync_at)}
                        </span>
                    </div>
                </div>

                {connection.last_error_message && (
                    <div className="mt-3 rounded-md border border-rose-500/20 bg-rose-500/10 p-2.5 text-xs text-rose-950 dark:text-rose-200 flex items-center gap-2">
                        <AlertTriangle className="size-3.5 text-rose-500 shrink-0" />
                        <span>Sync warning: {connection.last_error_message}</span>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
