/**
 * SPMS motion tokens (docs/04 Q13, docs/12 §13).
 *
 * Motion communicates state, continuity, hierarchy, or feedback — never
 * decoration. All animated components must honor these durations and the
 * reduced-motion policy (MotionConfig reducedMotion="user" at the app
 * root, plus CSS @media (prefers-reduced-motion: reduce) where needed).
 */

export const DURATION = {
    hover: 120,
    state: 150,
    expand: 250,
    theme: 250,
    page: 350,
    chart: 500,
}

/**
 * The standard easing as a Motion-accepted numeric cubic-bezier.
 * (Motion v13 validates `ease` strings strictly and rejects the CSS
 * form 'cubic-bezier(...)' at animation init; the array carries the
 * identical curve. CSS consumers must not interpolate this value —
 * it exists for JS transitions only.)
 */
export const EASE = {
    standard: [0.2, 0, 0, 1],
}
