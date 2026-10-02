import { Activity, ArrowRight, Eye, Lightbulb } from 'lucide-react'
import { Card } from '../../components/ui/card'
import { RecommendationCard } from '../../components/data-display/RecommendationCard'
import { cn } from '../../lib/utils'
import { IllustrativeChip } from './IllustrativeChip'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * RecommendationsSection (O1 §5) — shows what an SPMS recommendation
 * looks like using the shared RecommendationCard, and makes the
 * reasoning philosophy explicit: Evidence/Data → Understanding/Interpretation → Action/Recommendation.
 *
 * Content is clearly marked as an illustrative example; no appliance
 * control or guaranteed savings are implied anywhere.
 */

const CHAIN = [
    {
        icon: Activity,
        title: 'Evidence / Data',
        text: 'Verified bills, solar readings, weather context, and system status.',
        tone: 'bg-primary-soft text-primary-active',
    },
    {
        icon: Eye,
        title: 'Understanding / Interpretation',
        text: 'Forecasts, energy situation, and demand patterns — each with confidence and nature clearly labeled.',
        tone: 'bg-secondary-soft text-secondary-active',
    },
    {
        icon: Lightbulb,
        title: 'Action / Recommendation',
        text: 'A practical, timing-aware advisory suggestion. The household always remains in control.',
        tone: 'bg-warning/10 text-warning',
    },
]

const RECOMMENDATION_TYPES = [
    'Shifting flexible usage toward periods of higher solar availability',
    'Improving solar self-use when potential surplus is identified',
    'Shifting flexible usage away from higher-demand or lower-solar periods',
    'Forecast-aware usage timing for planned household activities',
    'Cost-aware guidance only when valid tariff and cost evidence exists',
    'Data-quality and system-status guidance when solar data is stale or unavailable',
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
                <Reveal className="flex flex-col gap-4 lg:col-span-2">
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

                    <Card className="border-border bg-surface p-4">
                        <h4 className="font-display text-xs font-semibold uppercase tracking-wider text-secondary">
                            Advisory guidance areas
                        </h4>
                        <p className="mt-1 text-xs text-muted">
                            SPMS provides recommendations only when supported by evidence:
                        </p>
                        <ul className="mt-3 space-y-2 text-xs text-body" aria-label="Supported recommendation types">
                            {RECOMMENDATION_TYPES.map((type) => (
                                <li key={type} className="flex items-start gap-2">
                                    <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                                    <span>{type}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </Reveal>

                <Reveal delay={0.12} className="lg:col-span-3">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold text-heading">
                            Example recommendation
                        </h3>
                        <IllustrativeChip label="Example recommendation — not live advice for a home" />
                    </div>
                    <RecommendationCard
                        category="Flexible usage"
                        impact="MEDIUM"
                        title="Consider shifting flexible household energy use toward the higher-solar period"
                        explanation="Solar availability is expected to be higher during this period while household demand is comparatively lower. Flexible loads may be better aligned with this window."
                        confidence="MODERATE"
                        timing="Example window: 11:00 – 14:00"
                        defaultWhyOpen={true}
                        why={{
                            facts: [
                                { label: 'Solar outlook', value: 'Higher availability expected around midday (illustrative)' },
                                { label: 'Household demand', value: 'Comparatively lower demand projected during this period' },
                                { label: 'Energy situation', value: 'Potential solar surplus — advisory guidance only' },
                            ],
                            explanation:
                                'Solar availability is expected to be higher during this period while household demand is comparatively lower. Flexible loads may be better aligned with this window.',
                        }}
                    />
                    <p className="mt-4 text-xs text-muted">
                        SPMS provides advisory guidance only; it never controls or schedules appliances on its own, and recommendations do not guarantee savings, surplus, or grid export. The household always remains in control.
                    </p>
                </Reveal>
            </div>
        </Section>
    )
}
