import { useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { AppSidebar } from '../components/navigation/AppSidebar'
import { MobileNavDrawer } from '../components/navigation/MobileNavDrawer'
import { AppHeader } from '../components/navigation/AppHeader'
import { LeafBackdrop } from '../components/decorative/LeafBackdrop'

/**
 * AppLayout (docs/04 §10–11) — the authenticated application shell:
 * full-width context header + persistent desktop sidebar (or responsive
 * drawer) + main content region. Future protected pages render inside
 * <Outlet/>.
 *
 * The desktop sidebar is permanently expanded (F3 refinement 2.5 —
 * the collapse feature was removed by owner decision); tablet/mobile
 * uses the temporary drawer below the lg breakpoint.
 *
 * No business logic, no data fetching, no authorization decisions —
 * backend remains authoritative (guards arrive with authentication).
 */
export function AppLayout() {
    const [drawerOpen, setDrawerOpen] = useState(false)
    const location = useLocation()
    const [lastPath, setLastPath] = useState(location.pathname)

    // Close the temporary drawer on route change (render-time adjustment —
    // drawer links also close it on click).
    if (location.pathname !== lastPath) {
        setLastPath(location.pathname)
        setDrawerOpen(false)
    }

    return (
        <div className="relative isolate flex min-h-screen flex-col bg-background">
            {/* Decorative leaf bunches — purely visual, behind all content,
                clipped to the content region (never under the top bar or
                sidebar; see LeafBackdrop) */}
            <LeafBackdrop />

            {/* Full-width header — owns SPMS branding + page context + controls */}
            <AppHeader onOpenNavigation={() => setDrawerOpen(true)} />

            <div className="flex min-h-0 flex-1">
                {/* Persistent desktop sidebar — navigation only, always expanded */}
                <div className="sticky top-16 hidden h-[calc(100vh-4rem)] shrink-0 lg:block">
                    <AppSidebar />
                </div>

                {/* Temporary navigation (tablet/mobile) — same nav config */}
                <MobileNavDrawer open={drawerOpen} onOpenChange={setDrawerOpen} />

                <main
                    id="main-content"
                    className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8"
                >
                    <Outlet />
                </main>
            </div>
        </div>
    )
}
