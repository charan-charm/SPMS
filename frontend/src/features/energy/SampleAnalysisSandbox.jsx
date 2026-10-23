import { useState } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Play, Sun, Home, Scale, Info } from 'lucide-react'
import { classifyEnergyBalance } from '../../services/api/energy'
import { formatKwh } from './energyPeriodUtils'
import { cn } from '../../lib/utils'

const SAMPLE_SCENARIOS = [
    { id: 'surplus', label: 'Solar-rich afternoon', solarKwh: 6.4, consumptionKwh: 4.1 },
    { id: 'balanced', label: 'Midday match', solarKwh: 5.0, consumptionKwh: 5.1 },
    { id: 'deficit', label: 'Evening peak', solarKwh: 1.2, consumptionKwh: 4.8 },
]

const SIMULATED_INPUT = (valueKwh) => ({
    valueKwh,
    dataNature: 'SIMULATED',
    dataStatus: 'FRESH',
    quality: 'UNVERIFIED',
})

function todayPeriod() {
    const now = new Date()
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const pad = (n) => String(n).padStart(2, '0')
    const iso = (date) =>
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    return { start: iso(start), end: iso(now), resolution: 'DAY' }
}

/**
 * SampleAnalysisSandbox (DEMO / Inspection Mode Only).
 *
 * Runs simulated scenario inputs through the backend classification pipeline.
 * Hard rule: MUST NEVER be rendered in REAL mode. Only rendered when isDemo === true.
 */
export function SampleAnalysisSandbox() {
    const [selectedScenario, setSelectedScenario] = useState(SAMPLE_SCENARIOS[0])
    const [isRunning, setIsRunning] = useState(false)
    const [resultData, setResultData] = useState(null)
    const [error, setError] = useState(null)

    const handleRunAnalysis = async () => {
        setIsRunning(true)
        setError(null)
        try {
            const resp = await classifyEnergyBalance({
                analysisType: 'TIME_PERIOD',
                period: todayPeriod(),
                solarGeneration: SIMULATED_INPUT(selectedScenario.solarKwh),
                householdConsumption: SIMULATED_INPUT(selectedScenario.consumptionKwh),
            })
            setResultData(resp.data)
        } catch (err) {
            setError(err.message || 'Sample analysis failed.')
        } finally {
            setIsRunning(false)
        }
    }

    return (
        <Card className="border-amber-500/30 bg-surface shadow-sm" data-testid="sample-analysis-card">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-sm font-semibold text-heading flex items-center gap-2">
                            <Scale className="size-4 text-amber-500" />
                            Sample Analysis Sandbox (Demo Inspection Only)
                        </CardTitle>
                        <CardDescription className="text-xs text-muted mt-0.5">
                            Executes simulated scenario inputs through the backend pipeline. Results are labeled simulated end-to-end.
                        </CardDescription>
                    </div>
                    <span className="inline-flex items-center rounded border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                        DEMO TOOLING
                    </span>
                </div>
            </CardHeader>

            <CardContent className="space-y-4">
                <div className="grid gap-2.5 sm:grid-cols-3" role="group" aria-label="Sample scenario">
                    {SAMPLE_SCENARIOS.map((sc) => {
                        const isSelected = selectedScenario.id === sc.id
                        return (
                            <button
                                key={sc.id}
                                type="button"
                                onClick={() => setSelectedScenario(sc)}
                                className={cn(
                                    'rounded-md border p-3 text-left transition-colors',
                                    isSelected
                                        ? 'border-amber-500/60 bg-amber-500/10 text-heading'
                                        : 'border-border/80 bg-surface hover:bg-muted/10 text-muted'
                                )}
                            >
                                <span className="block text-xs font-semibold text-heading">{sc.label}</span>
                                <span className="mt-1 block text-[11px] text-muted">
                                    {sc.solarKwh} kWh solar / {sc.consumptionKwh} kWh load
                                </span>
                            </button>
                        )
                    })}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                    <div className="flex items-center gap-2 text-xs text-muted">
                        <Sun className="size-3.5 text-amber-500" />
                        <Home className="size-3.5 text-sky-500" />
                        <span>Simulated inputs · Classified by backend · Not measured data</span>
                    </div>

                    <Button
                        size="sm"
                        onClick={handleRunAnalysis}
                        disabled={isRunning}
                        className="gap-1.5"
                    >
                        <Play className="size-3.5" />
                        {isRunning ? 'Running…' : 'Run sample analysis'}
                    </Button>
                </div>

                {error && (
                    <div className="rounded-md border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-600 dark:text-rose-400">
                        {error}
                    </div>
                )}

                {resultData && resultData.result && (
                    <div className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 space-y-3">
                        <div className="flex items-center justify-between border-b border-border/50 pb-2">
                            <span className="text-xs font-semibold text-heading">
                                Simulation Result: {resultData.result.classification}
                            </span>
                            <span className="inline-flex items-center rounded border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                                SIMULATED
                            </span>
                        </div>
                        <p className="text-xs text-muted">{resultData.result.explanation}</p>
                        <div className="grid grid-cols-3 gap-2 text-xs font-mono">
                            <div>Solar: {formatKwh(resultData.inputs?.solar_generation?.value_kwh ?? resultData.result?.solar_generation?.value_kwh)} kWh</div>
                            <div>Load: {formatKwh(resultData.inputs?.household_consumption?.value_kwh ?? resultData.result?.household_consumption?.value_kwh)} kWh</div>
                            <div className="font-semibold text-heading">
                                Balance: {formatKwh(resultData.result?.balance_kwh ?? resultData.result?.potential_surplus_kwh ?? resultData.result?.potential_deficit_kwh)} kWh
                            </div>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
