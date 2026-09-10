/**
 * LeafBackdrop (owner-requested visual refinement) — a purely decorative
 * background watermark belonging to the APPLICATION UI SHELL only.
 *
 * Composition (owner direction): TWO bunches of elongated botanical
 * leaves — TOP-RIGHT and BOTTOM-LEFT corners of the CONTENT REGION. The
 * layer is fixed to the content area ONLY: it starts below the 64px top
 * bar (top-16) and, on desktop, right of the 256px sidebar (lg:left-64),
 * so the bunches ALIGN with the nav bars' inner edges and are clipped at
 * them — leaves NEVER appear under or inside the top bar or sidebar.
 * Each bunch holds 8 leaves (smallest three hidden on mobile) anchored
 * along the corner. Leaf shape: elongated, broader body, sharp smooth
 * point — plain soft fill only, NO border and NO veins.
 *
 * Theme adaptation (owner direction): the SAME shapes everywhere —
 * light theme renders the lightest green, dark theme the lightest teal;
 * both barely perceptible (0.9% / 1.2% layer opacity, heavily
 * background-mixed fills). Fill colors derive ONLY from the existing
 * SPMS tokens via color-mix in styles/index.css.
 *
 * Safety and motion properties:
 * - pointer-events-none + aria-hidden: no interaction impact, never
 *   announced, no focusable parts.
 * - The layer never participates in layout, never causes
 *   scroll/shift/overlap, and sits behind all content (negative z-index
 *   in the layout's isolate stacking context; opaque cards cover any
 *   overlap).
 * - Motion (owner direction — gentle beach-breeze float): each bunch
 *   sways as ONE unit (spms-leaf-sway-a/b, 14s/16s, translate ≤6px +
 *   rotate ≤1°, ease-in-out infinite). No per-leaf animation. No
 *   scaling, bouncing, pulsing, or spinning.
 * - prefers-reduced-motion: reduce disables all animation; the static
 *   foliage remains visible (styles/index.css).
 * - Static inline SVG only — no raster assets, filters, blur, canvas,
 *   or JavaScript animation. Negligible performance impact.
 */

/**
 * Elongated botanical leaf — long body with a sharp, smooth point:
 * base at local origin, tip at (0,-130).
 */
const LEAF_PATH = 'M0 0 C -22 -34 -20 -86 0 -130 C 20 -86 22 -34 0 0 Z'

/**
 * One leaf of the bunch: static position/rotation/scale via SVG
 * transform; plain soft fill, NO border, NO veins. No independent
 * animation — the whole bunch moves as one. `minor` leaves are hidden
 * on mobile via styles/index.css.
 */
function BunchLeaf({ at, rotate, scale = 1, minor = false }) {
    return (
        <g
            transform={`translate(${at[0]} ${at[1]}) rotate(${rotate}) scale(${scale})`}
            className={minor ? 'spms-leaf-minor' : undefined}
        >
            {/* Soft translucent fill — lightest green / lightest teal per theme */}
            <path d={LEAF_PATH} className="spms-leaf-fill" />
        </g>
    )
}

/* Bottom-left bunch: leaves anchored along the content area's
   bottom-left corner, tips fanning up/right into the page.
   Top-right bunch: the same bouquet rotated 180° to its corner. */
const BOTTOM_LEFT_BUNCH = [
    { at: [20, 380], rotate: 15, scale: 1.5 },
    { at: [40, 391], rotate: 35, scale: 1.32 },
    { at: [62, 397], rotate: 55, scale: 1.16 },
    { at: [86, 399], rotate: 75, scale: 1.0 },
    { at: [110, 398], rotate: 95, scale: 0.88 },
    { at: [133, 393], rotate: 115, scale: 0.76, minor: true },
    { at: [155, 385], rotate: 135, scale: 0.62, minor: true },
    { at: [176, 374], rotate: 155, scale: 0.5, minor: true },
]

const TOP_RIGHT_BUNCH = BOTTOM_LEFT_BUNCH.map(({ at, rotate, scale, minor }) => ({
    at: [400 - at[0], 400 - at[1]],
    rotate: rotate + 180,
    scale,
    minor,
}))

export function LeafBackdrop() {
    return (
        <div
            aria-hidden="true"
            className="spms-leaf-layer pointer-events-none fixed top-16 right-0 bottom-0 left-0 lg:left-64 -z-10 overflow-hidden"
        >
            {/* —— Top-right bunch: the exact bottom-left bouquet rotated
                180° to hug the content area's top-right corner (below the
                top bar). —— */}
            <svg
                viewBox="0 0 400 400"
                focusable="false"
                className="spms-leaf-sway-a absolute -top-12 -right-12 h-[260px] w-[260px] opacity-[var(--leaf-opacity)] sm:-top-14 sm:-right-14 sm:h-[340px] sm:w-[340px] lg:-top-16 lg:-right-16 lg:h-[440px] lg:w-[440px]"
            >
                {TOP_RIGHT_BUNCH.map((leaf, index) => (
                    <BunchLeaf key={index} {...leaf} />
                ))}
            </svg>

            {/* —— Bottom-left bunch: bouquet hugging the content area's
                bottom-left corner, leaves elongating up/right into the
                page. —— */}
            <svg
                viewBox="0 0 400 400"
                focusable="false"
                className="spms-leaf-sway-b absolute -bottom-12 -left-12 h-[260px] w-[260px] opacity-[var(--leaf-opacity)] sm:-bottom-14 sm:-left-14 sm:h-[340px] sm:w-[340px] lg:-bottom-16 lg:-left-16 lg:h-[440px] lg:w-[440px]"
            >
                {BOTTOM_LEFT_BUNCH.map((leaf, index) => (
                    <BunchLeaf key={index} {...leaf} />
                ))}
            </svg>
        </div>
    )
}
