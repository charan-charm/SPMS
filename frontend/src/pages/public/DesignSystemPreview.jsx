import { useState } from 'react'
import { Link } from 'react-router'
import { Sun, Moon, Inbox, FlaskConical } from 'lucide-react'
import {
    Button,
    Input,
    Label,
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
    Checkbox,
    Switch,
    Tabs,
    TabsList,
    TabsTrigger,
    TabsContent,
    Separator,
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '../../components/ui'
import {
    MetricCard,
    StatusBadge,
    DataNatureBadge,
    ConfidenceIndicator,
    QualityIndicator,
    OperationStatus,
    RecommendationCard,
    ChartContainer,
    SolarDayArc,
} from '../../components/data-display'
import { EmptyState, ErrorState, LoadingSkeleton, LoadingState, SPMSLoadingState } from '../../components/feedback'
import { ThemeSwitch } from '../../components/navigation/ThemeSwitch'
import { useTheme } from '../../hooks/useTheme'

/**
 * DEV-ONLY design-system verification page (F2).
 *
 * Not a product page. Mounted only when import.meta.env.DEV is true so
 * the production build never includes it. Lets the owner visually
 * verify tokens, light/dark behavior, and the reusable foundation
 * without fabricating any SPMS data — every value shown is an explicit
 * presentation example of the component API, clearly labelled.
 */

function toHHmm(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}
export function DesignSystemPreview() {
    const { theme, toggleTheme } = useTheme()
    const [loadingPreviewOpen, setLoadingPreviewOpen] = useState(false)
    const [loadingPreviewActive, setLoadingPreviewActive] = useState(true)
    const [solarMinutes, setSolarMinutes] = useState(645)

    return (
        <main className="mx-auto max-w-5xl px-6 py-10">
            <header className="mb-8 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-secondary">
                        F2 · dev-only preview
                    </p>
                    <h1 className="font-display text-2xl font-semibold text-heading">
                        SPMS design system foundation
                    </h1>
                </div>
                <Button variant="icon" onClick={toggleTheme} aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}>
                    {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
            </header>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-info/40 bg-info/10 px-4 py-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-info">
                    <FlaskConical className="size-4" aria-hidden="true" />
                    Dev-only controls on this page: theme switch · loading-state preview
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ThemeSwitch />
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => {
                            setLoadingPreviewActive(true)
                            setLoadingPreviewOpen(true)
                        }}
                    >
                        Test Loading State
                    </Button>
                    <Button variant="secondary" size="sm" asChild>
                        <Link to="/dev/dashboard">Dashboard shell preview</Link>
                    </Button>
                </div>
            </div>

            <div className="grid gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Tokens &amp; typography</CardTitle>
                        <CardDescription>
                            Locked palette via Tailwind semantic utilities. Current theme: {theme}.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-3 gap-3 sm:grid-cols-6">
                            {[
                                ['bg', 'bg-background'],
                                ['surface', 'bg-surface'],
                                ['surface-alt', 'bg-surface-alt'],
                                ['primary', 'bg-primary'],
                                ['secondary', 'bg-secondary'],
                                ['border', 'bg-border'],
                                ['success', 'bg-success'],
                                ['warning', 'bg-warning'],
                                ['danger', 'bg-danger'],
                                ['info', 'bg-info'],
                                ['chart-solar', 'bg-chart-solar'],
                                ['chart-demo', 'bg-chart-demo'],
                            ].map(([name, className]) => (
                                <div key={name} className="text-center">
                                    <div className={`h-10 rounded-md border border-border ${className}`} />
                                    <p className="mt-1 text-[10px] text-muted">{name}</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-4 text-sm text-body">
                            Plus Jakarta Sans body — the quick brown fox jumps over the lazy dog.
                        </p>
                        <p className="font-display text-lg font-semibold text-heading">
                            Plus Jakarta Sans display — 12.4 kWh <span className="text-xs font-normal text-muted">(example styling)</span>
                        </p>

                        {/* F3 refinement 2.1 — final typography specimen (Plus Jakarta Sans) */}
                        <div className="mt-4 rounded-md border border-border bg-surface-alt p-4">
                            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">
                                Final application typography — Plus Jakarta Sans
                            </p>
                            <p className="text-base text-body">Regular 400 — hungry gray glyphs: g y Q 1</p>
                            <p className="text-base font-semibold text-heading">Semibold 600 — Energy 12.4 kWh</p>
                            <p className="text-xl font-extrabold text-heading">Extrabold 800 — 3.6 kW peak</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Primitives</CardTitle>
                        <CardDescription>Keyboard accessible, focus-visible, tokenized.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <Button>Primary</Button>
                            <Button variant="secondary">Secondary</Button>
                            <Button variant="tertiary">Tertiary</Button>
                            <Button variant="destructive">Destructive</Button>
                            <Button variant="icon" aria-label="Icon example"><Sun className="size-4" /></Button>
                            <Button loading>Loading</Button>
                            <Button disabled>Disabled</Button>
                        </div>

                        <div className="grid max-w-md gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="dsp-input">Example input</Label>
                                <Input id="dsp-input" placeholder="Presentation only" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="dsp-select">Example select</Label>
                                <Select>
                                    <SelectTrigger id="dsp-select">
                                        <SelectValue placeholder="Choose one" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="a">Option A</SelectItem>
                                        <SelectItem value="b">Option B</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox id="dsp-check" />
                                <Label htmlFor="dsp-check">Example checkbox</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <Switch id="dsp-switch" />
                                <Label htmlFor="dsp-switch">Example switch</Label>
                            </div>
                        </div>

                        <Tabs defaultValue="one">
                            <TabsList>
                                <TabsTrigger value="one">One</TabsTrigger>
                                <TabsTrigger value="two">Two</TabsTrigger>
                            </TabsList>
                            <TabsContent value="one"><p className="text-sm text-body">Tab one content.</p></TabsContent>
                            <TabsContent value="two"><p className="text-sm text-body">Tab two content.</p></TabsContent>
                        </Tabs>

                        <Separator />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Semantic data presentation</CardTitle>
                        <CardDescription>
                            Example values exercise the component API — never real SPMS data.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            {['ACTUAL', 'PREDICTED', 'ESTIMATED', 'SIMULATED'].map((n) => (
                                <DataNatureBadge key={n} nature={n} />
                            ))}
                            {['FRESH', 'STALE', 'DEGRADED', 'UNAVAILABLE', 'PROCESSING', 'ERROR'].map((s) => (
                                <StatusBadge key={s} status={s} />
                            ))}
                        </div>
                        <div className="flex flex-wrap gap-6">
                            <ConfidenceIndicator level="HIGH" score={0.86} />
                            <ConfidenceIndicator level="MODERATE" score={0.6} />
                            <ConfidenceIndicator level="LOW" />
                            <ConfidenceIndicator level="INSUFFICIENT" />
                        </div>
                        <QualityIndicator level="VERIFIED" />
                        <div className="flex flex-wrap gap-4">
                            <OperationStatus status="QUEUED" />
                            <OperationStatus status="PROCESSING" />
                            <OperationStatus status="COMPLETED" />
                            <OperationStatus status="FAILED" />
                            <OperationStatus status="RECOVERING" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Solar Day Arc</CardTitle>
                        <CardDescription>
                            Data-driven daylight visualization — the parent owns all times; the
                            component only interpolates and presents. Nothing is invented when
                            data is missing.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="space-y-3">
                            <SolarDayArc
                                sunrise="06:12"
                                solarPeak="12:34"
                                sunset="18:21"
                                currentTime={toHHmm(solarMinutes)}
                                date="2026-09-10"
                            />
                            <div className="flex flex-wrap items-center gap-3">
                                <Label htmlFor="dsp-solar-time" className="text-xs text-muted">
                                    Preview time
                                </Label>
                                <input
                                    id="dsp-solar-time"
                                    type="range"
                                    min={360}
                                    max={1140}
                                    step={5}
                                    value={solarMinutes}
                                    onChange={(event) => setSolarMinutes(Number(event.target.value))}
                                    className="h-1.5 w-64 max-w-full cursor-pointer accent-primary"
                                    aria-label="Preview time of day (development only)"
                                />
                                <span className="text-xs font-semibold text-heading">
                                    {toHHmm(solarMinutes)}
                                </span>
                            </div>
                        </div>

                        <Separator />

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <p className="mb-2 text-xs text-muted">Sunrise position</p>
                                <SolarDayArc sunrise="06:12" sunset="18:21" currentTime="06:12" />
                            </div>
                            <div>
                                <p className="mb-2 text-xs text-muted">Solar peak supplied</p>
                                <SolarDayArc
                                    sunrise="05:58"
                                    solarPeak="12:47"
                                    sunset="18:40"
                                    currentTime="12:47"
                                />
                            </div>
                            <div>
                                <p className="mb-2 text-xs text-muted">Sunset position</p>
                                <SolarDayArc sunrise="06:12" sunset="18:21" currentTime="18:21" />
                            </div>
                            <div>
                                <p className="mb-2 text-xs text-muted">
                                    Afternoon — no solar peak supplied
                                </p>
                                <SolarDayArc sunrise="06:12" sunset="18:21" currentTime="15:30" />
                            </div>
                            <div>
                                <p className="mb-2 text-xs text-muted">Stale · predicted nature</p>
                                <SolarDayArc
                                    sunrise="06:12"
                                    sunset="18:21"
                                    currentTime="11:15"
                                    status="STALE"
                                    nature="PREDICTED"
                                />
                            </div>
                            <div>
                                <p className="mb-2 text-xs text-muted">
                                    No data — nothing is invented
                                </p>
                                <SolarDayArc />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-3">
                    <MetricCard label="Example metric" value="12.4" unit="kWh" nature="ACTUAL" status="FRESH" comparison="Presentation example only" />
                    <MetricCard label="Example metric" value="8.1" unit="kWh" nature="PREDICTED" status="FRESH" />
                    <MetricCard label="Loading structure" loading />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <RecommendationCard
                        category="Example"
                        title="Presentation example recommendation"
                        explanation="This card demonstrates the component API. It contains no real SPMS household data."
                        impact="HIGH"
                        confidence="HIGH"
                        timing="Example timing context"
                        why={{
                            facts: [
                                { label: 'Solar forecast', value: 'Example fact' },
                                { label: 'Household demand', value: 'Example fact' },
                                { label: 'Potential situation', value: 'Example fact' },
                            ],
                            explanation: 'SPMS AI explains validated analytical results; it never invents numerical predictions.',
                        }}
                        onFeedback={() => {}}
                    />
                    <div className="grid content-start gap-4">
                        <ChartContainer
                            title="Chart container (empty)"
                            description="Recharts bodies arrive in later phases."
                            footer="Illustrative container only — no chart data exists yet."
                            accessibleSummary="Empty chart container for verification."
                            empty
                        />
                        <ErrorState retryLabel="Retry example" onRetry={() => {}} />
                        <EmptyState
                            variant="noData"
                            icon={Inbox}
                            explanation="Example empty state with action."
                            action={<Button variant="secondary" size="sm">Example action</Button>}
                        />
                        <div>
                            <p className="mb-2 text-xs text-muted">Loading skeleton:</p>
                            <LoadingSkeleton rows={2} />
                        </div>
                    </div>
                </div>
            </div>

            {/* Loading-state preview — dev-only test control for the reusable
                SPMSLoadingState; production builds never include this page.
                "Finish Loading" drives the real isLoading=false departure. */}
            <Dialog open={loadingPreviewOpen} onOpenChange={setLoadingPreviewOpen}>
                <DialogContent className="h-[calc(100vh-2rem)] max-w-none bg-background p-0">
                    <DialogTitle className="sr-only">Loading state preview</DialogTitle>
                    <DialogDescription className="sr-only">
                        Full-screen preview of the reusable SPMS loading state component.
                    </DialogDescription>
                    <SPMSLoadingState
                        className="h-full"
                        isLoading={loadingPreviewActive}
                        onExited={() => setLoadingPreviewOpen(false)}
                    />
                    <div className="absolute inset-x-0 bottom-5 flex justify-center">
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setLoadingPreviewActive(false)}
                            disabled={!loadingPreviewActive}
                        >
                            Finish Loading
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </main>
    )
}
