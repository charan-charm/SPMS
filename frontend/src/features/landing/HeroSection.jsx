import { useRef } from 'react'
import { motion, useMotionValue, useReducedMotion, useSpring } from 'motion/react'
import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '../../components/ui/button'
import { HeroAmbience } from './HeroAmbience'
import { EASE } from '../../lib/motion'

/**
 * HeroSection (O1 — final hero correction) — one centered, text-first
 * column: eyebrow, headline, one supporting line and the two calls to
 * action. There is no HeroDayCard, no right-side product card, no
 * dashboard mockup, no bill content and no large illustration; the
 * headline is the hero and the whitespace does the work.
 *
 * The only decoration is HeroAmbience (small leaves near the far edges
 * and one small warm sun). Pointer parallax is optional and extremely
 * restrained — a few bounded pixels of ambient drift driven by springs,
 * never cursor-following content, and skipped entirely for
 * prefers-reduced-motion users. Purely atmospheric: no API, no solar or
 * demo data, no metrics.
 */

const HERO_REVEAL = {
    hidden: { opacity: 0, y: 24 },
    show: { opacity: 1, y: 0 },
}

function heroTransition(delay, reducedMotion) {
    if (reducedMotion) {
        return { duration: 0, delay: 0 }
    }
    return {
        duration: 0.65,
        ease: EASE.standard,
        delay,
    }
}

const PARALLAX_SPRING = { stiffness: 60, damping: 20, mass: 0.6 }

export function HeroSection() {
    const sectionRef = useRef(null)
    const reducedMotion = useReducedMotion()

    const rawX = useMotionValue(0)
    const rawY = useMotionValue(0)
    const pointerX = useSpring(rawX, PARALLAX_SPRING)
    const pointerY = useSpring(rawY, PARALLAX_SPRING)

    function handlePointerMove(event) {
        if (reducedMotion || event.pointerType !== 'mouse') return
        const bounds = sectionRef.current?.getBoundingClientRect()
        if (!bounds) return
        rawX.set((event.clientX - bounds.left) / bounds.width - 0.5)
        rawY.set((event.clientY - bounds.top) / bounds.height - 0.5)
    }

    function handlePointerLeave() {
        rawX.set(0)
        rawY.set(0)
    }

    return (
        <section
            ref={sectionRef}
            aria-labelledby="hero-heading"
            onPointerMove={handlePointerMove}
            onPointerLeave={handlePointerLeave}
            className="relative isolate overflow-hidden"
        >
            <HeroAmbience x={pointerX} y={pointerY} />

            <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col items-center px-4 pb-20 pt-32 text-center sm:px-6 sm:pb-28 sm:pt-36 lg:pb-32 lg:pt-40">
                <motion.p
                    initial={reducedMotion ? false : HERO_REVEAL.hidden}
                    animate={HERO_REVEAL.show}
                    transition={heroTransition(1.42, reducedMotion)}
                    className="text-xs font-semibold uppercase tracking-[0.18em] text-secondary"
                >
                    Solar power management system
                </motion.p>

                <motion.h1
                    id="hero-heading"
                    initial={reducedMotion ? false : HERO_REVEAL.hidden}
                    animate={HERO_REVEAL.show}
                    transition={heroTransition(1.50, reducedMotion)}
                    className="mt-5 font-display text-4xl font-semibold leading-[1.1] tracking-tight text-heading sm:text-5xl lg:text-6xl"
                >
                    Make more of the{' '}
                    <span className="text-primary">solar energy</span> you generate.
                </motion.h1>

                <motion.p
                    initial={reducedMotion ? false : HERO_REVEAL.hidden}
                    animate={HERO_REVEAL.show}
                    transition={heroTransition(1.58, reducedMotion)}
                    className="mt-6 max-w-xl text-base text-body sm:text-lg"
                >
                    Understand your solar production and household energy use, and make better
                    energy decisions.
                </motion.p>

                <motion.div
                    initial={reducedMotion ? false : HERO_REVEAL.hidden}
                    animate={HERO_REVEAL.show}
                    transition={heroTransition(1.66, reducedMotion)}
                    className="mt-9 flex flex-wrap items-center justify-center gap-3"
                >
                    <Button size="lg" asChild>
                        <Link to="/register">
                            Get Started
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button size="lg" variant="secondary" asChild>
                        <Link to="/login">Sign In</Link>
                    </Button>
                </motion.div>
            </div>
        </section>
    )
}