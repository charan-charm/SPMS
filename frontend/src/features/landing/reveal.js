import { DURATION, EASE } from '../../lib/motion'

/**
 * Landing reveal vocabulary (O1) — one calm entrance pattern reused by
 * every landing section. Page-reveal duration sits inside the locked
 * motion tokens (docs/12 §13: page reveal 300–400ms), and the app-root
 * MotionConfig reducedMotion="user" collapses the translate component
 * for reduced-motion users; the opacity fade remains (it is not a
 * motion in the vestibular sense).
 */

export const REVEAL = {
    hidden: { opacity: 0, y: 18 },
    show: { opacity: 1, y: 0 },
}

export function revealTransition(delay = 0) {
    return {
        duration: DURATION.page / 1000,
        ease: EASE.standard,
        delay,
    }
}

export const REVEAL_VIEWPORT = { once: true, margin: '-64px' }

/** Shared horizontal rhythm for public content. */
export const CONTAINER_CLASS = 'mx-auto w-full max-w-6xl px-4 sm:px-6'
