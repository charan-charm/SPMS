import { useRef, useState } from 'react'
import { motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react'
import { IllustrativeChip } from './IllustrativeChip'
import { ChartPath } from './ChartPath'
import { buildDayComparison } from './dayCurve'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * SolarVsUsageSection (O1 — single graph card composition)
 *
 * Displays the core interactive 24-hour solar production vs household
 * consumption graph inside ONE single clean card container.
 * Child metric tiles (solar production, household demand, energy balance,
 * status) are removed from this section per owner directive.
 *
 * SolarDashboardCard remains preserved in the project for other uses.
 */

const CURVES = buildDayComparison()

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
    { swatch: <LegendLine color="var(--spms-chart-solar)" />, label: 'Solar — actual' },
    { swatch: <LegendLine color="var(--spms-chart-usage)" />, label: 'Consumption — actual' },
    { swatch: <LegendLine color="var(--spms-chart-solar)" dashed />, label: 'Solar — forecast' },
    { swatch: <LegendLine color="var(--spms-chart-usage)" dashed />, label: 'Consumption — forecast' },
    {
        swatch: <span aria-hidden="true" className="size-2.5 shrink-0 rounded-sm bg-chart-surplus/60" />,
        label: 'Potential surplus',
    },
    {
        swatch: <span aria-hidden="true" className="size-2.5 shrink-0 rounded-sm bg-chart-deficit/50" />,
        label: 'Potential deficit',
    },
]

const TILT_SPRING = { stiffness: 120, damping: 20, mass: 0.5 }

export function SolarVsUsageSection() {
    const cardRef = useRef(null)
    const reducedMotion = useReducedMotion()
    const [isHovered, setIsHovered] = useState(false)

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
        <Section id="solar-usage" labelledBy="solar-usage-heading">
            <SectionHeading
                id="solar-usage-heading"
                eyebrow="The core picture"
                title="Solar vs Household Energy"
                lead="See how solar production and household demand move through the day."
            />

            <Reveal className="mt-10 sm:mt-12">
                {/* ONE single card holding the complete interactive graph */}
                <div style={{ perspective: 1000 }} className="w-full">
                    <motion.div
                        ref={cardRef}
                        onPointerMove={handlePointerMove}
                        onPointerEnter={handlePointerEnter}
                        onPointerLeave={handlePointerLeave}
                        style={
                            reducedMotion
                                ? undefined
                                : { rotateX, rotateY, transformStyle: 'preserve-3d' }
                        }
                        className={`relative rounded-2xl border bg-surface p-5 sm:p-7 transition-all duration-200 ${
                            isHovered
                                ? 'border-primary/40 -translate-y-1 shadow-xl shadow-primary/5 dark:shadow-primary/10'
                                : 'border-border shadow-sm'
                        }`}
                    >
                        {/* Card Header — Graph Title + Illustrative badge */}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-border pb-5">
                            <p className="text-sm font-semibold text-heading">
                                Solar production vs household consumption — one illustrative day
                            </p>
                            <IllustrativeChip label="Illustrative" />
                        </div>

                        {/* Complete interactive SVG graph */}
                        <div className="mt-6">
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

                            {/* Chart legend */}
                            <ul className="mt-5 flex flex-wrap gap-x-5 gap-y-2" aria-label="Chart legend">
                                {LEGEND.map((item) => (
                                    <li key={item.label} className="flex items-center gap-2 text-xs text-body">
                                        {item.swatch}
                                        {item.label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </motion.div>
                </div>
            </Reveal>

            <Reveal delay={0.08} className="mt-6">
                <p className="mx-auto max-w-2xl text-center text-sm text-muted">
                    SPMS can also use electricity-bill information to understand household
                    usage and cost context.{' '}
                    <IllustrativeChip className="align-middle" label="Illustrative" />
                </p>
            </Reveal>
        </Section>
    )
}