import { Outlet, Link } from 'react-router'
import { BrandMark } from '../components/navigation/BrandMark'
import { ThemeSwitch } from '../components/navigation/ThemeSwitch'
import { Button } from '../components/ui/button'

/**
 * PublicLayout (docs/03 §4 PUBLIC) — landing/marketing context.
 * Minimal chrome: brand, theme control, auth entry points.
 */
export function PublicLayout() {
    return (
        <div className="flex min-h-screen flex-col bg-background">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-primary-contrast"
            >
                Skip to main content
            </a>

            <header className="sticky top-0 z-40 border-b border-border bg-background/90 backdrop-blur-sm">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 sm:px-6">
                    <Link to="/" aria-label="SPMS home" className="inline-flex">
                        <BrandMark variant="full" height={34} />
                    </Link>

                    <div className="flex items-center gap-2">
                        <ThemeSwitch />
                        <Button variant="ghost" asChild>
                            <Link to="/login">Log in</Link>
                        </Button>
                        <Button asChild>
                            <Link to="/register">Get Started</Link>
                        </Button>
                    </div>
                </div>
            </header>

            <main id="main-content" className="flex-1">
                <Outlet />
            </main>

            <footer className="border-t border-border bg-surface">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-1 px-4 py-6 sm:px-6">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                        SPMS — Solar Power Management System
                    </p>
                    <p className="text-xs text-muted">© 2026 SPMS. All rights reserved.</p>
                </div>
            </footer>
        </div>
    )
}
