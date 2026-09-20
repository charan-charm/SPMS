import { useEffect } from 'react'

/**
 * useCursorGlow (docs/04, docs/12 §17, Part B)
 *
 * Provides smooth lerp-animated cursor proximity tracking on the target container:
 * updates CSS variables (--cursor-x, --cursor-y, --trail-x, --trail-y, --cursor-active)
 * driving the interactive grid aura and light flow field.
 *
 * Safety:
 * - Respects prefers-reduced-motion: reduce
 * - Detects touch/coarse pointers and disables animation
 * - Graceful cleanup on unmount
 *
 * @param {import('react').RefObject<HTMLElement | null>} containerRef
 */
export function useCursorGlow(containerRef) {
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
    }, [containerRef])
}
