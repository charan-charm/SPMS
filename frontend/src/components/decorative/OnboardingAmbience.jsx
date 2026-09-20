/**
 * OnboardingAmbience (SPMS O3 onboarding background)
 *
 * Implements the approved SPMS authentication background visual language for /onboarding:
 * 1. Wide-square grid: fine 56px wide-square cells with radial fade mask matching AuthLayout.
 * 2. Interactive cursor-proximity energy glow: dual-layer cursor tracking field with
 *    trailing aura and dynamic light flow field (identical to Login / Register).
 * 3. Subtle ambient solar warmth in the upper-right corner.
 *
 * Safety & Accessibility:
 * - pointer-events-none + aria-hidden="true" + select-none
 * - fixed to viewport (-z-10), non-blocking
 * - motion-reduce: cursor glow hidden, static foliage remains
 * - light & dark theme adaptive via SPMS design tokens (--spms-border, --spms-primary, --spms-warning)
 */
export function OnboardingAmbience() {
    return (
        <div
            aria-hidden="true"
            data-testid="onboarding-ambience"
            className="pointer-events-none absolute inset-0 z-0 select-none overflow-hidden"
        >
            {/* 1. Enhanced wide-square grid (56px cells) matching authentication visual language */}
            <div
                data-testid="onboarding-grid"
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 select-none opacity-90 dark:opacity-75"
                style={{
                    backgroundImage: `
                        linear-gradient(to right, color-mix(in srgb, var(--spms-border) 85%, transparent) 1px, transparent 1px),
                        linear-gradient(to bottom, color-mix(in srgb, var(--spms-border) 85%, transparent) 1px, transparent 1px)
                    `,
                    backgroundSize: '56px 56px',
                    WebkitMaskImage:
                        'radial-gradient(ellipse 85% 75% at 50% 50%, black 45%, transparent 95%)',
                    maskImage:
                        'radial-gradient(ellipse 85% 75% at 50% 50%, black 45%, transparent 95%)',
                }}
            />

            {/* 2. Interactive cursor-proximity energy glow with dynamic flow & expanded aura (Auth pattern) */}
            <div
                data-testid="onboarding-cursor-glow"
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 z-0 select-none motion-reduce:hidden"
                style={{
                    opacity: 'var(--cursor-active, 0)',
                    willChange: 'opacity',
                }}
            >
                {/* Outer expanded light glow in edge (wide ~440px soft dissipation trailing the cursor) */}
                <div
                    className="absolute inset-0 auth-grid-flow-field"
                    style={{
                        backgroundImage: `
                            radial-gradient(420px circle at var(--trail-x, -999px) var(--trail-y, -999px), var(--auth-grid-aura, transparent) 0%, transparent 100%),
                            linear-gradient(to right, var(--auth-grid-glow, var(--spms-primary)) 1px, transparent 1px),
                            linear-gradient(to bottom, var(--auth-grid-glow, var(--spms-primary)) 1px, transparent 1px)
                        `,
                        backgroundSize: '100% 100%, 56px 56px, 56px 56px',
                        WebkitMaskImage: `
                            radial-gradient(440px circle at var(--trail-x, -999px) var(--trail-y, -999px), rgba(0, 0, 0, 0.45) 0%, rgba(0, 0, 0, 0.2) 40%, rgba(0, 0, 0, 0.05) 75%, transparent 100%)
                        `,
                        maskImage: `
                            radial-gradient(440px circle at var(--trail-x, -999px) var(--trail-y, -999px), rgba(0, 0, 0, 0.45) 0%, rgba(0, 0, 0, 0.2) 40%, rgba(0, 0, 0, 0.05) 75%, transparent 100%)
                        `,
                    }}
                />

                {/* Inner concentrated glow in middle (intense and slightly spread out from center) */}
                <div
                    className="absolute inset-0"
                    style={{
                        backgroundImage: `
                            radial-gradient(130px circle at var(--cursor-x, -999px) var(--cursor-y, -999px), var(--auth-grid-aura, transparent) 0%, transparent 100%),
                            linear-gradient(to right, var(--auth-grid-glow-core, var(--spms-primary)) 1px, transparent 1px),
                            linear-gradient(to bottom, var(--auth-grid-glow-core, var(--spms-primary)) 1px, transparent 1px)
                        `,
                        backgroundSize: '100% 100%, 56px 56px, 56px 56px',
                        WebkitMaskImage: `
                            radial-gradient(160px circle at var(--cursor-x, -999px) var(--cursor-y, -999px), rgba(0, 0, 0, 0.95) 0%, rgba(0, 0, 0, 0.65) 45%, rgba(0, 0, 0, 0.15) 80%, transparent 100%)
                        `,
                        maskImage: `
                            radial-gradient(160px circle at var(--cursor-x, -999px) var(--cursor-y, -999px), rgba(0, 0, 0, 0.95) 0%, rgba(0, 0, 0, 0.65) 45%, rgba(0, 0, 0, 0.15) 80%, transparent 100%)
                        `,
                    }}
                />
            </div>

            {/* 3. Soft diffused solar dissipation in the upper-right region (ambient warmth) */}
            <div
                data-testid="onboarding-solar-glow"
                aria-hidden="true"
                className="pointer-events-none absolute -top-20 -right-16 sm:-top-28 sm:-right-20 lg:-top-36 lg:-right-24 size-[360px] sm:size-[500px] lg:size-[640px] select-none rounded-full opacity-75 dark:opacity-40"
                style={{
                    background: `
                        radial-gradient(circle, 
                            color-mix(in srgb, var(--spms-warning) 8%, transparent) 0%, 
                            color-mix(in srgb, var(--spms-warning) 2.5%, transparent) 45%, 
                            transparent 75%)
                    `,
                }}
            />
        </div>
    )
}
