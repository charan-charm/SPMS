import { useEffect, useRef } from 'react'
import { Sparkles, CheckCircle2 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../ui/card'
import { LoadingState } from '../feedback/LoadingState'
import { useHousehold } from '../../features/household/useHousehold'

export function DemoOnboardingNotice({ onComplete }) {
    const { ensureDemo, hasHousehold } = useHousehold()
    const ensuredRef = useRef(false)

    useEffect(() => {
        if (ensuredRef.current) return
        ensuredRef.current = true

        let active = true

        const initializeDemo = async () => {
            try {
                if (!hasHousehold) {
                    await ensureDemo()
                }
                // Brief smooth visual pause so user sees the transition
                setTimeout(() => {
                    if (active && onComplete) {
                        onComplete()
                    }
                }, 600)
            } catch {
                // If already created or error, proceed to complete
                if (active && onComplete) {
                    onComplete()
                }
            }
        }

        initializeDemo()

        return () => {
            active = false
        }
    }, [ensureDemo, hasHousehold, onComplete])

    return (
        <Card className="w-full border-border bg-surface shadow-2">
            <CardHeader className="text-center pb-2">
                <div className="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-chart-demo/10 text-chart-demo">
                    <Sparkles className="size-6" aria-hidden="true" />
                </div>
                <div className="inline-block mx-auto mb-2">
                    <span className="rounded-full border border-chart-demo/50 bg-chart-demo/10 px-3 py-1 font-body text-xs font-semibold text-chart-demo">
                        DEMO — Simulated Demonstration Data
                    </span>
                </div>
                <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                    Preparing Demonstration Environment
                </CardTitle>
                <CardDescription className="text-sm text-muted">
                    Setting up your prepared demonstration household with simulated solar generation, sample electricity bills, and energy balance insights.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 pt-4 text-center">
                <div className="flex flex-col items-center justify-center py-4" aria-busy="true">
                    <LoadingState label="Configuring demonstration workspace..." />
                </div>

                <div className="space-y-2 text-left text-xs text-muted rounded-md bg-surface-alt/40 p-3.5 border border-border/60">
                    <div className="flex items-center gap-2 text-body">
                        <CheckCircle2 className="size-4 text-primary shrink-0" aria-hidden="true" />
                        <span>Seeded demonstration household ready</span>
                    </div>
                    <div className="flex items-center gap-2 text-body">
                        <CheckCircle2 className="size-4 text-primary shrink-0" aria-hidden="true" />
                        <span>Illustrative solar generation and grid exchange models</span>
                    </div>
                    <div className="flex items-center gap-2 text-body">
                        <CheckCircle2 className="size-4 text-primary shrink-0" aria-hidden="true" />
                        <span>Sample electricity bills and audit verification checks</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}
