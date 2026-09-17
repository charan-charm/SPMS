import { CloudSun, Home, Lightbulb, ListChecks, Receipt, Sun } from 'lucide-react'
import { Card } from '../../components/ui/card'
import { cn } from '../../lib/utils'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * CapabilitiesSection (O1 §3) — the six core capability groups of the
 * actual SPMS architecture (solar, household energy, bills, forecasts,
 * insights, recommendations). Copy corresponds to real system behavior:
 * advisory recommendations only, labeled estimates, verification-first
 * bill processing.
 */

const CAPABILITIES = [
    {
        icon: Sun,
        title: 'Understand solar production',
        text: 'See what your system actually generated, with source, freshness and data quality always visible.',
        tone: 'bg-primary-soft text-primary-active',
    },
    {
        icon: Home,
        title: 'Understand household consumption',
        text: 'Turn bills and meter evidence into a clear picture of what your home uses, and where it goes.',
        tone: 'bg-info/10 text-info',
    },
    {
        icon: Receipt,
        title: 'Analyze electricity bills',
        text: 'Upload a bill and SPMS extracts each field, cross-checks it against your readings, and asks you to verify before anything becomes trusted data.',
        tone: 'bg-secondary-soft text-secondary-active',
    },
    {
        icon: CloudSun,
        title: 'Forecast energy conditions',
        text: 'Project upcoming solar availability and household demand from validated history and weather context — estimates, clearly labeled as such.',
        tone: 'bg-warning/10 text-warning',
    },
    {
        icon: Lightbulb,
        title: 'Discover energy insights',
        text: 'Surface where production and demand line up — and where generated solar goes underused.',
        tone: 'bg-chart-surplus/10 text-chart-surplus',
    },
    {
        icon: ListChecks,
        title: 'Act on recommendations',
        text: 'Get concrete suggestions on what could shift, and when — always advisory. You decide; SPMS never controls appliances.',
        tone: 'bg-chart-demo/10 text-chart-demo',
    },
]

export function CapabilitiesSection() {
    return (
        <Section id="capabilities" labelledBy="capabilities-heading" alt>
            <SectionHeading
                id="capabilities-heading"
                eyebrow="Core capabilities"
                title="More than a solar dashboard."
                lead="A monitoring screen shows what happened. SPMS works out what it means for your household — and what you could reasonably do next."
            />

            <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {CAPABILITIES.map((capability, index) => (
                    <Reveal key={capability.title} delay={0.05 * index}>
                        <Card className="h-full">
                            <span
                                className={cn('grid size-10 place-items-center rounded-lg', capability.tone)}
                                aria-hidden="true"
                            >
                                <capability.icon className="size-5" />
                            </span>
                            <h3 className="mt-4 font-display text-base font-semibold text-heading">
                                {capability.title}
                            </h3>
                            <p className="mt-2 text-sm text-body">{capability.text}</p>
                        </Card>
                    </Reveal>
                ))}
            </div>
        </Section>
    )
}
