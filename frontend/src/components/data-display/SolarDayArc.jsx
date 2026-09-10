import { useId } from 'react'
import { motion, useReducedMotion } from 'motion/react'
import { Sun } from 'lucide-react'
import { EmptyState } from '../feedback/EmptyState'
import { ErrorState } from '../feedback/ErrorState'
import { DataNatureBadge } from './DataNatureBadge'
import { StatusBadge } from './StatusBadge'
import { cn } from '../../lib/utils'

/**
 * SolarDayArc — reusable data-driven solar-day visualization (docs/12
 * conventions). Presents the daylight period as a smooth half-elliptical
 * arc (sunrise → daylight → solar peak → afternoon → sunset) with ONE
 * polished SPMS sun marker interpolated along it from real values.
 *
 * Honest-data rules: the parent owns every time value; the component only
 * parses, interpolates, and presents. Missing or invalid sunrise/sunset
 * render an empty state (never invented 6 AM / 6 PM values). A missing
 * solarPeak renders no peak marker or label (the geometric midpoint is
 * never falsely labeled). A currentTime outside the daylight period hides
 * the sun instead of implying sunlight exists there. A currentTime that
 * fails to parse is treated as absent.
 *
 * States: ERROR → ErrorState (optional retry). Missing/untrustworthy
 * times → EmptyState (unavailable or insufficient variant). STALE /
 * DEGRADED / UNAVAILABLE-status and ACTUAL / PREDICTED / ESTIMATED /
 * SIMULATED natures are surfaced through the existing badge semantics via
 * the `status` / `nature` props.
 *
 * Accessibility: the SVG geometry is decorative; a visually hidden
 * sentence describes the solar day and the current position, so nothing
 * depends on color or position alone. When currentTime changes, the sun
 * glides to its new position (~550ms; instant under reduced motion).
 */

const VIEW_W = 400
const VIEW_H = 228
const CX = 200
const CY = 176
const RX = 152
const RY = 116

const ARC_PATH = `M ${CX - RX} ${CY} A ${RX} ${RY} 0 0 1 ${CX + RX} ${CY}`
const DAYLIGHT_PATH = `${ARC_PATH} Z`

function parseTime(value) {
    if (typeof value !== 'string') return null
    const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim())
    if (!match) return null
    const hours = Number(match[1])
    const minutes = Number(match[2])
    if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null
    return hours * 60 + minutes
}

function formatTime(minutes) {
    const point = new Date(2000, 0, 1, Math.floor(minutes / 60), minutes % 60)
    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(point)
}

function round2(value) {
    return Math.round(value * 100) / 100
}

// Parametric half-ellipse: t = 0 → sunrise endpoint (left), t = 0.5 →
// top-center, t = 1 → sunset endpoint (right). Time maps linearly to arc
// angle — one mathematical interpolation, no hand-placed positions.
function arcPoint(minutes, sunriseMin, sunsetMin) {
    const t = (minutes - sunriseMin) / (sunsetMin - sunriseMin)
    const angle = Math.PI * (1 - t)
    return {
        t,
        x: round2(CX + RX * Math.cos(angle)),
        y: round2(CY - RY * Math.sin(angle)),
    }
}

export function SolarDayArc({
    sunrise = null,
    solarPeak = null,
    sunset = null,
    currentTime = null,
    date = null,
    status = null,
    nature = null,
    onRetry = null,
    className,
}) {
    const reducedMotion = useReducedMotion()
    const uid = useId().replace(/[^a-zA-Z0-9]/g, '')
    const ids = {
        daylight: `spms-daylight-${uid}`,
        sunCore: `spms-sun-core-${uid}`,
        sunHalo: `spms-sun-halo-${uid}`,
        sunBloom: `spms-sun-bloom-${uid}`,
        arcGlow: `spms-arc-glow-${uid}`,
    }

    if (status === 'ERROR') {
        return (
            <div className={cn('w-full', className)}>
                <ErrorState
                    title="Solar day unavailable"
                    message="The solar day information could not be loaded."
                    retryLabel={onRetry ? 'Retry' : null}
                    onRetry={onRetry}
                />
            </div>
        )
    }

    const sunriseMin = parseTime(sunrise)
    const sunsetMin = parseTime(sunset)
    const peakMin = parseTime(solarPeak)
    const currentMin = parseTime(currentTime)
    const validDay = sunriseMin != null && sunsetMin != null && sunsetMin > sunriseMin

    if (!validDay) {
        return (
            <div className={cn('w-full', className)}>
                <EmptyState
                    variant={status === 'UNAVAILABLE' ? 'unavailable' : 'insufficient'}
                    icon={Sun}
                    explanation="Real sunrise and sunset times are needed to draw the solar day arc."
                    limitation="Times are never invented — the arc appears once trustworthy solar data exists."
                />
            </div>
        )
    }

    const peakValid = peakMin != null && peakMin > sunriseMin && peakMin < sunsetMin
    const peakPoint = peakValid ? arcPoint(peakMin, sunriseMin, sunsetMin) : null

    const currentPoint = currentMin != null ? arcPoint(currentMin, sunriseMin, sunsetMin) : null
    const inDaylight = currentPoint != null && currentPoint.t >= 0 && currentPoint.t <= 1
    const currentNote =
        currentPoint == null ? null : currentPoint.t < 0 ? 'Before sunrise' : currentPoint.t > 1 ? 'After sunset' : null

    const orbTransition = { duration: reducedMotion ? 0 : 0.55, ease: 'easeInOut' }

    const summaryParts = [
        `Solar day from ${formatTime(sunriseMin)} sunrise to ${formatTime(sunsetMin)} sunset.`,
    ]
    if (peakValid) {
        summaryParts.push(` Solar peak at ${formatTime(peakMin)}.`)
    }
    if (currentMin != null) {
        summaryParts.push(
            inDaylight
                ? ` Current solar position: ${formatTime(currentMin)}.`
                : ` ${currentNote} (${formatTime(currentMin)}).`,
        )
    }
    if (date) {
        summaryParts.push(` Day: ${date}.`)
    }

    return (
        <div className={cn('w-full', className)}>
            <p className="sr-only">{summaryParts.join('')}</p>

            <svg
                viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
                aria-hidden="true"
                focusable="false"
                className="h-auto w-full"
            >
                <defs>
                    <linearGradient id={ids.daylight} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--spms-warning)" stopOpacity="0.1" />
                        <stop offset="100%" stopColor="var(--spms-warning)" stopOpacity="0.02" />
                    </linearGradient>
                    <radialGradient id={ids.sunCore} cx="0.38" cy="0.34" r="0.9">
                        <stop offset="0%" stopColor="color-mix(in srgb, var(--spms-warning) 18%, #FFFFFF)" />
                        <stop offset="0.42" stopColor="var(--spms-warning)" />
                        <stop
                            offset="0.78"
                            stopColor="color-mix(in srgb, var(--spms-warning) 45%, var(--spms-chart-deficit))"
                        />
                        <stop offset="1" stopColor="var(--spms-chart-deficit)" />
                    </radialGradient>
                    <radialGradient id={ids.sunHalo}>
                        <stop offset="0%" stopColor="var(--spms-warning)" stopOpacity="0.3" />
                        <stop offset="1" stopColor="var(--spms-warning)" stopOpacity="0" />
                    </radialGradient>
                    <radialGradient id={ids.sunBloom}>
                        <stop offset="0%" stopColor="var(--spms-warning)" stopOpacity="0.5" />
                        <stop offset="1" stopColor="var(--spms-warning)" stopOpacity="0" />
                    </radialGradient>
                    <filter id={ids.arcGlow} x="-20%" y="-40%" width="140%" height="180%">
                        <feGaussianBlur stdDeviation="3" />
                    </filter>
                </defs>

                {/* Environmental baseline — extremely subtle, no landscape */}
                <path d={DAYLIGHT_PATH} fill={`url(#${ids.daylight})`} />
                <line
                    x1="28"
                    y1={CY}
                    x2={VIEW_W - 28}
                    y2={CY}
                    stroke="var(--spms-border)"
                    strokeWidth="1"
                    strokeOpacity="0.8"
                />

                {/* Daylight trajectory — ONE thin dashed warm path (dark theme
                    adds a restrained warm under-glow via styles/index.css) */}
                <path
                    className="spms-day-arc-glow"
                    d={ARC_PATH}
                    fill="none"
                    strokeWidth="5"
                    strokeDasharray="5 6"
                    strokeLinecap="round"
                    filter={`url(#${ids.arcGlow})`}
                />
                <path
                    className="spms-day-arc-line"
                    d={ARC_PATH}
                    fill="none"
                    strokeWidth="1.75"
                    strokeDasharray="5 6"
                    strokeLinecap="round"
                />

                {/* Solar Peak — only when the parent supplied a real value */}
                {peakPoint && (
                    <g>
                        <circle
                            cx={peakPoint.x}
                            cy={peakPoint.y}
                            r="2.5"
                            fill="var(--spms-warning)"
                            opacity="0.85"
                        />
                        <text
                            x={peakPoint.x}
                            y={peakPoint.y - 22}
                            textAnchor="middle"
                            fontSize="10"
                            fill="var(--spms-muted)"
                        >
                            Solar Peak
                        </text>
                        <text
                            x={peakPoint.x}
                            y={peakPoint.y - 10}
                            textAnchor="middle"
                            fontSize="11.5"
                            fontWeight="600"
                            fill="var(--spms-heading)"
                        >
                            {formatTime(peakMin)}
                        </text>
                    </g>
                )}

                {/* Endpoint anchors — tiny warm dots at the real data positions */}
                <circle cx={CX - RX} cy={CY} r="3" fill="var(--spms-warning)" opacity="0.9" />
                <circle cx={CX + RX} cy={CY} r="3" fill="var(--spms-warning)" opacity="0.9" />

                {/* Current-position guide + current time */}
                {currentPoint && (
                    <motion.line
                        initial={false}
                        animate={{
                            x1: currentPoint.x,
                            x2: currentPoint.x,
                            y1: currentPoint.y + 13,
                            y2: CY,
                            opacity: inDaylight ? 0.45 : 0,
                        }}
                        transition={orbTransition}
                        stroke="var(--spms-muted)"
                        strokeWidth="1"
                        strokeDasharray="2 4"
                    />
                )}
                {currentMin != null && (
                    <g textAnchor="middle">
                        <text x={CX} y={CY + 30} fontSize="10" fill="var(--spms-muted)">
                            {currentNote ?? 'Now'}
                        </text>
                        <text
                            x={CX}
                            y={CY + 44}
                            fontSize="12.5"
                            fontWeight="600"
                            fill="var(--spms-heading)"
                        >
                            {formatTime(currentMin)}
                        </text>
                    </g>
                )}

                {/* Endpoint labels — generated from supplied data */}
                <text x={CX - RX} y={CY + 30} fontSize="10" fill="var(--spms-muted)">
                    Sunrise
                </text>
                <text
                    x={CX - RX}
                    y={CY + 44}
                    fontSize="12.5"
                    fontWeight="600"
                    fill="var(--spms-heading)"
                >
                    {formatTime(sunriseMin)}
                </text>
                <text
                    x={CX + RX}
                    y={CY + 30}
                    textAnchor="end"
                    fontSize="10"
                    fill="var(--spms-muted)"
                >
                    Sunset
                </text>
                <text
                    x={CX + RX}
                    y={CY + 44}
                    textAnchor="end"
                    fontSize="12.5"
                    fontWeight="600"
                    fill="var(--spms-heading)"
                >
                    {formatTime(sunsetMin)}
                </text>

                {/* The SPMS sun — one primary indicator, the hero of the arc */}
                {currentPoint && (
                    <motion.g
                        initial={false}
                        animate={{
                            x: currentPoint.x,
                            y: currentPoint.y,
                            opacity: inDaylight ? 1 : 0,
                        }}
                        transition={orbTransition}
                    >
                        <g className="spms-day-sun-bloom">
                            <circle cx="0" cy="0" r="23" fill={`url(#${ids.sunHalo})`} />
                            <circle cx="0" cy="0" r="13" fill={`url(#${ids.sunBloom})`} />
                        </g>
                        <g
                            stroke="var(--spms-warning)"
                            strokeWidth="2"
                            strokeLinecap="round"
                            opacity="0.95"
                        >
                            {Array.from({ length: 8 }, (_, index) => {
                                const angle = (index * Math.PI) / 4
                                return (
                                    <line
                                        key={index}
                                        x1={round2(Math.cos(angle) * 12.5)}
                                        y1={round2(Math.sin(angle) * 12.5)}
                                        x2={round2(Math.cos(angle) * 16.5)}
                                        y2={round2(Math.sin(angle) * 16.5)}
                                    />
                                )
                            })}
                        </g>
                        <circle cx="0" cy="0" r="9.5" fill={`url(#${ids.sunCore})`} />
                        <ellipse
                            cx="-3"
                            cy="-3"
                            rx="3.8"
                            ry="2.6"
                            transform="rotate(-24 -3 -3)"
                            fill="#FFFFFF"
                            opacity="0.5"
                        />
                    </motion.g>
                )}
            </svg>

            {(nature || status) && (
                <div className="mt-1 flex flex-wrap items-center justify-center gap-2">
                    {nature && <DataNatureBadge nature={nature} />}
                    {status && <StatusBadge status={status} />}
                </div>
            )}
        </div>
    )
}
