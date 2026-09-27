import { useEffect, useRef } from 'react'
import { Outlet, Link } from 'react-router'
import { BrandMark } from '../components/navigation/BrandMark'
import { ThemeSwitch } from '../components/navigation/ThemeSwitch'

/**
 * AuthLayout (docs/03 §4 AUTHENTICATION) — focused, centered context
 * for Login / Register / Verification / Recovery. Future auth pages
 * render inside this shell; the actual forms belong to the
 * authentication milestones (backend PHP remains the authority).
 * Includes enhanced ambient wide-square grid plus an interactive
 * cursor-proximity energy glow restricted to auth pages only.
 */
export function AuthLayout() {
    const containerRef = useRef(null)

    useEffect(() => {
        const container = containerRef.current
        if (!container) return

        // Respect reduced motion and touch devices — no cursor tracking needed
        const isCoarse = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches
        const prefersReducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
        if (isCoarse || prefersReducedMotion) return

        let targetX = -999
        let targetY = -999
        let currentX = -999
        let currentY = -999
        let trailX = -999
        let trailY = -999
        let currentActive = 0
        let targetActive = 0
        let rafId = null

        const lerp = (start, end, factor) => start + (end - start) * factor

        const animate = () => {
            const time = performance.now() * 0.0015
            const flowDriftX = Math.sin(time) * 10
            const flowDriftY = Math.cos(time * 0.8) * 10

            currentX = lerp(currentX, targetX, 0.10)
            currentY = lerp(currentY, targetY, 0.10)
            trailX = lerp(trailX, targetX + flowDriftX, 0.04)
            trailY = lerp(trailY, targetY + flowDriftY, 0.04)
            currentActive = lerp(currentActive, targetActive, 0.06)

            container.style.setProperty('--cursor-x', `${currentX.toFixed(1)}px`)
            container.style.setProperty('--cursor-y', `${currentY.toFixed(1)}px`)
            container.style.setProperty('--trail-x', `${trailX.toFixed(1)}px`)
            container.style.setProperty('--trail-y', `${trailY.toFixed(1)}px`)
            container.style.setProperty('--cursor-active', `${currentActive.toFixed(3)}`)

            const distCore = Math.hypot(targetX - currentX, targetY - currentY)
            const distTrail = Math.hypot(targetX - trailX, targetY - trailY)

            // Continue animation if pointer is active or still flowing/fading
            if (targetActive > 0 || currentActive > 0.005 || distCore > 0.5 || distTrail > 0.5) {
                rafId = requestAnimationFrame(animate)
            } else {
                rafId = null
            }
        }

        const handlePointerMove = (e) => {
            const rect = container.getBoundingClientRect()
            targetX = e.clientX - rect.left
            targetY = e.clientY - rect.top
            targetActive = 1

            if (currentX < -500 || currentActive < 0.02) {
                currentX = targetX
                currentY = targetY
                trailX = targetX
                trailY = targetY
            }

            if (!rafId) {
                rafId = requestAnimationFrame(animate)
            }
        }

        const handlePointerLeave = () => {
            targetActive = 0
            if (!rafId) {
                rafId = requestAnimationFrame(animate)
            }
        }

        container.addEventListener('pointermove', handlePointerMove, { passive: true })
        container.addEventListener('pointerleave', handlePointerLeave, { passive: true })

        return () => {
            if (rafId) cancelAnimationFrame(rafId)
            container.removeEventListener('pointermove', handlePointerMove)
            container.removeEventListener('pointerleave', handlePointerLeave)
        }
    }, [])

    return (
        <div
            ref={containerRef}
            className="relative grid min-h-screen place-items-center bg-surface-alt px-4 py-10 overflow-hidden"
        >
            {/* Enhanced wide-square grid background (docs/04, docs/12 §17, Part B) —
                matches O1 hero grid visual language, fine 56px wide-square cells,
                calm radial fade mask, noticeably visible yet subtle and strictly behind auth content. */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 z-0 select-none opacity-90 dark:opacity-75"
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

            {/* Interactive cursor-proximity energy glow with dynamic flow & expanded aura (O2) */}
            <div
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

            <div className="relative z-10 w-full max-w-md">
                <div className="mb-6 flex items-center justify-between">
                    <Link to="/" aria-label="SPMS home" className="inline-flex">
                        <BrandMark variant="full" height={32} />
                    </Link>
                    <ThemeSwitch />
                </div>

                <main id="main-content">
                    <Outlet />
                </main>

                <p className="mt-6 text-center text-xs text-muted">
                    SPMS — Solar Power Management System
                </p>
            </div>
        </div>
    )
}
