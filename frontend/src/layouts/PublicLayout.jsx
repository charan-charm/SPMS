import { Outlet } from 'react-router'
import { PublicHeader } from '../components/navigation/PublicHeader'
import { PublicFooter } from '../components/navigation/PublicFooter'

/**
 * PublicLayout (docs/03 §4 PUBLIC) — landing/marketing context.
 * The chrome itself lives in the shared PublicHeader/PublicFooter
 * components (O1) so the landing page and future public pages use one
 * public presentation system.
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

            <PublicHeader />

            <main id="main-content" className="flex-1 pt-16">
                <Outlet />
            </main>

            <PublicFooter />
        </div>
    )
}
