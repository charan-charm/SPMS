import { motion, useReducedMotion } from 'motion/react'

/**
 * PublicLandingLeaves — fixed-position botanical corner frame for the
 * public landing page.
 *
 * EXACTLY THREE leaf silhouettes per corner arrangement:
 *   - TOP-RIGHT: 3 leaves fanning from the top-right viewport corner
 *   - BOTTOM-LEFT: 3 leaves fanning from the bottom-left viewport corner
 *
 * No other leaf positions. No clusters. No bunches. No top-left. No
 * bottom-right. No vines. No stems. No veins.
 *
 * Layering:
 * - position: fixed, z-index 1 (above body background, below all content)
 * - pointer-events: none — never intercepts interaction
 * - overflow: hidden per corner wrapper — no horizontal scroll
 * - sits below the sticky header (z-40) automatically
 *
 * Motion:
 * - top-right sways on spms-leaf-sway-a (11s)
 * - bottom-left sways on spms-leaf-sway-b (12.5s)
 * - prefers-reduced-motion: frozen by existing CSS
 *
 * Theme:
 * - light: subtle pale green (.spms-leaf-fill → --spms-primary-soft)
 * - dark:  subtle pale teal (.spms-leaf-fill → --spms-secondary-soft)
 */

/** Elongated botanical leaf — base at origin, tip at (0,-130). */
const LEAF_PATH = 'M0 0 C -22 -34 -20 -86 0 -130 C 20 -86 22 -34 0 0 Z'

function Leaf({ cx, cy, rotate, scale }) {
    return (
        <g transform={`translate(${cx} ${cy}) rotate(${rotate}) scale(${scale})`}>
            <path d={LEAF_PATH} className="spms-leaf-fill" />
        </g>
    )
}

/**
 * TOP-RIGHT: 3 leaves originating at (200,1) right near the header bottom line.
 * Inverted match of bottom-left fan, fine-tuned precision placement near fixed header.
 */
const TOP_RIGHT_LEAVES = [
    { cx: 200, cy: 1, rotate: -165, scale: 1.1 },
    { cx: 200, cy: 1, rotate: -132, scale: 0.95 },
    { cx: 200, cy: 1, rotate: -100, scale: 0.8 },
]

/**
 * BOTTOM-LEFT: 3 leaves originating from the exact bottom-left corner point (0,200).
 * Base of all 3 leaves coincides with the bottom-left viewport corner.
 */
const BOTTOM_LEFT_LEAVES = [
    { cx: 0, cy: 200, rotate: 15, scale: 1.1 },
    { cx: 0, cy: 200, rotate: 50, scale: 0.95 },
    { cx: 0, cy: 200, rotate: 85, scale: 0.8 },
]

export function PublicLandingLeaves() {
    const reducedMotion = useReducedMotion()

    return (
        <div
            aria-hidden="true"
            className="pointer-events-none fixed inset-0 z-0 select-none overflow-hidden max-w-full"
        >
            {/* TOP-RIGHT — 3 leaves originating below the header, fades in in sync with the solar region bloom */}
            <motion.div
                initial={reducedMotion ? { opacity: 1 } : { opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={
                    reducedMotion
                        ? { duration: 0 }
                        : { duration: 0.75, delay: 1.05, ease: [0.25, 1, 0.5, 1] }
                }
                className="absolute top-16 right-0 h-[180px] w-[180px] sm:h-[240px] sm:w-[240px] lg:h-[290px] lg:w-[290px] overflow-hidden pointer-events-none"
            >
                <svg
                    viewBox="0 0 200 200"
                    aria-hidden="true"
                    focusable="false"
                    className="spms-leaf-sway-a h-full w-full"
                    style={{ opacity: 'var(--spms-landing-leaf-opacity, 0.72)' }}
                >
                    {TOP_RIGHT_LEAVES.map((leaf, i) => (
                        <Leaf key={i} {...leaf} />
                    ))}
                </svg>
            </motion.div>

            {/* BOTTOM-LEFT — 3 leaves originating from exact bottom-left corner, fades in in sync with solar region bloom */}
            <motion.div
                initial={reducedMotion ? { opacity: 1 } : { opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={
                    reducedMotion
                        ? { duration: 0 }
                        : { duration: 0.75, delay: 1.05, ease: [0.25, 1, 0.5, 1] }
                }
                className="absolute bottom-0 left-0 h-[180px] w-[180px] sm:h-[240px] sm:w-[240px] lg:h-[290px] lg:w-[290px] overflow-hidden pointer-events-none"
            >
                <svg
                    viewBox="0 0 200 200"
                    aria-hidden="true"
                    focusable="false"
                    className="spms-leaf-sway-b h-full w-full"
                    style={{ opacity: 'var(--spms-landing-leaf-opacity, 0.72)' }}
                >
                    {BOTTOM_LEFT_LEAVES.map((leaf, i) => (
                        <Leaf key={i} {...leaf} />
                    ))}
                </svg>
            </motion.div>
        </div>
    )
}
