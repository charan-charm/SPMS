import { Activity, ArrowRight, Eye, Lightbulb } from 'lucide-react'
import { Card } from '../../components/ui/card'
import { RecommendationCard } from '../../components/data-display/RecommendationCard'
import { cn } from '../../lib/utils'
import { IllustrativeChip } from './IllustrativeChip'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * RecommendationsSection (O1 §5) — shows what an SPMS recommendation
 * looks like using the shared RecommendationCard, and makes the
 * reasoning chain explicit: DATA → UNDERSTANDING → ACTION. The card
 * content is a fixed example clearly marked as such; no appliance
 * control is implied anywhere.
 */

const CHAIN = [
    {
        icon: Activity,
        title: 'Data',
        text: 'Verified bills, solar readings, weather context.',
        tone: 'bg-primary-soft text-primary-active',
    },
    {
        icon: Eye,
        title: 'Understanding',
        text: 'Forecasts, energy balance, and insights — each labeled with its nature and confidence.',
        tone: 'bg-secondary-soft text-secondary-active',
    },
    {
        icon: Lightbulb,
        title: 'Action',
        text: 'A concrete, timing-aware suggestion. You decide whether it fits your day.',
        tone: 'bg-warning/10 text-warning',
    },
]

export function RecommendationsSection() {
    return (
        <Section id="recommendations" labelledBy="recommendations-heading" alt>
            <SectionHeading
                id="recommendations-heading"
                eyebrow="Recommendations"
                title="From energy data to a useful next step."
                lead="SPMS does not just chart your day — it works out what the data means and suggests something practical around it. Here is the shape of that thinking."
            />

            <div className="mt-12 grid items-start gap-6 lg:grid-cols-5">
                <Reveal className="lg:col-span-2">
                    <ol className="flex flex-col gap-3" aria-label="How SPMS forms a recommendation">
                        {CHAIN.map((step, index) => (
                            <li key={step.title} className="relative">
                                <Card className="flex items-start gap-4">
                                    <span
                                        className={cn(
                                            'grid size-10 shrink-0 place-items-center rounded-lg',
                                            step.tone,
                                        )}
                                        aria-hidden="true"
                                    >
                                        <step.icon className="size-5" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-wide text-muted">
                                            Step {index + 1}
                                        </p>
                                        <h3 className="font-display text-base font-semibold text-heading">
                                            {step.title}
                                        </h3>
                                        <p className="mt-1 text-sm text-body">{step.text}</p>
                                    </div>
                                </Card>
                                {index < CHAIN.length - 1 && (
                                    <span className="relative z-10 -my-1.5 flex justify-center" aria-hidden="true">
                                        <ArrowRight className="size-4 rotate-90 text-muted" />
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </Reveal>

                <Reveal delay={0.12} className="lg:col-span-3">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold text-heading">
                            What that produces
                        </h3>
                        <IllustrativeChip label="Example recommendation — not live advice for a home" />
                    </div>
                    <RecommendationCard
                        category="Flexible usage"
                        impact="MEDIUM"
                        title="Consider shifting flexible household energy use toward the higher-solar period"
                        explanation="High solar availability is expected around midday on this example day, while household demand is comparatively low — a window where flexible tasks can run on solar rather than wait."
                        confidence="MODERATE"
                        timing="Example window: around midday"
                        why={{
                            facts: [
                                { label: 'Solar outlook', value: 'Higher availability expected around midday (illustrative)' },
                                { label: 'Household demand', value: 'Comparatively lower midday demand (illustrative)' },
                                { label: 'Energy situation', value: 'Potential surplus — not a confirmed grid export' },
                            ],
                            explanation:
                                'This is an example of how SPMS reasons: validated data leads to an understanding, and the understanding leads to a suggestion. The decision — and the switching — stays with you.',
                        }}
                    />
                    <p className="mt-4 text-xs text-muted">
                        SPMS recommends; it never controls or schedules appliances on its own, and
                        recommendations are not guarantees of savings.
                    </p>
                </Reveal>
            </div>
        </Section>
    )
}
