import { useEffect, useState, useCallback, useRef } from 'react'
import {
    ResponsiveContainer,
    ComposedChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ReferenceLine,
} from 'recharts'
import {
    BarChart3,
    Sun,
    Receipt,
    Scale,
    RefreshCw,
    LoaderCircle,
    AlertCircle,
    Info,
    AlertTriangle,
    TrendingUp,
    TrendingDown,
    Minus,
} from 'lucide-react'
import { analyticsApi } from '../../services/api/analytics'
import { ChartContainer } from '../../components/data-display/ChartContainer'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'

// ----------------------------------------------------------------------------
// Utility helpers
// ----------------------------------------------------------------------------

/** Format a YYYY-MM string to a human-readable month label. */
function formatMonth(ym) {
    if (!ym) return ''
    const [year, month] = ym.split('-')
    const d = new Date(Number(year), Number(month) - 1, 1)
    return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
}

/** Format kWh value with up to 1 decimal place. */
function fmtKwh(val) {
    if (val == null) return '—'
    return `${Number(val).toLocaleString(undefined, { maximumFractionDigits: 1 })} kWh`
}

/** Format currency in Indian Rupees. */
function fmtRupees(val) {
    if (val == null) return '—'
    return `₹${Number(val).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
}

/** Returns a past YYYY-MM offset by `n` months from today. */
function monthOffset(n) {
    const d = new Date()
    d.setDate(1)
    d.setMonth(d.getMonth() + n)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

// ----------------------------------------------------------------------------
// Preset date-range options
// ----------------------------------------------------------------------------

const DATE_PRESETS = [
    { id: '6m',   label: 'Last 6 months',  from: monthOffset(-5)  },
    { id: '12m',  label: 'Last 12 months', from: monthOffset(-11) },
    { id: '24m',  label: 'Last 24 months', from: monthOffset(-23) },
]

// Data-nature → display config (subset of SPMS design system)
const NATURE_CONFIG = {
    ACTUAL:      { label: 'Actual',      className: 'text-emerald-400' },
    VERIFIED:    { label: 'Verified',    className: 'text-sky-400'     },
    EXTRACTED:   { label: 'Extracted',   className: 'text-amber-400'   },
    ESTIMATED:   { label: 'Estimated',   className: 'text-violet-400'  },
    UNAVAILABLE: { label: 'Unavailable', className: 'text-text-muted'  },
}

// ----------------------------------------------------------------------------
// Custom Recharts tooltip
// ----------------------------------------------------------------------------

function AnalyticsTooltip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) return null

    const d = payload[0]?.payload || {}

    return (
        <div className="rounded-xl border border-border/80 bg-surface/95 p-3.5 shadow-xl backdrop-blur-md text-xs space-y-2 min-w-[220px]">
            <div className="font-semibold text-text-primary border-b border-border/50 pb-1.5">
                {label}
            </div>

            {d.solar_kwh != null && (
                <div className="flex items-center justify-between text-emerald-400 font-medium">
                    <span className="flex items-center gap-1">
                        <span className="size-2 rounded-full bg-emerald-400 inline-block" />
                        Solar Generation
                    </span>
                    <span>{fmtKwh(d.solar_kwh)}</span>
                </div>
            )}
            {d.solar_kwh == null && (
                <div className="flex items-center justify-between text-text-muted">
                    <span>Solar Generation</span>
                    <span className="italic">No data</span>
                </div>
            )}

            {d.consumption_kwh != null && (
                <div className="flex items-center justify-between text-sky-400 font-medium">
                    <span className="flex items-center gap-1">
                        <span className="size-2 rounded-full bg-sky-400 inline-block" />
                        Billed Consumption
                    </span>
                    <span>{fmtKwh(d.consumption_kwh)}</span>
                </div>
            )}
            {d.consumption_kwh == null && (
                <div className="flex items-center justify-between text-text-muted">
                    <span>Billed Consumption</span>
                    <span className="italic">No verified bill</span>
                </div>
            )}

            {d.net_kwh != null && (
                <div className={`flex items-center justify-between font-medium border-t border-border/40 pt-1 ${d.net_kwh >= 0 ? 'text-emerald-400' : 'text-amber-400'}`}>
                    <span>{d.net_kwh < 0 ? 'Net Deficit (Used > Solar)' : 'Net Surplus (Solar > Used)'}</span>
                    <span>{d.net_kwh < 0 ? `${fmtKwh(Math.abs(d.net_kwh))}` : `${fmtKwh(d.net_kwh)}`}</span>
                </div>
            )}

            {d.bill_amount != null && (
                <div className="flex items-center justify-between text-text-secondary">
                    <span>Bill Amount</span>
                    <span>{fmtRupees(d.bill_amount)}</span>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-1.5 border-t border-border/40 pt-1">
                {d.solar_nature && d.solar_nature !== 'UNAVAILABLE' && (
                    <DataNatureBadge nature={d.solar_nature} />
                )}
                {d.bill_nature && d.bill_nature !== 'UNAVAILABLE' && (
                    <DataNatureBadge nature={d.bill_nature === 'VERIFIED' ? 'ACTUAL' : 'ESTIMATED'} />
                )}
            </div>
        </div>
    )
}

// ----------------------------------------------------------------------------
// Stat cards row
// ----------------------------------------------------------------------------

function StatCard({ icon: Icon, label, value, sub, iconClass = 'text-text-muted' }) {
    return (
        <div className="rounded-2xl border border-border/60 bg-surface-raised/60 p-4 flex items-start gap-3">
            <div className={`mt-0.5 flex-shrink-0 ${iconClass}`}>
                <Icon className="size-5" />
            </div>
            <div>
                <p className="text-xs text-text-muted">{label}</p>
                <p className="text-xl font-bold text-heading mt-0.5">{value}</p>
                {sub && <p className="text-xs text-text-muted mt-0.5">{sub}</p>}
            </div>
        </div>
    )
}

// ----------------------------------------------------------------------------
// No-data call-to-action
// ----------------------------------------------------------------------------

function NoDataState({ hasSolar, hasBills }) {
    if (!hasSolar && !hasBills) {
        return (
            <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
                <BarChart3 className="size-12 text-text-muted opacity-40" />
                <div>
                    <h3 className="font-display text-lg font-semibold text-heading">No analytics data yet</h3>
                    <p className="text-sm text-text-muted mt-1 max-w-sm">
                        Connect your GoodWe inverter and upload at least one verified APSPDCL bill
                        to see historical trends here.
                    </p>
                </div>
                <div className="flex items-center gap-4 mt-2">
                    <a href="/solar" className="text-sm font-medium text-accent hover:underline">
                        → Connect inverter
                    </a>
                    <a href="/bills" className="text-sm font-medium text-accent hover:underline">
                        → Upload bills
                    </a>
                </div>
            </div>
        )
    }

    return (
        <div className="rounded-2xl border border-border/50 bg-surface-raised/40 p-5 flex items-start gap-3">
            <Info className="size-4 text-text-muted flex-shrink-0 mt-0.5" />
            <div className="text-sm text-text-muted space-y-1">
                {!hasSolar && (
                    <p>
                        <span className="font-medium text-text-secondary">Solar data unavailable.</span>{' '}
                        <a href="/solar" className="underline hover:text-text-primary">Connect your GoodWe inverter</a>
                        {' '}to see generation trends.
                    </p>
                )}
                {!hasBills && (
                    <p>
                        <span className="font-medium text-text-secondary">No verified bills.</span>{' '}
                        <a href="/bills" className="underline hover:text-text-primary">Upload APSPDCL bills</a>
                        {' '}to see consumption data.
                    </p>
                )}
            </div>
        </div>
    )
}

// ----------------------------------------------------------------------------
// Main page
// ----------------------------------------------------------------------------

export function AnalyticsPage() {
    const [loading, setLoading]       = useState(true)
    const [error, setError]           = useState(null)
    const [summary, setSummary]       = useState(null)
    const [points, setPoints]         = useState([])
    const [aggSummary, setAggSummary] = useState(null)
    const [dataNotes, setDataNotes]   = useState(null)
    const [preset, setPreset]         = useState('12m')

    const abortRef   = useRef(null)
    const mountedRef = useRef(true)

    const loadData = useCallback(async (selectedPreset, signal) => {
        const presetObj = DATE_PRESETS.find((p) => p.id === selectedPreset)
        const fromMonth = presetObj?.from

        try {
            setError(null)

            const [sumRes, monthlyRes] = await Promise.all([
                analyticsApi.getSummary(signal),
                analyticsApi.getMonthly({
                    from_month: fromMonth,
                    signal,
                }),
            ])

            if (!mountedRef.current) return

            setSummary(sumRes)
            setPoints(monthlyRes.points || [])
            setAggSummary(monthlyRes.summary || null)
            setDataNotes(monthlyRes.data_notes || null)
        } catch (err) {
            if (err.name !== 'AbortError' && mountedRef.current) {
                setError(err.message || 'Failed to load analytics data.')
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
        loadData(preset, abortRef.current.signal)
    }

    useEffect(() => {
        mountedRef.current = true
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadData(preset, abortRef.current.signal)
        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [loadData, preset])

    // Derived
    const hasSolar = summary?.has_solar_data ?? false
    const hasBills = summary?.has_bill_data  ?? false
    const hasAnyData = hasSolar || hasBills

    // Chart data: fill display label, skip months with no data for cleaner rendering
    const chartData = points.map((p) => ({
        ...p,
        label: formatMonth(p.month),
        // Use null for Recharts to render gaps instead of zero
        solar_kwh:       p.solar_kwh       ?? null,
        consumption_kwh: p.consumption_kwh ?? null,
        net_kwh:         p.net_kwh         ?? null,
    }))

    // Check if we have at least one point with solar AND at least one with bill
    const hasChartData = chartData.some((d) => d.solar_kwh != null || d.consumption_kwh != null)

    // Totals for header stats
    const totalSolar    = aggSummary?.total_solar_kwh       ?? null
    const totalConsumption = aggSummary?.total_consumption_kwh ?? null
    const overlapMonths = aggSummary?.overlap_months        ?? 0
    const totalNet      = totalSolar != null && totalConsumption != null
        ? +(totalSolar - totalConsumption).toFixed(1)
        : null

    return (
        <div id="analytics-workspace" className="space-y-6">
            {/* Page header */}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="font-display text-2xl font-bold text-heading flex items-center gap-2.5">
                        <BarChart3 className="size-6 text-accent" />
                        Analytics
                    </h1>
                    <p className="text-sm text-text-muted mt-1">
                        Historical trends from actual inverter readings and verified electricity bills.
                    </p>
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                    {/* Preset selector */}
                    {DATE_PRESETS.map((p) => (
                        <button
                            key={p.id}
                            id={`analytics-preset-${p.id}`}
                            onClick={() => {
                                if (p.id !== preset) {
                                    setLoading(true)
                                    setPreset(p.id)
                                }
                            }}
                            className={[
                                'px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                                preset === p.id
                                    ? 'bg-accent/15 border-accent/40 text-accent'
                                    : 'border-border/60 text-text-muted hover:border-accent/30 hover:text-text-secondary bg-transparent',
                            ].join(' ')}
                        >
                            {p.label}
                        </button>
                    ))}

                    <button
                        id="analytics-refresh"
                        onClick={handleRefresh}
                        disabled={loading}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium border border-border/60 text-text-muted hover:text-text-secondary hover:border-accent/30 transition-colors disabled:opacity-40"
                        aria-label="Refresh analytics"
                    >
                        {loading ? (
                            <LoaderCircle className="size-4 animate-spin" />
                        ) : (
                            <RefreshCw className="size-4" />
                        )}
                        Refresh
                    </button>
                </div>
            </div>

            {/* Error banner */}
            {error && (
                <div className="rounded-2xl border border-red-500/30 bg-red-500/10 p-4 flex items-start gap-3">
                    <AlertCircle className="size-4 text-red-400 flex-shrink-0 mt-0.5" />
                    <div>
                        <p className="text-sm font-medium text-red-300">Failed to load analytics</p>
                        <p className="text-xs text-text-muted mt-0.5">{error}</p>
                    </div>
                </div>
            )}

            {/* Loading state */}
            {loading && (
                <div className="flex flex-col items-center justify-center py-20 gap-3">
                    <LoaderCircle className="size-8 animate-spin text-accent" />
                    <p className="text-sm text-text-muted">Loading analytics data…</p>
                </div>
            )}

            {!loading && !error && (
                <>
                    {/* No-data call to action */}
                    {!hasAnyData && <NoDataState hasSolar={hasSolar} hasBills={hasBills} />}

                    {/* Partial data notice */}
                    {hasAnyData && (!hasSolar || !hasBills) && (
                        <NoDataState hasSolar={hasSolar} hasBills={hasBills} />
                    )}

                    {/* Summary stat cards */}
                    {hasAnyData && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatCard
                                icon={Sun}
                                label="Total Solar Generated"
                                value={totalSolar != null ? fmtKwh(totalSolar) : '—'}
                                sub={hasSolar ? `${aggSummary?.solar_months_with_data ?? 0} months of data` : 'No inverter data'}
                                iconClass="text-emerald-400"
                            />
                            <StatCard
                                icon={Receipt}
                                label="Total Billed Consumption"
                                value={totalConsumption != null ? fmtKwh(totalConsumption) : '—'}
                                sub={hasBills ? `${aggSummary?.bill_months_with_data ?? 0} billing periods` : 'No verified bills'}
                                iconClass="text-sky-400"
                            />
                            <StatCard
                                icon={Scale}
                                label={totalNet != null && totalNet < 0 ? "Net Balance (Deficit: Used > Solar)" : "Net Balance (Solar − Used)"}
                                value={totalNet != null ? (totalNet < 0 ? `${fmtKwh(Math.abs(totalNet))}` : `${totalNet > 0 ? '+' : ''}${fmtKwh(totalNet)}`) : '—'}
                                sub={overlapMonths > 0 ? `Across ${overlapMonths} overlap months` : 'No overlap data'}
                                iconClass={totalNet == null ? 'text-text-muted' : totalNet >= 0 ? 'text-emerald-400' : 'text-amber-400'}
                            />
                            <StatCard
                                icon={TrendingUp}
                                label="Coverage Overlap"
                                value={overlapMonths > 0 ? `${overlapMonths} months` : '—'}
                                sub="Months with both solar & bill data"
                                iconClass="text-violet-400"
                            />
                        </div>
                    )}

                    {/* Main combined chart */}
                    {hasChartData && (
                        <ChartContainer
                            title="Monthly Generation vs Billed Consumption"
                            description="Solar generation from GoodWe inverter readings vs. verified APSPDCL billed consumption per month."
                            footer="Grid import/export is not shown — it is not measured by the inverter telemetry captured here."
                            accessibleSummary={`Monthly analytics for ${aggSummary?.total_months ?? 0} months`}
                        >
                            <div
                                id="analytics-chart-main"
                                style={{ height: 320 }}
                                className="w-full"
                                role="img"
                                aria-label="Monthly solar generation and billed consumption chart"
                            >
                                <ResponsiveContainer width="100%" height="100%">
                                    <ComposedChart data={chartData} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.06)" />
                                        <XAxis
                                            dataKey="label"
                                            tick={{ fontSize: 11, fill: 'var(--color-text-muted, #888)' }}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <YAxis
                                            tick={{ fontSize: 11, fill: 'var(--color-text-muted, #888)' }}
                                            axisLine={false}
                                            tickLine={false}
                                            tickFormatter={(v) => `${v}kWh`}
                                            width={60}
                                        />
                                        <Tooltip content={<AnalyticsTooltip />} />
                                        <Legend
                                            wrapperStyle={{ fontSize: 12, color: 'var(--color-text-muted, #888)' }}
                                        />
                                        <ReferenceLine y={0} stroke="rgba(255,255,255,0.12)" />

                                        <Bar
                                            dataKey="solar_kwh"
                                            name="Solar Generated (kWh)"
                                            fill="#34d399"
                                            fillOpacity={0.75}
                                            radius={[4, 4, 0, 0]}
                                            connectNulls={false}
                                        />
                                        <Bar
                                            dataKey="consumption_kwh"
                                            name="Billed Consumption (kWh)"
                                            fill="#38bdf8"
                                            fillOpacity={0.75}
                                            radius={[4, 4, 0, 0]}
                                            connectNulls={false}
                                        />
                                        <Line
                                            dataKey="net_kwh"
                                            name="Net Balance (kWh)"
                                            stroke="#a78bfa"
                                            strokeWidth={2}
                                            dot={{ fill: '#a78bfa', r: 3 }}
                                            connectNulls={false}
                                            strokeDasharray="4 2"
                                        />
                                    </ComposedChart>
                                </ResponsiveContainer>
                            </div>
                        </ChartContainer>
                    )}

                    {/* Monthly data table */}
                    {hasChartData && (
                        <div className="rounded-2xl border border-border/60 overflow-hidden">
                            <div className="px-5 py-4 border-b border-border/60 flex items-center gap-2">
                                <BarChart3 className="size-4 text-text-muted" />
                                <h2 className="font-display text-sm font-semibold text-heading">Monthly Breakdown</h2>
                            </div>

                            <div className="overflow-x-auto">
                                <table
                                    id="analytics-monthly-table"
                                    className="w-full text-sm"
                                    aria-label="Monthly analytics breakdown"
                                >
                                    <thead>
                                        <tr className="border-b border-border/40 bg-surface-raised/30">
                                            <th className="px-4 py-2.5 text-left text-xs font-semibold text-text-muted">Month</th>
                                            <th className="px-4 py-2.5 text-right text-xs font-semibold text-emerald-400">Solar (kWh)</th>
                                            <th className="px-4 py-2.5 text-right text-xs font-semibold text-sky-400">Consumption (kWh)</th>
                                            <th className="px-4 py-2.5 text-right text-xs font-semibold text-violet-400">Net (kWh)</th>
                                            <th className="px-4 py-2.5 text-right text-xs font-semibold text-text-muted">Bill Amount</th>
                                            <th className="px-4 py-2.5 text-left text-xs font-semibold text-text-muted">Data Quality</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/30">
                                        {chartData.map((row) => {
                                            const netPositive = row.net_kwh != null && row.net_kwh >= 0


                                            return (
                                                <tr
                                                    key={row.month}
                                                    className="hover:bg-surface-raised/30 transition-colors"
                                                >
                                                    <td className="px-4 py-3 font-medium text-text-primary">{row.label}</td>
                                                    <td className="px-4 py-3 text-right text-emerald-400">
                                                        {row.solar_kwh != null ? fmtKwh(row.solar_kwh) : (
                                                            <span className="text-text-muted italic text-xs">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sky-400">
                                                        {row.consumption_kwh != null ? fmtKwh(row.consumption_kwh) : (
                                                            <span className="text-text-muted italic text-xs">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        {row.net_kwh != null ? (
                                                            <span className={`flex items-center justify-end gap-1 font-medium ${netPositive ? 'text-emerald-400' : 'text-amber-400'}`}>
                                                                {netPositive ? (
                                                                    <TrendingUp className="size-3.5" />
                                                                ) : (
                                                                    <TrendingDown className="size-3.5" />
                                                                )}
                                                                {row.net_kwh > 0 ? '+' : ''}{fmtKwh(row.net_kwh)}
                                                            </span>
                                                        ) : (
                                                            <span className="text-text-muted text-xs flex items-center justify-end gap-1">
                                                                <Minus className="size-3" />
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-text-secondary">
                                                        {row.bill_amount != null ? fmtRupees(row.bill_amount) : (
                                                            <span className="text-text-muted italic text-xs">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-wrap gap-1">
                                                            {row.solar_nature !== 'UNAVAILABLE' && (
                                                                <DataNatureBadge nature={row.solar_nature} />
                                                            )}
                                                            {row.bill_nature !== 'UNAVAILABLE' && (
                                                                <DataNatureBadge
                                                                    nature={row.bill_nature === 'VERIFIED' ? 'ACTUAL' : 'ESTIMATED'}
                                                                />
                                                            )}
                                                            {row.solar_nature === 'UNAVAILABLE' && row.bill_nature === 'UNAVAILABLE' && (
                                                                <span className="text-xs text-text-muted italic">No data</span>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Data transparency note */}
                    {hasAnyData && dataNotes && (
                        <div className="rounded-2xl border border-border/50 bg-surface-raised/30 p-5 space-y-3">
                            <div className="flex items-center gap-2 text-sm font-semibold text-heading">
                                <AlertTriangle className="size-4 text-amber-400" />
                                Data Sources &amp; Limitations
                            </div>
                            <ul className="space-y-1.5 text-xs text-text-muted list-none">
                                <li className="flex items-start gap-2">
                                    <Sun className="size-3.5 text-emerald-400 flex-shrink-0 mt-0.5" />
                                    <span><span className="text-emerald-400 font-medium">Solar:</span> {dataNotes.solar}</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <Receipt className="size-3.5 text-sky-400 flex-shrink-0 mt-0.5" />
                                    <span><span className="text-sky-400 font-medium">Consumption:</span> {dataNotes.consumption}</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <Scale className="size-3.5 text-violet-400 flex-shrink-0 mt-0.5" />
                                    <span><span className="text-violet-400 font-medium">Net:</span> {dataNotes.net}</span>
                                </li>
                            </ul>
                        </div>
                    )}
                </>
            )}
        </div>
    )
}
