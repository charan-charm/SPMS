import { useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { FlaskConical } from 'lucide-react'
import { Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui'
import { SPMSLoadingState } from '../../components/feedback'

/**
 * DEV-ONLY loading-state demo page (route /dev/loading — development
 * builds only). Triggers the real SPMSLoadingState full-screen inside the
 * app shell so the logo-gold travelling sun, clear-glass backdrop, staged
 * status copy, and the corner-leaf response can be inspected over the
 * actual dashboard background. No API calls — the demo parent controls
 * isLoading; the overlay only unmounts after the departure completes.
 */

const DEMO_STAGES = [
    'Loading solar data...',
    'Analyzing household consumption...',
    'Preparing your forecast...',
    'Calculating energy balance...',
    'Preparing recommendations...',
]

export function LoadingStateDemo() {
    const [overlayOpen, setOverlayOpen] = useState(false)
    const [loadingActive, setLoadingActive] = useState(false)

    return (
        <div className="mx-auto flex max-w-6xl flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-info/40 bg-info/10 px-4 py-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-info">
                    <FlaskConical className="size-4" aria-hidden="true" />
                    Dev-only loading-state demo — the real SPMSLoadingState in the app shell
                </div>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                        setLoadingActive(true)
                        setOverlayOpen(true)
                    }}
                    disabled={overlayOpen}
                >
                    Test Loading State
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>What to watch</CardTitle>
                    <CardDescription>
                        The loading happens INSIDE the UI shell only — the subdued 75% wash covers
                        the content region below the header and right of the sidebar, nothing
                        else. The same warm 3D sun (both themes) emerges from BEHIND the top-right
                        leaf bunch, grows along a curved path, and breathes at center on its own
                        clean layer while staged status copy cycles beneath it. The leaves stay
                        above the wash as a subtle corner frame — barely swelling, never dominant.
                    </CardDescription>
                </CardHeader>
                <CardContent className="text-sm text-body">
                    Press “Finish Loading” to start the departure: the sun shrinks along a curved
                    path and slips back behind the bottom-left leaf bunch — the overlay closes
                    itself once that animation completes.
                </CardContent>
            </Card>

            <AnimatePresence>
                {overlayOpen && (
                    <motion.div
                        key="spms-loading-demo-overlay"
                        initial={{ opacity: 1 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.4, ease: 'easeInOut' }}
                        className="fixed top-16 right-0 bottom-0 left-0 z-[45] lg:left-64"
                    >
                        {/* The wash is its own layer: it fades in as the sun
                            arrives and slowly fades away during the departure
                            instead of lingering after the orb is gone. */}
                        <motion.div
                            aria-hidden="true"
                            className="absolute inset-0 bg-background/75"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: loadingActive ? 1 : 0 }}
                            transition={{
                                duration: loadingActive ? 0.8 : 1.7,
                                ease: 'easeInOut',
                            }}
                        />
                        <SPMSLoadingState
                            className="h-full"
                            isLoading={loadingActive}
                            statusSequence={DEMO_STAGES}
                            onExited={() => setOverlayOpen(false)}
                        />
                        <motion.div
                            className="absolute inset-x-0 bottom-6 flex justify-center"
                            initial={{ opacity: 1 }}
                            animate={{ opacity: loadingActive ? 1 : 0 }}
                            transition={{ duration: 0.7, ease: 'easeInOut' }}
                        >
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setLoadingActive(false)}
                                disabled={!loadingActive}
                            >
                                Finish Loading
                            </Button>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    )
}
