import { Check, Minus } from 'lucide-react'
import { Link } from 'react-router'
import { Card } from '../../components/ui/card'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * HouseholdsSection (O1 §8) — who SPMS is built for, stated plainly.
 * No testimonials, no customer logos, no invented statistics. The
 * honesty half of the grid sets expectations the way the product's
 * locked rules require (advisory only, labeled estimates).
 */

const FOR = [
    'You generate solar power but still buy electricity from the grid.',
    'Some midday solar appears to go underused while the evening gets expensive.',
    'You want to know when solar will be plentiful before deciding what to run.',
    'You want clear guidance in plain terms — not another technical dashboard.',
]

const HONEST = [
    { sign: Check, text: 'Actual, forecast and estimated values are always distinguished.' },
    { sign: Check, text: 'Insights build from verified data — starting small when history is thin.' },
    { sign: Minus, text: 'No guaranteed savings, and no performance numbers we cannot support.' },
    { sign: Minus, text: 'No appliance control. SPMS advises; your household decides.' },
]

export function HouseholdsSection() {
    return (
        <Section id="built-for-households" labelledBy="households-heading">
            <SectionHeading
                id="households-heading"
                eyebrow="Built for solar households"
                title="Made for the home that has panels — and still has a bill."
            />

            <div className="mt-12 grid gap-6 lg:grid-cols-2">
                <Reveal>
                    <Card className="h-full">
                        <h3 className="font-display text-lg font-semibold text-heading">
                            Sound familiar?
                        </h3>
                        <ul className="mt-5 space-y-4">
                            {FOR.map((item) => (
                                <li key={item} className="flex items-start gap-3">
                                    <span
                                        aria-hidden="true"
                                        className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary-soft text-primary-active"
                                    >
                                        <Check className="size-3.5" strokeWidth={3} />
                                    </span>
                                    <span className="text-sm text-body">{item}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-6 text-sm text-muted">
                            If that describes your home, the solar you generate already has more
                            potential than your bill currently shows.
                        </p>
                    </Card>
                </Reveal>

                <Reveal delay={0.1}>
                    <Card className="flex h-full flex-col bg-surface-alt">
                        <h3 className="font-display text-lg font-semibold text-heading">
                            Honest by design
                        </h3>
                        <ul className="mt-5 space-y-4">
                            {HONEST.map(({ sign: Icon, text }) => (
                                <li key={text} className="flex items-start gap-3">
                                    <span
                                        aria-hidden="true"
                                        className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted"
                                    >
                                        <Icon className="size-3.5" strokeWidth={3} />
                                    </span>
                                    <span className="text-sm text-body">{text}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-6 grow" />
                        <Link
                            to="/register"
                            className="mt-2 self-start text-sm font-semibold text-secondary hover:text-secondary-hover hover:underline"
                        >
                            See what your household could shift →
                        </Link>
                    </Card>
                </Reveal>
            </div>
        </Section>
    )
}
