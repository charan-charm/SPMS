import { motion, useReducedMotion } from 'motion/react'
import { DURATION, EASE } from '../../lib/motion'

/**
 * ChartPath (O1 landing) — a stroke-draw entrance for illustrative
 * line visuals (hero card + solar-vs-consumption chart). The draw is a
 * pathLength animation, which reducedMotion="user" does not collapse on
 * its own, so it is gated explicitly: reduced-motion users get the
 * finished line with no animation.
 */
export function ChartPath({ d, delay = 0, ...pathProps }) {
    const reduced = useReducedMotion()

    if (reduced) {
        return <path d={d} {...pathProps} />
    }

    return (
        <motion.path
            d={d}
            initial={{ pathLength: 0 }}
            whileInView={{ pathLength: 1 }}
            viewport={{ once: true, margin: '-64px' }}
            transition={{
                duration: DURATION.chart / 1000,
                ease: EASE.standard,
                delay,
            }}
            {...pathProps}
        />
    )
}
