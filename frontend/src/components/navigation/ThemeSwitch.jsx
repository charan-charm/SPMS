import { AnimatePresence, motion } from 'motion/react'
import { Sun, Moon } from 'lucide-react'
import { useTheme } from '../../hooks/useTheme'
import { DURATION, EASE } from '../../lib/motion'
import { cn } from '../../lib/utils'

const TRACK_W = 48
const KNOB = 22
const KNOB_TRAVEL = TRACK_W - KNOB - 6 // 3px inset each side

/**
 * ThemeSwitch (F3 refinement 2.1) — the single header theme control.
 *
 * - Clean compact switch: Light ↔ Dark only. No dropdown, chevron, or
 *   popup in the header; the underlying theme architecture still
 *   supports "system" internally (a future Settings surface can expose
 *   it without a second theme mechanism).
 * - Light/premium knob: theme-following surface (white in light, deep
 *   slate in dark) with a subtle border and restrained elevation —
 *   never a heavy colored pill.
 * - Sun ↔ Moon crossfade inside the knob (~250ms, standard ease, small
 *   slide + restrained rotation/scale; no bounce, no glow). Reduced
 *   motion (MotionConfig reducedMotion="user") collapses the movement
 *   to an instant, state-correct change.
 * - Accessible: role="switch", aria-checked, dynamic aria-label,
 *   keyboard focusable with visible focus ring.
 */
export function ThemeSwitch({ className }) {
    const { resolvedTheme, setTheme } = useTheme()
    const isDark = resolvedTheme === 'dark'

    return (
        <button
            type="button"
            role="switch"
            aria-checked={isDark}
            aria-label={isDark ? 'Switch to light theme' : 'Switch to dark theme'}
            title={isDark ? 'Switch to light theme' : 'Switch to dark theme'}
            onClick={() => setTheme(isDark ? 'light' : 'dark')}
            className={cn(
                'relative inline-flex shrink-0 cursor-pointer rounded-full border border-border bg-muted/15',
                'transition-colors duration-150 hover:bg-muted/25',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                className,
            )}
            style={{ width: TRACK_W, height: 28 }}
        >
            {/* Sliding knob — theme-following surface, subtle border + elevation */}
            <motion.span
                aria-hidden="true"
                initial={false}
                animate={{ x: isDark ? KNOB_TRAVEL : 0 }}
                transition={{ duration: DURATION.theme / 1000, ease: EASE.standard }}
                className="absolute top-[3px] left-[3px] grid place-items-center rounded-full border border-border bg-surface shadow-1"
                style={{ width: KNOB, height: KNOB }}
            >
                {/* Sun (light) / Moon (dark) — restrained crossfade + rotation */}
                <span className="relative grid size-3.5 place-items-center">
                    <AnimatePresence initial={false}>
                        {isDark ? (
                            <motion.span
                                key="moon"
                                initial={{ opacity: 0, rotate: -30, scale: 0.6 }}
                                animate={{ opacity: 1, rotate: 0, scale: 1 }}
                                exit={{ opacity: 0, rotate: 30, scale: 0.6 }}
                                transition={{ duration: DURATION.theme / 1000, ease: EASE.standard }}
                                className="absolute inset-0 grid place-items-center"
                            >
                                <Moon className="size-3 text-heading" aria-hidden="true" />
                            </motion.span>
                        ) : (
                            <motion.span
                                key="sun"
                                initial={{ opacity: 0, rotate: 30, scale: 0.6 }}
                                animate={{ opacity: 1, rotate: 0, scale: 1 }}
                                exit={{ opacity: 0, rotate: -30, scale: 0.6 }}
                                transition={{ duration: DURATION.theme / 1000, ease: EASE.standard }}
                                className="absolute inset-0 grid place-items-center"
                            >
                                <Sun className="size-3 text-warning" aria-hidden="true" />
                            </motion.span>
                        )}
                    </AnimatePresence>
                </span>
            </motion.span>
        </button>
    )
}
