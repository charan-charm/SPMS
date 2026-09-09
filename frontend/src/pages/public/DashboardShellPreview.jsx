import {
    ResponsiveContainer,
    AreaChart,
    Area,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
} from 'recharts'
import { Sparkles, FlaskConical } from 'lucide-react'
import {
    MetricCard,
    RecommendationCard,
    OperationStatus,
    ChartContainer,
} from '../../components/data-display'
import { Card } from '../../components/ui/card'
import { CHART_LINE_STYLE } from '../../lib/semantics'

/**
 * DashboardShellPreview (F3 refinement 2 — DEV ONLY).
 *
 * Renders a representative dashboard composition inside the real
 * application shell so the owner can evaluate typography (Plus Jakarta
 * Sans), spacing, card proportions and the overall calm/premium feel.
 * Every value is explicitly marked sample — no real SPMS household
 * data exists until backend integration.
 * Route: /dev/dashboard (dead code in production builds).
 */

const SAMPLE_SERIES = [
    { hour: '00:00', solar: 0, usage: 0.4, solarForecast: 0 },
    { hour: '02:00', solar: 0, usage: 0.3, solarForecast: 0 },
    { hour: '04:00', solar: 0, usage: 0.3, solarForecast: 0 },
    { hour: '06:00', solar: 0.4, usage: 0.6, solarForecast: 0.3 },
    { hour: '08:00', solar: 1.6, usage: 1.1, solarForecast: 1.4 },
    { hour: '10:00', solar: 2.8, usage: 0.9, solarForecast: 2.5 },
    { hour: '12:00', solar: 3.6, usage: 1.2, solarForecast: 3.3 },
    { hour: '14:00', solar: 3.1, usage: 1.4, solarForecast: 2.9 },
    { hour: '16:00', solar: 1.8, usage: 1.8, solarForecast: 1.7 },
    { hour: '18:00', solar: 0.5, usage: 2.2, solarForecast: 0.5 },
    { hour: '20:00', solar: 0, usage: 1.6, solarForecast: 0 },
    { hour: '22:00', solar: 0, usage: 0.8, solarForecast: 0 },
]

function SampleTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null
    return (
        <div className="rounded-md border border-border bg-surface px-3 py-2 text-xs shadow-1">
            <p className="mb-1 font-semibold text-heading">{label}</p>
            {payload.map((entry) => (
                <p key={entry.name} className="flex items-center gap-2 text-body">
                    <span
                        aria-hidden="true"
                        className="inline-block size-2 rounded-full"
                        style={{ backgroundColor: entry.color }}
                    />
                    {entry.name}: {entry.value} kW
                </p>
            ))}
        </div>
    )
}

export function DashboardShellPreview() {
    return (
        <div className="mx-auto flex max-w-6xl flex-col gap-6">
            {/* Preview banner — sample content is impossible to mistake for real data */}
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-chart-demo/40 bg-chart-demo/10 px-4 py-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-chart-demo">
                    <FlaskConical className="size-4" aria-hidden="true" />
                    Dashboard shell preview — all values are static samples, never real data
                </div>
            </div>

            {/* Metric row — proportions, typography hierarchy, badge placement */}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Energy today"
                    value="18.4"
                    unit="kWh"
                    nature="SIMULATED"
                    status="FRESH"
                    comparison="Sample value for shell evaluation"
                />
                <MetricCard
                    label="Current output"
                    value="2.4"
                    unit="kW"
                    nature="SIMULATED"
                    status="FRESH"
                    comparison="Sample value for shell evaluation"
                />
                <MetricCard
                    label="Self-sufficiency"
                    value="64"
                    unit="%"
                    nature="SIMULATED"
                    status="DEGRADED"
                    comparison="Sample value for shell evaluation"
                />
                <MetricCard
                    label="Battery level"
                    value="78"
                    unit="%"
                    nature="SIMULATED"
                    status="FRESH"
                    comparison="Sample value for shell evaluation"
                />
            </div>

            {/* Production chart — the visual anchor of the dashboard */}
            <ChartContainer
                title="Solar production vs household usage"
                description="Sample composition — real series arrive from backend data."
                actions={
                    <span className="rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-muted">
                        Sample range: today
                    </span>
                }
                footer="Illustrative sample chart with simulated values — dashed line marks predicted semantics."
                accessibleSummary="Sample area chart comparing solar production and household usage across the day, with a dashed predicted line."
            >
                <div className="h-72 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={SAMPLE_SERIES} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="previewSolarFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="var(--color-chart-solar)" stopOpacity={0.28} />
                                    <stop offset="100%" stopColor="var(--color-chart-solar)" stopOpacity={0.02} />
                                </linearGradient>
                                <linearGradient id="previewUsageFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="var(--color-chart-usage)" stopOpacity={0.22} />
                                    <stop offset="100%" stopColor="var(--color-chart-usage)" stopOpacity={0.02} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                            <XAxis
                                dataKey="hour"
                                tickLine={false}
                                axisLine={false}
                                tick={{ fill: 'var(--color-muted)', fontSize: 11 }}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                tick={{ fill: 'var(--color-muted)', fontSize: 11 }}
                                width={36}
                                unit=" kW"
                            />
                            <Tooltip content={<SampleTooltip />} cursor={{ stroke: 'var(--color-border)' }} />
                            <Area
                                type="monotone"
                                dataKey="solar"
                                name="Solar production"
                                stroke="var(--color-chart-solar)"
                                strokeWidth={2}
                                fill="url(#previewSolarFill)"
                            />
                            <Area
                                type="monotone"
                                dataKey="solarForecast"
                                name="Predicted solar"
                                stroke="var(--color-chart-solar-fc)"
                                strokeWidth={2}
                                strokeDasharray={CHART_LINE_STYLE.PREDICTED.strokeDasharray}
                                fill="none"
                            />
                            <Area
                                type="monotone"
                                dataKey="usage"
                                name="Household usage"
                                stroke="var(--color-chart-usage)"
                                strokeWidth={2}
                                fill="url(#previewUsageFill)"
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            </ChartContainer>

            {/* Intelligence + operations composition */}
            <div className="grid gap-4 lg:grid-cols-3">
                <RecommendationCard
                    className="lg:col-span-2"
                    category="Sample"
                    title="Sample recommendation for shell evaluation"
                    explanation="This card exists only to evaluate typography, spacing and card proportions inside the shell. It contains no real household analysis."
                    impact="HIGH"
                    confidence="MODERATE"
                    timing="Sample timing context"
                    why={{
                        facts: [
                            { label: 'Solar forecast', value: 'Sample fact for layout evaluation' },
                            { label: 'Household demand', value: 'Sample fact for layout evaluation' },
                            { label: 'Battery state', value: 'Sample fact for layout evaluation' },
                        ],
                        explanation: 'Sample explanation text for measuring line length and readability in the two-column layout.',
                    }}
                    onFeedback={() => {}}
                />
                <Card className="flex flex-col gap-4">
                    <div className="flex items-center gap-2">
                        <Sparkles className="size-4 text-secondary" aria-hidden="true" />
                        <h3 className="font-display text-base font-semibold text-heading">Operation states</h3>
                    </div>
                    <div className="flex flex-col items-start gap-3">
                        <OperationStatus status="PROCESSING" detail="Sample detail line" />
                        <OperationStatus status="COMPLETED" detail="Sample detail line" />
                        <OperationStatus status="RECOVERING" detail="Sample detail line" />
                        <OperationStatus status="FAILED" detail="Sample detail line" />
                    </div>
                </Card>
            </div>
        </div>
    )
}
