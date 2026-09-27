import { useEffect, useState, useCallback, useRef } from 'react'
import { reportsApi } from '../../services/api/reports'
import {
    FileSpreadsheet,
    Download,
    LoaderCircle,
    AlertCircle,
    CheckCircle2,
    Calendar,
    Table,
    Sun,
    Receipt,
    RefreshCw,
} from 'lucide-react'

export function ReportsPage() {
    const [loading, setLoading] = useState(true)
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState(null)
    const [downloadingReport, setDownloadingReport] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null)
    const [downloadError, setDownloadError] = useState(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    const loadSummary = useCallback(async (signal) => {
        try {
            setError(null)
            const resp = await reportsApi.getSummary(signal)
            if (!mountedRef.current) return
            setSummary(resp.reports)
        } catch (err) {
            if (err.name !== 'AbortError' && mountedRef.current) {
                setError(err.message || 'Failed to load reports summary.')
            }
        } finally {
            if (mountedRef.current) {
                setLoading(false)
            }
        }
    }, [])

    const handleRefresh = async () => {
        setLoading(true)
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        await loadSummary(abortRef.current.signal)
    }

    useEffect(() => {
        mountedRef.current = true
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadSummary(abortRef.current.signal)
        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [loadSummary])

    const handleDownloadBills = async () => {
        setDownloadingReport('bills')
        setDownloadError(null)
        setSuccessMessage(null)
        try {
            const { filename } = await reportsApi.downloadBills()
            setSuccessMessage(`Successfully downloaded ${filename}`)
        } catch (err) {
            setDownloadError(err.message || 'Failed to download billing report.')
        } finally {
            setDownloadingReport(null)
        }
    }

    const handleDownloadSolar = async () => {
        setDownloadingReport('solar')
        setDownloadError(null)
        setSuccessMessage(null)
        try {
            const { filename } = await reportsApi.downloadSolar()
            setSuccessMessage(`Successfully downloaded ${filename}`)
        } catch (err) {
            setDownloadError(err.message || 'Failed to download solar telemetry report.')
        } finally {
            setDownloadingReport(null)
        }
    }

    if (loading) {
        return (
            <div className="flex min-h-[400px] items-center justify-center">
                <div className="flex items-center gap-2.5 text-sm text-muted">
                    <LoaderCircle className="size-5 animate-spin text-primary" />
                    Loading available reports...
                </div>
            </div>
        )
    }

    const billsCount = summary?.bills?.count ?? 0
    const solarCount = summary?.solar?.count ?? 0

    return (
        <div className="max-w-5xl mx-auto space-y-6 px-4 py-8">
            {/* Header */}
            <div className="flex items-start justify-between">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 mt-0.5">
                        <FileSpreadsheet className="size-5" />
                    </div>
                    <div>
                        <h1 className="font-display text-2xl font-bold text-heading tracking-tight leading-tight">
                            Reports
                        </h1>
                        <p className="text-sm text-muted mt-1 leading-snug">
                            Export verified billing statements and high-precision solar telemetry for your household.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={handleRefresh}
                    title="Refresh data counts"
                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-muted hover:text-heading bg-panel/80 hover:bg-panel border border-border/60 rounded-md transition-colors"
                >
                    <RefreshCw className="size-3.5" />
                    Refresh
                </button>
            </div>

            {/* Error banner */}
            {error && (
                <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-950 dark:text-rose-200 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <AlertCircle className="size-4 text-rose-500 shrink-0" />
                        <span>{error}</span>
                    </div>
                    <button
                        type="button"
                        onClick={loadSummary}
                        className="underline hover:no-underline font-medium text-rose-600 dark:text-rose-400"
                    >
                        Try again
                    </button>
                </div>
            )}

            {/* Success feedback toast/banner */}
            {successMessage && (
                <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-xs text-emerald-950 dark:text-emerald-200 flex items-center justify-between gap-2.5">
                    <div className="flex items-center gap-2.5">
                        <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                        <span>{successMessage}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSuccessMessage(null)}
                        className="text-xs text-muted hover:text-heading"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* Download error feedback */}
            {downloadError && (
                <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-950 dark:text-rose-200 flex items-center justify-between gap-2.5">
                    <div className="flex items-center gap-2.5">
                        <AlertCircle className="size-4 text-rose-500 shrink-0" />
                        <span>{downloadError}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setDownloadError(null)}
                        className="text-xs text-muted hover:text-heading"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* Reports Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* 1. Verified Billing History */}
                <div className="rounded-xl border border-border/60 bg-panel/60 p-6 flex flex-col justify-between shadow-sm space-y-5">
                    <div className="space-y-4">
                        <div className="flex items-start justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex size-9 items-center justify-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400">
                                    <Receipt className="size-4.5" />
                                </div>
                                <div>
                                    <h2 className="text-base font-semibold text-heading">
                                        Verified Billing History
                                    </h2>
                                    <p className="text-xs text-muted">
                                        APSPDCL electricity statement ledger
                                    </p>
                                </div>
                            </div>

                            <span className="text-[11px] font-medium px-2 py-0.5 rounded-full border border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400">
                                RFC 4180 CSV
                            </span>
                        </div>

                        <p className="text-xs text-muted leading-relaxed">
                            Consolidated utility statements with normalized billing periods, verified consumption (kWh), solar feed-in credits, tariff breakdown, fixed charges, and verification lineage.
                        </p>

                        <div className="rounded-lg border border-border/40 bg-surface/50 p-3.5 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-muted flex items-center gap-1.5">
                                    <Table className="size-3.5 text-muted" /> Available Records:
                                </span>
                                <span className="font-medium text-heading">
                                    {billsCount > 0 ? `${billsCount} verified statement${billsCount > 1 ? 's' : ''}` : '0 statements available'}
                                </span>
                            </div>

                            {summary?.bills?.last_date && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted flex items-center gap-1.5">
                                        <Calendar className="size-3.5 text-muted" /> Date Coverage:
                                    </span>
                                    <span className="font-medium text-heading">
                                        {summary.bills.first_date ? `${summary.bills.first_date} → ` : ''}{summary.bills.last_date}
                                    </span>
                                </div>
                            )}

                            {billsCount === 0 && (
                                <p className="text-[11px] text-amber-600 dark:text-amber-400 pt-1">
                                    No verified statements yet. Upload an APSPDCL statement in the Bills workspace to populate.
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <span className="text-[11px] font-medium text-muted uppercase tracking-wider">
                                Included Fields:
                            </span>
                            <div className="flex flex-wrap gap-1 text-[10px] text-muted">
                                {[
                                    'billing_period_start',
                                    'billing_period_end',
                                    'service_number',
                                    'consumption_kwh',
                                    'solar_generation_kwh',
                                    'net_units',
                                    'bill_amount',
                                    'energy_charge',
                                    'fixed_charge',
                                    'other_charges',
                                    'verification_status',
                                ].map((col) => (
                                    <span
                                        key={col}
                                        className="px-1.5 py-0.5 rounded bg-surface/80 border border-border/40 font-mono text-[10px]"
                                    >
                                        {col}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="pt-2">
                        <button
                            type="button"
                            onClick={handleDownloadBills}
                            disabled={downloadingReport === 'bills'}
                            className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-xs font-semibold text-white bg-primary hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-all"
                        >
                            {downloadingReport === 'bills' ? (
                                <>
                                    <LoaderCircle className="size-3.5 animate-spin" />
                                    <span>Preparing CSV...</span>
                                </>
                            ) : (
                                <>
                                    <Download className="size-3.5" />
                                    <span>Download Billing History (CSV)</span>
                                </>
                            )}
                        </button>
                    </div>
                </div>

                {/* 2. GoodWe Solar Telemetry */}
                <div className="rounded-xl border border-border/60 bg-panel/60 p-6 flex flex-col justify-between shadow-sm space-y-5">
                    <div className="space-y-4">
                        <div className="flex items-start justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex size-9 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                    <Sun className="size-4.5" />
                                </div>
                                <div>
                                    <h2 className="text-base font-semibold text-heading">
                                        GoodWe Solar Telemetry
                                    </h2>
                                    <p className="text-xs text-muted">
                                        Inverter generation & interval readings
                                    </p>
                                </div>
                            </div>

                            <span className="text-[11px] font-medium px-2 py-0.5 rounded-full border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                RFC 4180 CSV
                            </span>
                        </div>

                        <p className="text-xs text-muted leading-relaxed">
                            High-precision interval telemetry ingested directly from GoodWe SEMS. Includes active power (W), daily yield, total yield, AC electrical parameters, and inverter operating status.
                        </p>

                        <div className="rounded-lg border border-border/40 bg-surface/50 p-3.5 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-muted flex items-center gap-1.5">
                                    <Table className="size-3.5 text-muted" /> Available Readings:
                                </span>
                                <span className="font-medium text-heading">
                                    {solarCount > 0 ? `${solarCount} telemetry interval${solarCount > 1 ? 's' : ''}` : '0 readings recorded'}
                                </span>
                            </div>

                            {summary?.solar?.last_date && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted flex items-center gap-1.5">
                                        <Calendar className="size-3.5 text-muted" /> Latest Reading:
                                    </span>
                                    <span className="font-medium text-heading">
                                        {summary.solar.last_date}
                                    </span>
                                </div>
                            )}

                            {solarCount === 0 && (
                                <p className="text-[11px] text-amber-600 dark:text-amber-400 pt-1">
                                    No telemetry recorded yet. Connect your GoodWe inverter in the Solar workspace to start telemetry recording.
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <span className="text-[11px] font-medium text-muted uppercase tracking-wider">
                                Included Fields:
                            </span>
                            <div className="flex flex-wrap gap-1 text-[10px] text-muted">
                                {[
                                    'measurement_time',
                                    'power',
                                    'ac_voltage',
                                    'ac_current',
                                    'frequency',
                                    'pv_power',
                                    'temperature',
                                    'daily_generation_kwh',
                                    'total_generation_kwh',
                                    'inverter_status',
                                    'data_status',
                                ].map((col) => (
                                    <span
                                        key={col}
                                        className="px-1.5 py-0.5 rounded bg-surface/80 border border-border/40 font-mono text-[10px]"
                                    >
                                        {col}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="pt-2">
                        <button
                            type="button"
                            onClick={handleDownloadSolar}
                            disabled={downloadingReport === 'solar'}
                            className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-xs font-semibold text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-all"
                        >
                            {downloadingReport === 'solar' ? (
                                <>
                                    <LoaderCircle className="size-3.5 animate-spin" />
                                    <span>Preparing CSV...</span>
                                </>
                            ) : (
                                <>
                                    <Download className="size-3.5" />
                                    <span>Download Telemetry (CSV)</span>
                                </>
                            )}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
