import { motion } from 'motion/react'
import { cn } from '../../lib/utils'
import { CONTAINER_CLASS, REVEAL, REVEAL_VIEWPORT, revealTransition } from './reveal'

/**
 * Shared landing section scaffolding (O1) — semantic <section> with
 * scroll offset for the sticky header, one heading block pattern, and
 * a single reveal wrapper. All landing sections compose these so the
 * page reads as one system rather than unrelated cards.
 */

export function Section({ id, labelledBy, alt = false, className, children }) {
    return (
        <section
            id={id}
            aria-labelledby={labelledBy}
            className={cn(
                'scroll-mt-20 py-16 sm:py-20 lg:py-24',
                alt && 'border-y border-border bg-surface',
                className,
            )}
        >
            <div className={CONTAINER_CLASS}>{children}</div>
        </section>
    )
}

export function SectionHeading({ id, eyebrow, title, lead, align = 'center', className }) {
    return (
        <motion.header
            initial={REVEAL.hidden}
            whileInView={REVEAL.show}
            viewport={REVEAL_VIEWPORT}
            transition={revealTransition()}
            className={cn('max-w-2xl', align === 'center' && 'mx-auto text-center', className)}
        >
            {eyebrow && (
                <p className="text-xs font-semibold uppercase tracking-wider text-secondary">
                    {eyebrow}
                </p>
            )}
            <h2 id={id} className="mt-3 font-display text-3xl font-semibold text-heading sm:text-4xl">
                {title}
            </h2>
            {lead && <p className="mt-4 text-base text-body sm:text-lg">{lead}</p>}
        </motion.header>
    )
}

export function Reveal({ delay = 0, className, children }) {
    return (
        <motion.div
            initial={REVEAL.hidden}
            whileInView={REVEAL.show}
            viewport={REVEAL_VIEWPORT}
            transition={revealTransition(delay)}
            className={className}
        >
            {children}
        </motion.div>
    )
}
