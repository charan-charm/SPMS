import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router'
import {
    Sparkles,
    ChevronDown,
    Check,
    Loader2,
    AlertCircle,
} from 'lucide-react'
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '../ui/dropdown-menu'
import { useAuth } from '../../features/auth/useAuth'
import { useHousehold } from '../../features/household/useHousehold'
import { useSolar } from '../../features/solar/useSolar'
import { scenariosApi } from '../../services/api/scenarios'

/**
 * Compact, secure DEMO Scenario Switcher.
 *
 * Renders in AppHeader exclusively for authenticated DEMO accounts.
 * Allows examiners to seamlessly switch among the 5 domain-intelligence
 * scenarios without manual login/logout cycles or credential exposure.
 */
export function DemoScenarioSwitcher() {
    const { account, refreshSession } = useAuth()
    const { refreshHousehold } = useHousehold()
    const { refreshConnection } = useSolar()
    const navigate = useNavigate()

    const isDemo = account?.mode === 'DEMO_TEST'

    const [scenarios, setScenarios] = useState([])
    const [activeScenario, setActiveScenario] = useState(null)
    const [isOpen, setIsOpen] = useState(false)
    const [isSwitching, setIsSwitching] = useState(false)
    const [switchingId, setSwitchingId] = useState(null)
    const [error, setError] = useState(null)

    useEffect(() => {
        let isMounted = true
        if (!isDemo) return

        scenariosApi
            .list()
            .then((data) => {
                if (!isMounted) return
                setScenarios(data?.scenarios || [])
                setActiveScenario(data?.active_scenario || null)
            })
            .catch((err) => {
                if (!isMounted) return
                setError(err?.message || 'Failed to load demo scenarios.')
            })

        return () => {
            isMounted = false
        }
    }, [isDemo, account?.email])

    const handleSelectScenario = async (scenarioId) => {
        if (isSwitching) {
            return
        }

        if (activeScenario?.id === scenarioId) {
            setIsOpen(false)
            return
        }

        setIsSwitching(true)
        setSwitchingId(scenarioId)
        setError(null)

        try {
            const result = await scenariosApi.switchScenario(scenarioId)
            if (result?.switched) {
                setActiveScenario(result.active_scenario)
                setIsOpen(false)

                // Re-sync all state contexts with the new backend authority
                await Promise.allSettled([
                    refreshSession?.(),
                    refreshHousehold?.(),
                    refreshConnection?.(),
                ])

                navigate('/dashboard')
            }
        } catch (err) {
            setError(err?.message || 'Failed to switch scenario.')
            setIsOpen(true)
        } finally {
            setIsSwitching(false)
            setSwitchingId(null)
        }
    }

    if (!isDemo) {
        return null
    }

    const currentLabel = activeScenario
        ? `${activeScenario.id}: ${activeScenario.name}`
        : 'Demo Scenario'

    return (
        <div className="relative inline-flex items-center" data-testid="demo-scenario-switcher">
            <DropdownMenu open={isOpen} onOpenChange={setIsOpen}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        disabled={isSwitching}
                        onClick={() => setIsOpen((prev) => !prev)}
                        className="inline-flex items-center gap-1.5 rounded-full border border-chart-demo/40 bg-surface px-2.5 py-1 text-xs font-semibold text-chart-demo hover:bg-chart-demo/10 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus cursor-pointer disabled:opacity-50"
                        title="Switch domain intelligence demonstration scenario"
                        aria-label={`Demo Scenario Switcher: currently ${currentLabel}`}
                    >
                        {isSwitching ? (
                            <Loader2 className="size-3 animate-spin text-chart-demo" aria-hidden="true" />
                        ) : (
                            <Sparkles className="size-3 text-chart-demo" aria-hidden="true" />
                        )}
                        <span className="max-w-[160px] truncate sm:max-w-[220px]">
                            {isSwitching ? 'Switching...' : (activeScenario?.id ? `${activeScenario.id} · ${activeScenario.name}` : 'Demo Scenario')}
                        </span>
                        <ChevronDown className="size-3 opacity-70 shrink-0" aria-hidden="true" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-80 sm:w-88 p-1.5">
                    <div className="px-2.5 py-1.5">
                        <p className="text-xs font-bold text-heading">Switch Demo Scenario</p>
                        <p className="text-[11px] text-muted">
                            Preseeded solar intelligence personas (SCEN-01..05)
                        </p>
                    </div>

                    {error && (
                        <div className="mx-2 my-1 flex items-center gap-1.5 rounded-md bg-rose-500/10 p-2 text-xs text-rose-600 dark:text-rose-400">
                            <AlertCircle className="size-3.5 shrink-0" />
                            <span>{error}</span>
                        </div>
                    )}

                    <DropdownMenuSeparator />

                    <div className="space-y-0.5 max-h-72 overflow-y-auto overflow-x-hidden pr-0.5" role="menu">
                        {scenarios.map((scen) => {
                            const isActive = activeScenario?.id === scen.id
                            const isThisSwitching = switchingId === scen.id

                            return (
                                <DropdownMenuItem
                                    key={scen.id}
                                    disabled={isSwitching}
                                    onSelect={(e) => {
                                        e?.preventDefault?.()
                                        handleSelectScenario(scen.id)
                                    }}
                                    onClick={(e) => {
                                        e?.preventDefault?.()
                                        handleSelectScenario(scen.id)
                                    }}
                                    className={`flex items-center gap-2.5 px-2.5 py-2 rounded-md cursor-pointer transition-colors text-xs select-none ${
                                        isActive
                                            ? 'bg-chart-demo/15 text-chart-demo dark:bg-chart-demo/25 font-semibold'
                                            : 'hover:bg-surface-alt text-heading'
                                    }`}
                                    title={`${scen.id}: ${scen.name}${scen.description ? ` — ${scen.description}` : ''}`}
                                    aria-label={`Select ${scen.id}: ${scen.name}`}
                                    aria-selected={isActive}
                                >
                                    <div className="shrink-0 flex items-center justify-center">
                                        {isThisSwitching ? (
                                            <Loader2 className="size-3.5 animate-spin text-chart-demo" aria-hidden="true" />
                                        ) : isActive ? (
                                            <Check className="size-3.5 text-chart-demo" aria-hidden="true" />
                                        ) : (
                                            <div className="size-3.5 rounded-full border border-border/80" aria-hidden="true" />
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2 min-w-0 flex-1 overflow-hidden">
                                        <span className="font-mono text-[10px] font-bold uppercase tracking-wider rounded bg-surface border border-border/80 px-1.5 py-0.5 shrink-0 text-muted">
                                            {scen.id}
                                        </span>
                                        <span className="truncate font-semibold text-heading">
                                            {scen.name}
                                        </span>
                                    </div>
                                </DropdownMenuItem>
                            )
                        })}
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    )
}
