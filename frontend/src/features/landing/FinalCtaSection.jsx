import { Link } from 'react-router'
import { Button } from '../../components/ui/button'
import { CONTAINER_CLASS, REVEAL, REVEAL_VIEWPORT, revealTransition } from './reveal'
import { motion } from 'motion/react'
import { cn } from '../../lib/utils'

/**
 * FinalCtaSection (O1 — final visual direction) — the calm close: one
 * clear invitation, primary Get Started, secondary Sign In. No
 * illustration, no extra copy. Visually echoes the hero (same container
 * rhythm, centered column).
 */
export function FinalCtaSection() {
    return (
        <section aria-labelledby="final-cta-heading" className="border-t border-border py-20 sm:py-24">
            <motion.div
                initial={REVEAL.hidden}
                whileInView={REVEAL.show}
                viewport={REVEAL_VIEWPORT}
                transition={revealTransition()}
                className={cn(CONTAINER_CLASS, 'mx-auto flex max-w-2xl flex-col items-center text-center')}
            >
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-secondary">
                    Solar Power Management System
                </p>
                <h2
                    id="final-cta-heading"
                    className="mt-3 font-display text-3xl font-semibold text-heading sm:text-4xl"
                >
                    Understand your household energy.
                </h2>
                <p className="mt-4 text-base text-body sm:text-lg">Start with SPMS.</p>
                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <Button size="lg" asChild>
                        <Link to="/register">Get Started</Link>
                    </Button>
                    <Button size="lg" variant="secondary" asChild>
                        <Link to="/login">Sign In</Link>
                    </Button>
                </div>
            </motion.div>
        </section>
    )
}