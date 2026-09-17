import { motion, useReducedMotion, useTransform } from 'motion/react'
import { SunOrb } from '../../components/feedback/SPMSLoadingState'

/**
 * HeroAmbience (O1 hero — solar entrance ambience)
 *
 * Two decorative elements only:
 *
 * 1. Localized sun halo — a small tight radial glow that exists ONLY
 *    immediately around the SunOrb. It is positioned to coincide exactly
 *    with the orb center. It never spreads across the hero area.
 *
 * 2. The SPMS SunOrb — emerges from the top-right along the approved
 *    curved arrival arc (SPMSLoadingState motion language), settles in
 *    the sun zone above the eyebrow text.
 *
 * No corner botanical leaves here — PublicLandingLeaves provides the
 * fixed viewport frame. No large hero wash. No edge-to-edge gradient.
 *
 * prefers-reduced-motion: sun appears static at settled position.
 * Purely decorative: pointer-events-none, aria-hidden.
 */

const ARRIVE_X_EASE = [0.3, 0.1, 0.25, 1]
const ARRIVE_Y_EASE = [0.66, 0, 0.3, 1]
const ARRIVE_DURATION_S = 1.35

// Sun orb size class used in both the orb and the halo sizing
const SUN_SIZE = 'size-20 sm:size-24'

export function HeroAmbience({ x, y }) {
    const reducedMotion = useReducedMotion()

    const driftX = useTransform(x, [-0.5, 0.5], [-4, 4])
    const driftY = useTransform(y, [-0.5, 0.5], [-2, 2])

    return (
        <div
            data-hero-ambience
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 z-0 select-none overflow-hidden"
        >
            {/* Subtle wide-square grid background for the Hero section only */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 -z-10 select-none opacity-85 dark:opacity-65"
                style={{
                    backgroundImage: `
                        linear-gradient(to right, color-mix(in srgb, var(--spms-border) 85%, transparent) 1.5px, transparent 1.5px),
                        linear-gradient(to bottom, color-mix(in srgb, var(--spms-border) 85%, transparent) 1.5px, transparent 1.5px)
                    `,
                    backgroundSize: '56px 56px',
                    WebkitMaskImage:
                        'radial-gradient(ellipse 80% 70% at 50% 35%, black 30%, transparent 95%)',
                    maskImage:
                        'radial-gradient(ellipse 80% 70% at 50% 35%, black 30%, transparent 95%)',
                }}
            />

            {/* The Solar Orb + its localized arrival glow.
                Both are co-centered. Sits in the sun zone above the eyebrow text. */}
            <div
                className="absolute left-1/2 -translate-x-1/2 pointer-events-none"
                style={{ top: '1.75rem' }}
            >
                <motion.div
                    style={reducedMotion ? undefined : { x: driftX, y: driftY }}
                    className="relative flex items-center justify-center"
                >
                        {/* Soft localized warm glow — remains invisible while sun travels; blooms smoothly ONLY after the sun has arrived & settled */}
                        <motion.div
                            aria-hidden="true"
                            initial={reducedMotion ? { opacity: 1 } : { opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={
                                reducedMotion
                                    ? { duration: 0 }
                                    : {
                                          duration: 0.75,
                                          delay: 1.05,
                                          ease: [0.25, 1, 0.5, 1],
                                      }
                            }
                        className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 pointer-events-none rounded-full"
                        style={{
                            width: '24rem',
                            height: '24rem',
                            background:
                                'radial-gradient(closest-side, color-mix(in srgb, var(--spms-warning) 30%, transparent), color-mix(in srgb, var(--spms-warning) 8%, transparent) 55%, transparent 100%)',
                        }}
                    />

                    {/* Sun orb — smooth continuous arrival without settling flicker */}
                    <motion.div
                        initial={
                            reducedMotion
                                ? { x: 0, y: 0, scale: 0.9, opacity: 1 }
                                : {
                                      x: 200,
                                      y: -30,
                                      scale: 0.26,
                                      opacity: 0,
                                  }
                        }
                        animate={{ x: 0, y: 0, scale: 0.9, opacity: 1 }}
                        transition={
                            reducedMotion
                                ? { duration: 0 }
                                : {
                                      x: { duration: ARRIVE_DURATION_S, ease: ARRIVE_X_EASE },
                                      y: { duration: ARRIVE_DURATION_S, ease: ARRIVE_Y_EASE },
                                      scale: { duration: ARRIVE_DURATION_S, ease: 'easeInOut' },
                                      opacity: { duration: ARRIVE_DURATION_S * 0.7, ease: 'easeOut' },
                                  }
                        }
                        className="relative will-change-transform"
                    >
                        <SunOrb className={`block ${SUN_SIZE}`} />
                    </motion.div>
                </motion.div>
            </div>
        </div>
    )
}