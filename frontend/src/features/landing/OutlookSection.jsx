import { Card } from '../../components/ui/card'
import { ConfidenceIndicator } from '../../components/data-display/ConfidenceIndicator'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'
import { IllustrativeChip } from './IllustrativeChip'
import { Reveal, Section, SectionHeading } from './Section'

/**
 * OutlookSection (O1 §6) — the forward view. A clearly illustrative
 * three-day sample shows how SPMS frames upcoming energy conditions:
 * potential surplus, close match, and potential deficit windows, each
 * forecast-styled (PREDICTED badge, differentiated bars, declining
 * confidence on the longer horizon). No accuracy percentages, no
 * invented performance statistics.
 */

const HOURS = Array.from({ length: 24 }, (_, hour) => hour)

// Example condition profiles (0–23). n=night, d=deficit-leaning,
// m=match/surplus-leaning window, s=surplus window.
const OUTLOOK_DAYS = [
    {
        id: 'surplus',
        when: 'Tomorrow',
        condition: 'Potential surplus',
        tone: 'success',
        summary: 'Strong midday solar with lower midday demand — a wide window where flexible use could run mostly on solar.',
        confidence: 'HIGH',
        profile: (h) => (h < 6 || h >= 20 ? 'n' : h < 9 || (h >= 17 && h < 20) ? 'd' : h < 10 ? 'm' : 's'),
    },
    {
        id: 'match',
        when: 'In two days',
        condition: 'Mostly balanced',
        tone: 'info',
        summary: 'Solar and demand staying close through the day, with morning and evening use running ahead of production.',
        confidence: 'MODERATE',
        profile: (h) => (h < 6 || h >= 20 ? 'n' : h >= 9 && h < 17 ? 'm' : 'd'),
    },
    {
        id: 'deficit',
        when: 'In three days',
        condition: 'Demand likely ahead of solar',
        tone: 'warning',
        summary: 'Cloudier conditions in the example — a narrower midday window and grid-supported mornings and evenings.',
        confidence: 'LOW',
        profile: (h) => (h < 7 || h >= 19 ? 'n' : h >= 11 && h < 15 ? 'm' : 'd'),
    },
]

const CELL_CLASS = {
    n: 'bg-surface-alt border-border',
    d: 'bg-chart-deficit/45 border-transparent',
    m: 'bg-secondary/35 border-transparent',
    s: 'bg-chart-surplus/55 border-transparent',
}

function HourStrip({ profile, label }) {
    return (
        <div
            role="img"
            aria-label={`${label}: hourly bars — night hours muted, orange-leaning cells mark periods where household demand is expected to exceed solar, green cells mark periods where solar is expected to exceed household demand.`}
            className="flex h-8 items-stretch gap-px overflow-hidden rounded-md border border-border bg-border"
        >
            {HOURS.map((hour) => (
                <span key={hour} aria-hidden="true" className={cn('grow', CELL_CLASS[profile(hour)])} />
            ))}
        </div>
    )
}

export function OutlookSection() {
    return (
        <Section id="outlook" labelledBy="outlook-heading">
            <SectionHeading
                id="outlook-heading"
                eyebrow="Energy outlook"
                title="See tomorrow's energy before it happens."
                lead="SPMS projects the next hours and days as conditions, not certainties: when solar is likely to run ahead of the home, and when the home is likely to lean on the grid."
            />

            <div className="mt-6 flex justify-center">
                <IllustrativeChip label="Example outlook shapes — illustrative, not a forecast for your home" />
            </div>

            <div className="mt-8 grid gap-5 md:grid-cols-3">
                {OUTLOOK_DAYS.map((day, index) => (
                    <Reveal key={day.id} delay={0.08 * index}>
                        <Card className="flex h-full flex-col gap-4">
                            <div className="flex items-center justify-between gap-2">
                                <h3 className="font-display text-base font-semibold text-heading">{day.when}</h3>
                                <DataNatureBadge nature="PREDICTED" />
                            </div>

                            <span
                                className={cn(
                                    'inline-flex w-fit items-center rounded-full border px-3 py-1 text-sm font-semibold',
                                    TONE_CLASS[day.tone],
                                )}
                            >
                                {day.condition}
                            </span>

                            <HourStrip label={day.when} profile={day.profile} />
                            <div className="flex items-center justify-between text-[10px] text-muted">
                                <span>12 AM</span>
                                <span>6 AM</span>
                                <span>12 PM</span>
                                <span>6 PM</span>
                                <span>12 AM</span>
                            </div>

                            <p className="grow text-sm text-body">{day.summary}</p>
                            <ConfidenceIndicator level={day.confidence} />
                        </Card>
                    </Reveal>
                ))}
            </div>

            <div className="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-muted">
                <span className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="size-2.5 rounded-sm bg-chart-surplus/55" />
                    Solar expected ahead of demand
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="size-2.5 rounded-sm bg-secondary/35" />
                    Roughly balanced
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="size-2.5 rounded-sm bg-chart-deficit/45" />
                    Demand expected ahead of solar
                </span>
            </div>

            <p className="mx-auto mt-6 max-w-2xl text-center text-xs text-muted">
                Forecast confidence narrows with the horizon — a potential surplus is not confirmed
                grid export, and a potential deficit is not confirmed grid import. Real outlooks are
                computed for your household from validated history and change with the weather.
            </p>
        </Section>
    )
}
