import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, Home, Info, Play, Scale, Sun } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { MetricCard } from '../../components/data-display/MetricCard'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { StatusBadge } from '../../components/data-display/StatusBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { LoadingState } from '../../components/feedback/LoadingState'
import { ApiError } from '../../services/api/client'
import { classifyEnergyBalance, getCurrentEnergyBalance } from '../../services/api/energy'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * Energy Balance & Situation Analysis (F4 — docs/02 §2, docs/04 §60).
 *
 * The backend (/api/v1/energy/balance) is the sole authority for the
 * classification. This page presents backend results and never derives
 * a balance, confidence, or nature value itself. The sample analysis
 * runs simulated (demo) inputs through the real backend pipeline and
 * labels them as simulated end to end.
 */

const CLASSIFICATION_PRESENTATION = {
    POTENTIAL_SURPLUS: { label: 'Potential Surplus', tone: 'success' },
    BALANCED: { label: 'Balanced', tone: 'info' },
    POTENTIAL_DEFICIT: { label: 'Potential Deficit', tone: 'warning' },
    UNAVAILABLE: { label: 'Currently unavailable', tone: 'muted' },
}

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

function formatKwh(value) {
    return Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 })
}

function todayPeriod() {
    const now = new Date()
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const pad = (n) => String(n).padStart(2, '0')
    const iso = (date) =>
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    return { start: iso(start), end: iso(now), resolution: 'DAY' }
}

function ScenarioButton({ scenario, selected, onSelect }) {
    return (
        <button
            type="button"
            onClick={() => onSelect(scenario)}
            aria-pressed={selected}
            className={cn(
                'rounded-md border px-3 py-2 text-left text-sm transition-colors',
                selected
                    ? 'border-primary/50 bg-primary-soft text-primary-active'
                    : 'border-border bg-surface text-body hover:bg-surface-alt',
            )}
        >
            <span className="block font-medium">{scenario.label}</span>
            <span className="mt-0.5 block text-xs text-muted">
                Simulated: {scenario.solarKwh} kWh solar / {scenario.consumptionKwh} kWh consumption
            </span>
        </button>
    )
}

function BalanceResult({ data }) {
    const result = data.result
    const classification = result?.classification
    const presentation =
        (classification && CLASSIFICATION_PRESENTATION[classification]) ||
        (result?.state === 'UNAVAILABLE' ? CLASSIFICATION_PRESENTATION.UNAVAILABLE : null)

    return (
        <Card aria-live="polite">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2">
                        <Scale className="size-5 text-secondary" aria-hidden="true" />
                        Energy balance result
                    </CardTitle>
                    <div className="flex items-center gap-2">
                        {result?.data_nature && <DataNatureBadge nature={result.data_nature} />}
                        {result?.data_status && <StatusBadge status={result.data_status} />}
                    </div>
                </div>
                {presentation && (
                    <p
                        className={cn(
                            'inline-flex w-fit items-center rounded-full border px-3 py-1 font-display text-base font-semibold',
                            TONE_CLASS[presentation.tone],
                        )}
                    >
                        {presentation.label}
                    </p>
                )}
                <CardDescription>{result?.explanation}</CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-5">
                {result?.state === 'CLASSIFIED' && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <MetricCard
                                label="Solar generation"
                                value={formatKwh(data.inputs.solar_generation.value_kwh)}
                                unit="kWh"
                                nature={data.inputs.solar_generation.data_nature}
                                status={data.inputs.solar_generation.data_status}
                            />
                            <MetricCard
                                label="Household consumption"
                                value={formatKwh(data.inputs.household_consumption.value_kwh)}
                                unit="kWh"
                                nature={data.inputs.household_consumption.data_nature}
                                status={data.inputs.household_consumption.data_status}
                            />
                            <MetricCard
                                label={result.classification === 'POTENTIAL_DEFICIT' ? 'Potential Deficit' : (result.classification === 'POTENTIAL_SURPLUS' ? 'Potential Surplus' : 'Balance')}
                                value={result.classification === 'POTENTIAL_DEFICIT' || result.classification === 'POTENTIAL_SURPLUS'
                                    ? `${formatKwh(Math.abs(result.balance_kwh))}`
                                    : `${formatKwh(result.balance_kwh)}`}
                                unit="kWh"
                                comparison={result.classification === 'POTENTIAL_DEFICIT'
                                    ? 'Household consumption exceeds solar generation'
                                    : (result.classification === 'POTENTIAL_SURPLUS'
                                        ? 'Solar generation exceeds household consumption'
                                        : 'Solar generation matches household consumption')}
                            />
                        </div>

                        {result.confidence_level && (
                            <ConfidenceIndicator level={result.confidence_level} />
                        )}

                        <div className="rounded-md border border-border bg-surface-alt px-4 py-3">
                            <p className="flex items-start gap-2 text-sm text-body">
                                <Info className="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                                Measurement context: POTENTIAL — a potential surplus is not confirmed
                                grid export and a potential deficit is not confirmed grid import.
                            </p>
                        </div>

                        {data.analysis.period && (
                            <p className="text-xs text-muted">
                                Period: {data.analysis.period.start} → {data.analysis.period.end}
                                {data.analysis.period.resolution ? ` (${data.analysis.period.resolution})` : ''}
                            </p>
                        )}

                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted">
                                Notes
                            </p>
                            <ul className="space-y-1">
                                {data.notes.map((note) => (
                                    <li key={note} className="flex items-start gap-2 text-sm text-body">
                                        <span aria-hidden="true" className="mt-2 size-1 shrink-0 rounded-full bg-muted" />
                                        {note}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    )
}

function normalizeError(err) {
    return err instanceof ApiError
        ? err
        : new ApiError({ code: 'UNEXPECTED_ERROR', message: 'Something went wrong.', status: 0 })
}

export function EnergyBalancePage() {
    const [phase, setPhase] = useState('loading')
    const [storedData, setStoredData] = useState(null)
    const [resultData, setResultData] = useState(null)
    const [scenario, setScenario] = useState(SAMPLE_SCENARIOS[0])
    const [error, setError] = useState(null)

    const loadCurrent = useCallback(
        (options = {}) =>
            getCurrentEnergyBalance()
                .then((response) => {
                    setStoredData(response.data)
                    setPhase('ready')
                })
                .catch((err) => {
                    setError(normalizeError(err))
                    setPhase('error')
                })
                .finally(() => {
                    if (options.reset) {
                        setError(null)
                    }
                }),
        [],
    )

    useEffect(() => {
        loadCurrent()
    }, [loadCurrent])

    const retryLoad = () => {
        setPhase('loading')
        setError(null)
        loadCurrent()
    }

    const runSampleAnalysis = async () => {
        setPhase('running')
        setError(null)
        try {
            const response = await classifyEnergyBalance({
                analysisType: 'TIME_PERIOD',
                period: todayPeriod(),
                solarGeneration: SIMULATED_INPUT(scenario.solarKwh),
                householdConsumption: SIMULATED_INPUT(scenario.consumptionKwh),
            })
            setResultData(response.data)
            setPhase('result')
        } catch (err) {
            setError(normalizeError(err))
            setPhase('error')
        }
    }

    return (
        <div className="mx-auto flex max-w-6xl flex-col gap-6">
            <div>
                <h1 className="font-display text-2xl font-semibold text-heading">Energy</h1>
                <p className="mt-1 text-sm text-muted">
                    Compare solar generation with household consumption to understand your
                    potential energy situation. The backend classifies the balance; a potential
                    surplus or deficit is never a confirmed grid export or import.
                </p>
            </div>

            {phase === 'loading' && <LoadingState label="Loading energy balance" />}

            {phase === 'error' && (
                <ErrorState
                    title="Energy balance unavailable"
                    message={
                        error?.message ??
                        'This information could not be loaded. Other parts of the application are unaffected.'
                    }
                    retryLabel="Try again"
                    onRetry={retryLoad}
                />
            )}

            {(phase === 'ready' || phase === 'result' || phase === 'running') && (
                <>
                    {phase === 'ready' &&
                        (storedData?.result?.state === 'UNAVAILABLE' ? (
                            <EmptyState
                                variant="unavailable"
                                icon={AlertCircle}
                                explanation={storedData.result.explanation}
                                limitation={storedData.result.limitation || "A balance can be calculated here once solar generation and household consumption data sources are connected."}
                            />
                        ) : (
                            <BalanceResult data={storedData} />
                        ))}

                    {phase === 'result' && resultData && (
                        <div className="flex flex-col gap-3">
                            <div className="flex items-center justify-between rounded-lg border border-chart-demo/40 bg-chart-demo/10 px-4 py-2.5 text-sm">
                                <span className="font-semibold text-chart-demo">
                                    Showing Sample Analysis Result (Simulated Scenario)
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setResultData(null)
                                        setPhase('ready')
                                    }}
                                >
                                    View Live Household Balance
                                </Button>
                            </div>
                            <BalanceResult data={resultData} />
                        </div>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Sample analysis</CardTitle>
                            <CardDescription>
                                Runs simulated (demo) inputs through the real backend pipeline.
                                Results are labeled simulated end to end — they are not real
                                household data.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid gap-3 sm:grid-cols-3" role="group" aria-label="Sample scenario">
                                {SAMPLE_SCENARIOS.map((option) => (
                                    <ScenarioButton
                                        key={option.id}
                                        scenario={option}
                                        selected={scenario.id === option.id}
                                        onSelect={setScenario}
                                    />
                                ))}
                            </div>

                            <div className="flex items-center gap-2 text-xs text-muted">
                                <Sun className="size-3.5" aria-hidden="true" />
                                <Home className="size-3.5" aria-hidden="true" />
                                Simulated inputs · classified by the backend · never treated as
                                measured data
                            </div>

                            <div>
                                <Button onClick={runSampleAnalysis} loading={phase === 'running'}>
                                    <Play className="size-4" aria-hidden="true" />
                                    Run sample analysis
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    )
}
