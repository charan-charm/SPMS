import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { cn } from '../../lib/utils'

/**
 * SPMSLoadingState — travelling-orb brand loading screen. A warm glowing
 * sun emerges from BEHIND the
 * existing top-right leaf bunch —
 * while loading, the leaf layer is raised above the translucent wash
 * (body.spms-loading-active), so the orb genuinely slips out from behind
 * the foliage — grows along a smooth curved path, and breathes at the
 * center for as long as `isLoading` is true. When the parent sets
 * `isLoading={false}` the orb shrinks along a different curved path and
 * slips back behind the bottom-left leaf bunch before reporting
 * completion via `onExited`. No panel, no orbit, no progress bar, no fake
 * durations — the parent alone controls when loading ends.
 *
 * Presentation only: `title` / `subtitle` / `status` are configurable
 * staging copy, `statusSequence` optionally cycles staged action lines
 * while the orb is centered, `isLoading` is the single control input, and
 * `onExited` is an optional UI-lifecycle callback. No business logic, no
 * API calls.
 *
 * Motion: travel + scale/opacity run through Motion (existing dependency,
 * auto-adapted by the app-root MotionConfig reducedMotion="user" — travel
 * transforms are skipped and the orb simply fades in at center / out in
 * place). The glow breathe and ray rotation are CSS (styles/index.css)
 * and are disabled under prefers-reduced-motion. Start/end points are
 * measured from the rendered container so they track the real leaf-bunch
 * zones at any viewport size.
 */

const ARRIVE_DURATION_S = 1.6
const DEPART_DURATION_S = 1.7
const BREATHE_DURATION_S = 2.6

const SUN_START_SCALE = 0.2
const SUN_CENTER_SCALE = 1.18
const SUN_END_SCALE = 0.18
const BREATHE_PEAK_SCALE = 1.025

// Distinct per-axis curves per phase — the two arcs bow differently, so
// the departure never reads as a mechanical reverse of the arrival.
const ARRIVE_X_EASE = [0.3, 0.1, 0.25, 1]
const ARRIVE_Y_EASE = [0.66, 0, 0.3, 1]
const DEPART_X_EASE = [0.55, 0.05, 0.35, 1]
const DEPART_Y_EASE = [0.3, 0.15, 0.6, 1]

/**
 * The loading sun: a restrained 3D sphere — warm yellow central
 * illumination, deeper orange lower/outer shading (existing tokens),
 * layered soft bloom (the atmospheric halo), subtle rays, and a bright
 * specular highlight suggesting sphericity. Identical in both themes;
 * only the bloom strength adapts (styles/index.css).
 */
export function SunOrb({ className = 'block size-24' } = {}) {
    const uid = useId().replace(/[^a-zA-Z0-9]/g, '')
    const ids = {
        core: `spms-sun-core-${uid}`,
        bloomOuter: `spms-sun-bloom-outer-${uid}`,
        bloomInner: `spms-sun-bloom-inner-${uid}`,
        highlight: `spms-sun-highlight-${uid}`,
    }
    return (
        <svg viewBox="0 0 96 96" aria-hidden="true" focusable="false" className={className}>
            <defs>
                <radialGradient id={ids.core} cx="0.38" cy="0.34" r="0.9">
                    <stop offset="0%" stopColor="color-mix(in srgb, var(--spms-warning) 18%, #FFFFFF)" />
                    <stop offset="0.42" stopColor="var(--spms-warning)" />
                    <stop
                        offset="0.8"
                        stopColor="color-mix(in srgb, var(--spms-warning) 45%, var(--spms-chart-deficit))"
                    />
                    <stop offset="1" stopColor="var(--spms-chart-deficit)" />
                </radialGradient>
                <radialGradient id={ids.bloomOuter}>
                    <stop offset="0%" stopColor="var(--spms-warning)" stopOpacity="0.3" />
                    <stop offset="1" stopColor="var(--spms-warning)" stopOpacity="0" />
                </radialGradient>
                <radialGradient id={ids.bloomInner}>
                    <stop offset="0%" stopColor="var(--spms-warning)" stopOpacity="0.5" />
                    <stop offset="1" stopColor="var(--spms-warning)" stopOpacity="0" />
                </radialGradient>
                <radialGradient id={ids.highlight}>
                    <stop offset="0%" stopColor="#FFFFFF" stopOpacity="0.55" />
                    <stop offset="1" stopColor="#FFFFFF" stopOpacity="0" />
                </radialGradient>
            </defs>
            <g className="spms-sun-orb-bloom">
                <g className="spms-sun-orb-glow-breathe">
                    <circle cx="48" cy="48" r="46" fill={`url(#${ids.bloomOuter})`} />
                    <circle cx="48" cy="48" r="30" fill={`url(#${ids.bloomInner})`} />
                </g>
            </g>
            <g
                className="spms-sun-orb-rays"
                stroke="var(--spms-warning)"
                strokeWidth="2.4"
                strokeLinecap="round"
                opacity="0.9"
            >
                {Array.from({ length: 8 }, (_, index) => {
                    const angle = (index * Math.PI) / 4
                    return (
                        <line
                            key={index}
                            x1={48 + Math.cos(angle) * 24.5}
                            y1={48 + Math.sin(angle) * 24.5}
                            x2={48 + Math.cos(angle) * 30}
                            y2={48 + Math.sin(angle) * 30}
                        />
                    )
                })}
            </g>
            <circle cx="48" cy="48" r="20" fill={`url(#${ids.core})`} />
            <ellipse
                cx="41"
                cy="40"
                rx="8.5"
                ry="6"
                transform="rotate(-24 41 40)"
                fill={`url(#${ids.highlight})`}
            />
        </svg>
    )
}

export function SPMSLoadingState({
    title = 'Harnessing a brighter tomorrow...',
    subtitle = 'Loading your solar insights',
    status = 'Analyzing your energy data...',
    statusSequence,
    isLoading = true,
    onExited,
    className,
}) {
    const containerRef = useRef(null)
    const stageRef = useRef(null)
    const [travel, setTravel] = useState(null)
    const onExitedRef = useRef(onExited)

    // Parent-controlled state machine — no internal timers, no fake duration.
    // Motion completion events advance `arrived`/`exited`; a change of the
    // isLoading prop starts a fresh cycle (React's adjust-state-on-prop-change
    // pattern — deliberately no effects involved).
    const [cycle, setCycle] = useState({ isLoading, arrived: false, exited: !isLoading })
    if (cycle.isLoading !== isLoading) {
        setCycle({ isLoading, arrived: false, exited: false })
    }

    let phase
    if (isLoading) {
        phase = cycle.arrived ? 'center' : 'arriving'
    } else {
        phase = cycle.exited ? 'exited' : 'departing'
    }

    // The copy and its clean-layer pool live only while the orb is on its
    // way or centered — they fade out as the orb departs instead of
    // lingering over the page.
    const copyVisible = phase === 'arriving' || phase === 'center'

    // Staged action copy: while the orb is centered, the status line cycles
    // through `statusSequence` (falls back to the single `status` line).
    const stages = statusSequence && statusSequence.length > 0 ? statusSequence : [status]
    const [stageIndex, setStageIndex] = useState(0)
    useEffect(() => {
        if (phase !== 'center' || stages.length < 2) return undefined
        const timer = setInterval(() => {
            setStageIndex((current) => (current + 1) % stages.length)
        }, 2600)
        return () => clearInterval(timer)
    }, [phase, stages.length])

    // Layering (whole loading lifecycle): the existing leaf layer is raised
    // above the loading wash so the orb genuinely starts and ends behind
    // the foliage. Released only when the overlay unmounts.
    useEffect(() => {
        document.body.classList.add('spms-loading-active')
        return () => document.body.classList.remove('spms-loading-active')
    }, [])

    // Visual emphasis (arrival + center only): released as the departure
    // BEGINS, so the leaves softly recede — via opacity/scale transitions,
    // never a fill/theme color interpolation — while the sun travels home.
    useEffect(() => {
        if (phase === 'departing' || phase === 'exited') return undefined
        document.body.classList.add('spms-leaf-emphasis')
        return () => document.body.classList.remove('spms-leaf-emphasis')
    }, [phase])

    useEffect(() => {
        onExitedRef.current = onExited
    })

    useEffect(() => {
        if (phase === 'exited') onExitedRef.current?.()
    }, [phase])

    const handleOrbComplete = useCallback(() => {
        setCycle((current) => {
            if (current.isLoading) {
                return current.arrived ? current : { ...current, arrived: true }
            }
            return current.exited ? current : { ...current, exited: true }
        })
    }, [])

    // Start/end points sit inside the real leaf-bunch zones of the
    // surrounding container (top-right and bottom-left corners), measured
    // from the stage center so the orb travels leaves → center → leaves.
    useLayoutEffect(() => {
        const container = containerRef.current
        const stage = stageRef.current
        if (!container || !stage) return undefined
        const clamp = (value, min, max) => Math.min(max, Math.max(min, value))
        const measure = () => {
            const c = container.getBoundingClientRect()
            const s = stage.getBoundingClientRect()
            const stageCenterX = s.left - c.left + s.width / 2
            const stageCenterY = s.top - c.top + s.height / 2
            const arriveInsetX = clamp(c.width * 0.075, 48, 88)
            const arriveInsetY = clamp(c.height * 0.15, 64, 128)
            const departInsetX = clamp(c.width * 0.085, 48, 96)
            const departInsetY = clamp(c.height * 0.14, 56, 112)
            setTravel({
                ax: c.width - arriveInsetX - stageCenterX,
                ay: arriveInsetY - stageCenterY,
                dx: departInsetX - stageCenterX,
                dy: c.height - departInsetY - stageCenterY,
            })
        }
        measure()
        window.addEventListener('resize', measure)
        return () => window.removeEventListener('resize', measure)
    }, [])

    // `null` keyframes continue from wherever the orb currently is — a
    // mid-arrival cancellation departs smoothly instead of snapping.
    const orbAnimate = (() => {
        if (!travel) return { opacity: 0 }
        if (phase === 'arriving') {
            return {
                x: [travel.ax, 0],
                y: [travel.ay, 0],
                scale: [SUN_START_SCALE, SUN_CENTER_SCALE],
                // Fades in fast while still among the leaves — it reads as
                // emerging from behind the foliage.
                opacity: [0, 0.7, 1, 1],
                transition: {
                    x: { duration: ARRIVE_DURATION_S, ease: ARRIVE_X_EASE },
                    y: { duration: ARRIVE_DURATION_S, ease: ARRIVE_Y_EASE },
                    scale: { duration: ARRIVE_DURATION_S, ease: 'easeInOut' },
                    opacity: { duration: ARRIVE_DURATION_S, times: [0, 0.2, 0.5, 1], ease: 'easeOut' },
                },
            }
        }
        if (phase === 'center') {
            return {
                x: 0,
                y: 0,
                scale: SUN_CENTER_SCALE,
                opacity: 1,
                transition: { duration: 0.25 },
            }
        }
        if (phase === 'departing') {
            return {
                x: [null, travel.dx],
                y: [null, travel.dy],
                scale: [null, SUN_END_SCALE],
                // Stays fully visible through the shrink — the leaf bunch
                // itself swallows the orb at the end of the path.
                opacity: [null, 1, 1, 0.6, 0],
                transition: {
                    x: { duration: DEPART_DURATION_S, ease: DEPART_X_EASE },
                    y: { duration: DEPART_DURATION_S, ease: DEPART_Y_EASE },
                    scale: { duration: DEPART_DURATION_S, ease: 'easeInOut' },
                    opacity: {
                        duration: DEPART_DURATION_S,
                        times: [0, 0.3, 0.8, 0.93, 1],
                        ease: 'easeIn',
                    },
                },
            }
        }
        return { x: travel.dx, y: travel.dy, scale: SUN_END_SCALE, opacity: 0, transition: { duration: 0 } }
    })()

    return (
        <div
            ref={containerRef}
            className={cn(
                'relative flex w-full flex-col items-center justify-center gap-3',
                className,
            )}
        >
            {/* Dedicated clean center layer — a soft pool of background color
                that subdues the underlying page around the composition
                (no visible box), so the sun and copy never compete with
                card text behind them. Fades away with the copy. */}
            <motion.div
                aria-hidden="true"
                className="pointer-events-none absolute left-1/2 top-1/2 h-[26rem] w-[34rem] max-w-full -translate-x-1/2 -translate-y-1/2"
                style={{
                    background:
                        'radial-gradient(closest-side, var(--spms-background) 38%, transparent)',
                }}
                initial={{ opacity: 0 }}
                animate={{ opacity: copyVisible ? 1 : 0 }}
                transition={{ duration: 0.7, ease: 'easeInOut' }}
            />

            {/* Orb stage — center of the loading composition; text sits below,
                so the orb can never overlap the copy. The orb renders above
                the page wash but below the raised leaf layer, so its path
                starts and ends behind the real foliage. */}
            <div ref={stageRef} className="relative flex h-52 w-full items-center justify-center sm:h-64">
                {travel && (
                    <motion.div
                        className="pointer-events-none will-change-transform"
                        initial={{
                            x: travel.ax,
                            y: travel.ay,
                            scale: SUN_START_SCALE,
                            opacity: 0,
                        }}
                        animate={orbAnimate}
                        onAnimationComplete={handleOrbComplete}
                    >
                        <motion.div
                            animate={{
                                scale: [
                                    SUN_CENTER_SCALE,
                                    SUN_CENTER_SCALE * BREATHE_PEAK_SCALE,
                                    SUN_CENTER_SCALE,
                                ],
                            }}
                            transition={{ duration: BREATHE_DURATION_S, ease: 'easeInOut', repeat: Infinity }}
                            className="will-change-transform"
                        >
                            <SunOrb />
                        </motion.div>
                    </motion.div>
                )}
            </div>

            <motion.div
                className="text-center"
                initial={{ opacity: 0, y: 6 }}
                animate={copyVisible ? { opacity: 1, y: 0 } : { opacity: 0, y: 6 }}
                transition={{ duration: 0.7, ease: 'easeInOut' }}
            >
                <p className="font-display text-lg font-semibold text-heading">{title}</p>
                <p className="mt-1 text-sm font-medium text-body">{subtitle}</p>
                <div className="relative mx-auto mt-1.5 h-5 w-64 max-w-full">
                    <AnimatePresence mode="wait">
                        <motion.p
                            key={`${stageIndex}-${stages[stageIndex % stages.length]}`}
                            initial={{ opacity: 0, y: 4 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -4 }}
                            transition={{ duration: 0.35, ease: 'easeInOut' }}
                            className="absolute inset-0 truncate text-xs text-muted"
                        >
                            {stages[stageIndex % stages.length]}
                        </motion.p>
                    </AnimatePresence>
                </div>
            </motion.div>
        </div>
    )
}
