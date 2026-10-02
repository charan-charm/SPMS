import { useRef, useState } from 'react'
import { motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react'
import { Sun, Zap, ArrowUpRight, Sparkles, Activity } from 'lucide-react'
import { IllustrativeChip } from './IllustrativeChip'
import { ChartPath } from './ChartPath'
import { buildDayComparison } from './dayCurve'

/**
 * SolarDashboardCard — a premium presentation-only showcase of the SPMS
 * dashboard experience for the landing page.
 *
 * Demonstrates the four core energy dimensions:
 * 1. Solar production (clean PV generation)
 * 2. Household consumption (real-time demand)
 * 3. Energy balance (net surplus/export)
 * 4. Solar availability & actionable decision support
 *
 * Presentation only: No API calls, no auth, no live GoodWe data,
 * and no appliance control. Purely illustrative sample curves and metrics.
 *
 * Motion & Interactivity:
 * - On pointer hover: card lifts slightly, border subtly brightens, shadow
 *   deepens, icons micro-shift, and chart anchor point pulses.
 * - Gentle bounded cursor parallax (max ±2° tilt on mouse only).
 * - Under prefers-reduced-motion: parallax is disabled, chart stays static,
 *   and all content remains cleanly accessible.
 * - Mobile: pointer tilt is disabled; natural touch/scroll presentation.
 */

const CURVES = buildDayComparison()

const TILT_SPRING = { stiffness: 120, damping: 20, mass: 0.5 }

function LegendLine({ color, dashed = false, width = 24 }) {
    return (
        <svg width={width} height="6" aria-hidden="true" focusable="false" className="shrink-0">
            <line
                x1="1"
                y1="3"
                x2={width - 1}
                y2="3"
                stroke={color}
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeDasharray={dashed ? '5 5' : undefined}
            />
        </svg>
    )
}

const LEGEND = [
    { swatch: <LegendLine color="var(--spms-chart-solar)" />, label: 'Solar — example' },
    { swatch: <LegendLine color="var(--spms-chart-usage)" />, label: 'Consumption — example' },
    { swatch: <LegendLine color="var(--spms-chart-solar)" dashed />, label: 'Solar — forecast (example)' },
    { swatch: <LegendLine color="var(--spms-chart-usage)" dashed />, label: 'Consumption — forecast (example)' },
    {
        swatch: <span aria-hidden="true" className="size-2.5 shrink-0 rounded-sm bg-chart-surplus/60" />,
        label: 'Potential surplus (illustrative)',
    },
    {
        swatch: <span aria-hidden="true" className="size-2.5 shrink-0 rounded-sm bg-chart-deficit/50" />,
        label: 'Potential deficit (illustrative)',
    },
]

export function SolarDashboardCard() {
    const cardRef = useRef(null)
    const reducedMotion = useReducedMotion()
    const [isHovered, setIsHovered] = useState(false)

    // Bounded perspective parallax on desktop mouse
    const rawX = useMotionValue(0)
    const rawY = useMotionValue(0)
    const springX = useSpring(rawX, TILT_SPRING)
    const springY = useSpring(rawY, TILT_SPRING)

    const rotateX = useTransform(springY, [-0.5, 0.5], [1.5, -1.5])
    const rotateY = useTransform(springX, [-0.5, 0.5], [-2, 2])

    function handlePointerMove(event) {
        if (reducedMotion || event.pointerType !== 'mouse') return
        const bounds = cardRef.current?.getBoundingClientRect()
        if (!bounds) return
        rawX.set((event.clientX - bounds.left) / bounds.width - 0.5)
        rawY.set((event.clientY - bounds.top) / bounds.height - 0.5)
    }

    function handlePointerEnter(event) {
        if (event.pointerType === 'mouse') setIsHovered(true)
    }

    function handlePointerLeave() {
        setIsHovered(false)
        rawX.set(0)
        rawY.set(0)
    }

    return (
        <div style={{ perspective: 1000 }} className="w-full">
            <motion.div
                ref={cardRef}
                onPointerMove={handlePointerMove}
                onPointerEnter={handlePointerEnter}
                onPointerLeave={handlePointerLeave}
                style={
                    reducedMotion
                        ? undefined
                        : {
                              rotateX,
                              rotateY,
                              transformStyle: 'preserve-3d',
                          }
                }
                className={`group relative rounded-2xl border bg-surface p-5 sm:p-7 transition-all duration-200 ${
                    isHovered
                        ? 'border-primary/40 -translate-y-1 shadow-xl shadow-primary/5 dark:shadow-primary/10'
                        : 'border-border shadow-sm'
                }`}
            >
                {/* Header bar */}
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/80 pb-5">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary">
                            <Activity className="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="font-display text-base sm:text-lg font-semibold tracking-tight text-heading">
                                    Solar overview
                                </h3>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-chart-surplus/15 px-2 py-0.5 text-[11px] font-medium text-chart-surplus">
                                    <span className="relative flex size-1.5">
                                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-chart-surplus opacity-75" />
                                        <span className="relative inline-flex size-1.5 rounded-full bg-chart-surplus" />
                                    </span>
                                    Simulated active
                                </span>
                            </div>
                            <p className="text-xs text-muted">
                                Real-time household production, consumption balance & forecast
                            </p>
                        </div>
                    </div>

                    <IllustrativeChip label="Illustrative dashboard preview" />
                </div>

                {/* Supporting compact elements (4 metric tiles) */}
                <div className="mt-5 grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    {/* Solar production */}
                    <div
                        className={`rounded-xl border border-border/80 bg-surface-alt/50 p-3.5 transition-all duration-200 ${
                            isHovered ? 'border-primary/30 bg-primary-soft/10' : ''
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted">Solar production</span>
                            <Sun
                                className={`size-4 text-warning transition-transform duration-200 ${
                                    isHovered ? '-translate-y-0.5 rotate-12' : ''
                                }`}
                                aria-hidden="true"
                            />
                        </div>
                        <div className="mt-2 flex items-baseline gap-1.5">
                            <span className="font-display text-xl sm:text-2xl font-bold tracking-tight text-heading">
                                4.8
                            </span>
                            <span className="text-xs font-semibold text-muted">kW</span>
                        </div>
                        <p className="mt-1 text-[11px] text-secondary font-medium">
                            Peak today: 5.2 kW
                        </p>
                    </div>

                    {/* Household consumption */}
                    <div
                        className={`rounded-xl border border-border/80 bg-surface-alt/50 p-3.5 transition-all duration-200 ${
                            isHovered ? 'border-border' : ''
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted">Household demand</span>
                            <Zap
                                className={`size-4 text-heading transition-transform duration-200 ${
                                    isHovered ? '-translate-y-0.5' : ''
                                }`}
                                aria-hidden="true"
                            />
                        </div>
                        <div className="mt-2 flex items-baseline gap-1.5">
                            <span className="font-display text-xl sm:text-2xl font-bold tracking-tight text-heading">
                                1.9
                            </span>
                            <span className="text-xs font-semibold text-muted">kW</span>
                        </div>
                        <p className="mt-1 text-[11px] text-muted">
                            Baseline: 0.8 kW
                        </p>
                    </div>

                    {/* Energy balance */}
                    <div
                        className={`rounded-xl border border-border/80 bg-surface-alt/50 p-3.5 transition-all duration-200 ${
                            isHovered ? 'border-chart-surplus/40 bg-chart-surplus/5' : ''
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted">Energy balance</span>
                            <ArrowUpRight
                                className={`size-4 text-chart-surplus transition-transform duration-200 ${
                                    isHovered ? '-translate-y-0.5 translate-x-0.5' : ''
                                }`}
                                aria-hidden="true"
                            />
                        </div>
                        <div className="mt-2 flex items-baseline gap-1.5">
                            <span className="font-display text-xl sm:text-2xl font-bold tracking-tight text-chart-surplus">
                                +2.9
                            </span>
                            <span className="text-xs font-semibold text-chart-surplus/80">kW</span>
                        </div>
                        <p className="mt-1 text-[11px] text-chart-surplus font-medium">
                            Surplus exporting
                        </p>
                    </div>

                    {/* Solar availability/status */}
                    <div
                        className={`rounded-xl border border-border/80 bg-surface-alt/50 p-3.5 transition-all duration-200 ${
                            isHovered ? 'border-secondary/40' : ''
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted">Solar status</span>
                            <Sparkles
                                className={`size-4 text-secondary transition-transform duration-200 ${
                                    isHovered ? '-translate-y-0.5 rotate-6' : ''
                                }`}
                                aria-hidden="true"
                            />
                        </div>
                        <div className="mt-2 flex items-baseline gap-1.5">
                            <span className="font-display text-xl sm:text-2xl font-bold tracking-tight text-heading">
                                Optimal
                            </span>
                        </div>
                        <p className="mt-1 text-[11px] text-muted">
                            Midday solar window
                        </p>
                    </div>
                </div>

                {/* Primary visual: Solar production vs household consumption trend */}
                <div className="mt-6 border-t border-dashed border-border pt-6">
                    <svg
                        viewBox="0 0 720 320"
                        role="img"
                        aria-label="Illustrative example chart: household solar production and consumption across one example day. Solar rises after sunrise, peaks around midday and ends near sunset; consumption shows morning and evening peaks. Before midday the values are labeled actual, after it forecast as dashed lines. The hours around midday form a potential surplus where the solar curve sits above consumption; early morning and evening form potential deficits."
                        focusable="false"
                        className="h-auto w-full"
                    >
                        {CURVES.grid.map(({ kwh, y }) => (
                            <g key={kwh}>
                                <line
                                    x1={CURVES.plot.left}
                                    y1={y}
                                    x2={CURVES.plot.right}
                                    y2={y}
                                    stroke="var(--spms-border)"
                                    strokeWidth="1"
                                />
                                <text
                                    x={CURVES.plot.left - 8}
                                    y={y + 3.5}
                                    textAnchor="end"
                                    fontSize="10"
                                    fill="var(--spms-muted)"
                                >
                                    {kwh}
                                </text>
                            </g>
                        ))}
                        <text x="14" y="20" fontSize="10" fill="var(--spms-muted)">
                            kWh
                        </text>

                        {CURVES.surplusArea && (
                            <path d={CURVES.surplusArea} fill="var(--spms-chart-surplus)" opacity="0.14" />
                        )}
                        {CURVES.deficitAreas.map((d, i) => (
                            <path key={i} d={d} fill="var(--spms-chart-deficit)" opacity="0.10" />
                        ))}

                        <path d={CURVES.estimateBand} fill="var(--spms-chart-estimated)" opacity="0.12" />

                        <line
                            x1={CURVES.xNow}
                            y1={CURVES.plot.top}
                            x2={CURVES.xNow}
                            y2={CURVES.plot.bottom}
                            stroke="var(--spms-muted)"
                            strokeWidth="1"
                            strokeDasharray="3 4"
                            opacity="0.7"
                        />
                        <text
                            x={CURVES.plot.left + 8}
                            y={CURVES.plot.top + 14}
                            fontSize="10"
                            fontWeight="600"
                            fill="var(--spms-muted)"
                        >
                            MEASURED SO FAR
                        </text>
                        <text
                            x={Number(CURVES.xNow) + 8}
                            y={CURVES.plot.top + 14}
                            fontSize="10"
                            fontWeight="600"
                            fill="var(--spms-secondary)"
                        >
                            FORECAST
                        </text>

                        <ChartPath
                            d={CURVES.solarSolid}
                            fill="none"
                            stroke="var(--spms-chart-solar)"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                            delay={0.05}
                        />
                        <ChartPath
                            d={CURVES.usageSolid}
                            fill="none"
                            stroke="var(--spms-chart-usage)"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                            delay={0.15}
                        />
                        <ChartPath
                            d={CURVES.solarForecast}
                            fill="none"
                            stroke="var(--spms-chart-solar)"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                            strokeDasharray="8 7"
                            opacity="0.85"
                            delay={0.35}
                        />
                        <ChartPath
                            d={CURVES.usageForecast}
                            fill="none"
                            stroke="var(--spms-chart-usage)"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                            strokeDasharray="8 7"
                            opacity="0.85"
                            delay={0.45}
                        />

                        <circle
                            cx={CURVES.nowAnchor.solar.x}
                            cy={CURVES.nowAnchor.solar.solarY}
                            r={isHovered ? 5 : 4}
                            fill="var(--spms-chart-solar)"
                            className="transition-all duration-200"
                        />
                        <circle
                            cx={CURVES.nowAnchor.usage.x}
                            cy={CURVES.nowAnchor.usage.usageY}
                            r={isHovered ? 5 : 4}
                            fill="var(--spms-chart-usage)"
                            className="transition-all duration-200"
                        />

                        {CURVES.ticks.map((tick) => (
                            <text
                                key={tick.hour}
                                x={tick.x}
                                y={CURVES.plot.bottom + 20}
                                textAnchor="middle"
                                fontSize="10"
                                fill="var(--spms-muted)"
                            >
                                {tick.label}
                            </text>
                        ))}
                    </svg>

                    <ul className="mt-5 flex flex-wrap gap-x-5 gap-y-2" aria-label="Chart legend">
                        {LEGEND.map((item) => (
                            <li key={item.label} className="flex items-center gap-2 text-xs text-body">
                                {item.swatch}
                                {item.label}
                            </li>
                        ))}
                    </ul>
                </div>

                {/* Small recommendation/insight element alongside visualization */}
                <div className="mt-6 rounded-xl border border-border bg-surface-alt/70 p-4 sm:p-4.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span aria-hidden="true" className="size-2 rounded-full bg-chart-surplus" />
                            <p className="text-sm font-semibold text-heading">
                                Good solar availability around midday.
                            </p>
                        </div>
                        <IllustrativeChip label="Illustrative example" />
                    </div>
                    <p className="mt-1.5 text-xs sm:text-sm text-body leading-relaxed">
                        Flexible household consumption (such as dishwashers or washing machines) can be
                        shifted toward this high solar surplus window. SPMS advises — it never controls or
                        schedules your appliances.
                    </p>
                </div>
            </motion.div>
        </div>
    )
}
