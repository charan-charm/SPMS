import { useEffect, useState, useCallback, useRef } from 'react'
import { reportsApi } from '../../services/api/reports'
import { ReportMonthSelector } from '../../features/reports/ReportMonthSelector'
import { useAuth } from '../../features/auth/useAuth'
import {
    FileSpreadsheet,
    FileText,
    Download,
    LoaderCircle,
    AlertCircle,
    CheckCircle2,
    Sun,
    Zap,
    Receipt,
    ShieldCheck,
    Clock,
    RefreshCw,
    TrendingUp,
    TrendingDown,
    Info,
} from 'lucide-react'
import {
    ResponsiveContainer,
    ComposedChart,
    BarChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
    Legend,
} from 'recharts'
import { ChartContainer } from '../../components/data-display/ChartContainer'

/**
 * Custom Tooltip for Daily Trends Chart.
 * Opaque solid background with no backdrop-blur to eliminate GPU compositing artifacts.
 */
function DailyTrendTooltip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) return null

    const dataPoint = payload[0]?.payload || {}
    const solar = dataPoint.solar_generation_kwh
    const demand = dataPoint.estimated_demand_kwh
    const balance = dataPoint.potential_balance_kwh

    return (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3 shadow-xl text-xs space-y-1.5 min-w-[170px]">
            <div className="font-semibold text-heading border-b border-border/40 pb-1 flex items-center justify-between">
                <span>{label}</span>
                <span className="text-[10px] font-medium text-muted">Daily Summary</span>
            </div>

            <div className="space-y-1 pt-0.5">
                {solar !== null && solar !== undefined && (
                    <div className="flex items-center justify-between gap-3 text-amber-500 font-medium">
                        <span>Solar Generation:</span>
                        <span className="font-mono">{Number(solar).toFixed(2)} kWh</span>
                    </div>
                )}
                {demand !== null && demand !== undefined && (
                    <div className="flex items-center justify-between gap-3 text-sky-500 font-medium">
                        <span>Estimated Demand:</span>
                        <span className="font-mono">{Number(demand).toFixed(2)} kWh</span>
                    </div>
                )}
                {balance !== null && balance !== undefined && (
                    <div className="flex items-center justify-between gap-3 pt-1 border-t border-border/30 font-medium">
                        <span className="text-muted">Potential Balance:</span>
                        <span className={`font-mono ${balance >= 0 ? 'text-emerald-500' : 'text-rose-500'}`}>
                            {balance >= 0 ? `+${Number(balance).toFixed(2)}` : Number(balance).toFixed(2)} kWh
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

/**
 * Restrained peripheral leaf watermark strictly framing outer page corners.
 * "Branding around content, not over content."
 */
function SubtleLeafPerimeter() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden -z-10 opacity-[0.04] dark:opacity-[0.02]">
            {/* Top-right corner bouquet pushed far into the perimeter */}
            <svg
                className="absolute -top-28 -right-28 w-80 h-80 text-emerald-600 dark:text-emerald-400 rotate-45 transform"
                viewBox="0 0 200 200"
                fill="currentColor"
            >
                <path d="M100 10 C 60 50 60 120 100 180 C 140 120 140 50 100 10 Z" />
                <path d="M70 40 C 40 70 45 130 80 170 C 105 125 100 70 70 40 Z" opacity="0.7" />
                <path d="M130 40 C 100 70 105 125 130 170 C 165 130 160 70 130 40 Z" opacity="0.7" />
            </svg>
            {/* Bottom-left corner bouquet */}
            <svg
                className="absolute -bottom-28 -left-28 w-80 h-80 text-emerald-600 dark:text-emerald-400 -rotate-135 transform"
                viewBox="0 0 200 200"
                fill="currentColor"
            >
                <path d="M100 10 C 60 50 60 120 100 180 C 140 120 140 50 100 10 Z" />
                <path d="M70 40 C 40 70 45 130 80 170 C 105 125 100 70 70 40 Z" opacity="0.7" />
            </svg>
        </div>
    )
}

export function ReportsPage() {
    const { account } = useAuth()
    const isDemo = Boolean(account?.is_demo || account?.role === 'DEMO_VIEWER')
    const [loading, setLoading] = useState(true)
    const [report, setReport] = useState(null)
    const [selectedMonth, setSelectedMonth] = useState(null)
    const [error, setError] = useState(null)
    const [downloadingType, setDownloadingType] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null)
    const [downloadError, setDownloadError] = useState(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    const loadData = useCallback(async (month, signal) => {
        try {
            setError(null)
            const resp = await reportsApi.getMonthly(month, signal)
            if (!mountedRef.current) return
            setReport(resp)
        } catch (err) {
            if (err.name !== 'AbortError' && mountedRef.current) {
                setError(err.message || 'Failed to load monthly energy report.')
            }
        } finally {
            if (mountedRef.current) {
                setLoading(false)
            }
        }
    }, [])

    const handleRefresh = () => {
        setLoading(true)
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadData(selectedMonth, abortRef.current.signal)
    }

    const handleMonthChange = (newMonth) => {
        if (!newMonth || newMonth === selectedMonth) return
        setSelectedMonth(newMonth)
        setLoading(true)
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadData(newMonth, abortRef.current.signal)
    }

    useEffect(() => {
        mountedRef.current = true
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadData(selectedMonth, abortRef.current.signal)
        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [loadData, selectedMonth])

    const handleDownloadPdf = async () => {
        if (!report) return
        setDownloadingType('pdf')
        setDownloadError(null)
        setSuccessMessage(null)
        try {
            const { filename } = await reportsApi.downloadPdf(report.period.month)
            setSuccessMessage(`Successfully downloaded ${filename}`)
        } catch (err) {
            setDownloadError(err.message || 'Failed to generate PDF report.')
        } finally {
            setDownloadingType(null)
        }
    }

    const handleDownloadExcel = async () => {
        if (!report) return
        setDownloadingType('excel')
        setDownloadError(null)
        setSuccessMessage(null)
        try {
            const { filename } = await reportsApi.downloadExcel(report.period.month)
            setSuccessMessage(`Successfully downloaded ${filename}`)
        } catch (err) {
            setDownloadError(err.message || 'Failed to generate Excel report.')
        } finally {
            setDownloadingType(null)
        }
    }

    if (loading && !report) {
        return (
            <div className="flex min-h-[460px] items-center justify-center">
                <div className="flex items-center gap-3 text-sm text-muted">
                    <LoaderCircle className="size-5 animate-spin text-primary" />
                    <span>Loading monthly energy report...</span>
                </div>
            </div>
        )
    }

    const period = report?.period
    const kpis = report?.kpis
    const insight = report?.insight
    const trends = report?.daily_trends || []
    const bills = report?.bills || []
    const availableMonths = report?.available_months || []

    const hasSolarData = kpis?.solar_generation_kwh !== null && kpis?.solar_generation_kwh !== undefined
    const hasDemandData = kpis?.household_demand_kwh !== null && kpis?.household_demand_kwh !== undefined
    const hasBalance = kpis?.potential_balance_kwh !== null && kpis?.potential_balance_kwh !== undefined

    // Determine latest verified bill context
    const latestBill = kpis?.latest_verified_bill || (bills.length > 0 ? bills[0] : null)
    const latestBillAmount = latestBill?.amount ?? latestBill?.bill_amount ?? null
    const latestBillPeriod = latestBill?.billing_period_label ?? (
        latestBill?.billing_period_start && latestBill?.billing_period_end
            ? `${latestBill.billing_period_start} → ${latestBill.billing_period_end}`
            : null
    )

    // Format chart date labels
    const chartSeries = trends.map((t) => ({
        ...t,
        dayLabel: `Day ${t.day}`,
    }))

    return (
        <div className="relative max-w-6xl mx-auto space-y-6 px-4 py-8">
            <SubtleLeafPerimeter />

            {/* Header: Title, Description, Custom Month Selector & Refresh */}
            <header className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-border/50">
                <div>
                    <div className="flex items-center gap-2.5">
                        <div className="flex size-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <FileSpreadsheet className="size-5" />
                        </div>
                        <h1 className="font-display text-2xl font-bold text-heading tracking-tight">
                            Reports
                        </h1>
                    </div>
                    <p className="text-xs text-muted mt-1">
                        Monthly household energy report
                    </p>
                </div>

                {/* Custom Month Selector Bar */}
                <div className="flex flex-wrap items-center gap-2.5">
                    <ReportMonthSelector
                        selectedMonth={period?.month}
                        availableMonths={availableMonths}
                        onMonthChange={handleMonthChange}
                        disabled={loading}
                        canPrevious={Boolean(period?.previous_month)}
                        canNext={Boolean(period?.next_month && !period?.is_current_month)}
                        onPrevious={() => period?.previous_month && handleMonthChange(period.previous_month)}
                        onNext={() => period?.next_month && handleMonthChange(period.next_month)}
                    />

                    <button
                        type="button"
                        onClick={handleRefresh}
                        disabled={loading}
                        title="Refresh report data"
                        className="flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-300 hover:text-heading bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                    >
                        <RefreshCw className={`size-3.5 ${loading ? 'animate-spin text-primary' : ''}`} />
                        <span>Refresh</span>
                    </button>
                </div>
            </header>

            {/* Error Feedback Banner */}
            {error && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-950 dark:text-rose-200 flex items-center justify-between gap-3 shadow-sm">
                    <div className="flex items-center gap-2.5">
                        <AlertCircle className="size-4 text-rose-500 shrink-0" />
                        <span>{error}</span>
                    </div>
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="underline hover:no-underline font-medium text-rose-600 dark:text-rose-400"
                    >
                        Retry
                    </button>
                </div>
            )}

            {/* Success Download Toast */}
            {successMessage && (
                <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-xs text-emerald-950 dark:text-emerald-200 flex items-center justify-between gap-2.5 shadow-sm">
                    <div className="flex items-center gap-2.5">
                        <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                        <span className="font-medium">{successMessage}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSuccessMessage(null)}
                        className="text-xs text-muted hover:text-heading font-medium"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* Download Failure Banner */}
            {downloadError && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-950 dark:text-rose-200 flex items-center justify-between gap-2.5 shadow-sm">
                    <div className="flex items-center gap-2.5">
                        <AlertCircle className="size-4 text-rose-500 shrink-0" />
                        <span>{downloadError}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setDownloadError(null)}
                        className="text-xs text-muted hover:text-heading font-medium"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* SECTION 1: MONTH AT A GLANCE (4 Compact Consistent KPI Cards) */}
            <section className="space-y-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-muted">
                        Month at a Glance
                    </h2>
                    <span className="text-[11px] text-muted font-medium">
                        {period?.start_date} → {period?.end_date}
                    </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {/* KPI 1: Solar Generation */}
                    <div className="rounded-xl border border-border/80 bg-panel p-4 shadow-xs flex flex-col justify-between min-h-[112px] space-y-2 hover:border-border transition-colors">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                <Sun className="size-3.5 text-amber-500" /> Solar Generation
                            </span>
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                {kpis?.solar_data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')}
                            </span>
                        </div>
                        <div>
                            <div className="font-display text-2xl font-bold text-heading">
                                {hasSolarData ? `${kpis.solar_generation_kwh} kWh` : 'N/A'}
                            </div>
                            <p className="text-[11px] text-muted mt-0.5 truncate" title={kpis?.solar_provenance || 'GoodWe SEMS'}>
                                {kpis?.solar_provenance || 'GoodWe SEMS'}
                            </p>
                        </div>
                    </div>

                    {/* KPI 2: Estimated Household Demand */}
                    <div className="rounded-xl border border-border/80 bg-panel p-4 shadow-xs flex flex-col justify-between min-h-[112px] space-y-2 hover:border-border transition-colors">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                <Zap className="size-3.5 text-sky-500" /> Estimated Demand
                            </span>
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400">
                                ESTIMATED
                            </span>
                        </div>
                        <div>
                            <div className="font-display text-2xl font-bold text-heading">
                                {hasDemandData ? `${kpis.household_demand_kwh} kWh` : 'Unavailable'}
                            </div>
                            <p className="text-[11px] text-muted mt-0.5 truncate" title={kpis?.demand_provenance || 'Verified billing history'}>
                                {kpis?.demand_provenance || 'Verified billing history'}
                            </p>
                        </div>
                    </div>

                    {/* KPI 3: Potential Energy Balance */}
                    <div className="rounded-xl border border-border/80 bg-panel p-4 shadow-xs flex flex-col justify-between min-h-[112px] space-y-2 hover:border-border transition-colors">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                {kpis?.balance_status === 'SURPLUS' ? (
                                    <TrendingUp className="size-3.5 text-emerald-500" />
                                ) : (
                                    <TrendingDown className="size-3.5 text-rose-500" />
                                )}
                                Potential Balance
                            </span>
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-indigo-500/30 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                                POTENTIAL
                            </span>
                        </div>
                        <div>
                            <div className="font-display text-2xl font-bold text-heading">
                                {hasBalance ? (
                                    <span className={kpis.potential_balance_kwh >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}>
                                        {kpis.potential_balance_kwh >= 0 ? `+${kpis.potential_balance_kwh}` : kpis.potential_balance_kwh} kWh
                                    </span>
                                ) : (
                                    'N/A'
                                )}
                            </div>
                            <p className="text-[11px] text-muted mt-0.5 truncate" title={kpis?.balance_provenance || 'Analytical balance'}>
                                {kpis?.balance_provenance || 'Analytical balance'}
                            </p>
                        </div>
                    </div>

                    {/* KPI 4: Latest Verified Bill */}
                    <div className="rounded-xl border border-border/80 bg-panel p-4 shadow-xs flex flex-col justify-between min-h-[112px] space-y-2 hover:border-border transition-colors">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted flex items-center gap-1.5">
                                <Receipt className="size-3.5 text-slate-500" /> Latest Verified Bill
                            </span>
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                {latestBill?.status || 'VERIFIED'}
                            </span>
                        </div>
                        <div>
                            <div className="font-display text-2xl font-bold text-heading">
                                {latestBillAmount !== null
                                    ? `₹${Number(latestBillAmount).toLocaleString('en-IN')}`
                                    : 'Unavailable'}
                            </div>
                            <p className="text-[11px] text-muted mt-0.5 truncate" title={latestBillPeriod ? `Billing period: ${latestBillPeriod}` : 'No verified statement'}>
                                {latestBillPeriod ? `Billing: ${latestBillPeriod}` : 'No verified statement'}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {/* SECTION 2: MONTHLY INSIGHT (Compact Polished Section) */}
            {insight && (
                <section className="rounded-xl border border-sky-500/25 bg-sky-500/5 dark:bg-sky-500/10 p-4 space-y-2 shadow-xs">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2 text-sky-700 dark:text-sky-300 font-semibold text-xs uppercase tracking-wider">
                            <Info className="size-3.5 shrink-0" />
                            <span>Monthly Insight</span>
                        </div>
                        <span className="text-[10px] font-mono text-muted">
                            {period?.label}
                        </span>
                    </div>

                    <p className="text-xs text-heading font-medium leading-relaxed">
                        {insight.narrative || insight.headline}
                    </p>

                    {/* Provenance Line */}
                    <div className="pt-1.5 border-sky-500/20 flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted">
                        <div className="flex items-center gap-1.5 font-medium">
                            <span className="text-amber-600 dark:text-amber-400">{kpis?.solar_data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')} solar</span>
                            <span>•</span>
                            <span className="text-sky-600 dark:text-sky-400">ESTIMATED demand</span>
                            <span>•</span>
                            <span className="text-indigo-600 dark:text-indigo-400">POTENTIAL balance</span>
                        </div>

                        {insight.limitations && insight.limitations.length > 0 && (
                            <span className="text-[10px] text-muted/80">
                                {insight.limitations[0]}
                            </span>
                        )}
                    </div>
                </section>
            )}

            {/* SECTION 3 & 4: SOLAR PERFORMANCE & ENERGY BALANCE CHARTS (cursor={false} to remove hover rectangle artifact) */}
            <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Chart 1: Daily Solar Generation */}
                <ChartContainer
                    title="Daily Solar Generation"
                    description={kpis?.solar_data_nature === 'SIMULATED' || isDemo ? "Simulated demo generation." : "Actual generation measured by GoodWe inverter."}
                    accessibleSummary="Bar chart showing daily solar generation in kilowatt hours for each day of the month."
                    empty={!hasSolarData}
                >
                    <div className="h-[250px] w-full pt-2">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={chartSeries} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="rgba(148, 163, 184, 0.2)" />
                                <XAxis dataKey="day" tickLine={false} stroke="#94a3b8" fontSize={11} />
                                <YAxis tickLine={false} stroke="#94a3b8" fontSize={11} unit=" kWh" width={55} />
                                <Tooltip content={<DailyTrendTooltip />} cursor={false} />
                                <Bar dataKey="solar_generation_kwh" name={`Solar Generation (${kpis?.solar_data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')})`} fill="#f59e0b" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ChartContainer>

                {/* Chart 2: Solar Generation vs Estimated Daily Demand */}
                <ChartContainer
                    title="Solar Generation vs Estimated Daily Demand"
                    description="Estimated daily demand baseline compared against actual solar generation."
                    accessibleSummary="Line chart comparing daily solar generation against estimated baseline demand."
                    empty={!hasSolarData && !hasDemandData}
                >
                    <div className="h-[250px] w-full pt-2">
                        {hasDemandData ? (
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={chartSeries} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="rgba(148, 163, 184, 0.2)" />
                                    <XAxis dataKey="day" tickLine={false} stroke="#94a3b8" fontSize={11} />
                                    <YAxis tickLine={false} stroke="#94a3b8" fontSize={11} unit=" kWh" width={55} />
                                    <Tooltip content={<DailyTrendTooltip />} cursor={false} />
                                    <Legend wrapperStyle={{ fontSize: 11, paddingTop: 6 }} />
                                    <Line type="monotone" dataKey="solar_generation_kwh" name={`Solar Generation (${kpis?.solar_data_nature || (isDemo ? 'SIMULATED' : 'ACTUAL')})`} stroke="#f59e0b" strokeWidth={2} dot={false} />
                                    <Line type="stepAfter" dataKey="estimated_demand_kwh" name="Estimated Daily Demand Baseline (ESTIMATED)" stroke="#0284c7" strokeWidth={2} strokeDasharray="4 4" dot={false} />
                                </ComposedChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="flex h-full flex-col items-center justify-center text-center p-6 space-y-2">
                                <Zap className="size-8 text-sky-500/50" />
                                <p className="text-xs font-semibold text-heading">Estimated Demand Baseline Unavailable</p>
                                <p className="text-[11px] text-muted max-w-sm">
                                    No verified utility statement covers this month yet. Upload and verify an APSPDCL statement to unlock demand comparison.
                                </p>
                            </div>
                        )}
                    </div>
                </ChartContainer>
            </section>

            {/* SECTION 5: BILLING SUMMARY */}
            <section className="rounded-xl border border-border/80 bg-panel p-5 shadow-xs space-y-3.5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2 border-b border-border/40">
                    <div>
                        <h2 className="text-sm font-semibold text-heading flex items-center gap-2">
                            <Receipt className="size-4 text-sky-500" />
                            Billing Summary
                        </h2>
                        <p className="text-[11px] text-muted mt-0.5">
                            Authoritative utility statement ledger with trust boundary isolation.
                        </p>
                    </div>

                    <div className="flex items-center gap-2 text-xs">
                        <span className="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-medium text-[11px]">
                            <ShieldCheck className="size-3" />
                            {kpis?.bills_summary?.verified_count || 0} Verified
                        </span>
                        {kpis?.bills_summary?.awaiting_review_count > 0 && (
                            <span className="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400 font-medium text-[11px]">
                                <Clock className="size-3" />
                                {kpis.bills_summary.awaiting_review_count} Awaiting Review
                            </span>
                        )}
                    </div>
                </div>

                {bills.length === 0 ? (
                    <div className="rounded-lg border border-border/40 bg-surface/40 p-6 text-center text-xs text-muted space-y-1">
                        <p className="font-medium text-heading">No utility statements recorded for this month.</p>
                        <p className="text-[11px]">Upload an APSPDCL statement in the Bills workspace to include it in this report.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border/40">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-surface/80 text-muted border-b border-border/50 text-[11px] uppercase tracking-wider font-semibold">
                                <tr>
                                    <th className="py-2.5 px-3">Billing Period</th>
                                    <th className="py-2.5 px-3">Service No.</th>
                                    <th className="py-2.5 px-3 text-right">Consumption</th>
                                    <th className="py-2.5 px-3 text-right">Solar Export Units</th>
                                    <th className="py-2.5 px-3 text-right">Net Units</th>
                                    <th className="py-2.5 px-3 text-right">Bill Amount</th>
                                    <th className="py-2.5 px-3 text-center">Verification</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/30">
                                {bills.map((b) => (
                                    <tr key={b.id} className="hover:bg-surface/50 transition-colors">
                                        <td className="py-2 px-3 font-medium text-heading whitespace-nowrap">
                                            {b.billing_period_start} → {b.billing_period_end}
                                        </td>
                                        <td className="py-2 px-3 font-mono text-[11px] text-muted">
                                            {b.service_number || '—'}
                                        </td>
                                        <td className="py-2 px-3 text-right font-mono font-medium text-heading">
                                            {b.consumption_kwh !== null ? `${b.consumption_kwh} kWh` : '—'}
                                        </td>
                                        <td className="py-2 px-3 text-right font-mono text-muted">
                                            {b.solar_export_units_kwh !== null ? `${b.solar_export_units_kwh} kWh` : '—'}
                                        </td>
                                        <td className="py-2 px-3 text-right font-mono text-muted">
                                            {b.net_units_kwh !== null ? `${b.net_units_kwh} kWh` : '—'}
                                        </td>
                                        <td className="py-2 px-3 text-right font-mono font-semibold text-heading">
                                            {b.bill_amount !== null ? `₹${Number(b.bill_amount).toLocaleString('en-IN')}` : '—'}
                                        </td>
                                        <td className="py-2 px-3 text-center whitespace-nowrap">
                                            <span
                                                className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                                                    b.status === 'VERIFIED'
                                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                                        : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                                }`}
                                            >
                                                {b.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-[10px] text-muted/80 leading-normal">
                    * Utility billing periods may differ from calendar months (e.g., 01 Sep → 01 Oct). Solar export units represent energy credited by the utility, distinct from GoodWe inverter solar generation.
                </p>
            </section>

            {/* SECTION 6: SOLAR DATA COVERAGE */}
            <section className="rounded-xl border border-border/80 bg-panel p-4 shadow-xs space-y-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-semibold text-heading flex items-center gap-2">
                            <Sun className="size-4 text-amber-500" />
                            Solar Data Coverage
                        </h2>
                        <p className="text-[11px] text-muted mt-0.5">
                            {kpis?.solar_data_nature === 'SIMULATED' || isDemo
                                ? `Simulated scenario interval measurements recorded during ${period?.label}.`
                                : `High-precision GoodWe SEMS interval measurements recorded during ${period?.label}.`}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="text-[10px] text-muted font-medium">
                            Source: {kpis?.solar_provenance || ((kpis?.solar_data_nature === 'SIMULATED' || isDemo) ? 'Simulated DEMO' : 'GoodWe SEMS')} • {kpis?.solar_data_nature || ((kpis?.solar_data_nature === 'SIMULATED' || isDemo) ? 'SIMULATED' : 'ACTUAL')}
                        </span>
                        <span
                            className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                                kpis?.solar_coverage?.data_status === 'FRESH'
                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                    : kpis?.solar_coverage?.data_status === 'PARTIAL'
                                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                        : 'bg-surface text-muted border-border/80'
                            }`}
                        >
                            {kpis?.solar_coverage?.data_status || 'NO DATA'}
                        </span>
                    </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div className="rounded-lg bg-surface/60 p-2.5 border border-border/40">
                        <span className="text-muted block text-[10px]">Days with Data:</span>
                        <span className="font-semibold text-heading text-xs mt-0.5 block">
                            {kpis?.solar_coverage?.days_with_data || 0} of {period?.days_in_month || 0} days
                        </span>
                    </div>

                    <div className="rounded-lg bg-surface/60 p-2.5 border border-border/40">
                        <span className="text-muted block text-[10px]">Total Readings:</span>
                        <span className="font-semibold text-heading text-xs mt-0.5 block">
                            {Number(kpis?.solar_coverage?.readings_count || 0).toLocaleString()} readings
                        </span>
                    </div>

                    <div className="rounded-lg bg-surface/60 p-2.5 border border-border/40">
                        <span className="text-muted block text-[10px]">Coverage Range:</span>
                        <span className="font-semibold text-heading text-xs mt-0.5 block">
                            {period?.start_date} → {period?.end_date}
                        </span>
                    </div>

                    <div className="rounded-lg bg-surface/60 p-2.5 border border-border/40 truncate">
                        <span className="text-muted block text-[10px]">Inverter Telemetry:</span>
                        <span className="font-semibold text-emerald-600 dark:text-emerald-400 text-xs mt-0.5 block truncate">
                            GoodWe Inverter Online
                        </span>
                    </div>
                </div>
            </section>

            {/* SECTION 7: EXPORT REPORT (PDF Primary, XLSX Secondary, NO CSV) */}
            <section className="rounded-xl border border-border/90 bg-panel p-6 shadow-xs space-y-4">
                <div>
                    <h2 className="text-base font-semibold text-heading flex items-center gap-2">
                        <Download className="size-4 text-emerald-500" />
                        EXPORT REPORT
                    </h2>
                    <p className="text-xs text-muted mt-0.5">
                        Monthly Energy Report • {period?.label}
                    </p>
                    <p className="text-[11px] text-body/80 mt-1 max-w-2xl leading-relaxed">
                        A presentation-ready report containing monthly summary, solar performance, energy balance, billing summary, data coverage, charts, and provenance.
                    </p>
                </div>

                <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-4 pt-1">
                    {/* PRIMARY ACTION: Download PDF Report */}
                    <button
                        type="button"
                        onClick={handleDownloadPdf}
                        disabled={downloadingType === 'pdf'}
                        className="flex items-center justify-center gap-2.5 px-6 py-3 rounded-xl text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-all active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                    >
                        {downloadingType === 'pdf' ? (
                            <>
                                <LoaderCircle className="size-4 animate-spin" />
                                <span>Generating PDF Report...</span>
                            </>
                        ) : (
                            <>
                                <FileText className="size-4" />
                                <span>Download Monthly PDF Report</span>
                            </>
                        )}
                    </button>

                    {/* SECONDARY ACTION: Download Detailed Excel Workbook */}
                    <button
                        type="button"
                        onClick={handleDownloadExcel}
                        disabled={downloadingType === 'excel'}
                        className="flex items-center justify-center gap-2.5 px-5 py-3 rounded-xl text-xs font-semibold text-slate-800 dark:text-slate-200 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 disabled:opacity-50 disabled:cursor-not-allowed shadow-xs transition-all active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    >
                        {downloadingType === 'excel' ? (
                            <>
                                <LoaderCircle className="size-4 animate-spin text-primary" />
                                <span>Generating Excel Workbook...</span>
                            </>
                        ) : (
                            <>
                                <FileSpreadsheet className="size-4 text-emerald-600 dark:text-emerald-400" />
                                <span>Download Detailed Excel Workbook</span>
                            </>
                        )}
                    </button>
                </div>

                <p className="text-[11px] text-muted leading-relaxed">
                    Detailed tables, daily trends, billing information, solar telemetry and charts.
                </p>
            </section>
        </div>
    )
}
