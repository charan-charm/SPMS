import { useRef } from 'react'
import { Outlet, Link, useNavigate } from 'react-router'
import { LogOut } from 'lucide-react'
import { BrandMark } from '../components/navigation/BrandMark'
import { ThemeSwitch } from '../components/navigation/ThemeSwitch'
import { LeafBackdrop } from '../components/decorative/LeafBackdrop'
import { OnboardingAmbience } from '../components/decorative/OnboardingAmbience'
import { useAuth } from '../features/auth/useAuth'
import { useCursorGlow } from '../hooks/useCursorGlow'

/**
 * OnboardingLayout — entry-experience shell for /onboarding.
 * Belongs strictly to the same visual design family as AuthLayout:
 * - Centered content container on bg-surface-alt
 * - Integrated, restrained brand header (BrandMark + ThemeSwitch + clean Sign Out)
 * - Subtle ambient wide-square grid with interactive cursor-proximity glow
 * - Peripheral botanical framing
 * - SPMS brand footer note matching authentication
 */
export function OnboardingLayout() {
    const containerRef = useRef(null)
    useCursorGlow(containerRef)

    const { account, logout } = useAuth()
    const navigate = useNavigate()

    const handleLogout = async () => {
        if (logout) {
            await logout()
        }
        navigate('/login')
    }

    return (
        <div
            ref={containerRef}
            className="relative grid min-h-screen place-items-center bg-surface-alt px-4 py-8 sm:py-12 overflow-hidden"
        >
            {/* Ambient background atmosphere: auth wide-square grid + cursor energy glow + solar warmth */}
            <OnboardingAmbience />

            {/* Peripheral botanical leaf watermark */}
            <LeafBackdrop variant="onboarding" />

            {/* Centered content shell — matching AuthLayout rhythm with comfortable breathing room */}
            <div className="relative z-10 w-full max-w-lg sm:max-w-xl">
                <header className="mb-6 flex items-center justify-between">
                    <Link to="/" aria-label="SPMS home" className="inline-flex">
                        <BrandMark variant="full" height={32} />
                    </Link>

                    <div className="flex items-center gap-2">
                        <ThemeSwitch />

                        {account && (
                            <button
                                type="button"
                                onClick={handleLogout}
                                aria-label="Sign out of account"
                                title="Sign out"
                                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted transition-colors hover:border-danger/40 hover:bg-danger/5 hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus cursor-pointer"
                            >
                                <LogOut className="size-3.5" aria-hidden="true" />
                                <span className="hidden sm:inline">Sign out</span>
                            </button>
                        )}
                    </div>
                </header>

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
