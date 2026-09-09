import { Outlet, Link } from 'react-router'
import { BrandMark } from '../components/navigation/BrandMark'
import { ThemeSwitch } from '../components/navigation/ThemeSwitch'

/**
 * AuthLayout (docs/03 §4 AUTHENTICATION) — focused, centered context
 * for Login / Register / Verification / Recovery. Future auth pages
 * render inside this shell; the actual forms belong to the
 * authentication milestones (backend PHP remains the authority).
 */
export function AuthLayout() {
    return (
        <div className="grid min-h-screen place-items-center bg-surface-alt px-4 py-10">
            <div className="w-full max-w-md">
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
