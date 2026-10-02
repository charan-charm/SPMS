import { useState, useEffect } from 'react'
import { Outlet, useLocation } from 'react-router'
import { AppSidebar } from '../components/navigation/AppSidebar'
import { MobileNavDrawer } from '../components/navigation/MobileNavDrawer'
import { AppHeader } from '../components/navigation/AppHeader'
import { LeafBackdrop } from '../components/decorative/LeafBackdrop'
import { useAssistant, AssistantDrawer, SolarAIAvatar, AssistantContext } from '../features/assistant'

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
 * Integrated with SPMS F8 AI Energy Assistant drawer and safe context.
 */
export function AppLayout() {
    const [drawerOpen, setDrawerOpen] = useState(false)
    const [fabEntered, setFabEntered] = useState(false)
    const location = useLocation()
    const [lastPath, setLastPath] = useState(location.pathname)

    // Ensure the one-time FAB entrance animation only plays once per page load (~400ms delay + 850ms duration)
    useEffect(() => {
        const timer = setTimeout(() => {
            setFabEntered(true)
        }, 1350)
        return () => clearTimeout(timer)
    }, [])

    // Close the temporary drawer on route change (render-time adjustment —
    // drawer links also close it on click).
    if (location.pathname !== lastPath) {
        setLastPath(location.pathname)
        setDrawerOpen(false)
    }

    // Derive safe page context name from current URL
    const pageContext = location.pathname.replace(/^\//, '').split('/')[0] || 'dashboard'
    const assistant = useAssistant({ pageContext })

    // Shared context value — any page within AppLayout can call askSolarAI()
    const assistantContextValue = {
        isOpen: assistant.isOpen,
        openSolarAI: () => assistant.setIsOpen(true),
        askSolarAI: (question) => {
            assistant.setIsOpen(true)
            if (question) assistant.sendMessage(question)
        },
    }

    return (
        <div className="relative isolate flex min-h-screen flex-col bg-background">
            {/* Decorative leaf bunches — purely visual, behind all content,
                clipped to the content region (never under the top bar or
                sidebar; see LeafBackdrop) */}
            <LeafBackdrop />

            {/* Full-width header — owns SPMS branding + page context + controls */}
            <AppHeader
                onOpenNavigation={() => setDrawerOpen(true)}
            />

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
                    <AssistantContext.Provider value={assistantContextValue}>
                        <Outlet />
                    </AssistantContext.Provider>
                </main>
            </div>

            {/* Final Global SolarAI Entry Point (Glassmorphism FAB with Ambient Orbit Glow) */}
            {!assistant.isOpen && (
                <div
                    className={`${fabEntered ? '' : 'solarai-fab-entrance'} fixed bottom-6 right-6 z-40 group flex items-center justify-center`}
                    onAnimationEnd={() => setFabEntered(true)}
                >
                    {/* Ambient Glow Halo & Rotating Orbit Track */}
                    <div className="solarai-orbit-halo" aria-hidden="true" />
                    <div className="solarai-orbit-track" aria-hidden="true">
                        <div className="solarai-orbit-particle" />
                    </div>

                    <button
                        id="solarai-fab-trigger"
                        type="button"
                        onClick={() => {
                            setFabEntered(true)
                            assistant.setIsOpen(true)
                        }}
                        className="solarai-fab-orb relative flex size-14 items-center justify-center rounded-full border border-border/80 bg-surface/85 dark:bg-surface-alt/80 backdrop-blur-md transition-all duration-200 hover:scale-[1.04] hover:border-primary/50 active:scale-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus cursor-pointer"
                        aria-label="Open SolarAI Energy Assistant"
                        aria-expanded={assistant.isOpen}
                        aria-controls="assistant-drawer"
                    >
                        <SolarAIAvatar size="md" state="settled" />
                    </button>

                </div>
            )}

            {/* F8 AI Energy Assistant Drawer */}
            <AssistantDrawer
                isOpen={assistant.isOpen}
                onClose={() => assistant.setIsOpen(false)}
                messages={assistant.messages}
                loading={assistant.loading}
                error={assistant.error}
                onSendMessage={assistant.sendMessage}
                onRetry={assistant.retryLast}
                onClear={assistant.clearHistory}
                pageContext={pageContext}
            />
        </div>
    )
}
