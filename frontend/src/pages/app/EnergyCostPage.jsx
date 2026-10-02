import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, Info, IndianRupee, Play, Receipt } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { MetricCard } from '../../components/data-display/MetricCard'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { StatusBadge } from '../../components/data-display/StatusBadge'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { QualityIndicator } from '../../components/data-display/QualityIndicator'
import { CostRange } from '../../components/data-display/CostRange'
import { ChartContainer } from '../../components/data-display/ChartContainer'
import { EmptyState } from '../../components/feedback/EmptyState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { LoadingState } from '../../components/feedback/LoadingState'
import { ApiError } from '../../services/api/client'
import { estimateEnergyCost, getHistoricalEnergyCost } from '../../services/api/energyCost'
import { formatMoney } from '../../lib/money'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * Energy Cost Analysis (F5 — docs/02 §2, docs/01 §19, docs/10 D-042).
 *
 * The backend (/api/v1/energy/cost/*) is the sole authority for
 * numerical cost calculation. This page presents backend results and
 * never derives a cost, range, confidence, or nature value itself. The
 * sample estimate runs simulated (demo) energy inputs and a declared
 * pricing basis through the real backend pipeline and labels them as
 * simulated/estimated end to end.
 *
 * Honesty rules mirrored from the backend contract:
 * - "Verified historical cost" is currently UNAVAILABLE (no verified
 *   bill persistence yet) and is kept strictly separate from estimates.
 * - Estimated values are never labeled bill, actual, confirmed, or
 *   guaranteed.
 * - Fixed charges are presented separately, never multiplied by kWh.
 */

const PRICING_BASES = [
    {
        id: 'TARIFF_EVIDENCE',
        label: 'Tariff evidence',
        evidence: 'Evidence level 1 of 3 — strongest',
        needsRate: true,
    },
    {
        id: 'HISTORICAL_BILL_RELATIONSHIP',
        label: 'Bill relationship',
        evidence: 'Evidence level 2 of 3 — fallback',
        needsRate: true,
    },
    {
        id: 'USER_PROVIDED_RATE',
        label: 'User-provided rate',
        evidence: 'Evidence level 3 of 3 — declared',
        needsRate: true,
    },
    {
        id: 'INSUFFICIENT_EVIDENCE',
        label: 'Insufficient evidence',
        evidence: 'No estimate is computed',
        needsRate: false,
    },
]

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

function basisPresentation(basisId) {
    return PRICING_BASES.find((basis) => basis.id === basisId) ?? null
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

function BasisButton({ basis, selected, onSelect }) {
    return (
        <button
            type="button"
            onClick={() => onSelect(basis)}
            aria-pressed={selected}
            className={cn(
                'rounded-md border px-3 py-2 text-left text-sm transition-colors',
                selected
                    ? 'border-primary/50 bg-primary-soft text-primary-active'
                    : 'border-border bg-surface text-body hover:bg-surface-alt',
            )}
        >
            <span className="block font-medium">{basis.label}</span>
            <span className="mt-0.5 block text-xs text-muted">{basis.evidence}</span>
        </button>
    )
}

function EstimateResult({ data }) {
    const result = data.result

    if (result?.state === 'INSUFFICIENT_EVIDENCE') {
        return (
            <Card aria-live="polite">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <IndianRupee className="size-5 text-secondary" aria-hidden="true" />
                        Estimated energy cost
                    </CardTitle>
                    <CardDescription>{result.explanation}</CardDescription>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        variant="insufficient"
                        icon={IndianRupee}
                        explanation="SPMS does not invent tariff information. Declare verified tariff evidence, a verified bill relationship, or an explicit rate to compute an estimate."
                        limitation="Anything shown here would be an estimate — never a confirmed future electricity bill."
                    />
                </CardContent>
            </Card>
        )
    }

    if (result?.state !== 'ESTIMATED') {
        return null
    }

    const basis = basisPresentation(result.pricing_basis)

    return (
        <Card aria-live="polite">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2">
                        <IndianRupee className="size-5 text-secondary" aria-hidden="true" />
                        Estimated energy cost
                    </CardTitle>
                    <div className="flex items-center gap-2">
                        {result.data_nature && <DataNatureBadge nature={result.data_nature} />}
                        {result.data_status && <StatusBadge status={result.data_status} />}
                    </div>
                </div>
                <p
                    className={cn(
                        'inline-flex w-fit items-center rounded-full border px-3 py-1 font-display text-base font-semibold',
                        TONE_CLASS.warning,
                    )}
                >
                    Future estimate
                </p>
                <CardDescription>{result.explanation}</CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <MetricCard
                        label="Estimated variable cost"
                        value={formatMoney(result.estimated_variable_cost, result.currency)}
                        nature={result.data_nature}
                        status={result.data_status}
                        comparison={`Basis: ${basis?.label ?? result.pricing_basis} · not a bill`}
                    />
                    <MetricCard
                        label="Potential grid-dependent energy"
                        value={formatKwh(data.energy_position.grid_dependent_kwh)}
                        unit="kWh"
                        comparison="Potential position — never measured grid import"
                    />
                    {result.fixed_charge && (
                        <MetricCard
                            label="Fixed charge (separate)"
                            value={formatMoney(result.fixed_charge.amount, result.fixed_charge.currency)}
                            comparison="Kept separate — not multiplied by kWh, not included"
                        />
                    )}
                </div>

                {result.range && (
                    <CostRange
                        low={result.range.low}
                        high={result.range.high}
                        currency={result.currency}
                        halfWidthPercent={result.range.half_width_percent}
                        triggers={result.range.triggers}
                    />
                )}

                {result.confidence_level && <ConfidenceIndicator level={result.confidence_level} />}
                {result.quality && <QualityIndicator level={result.quality} />}

                <div className="rounded-md border border-border bg-surface-alt px-4 py-3">
                    <p className="flex items-start gap-2 text-sm text-body">
                        <Info className="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                        This is an estimate based on predicted energy and the stated pricing basis. It is not a
                        confirmed future electricity bill, not a verified historical amount, and not a guaranteed
                        saving. Fixed charges apply separately and potential solar surplus does not imply grid export compensation.
                    </p>
                </div>

                {basis && (
                    <p className="text-xs text-muted">
                        Pricing basis: {basis.label} ({basis.evidence})
                        {result.pricing_source ? ` · Source: ${result.pricing_source}` : ''}
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
            </CardContent>
        </Card>
    )
}

function HistoricalCost({ data }) {
    const result = data?.result

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2">
                        <Receipt className="size-5 text-secondary" aria-hidden="true" />
                        Verified historical cost
                    </CardTitle>
                    {result?.data_status && <StatusBadge status={result.data_status} />}
                </div>
                <CardDescription>
                    Built only from verified electricity bill records — kept strictly separate from
                    future estimates.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {result?.state === 'UNAVAILABLE' ? (
                    <EmptyState
                        variant="unavailable"
                        icon={Receipt}
                        explanation={result.explanation}
                        limitation="Verified bill records arrive with the bill-management milestone; historical cost will appear here once they exist."
                    />
                ) : (
                    <EmptyState
                        variant="noData"
                        icon={Receipt}
                        explanation="No verified historical cost is available yet."
                    />
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

export function EnergyCostPage() {
    const [phase, setPhase] = useState('loading')
    const [historicalData, setHistoricalData] = useState(null)
    const [resultData, setResultData] = useState(null)
    const [scenario, setScenario] = useState(SAMPLE_SCENARIOS[2])
    const [basis, setBasis] = useState(PRICING_BASES[2])
    const [unitRate, setUnitRate] = useState('8.5')
    const [fixedCharge, setFixedCharge] = useState('')
    const [error, setError] = useState(null)

    const loadCurrent = useCallback(
        (options = {}) =>
            getHistoricalEnergyCost()
                .then((response) => {
                    setHistoricalData(response.data)
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

    const parsedRate = Number.parseFloat(unitRate)
    const parsedFixedCharge = fixedCharge.trim() === '' ? null : Number.parseFloat(fixedCharge)
    const rateMissing = basis.needsRate && !(Number.isFinite(parsedRate) && parsedRate > 0)

    const runSampleEstimate = async () => {
        setPhase('running')
        setError(null)
        try {
            const response = await estimateEnergyCost({
                solarGeneration: SIMULATED_INPUT(scenario.solarKwh),
                householdConsumption: SIMULATED_INPUT(scenario.consumptionKwh),
                pricing: {
                    basis: basis.id,
                    ...(basis.needsRate ? { unitRate: parsedRate } : {}),
                    currency: 'INR',
                    source: 'Declared in the development cost preview',
                },
                ...(parsedFixedCharge !== null && Number.isFinite(parsedFixedCharge) && parsedFixedCharge >= 0
                    ? {
                          fixedCharge: {
                              amount: parsedFixedCharge,
                              currency: 'INR',
                              label: 'Fixed charge (sample)',
                          },
                      }
                    : {}),
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
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-heading">Cost</h1>
                    <p className="mt-1 text-sm text-muted">
                        Understand the estimated cost impact of your predicted energy position. Historical
                        verified cost stays strictly separate and is unavailable until verified bill records
                        exist. The backend computes every number; a potential deficit is never a measured
                        grid import.
                    </p>
                </div>
            </div>

            {phase === 'loading' && <LoadingState label="Loading cost analysis" />}

            {phase === 'error' && (
                <ErrorState
                    title="Cost analysis unavailable"
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
                    <HistoricalCost data={historicalData} />

                    {phase === 'result' && resultData && <EstimateResult data={resultData} />}

                    <Card>
                        <CardHeader>
                            <CardTitle>Sample estimate</CardTitle>
                            <CardDescription>
                                Runs simulated (demo) energy inputs and a declared pricing basis through
                                the real backend pipeline. Results are labeled simulated and estimated end
                                to end — they are not real household data and never a confirmed bill.
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

                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                                    Declared pricing basis
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2" role="group" aria-label="Pricing basis">
                                    {PRICING_BASES.map((option) => (
                                        <BasisButton
                                            key={option.id}
                                            basis={option}
                                            selected={basis.id === option.id}
                                            onSelect={setBasis}
                                        />
                                    ))}
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cost-unit-rate">
                                        Unit rate (₹ per kWh){basis.needsRate ? '' : ' — not used'}
                                    </Label>
                                    <Input
                                        id="cost-unit-rate"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={unitRate}
                                        onChange={(event) => setUnitRate(event.target.value)}
                                        disabled={!basis.needsRate}
                                        placeholder="e.g. 8.50"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cost-fixed-charge">Fixed charge (₹, optional)</Label>
                                    <Input
                                        id="cost-fixed-charge"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={fixedCharge}
                                        onChange={(event) => setFixedCharge(event.target.value)}
                                        placeholder="e.g. 150"
                                    />
                                    <p className="text-xs text-muted">
                                        Kept separate — never multiplied by kWh.
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-2 text-xs text-muted">
                                <AlertCircle className="size-3.5" aria-hidden="true" />
                                Simulated energy inputs · declared pricing basis · estimated by the
                                backend · never a confirmed future bill
                            </div>

                            <div>
                                <Button
                                    onClick={runSampleEstimate}
                                    loading={phase === 'running'}
                                    disabled={rateMissing}
                                >
                                    <Play className="size-4" aria-hidden="true" />
                                    Run sample estimate
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <ChartContainer
                        title="Cost trend"
                        description="Historical verified cost over time, built only from verified bill records."
                        footer="No cost series exists yet — verified bill history arrives with the bill-management milestone, so nothing is plotted."
                        accessibleSummary="Cost trend is unavailable because no verified historical cost data exists yet. Nothing is invented to fill the chart."
                        empty
                    />
                </>
            )}
        </div>
    )
}
