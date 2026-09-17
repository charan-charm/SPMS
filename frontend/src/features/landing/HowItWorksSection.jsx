import { motion, useReducedMotion } from 'motion/react'
import { Activity, CloudSun, Lightbulb, PlugZap, Scale } from 'lucide-react'
import { Card } from '../../components/ui/card'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * HowItWorksSection (O1) — five connected steps (01–05). Styled with soft, light
 * brand pastel colors (pale green & pale teal) for badges and icons, and an animated
 * soft gradient line track drawing seamlessly behind the cards.
 */

const STEPS = [
    {
        number: '01',
        icon: PlugZap,
        title: 'Connect',
        text: 'Add what your household already has — solar system details, electricity bills and basic context.',
        badgeTone: 'border-primary/25 bg-primary-soft/80 text-primary-active',
        iconTone: 'bg-primary-soft text-primary-active',
    },
    {
        number: '02',
        icon: Activity,
        title: 'Understand',
        text: 'SPMS builds a picture of how much your home uses, and when, from the evidence you provide.',
        badgeTone: 'border-secondary/25 bg-secondary-soft/80 text-secondary-active',
        iconTone: 'bg-secondary-soft text-secondary-active',
    },
    {
        number: '03',
        icon: Scale,
        title: 'Analyze',
        text: 'Production and consumption are read together, hour by hour, to find where solar fits and where it slips away.',
        badgeTone: 'border-primary/25 bg-primary-soft/80 text-primary-active',
        iconTone: 'bg-primary-soft text-primary-active',
    },
    {
        number: '04',
        icon: CloudSun,
        title: 'Forecast',
        text: 'Upcoming solar availability and household demand are estimated — clearly labeled as forecasts, never as measurements.',
        badgeTone: 'border-secondary/25 bg-secondary-soft/80 text-secondary-active',
        iconTone: 'bg-secondary-soft text-secondary-active',
    },
    {
        number: '05',
        icon: Lightbulb,
        title: 'Recommend',
        text: 'You receive practical, timing-aware suggestions. Acting on them is always your choice.',
        badgeTone: 'border-warning/30 bg-warning/15 text-warning',
        iconTone: 'bg-warning/15 text-warning',
    },
]

export function HowItWorksSection() {
    const reducedMotion = useReducedMotion()

    return (
        <Section id="how-it-works" labelledBy="how-it-works-heading" alt>
            <SectionHeading
                id="how-it-works-heading"
                eyebrow="How SPMS works"
                title="Five steps from energy data to better decisions."
            />

            <div className="relative mt-12">
                {/* Richer, slightly darker animated line track behind the cards on desktop — draws from step 01 to 05 */}
                <div
                    className="pointer-events-none absolute left-10 right-10 top-[2.25rem] hidden h-1 -translate-y-1/2 overflow-hidden rounded-full bg-border/70 dark:bg-border/80 lg:block"
                    aria-hidden="true"
                >
                    <motion.div
                        initial={reducedMotion ? { scaleX: 1 } : { scaleX: 0 }}
                        whileInView={{ scaleX: 1 }}
                        viewport={{ once: true, margin: '-40px' }}
                        transition={{ duration: 1.3, ease: [0.22, 1, 0.36, 1], delay: 0.15 }}
                        className="h-full w-full origin-left bg-gradient-to-r from-primary via-secondary to-primary opacity-90 shadow-sm"
                    />
                </div>

                <ol
                    className="relative z-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-5"
                    aria-label="How SPMS works, step by step"
                >
                    {STEPS.map((step, index) => (
                        <li key={step.number} className="group relative h-full">
                            <Reveal delay={0.06 * index} className="h-full">
                                <Card className="relative h-full transition-all duration-300 ease-out hover:-translate-y-1.5 hover:border-primary/50 hover:shadow-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <span
                                            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-display text-xs font-semibold ${step.badgeTone}`}
                                        >
                                            Step {step.number}
                                        </span>
                                        <span
                                            className={`grid size-9 place-items-center rounded-lg ${step.iconTone} transition-all duration-300 ease-out group-hover:scale-110 group-hover:shadow-sm`}
                                            aria-hidden="true"
                                        >
                                            <step.icon className="size-5" />
                                        </span>
                                    </div>
                                    <h3 className="mt-4 font-display text-base font-semibold text-heading transition-colors duration-300 group-hover:text-primary">
                                        {step.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-body">{step.text}</p>
                                </Card>
                            </Reveal>
                        </li>
                    ))}
                </ol>
            </div>
        </Section>
    )
}