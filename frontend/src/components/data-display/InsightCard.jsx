import { useId, useState } from 'react'
import {
    Activity,
    AlertCircle,
    ArrowRight,
    Calendar,
    ChevronDown,
    CloudSun,
    IndianRupee,
    Lightbulb,
    Sparkles,
    Sun,
    Zap,
} from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card'
import { DataNatureBadge } from './DataNatureBadge'
import { StatusBadge } from './StatusBadge'
import { ConfidenceIndicator } from './ConfidenceIndicator'
import { QualityIndicator } from './QualityIndicator'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * InsightCard (F6 — docs/10 D-043..D-048) — presents one backend
 * insight in clear, household-friendly language.
 *
 * Core User Experience Architecture:
 * 1. What SPMS Observed: Direct finding in plain English
 * 2. Why It Matters: Household impact on solar self-consumption, bills, or grid draw
 * 3. Recommended Next Step: Practical, actionable household advice with direct navigation
 * 4. Context & Quality Bar: Affected dates, confidence, and data nature
 * 5. Detailed Evidence: Expandable deterministic facts, limitations, and audit provenance
 */

const SIGNIFICANCE_TONE = {
    HIGH: TONE_CLASS.warning,
    MODERATE: TONE_CLASS.info,
    LOW: TONE_CLASS.muted,
}

const SIGNIFICANCE_LABEL = {
    HIGH: 'High significance',
    MODERATE: 'Moderate significance',
    LOW: 'Low significance',
}

function humanizeToken(value) {
    if (!value || typeof value !== 'string') return ''
    const words = value.toLowerCase().split('_')
    return words.map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
}

function periodLabel(period) {
    if (!period) {
        return 'Not period-scoped'
    }
    const start = period.start ?? null
    const end = period.end ?? null
    if (start && end) {
        if (start === end) {
            return start
        }
        return `${start} → ${end}`
    }
    return start ?? end ?? 'Not period-scoped'
}

function CategoryIcon({ category, type, className }) {
    if (type === 'DATA_LIMITATION') {
        return <AlertCircle className={className} aria-hidden="true" />
    }
    switch (category) {
        case 'WEATHER':
            return <CloudSun className={className} aria-hidden="true" />
        case 'SOLAR':
            return <Sun className={className} aria-hidden="true" />
        case 'CONSUMPTION':
            return <Activity className={className} aria-hidden="true" />
        case 'ENERGY':
            return <Zap className={className} aria-hidden="true" />
        case 'COST':
            return <IndianRupee className={className} aria-hidden="true" />
        default:
            return <Lightbulb className={className} aria-hidden="true" />
    }
}

function CategoryIconBadge({ category, type }) {
    let colorClasses = 'bg-primary/10 text-primary-active border-primary/20'
    if (type === 'DATA_LIMITATION') {
        colorClasses = 'bg-warning/10 text-warning border-warning/20'
    } else {
        switch (category) {
            case 'WEATHER':
                colorClasses = 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20'
                break
            case 'SOLAR':
                colorClasses = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                break
            case 'CONSUMPTION':
                colorClasses = 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20'
                break
            case 'ENERGY':
                colorClasses = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                break
            case 'COST':
                colorClasses = 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20'
                break
        }
    }

    return (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg border', colorClasses)}>
            <CategoryIcon category={category} type={type} className="size-4" />
        </div>
    )
}

function getHouseholdGuidance(insight) {
    if (!insight) {
        return {
            whyItMatters: 'Energy observations help identify opportunities to increase solar self-consumption and lower electricity costs.',
            actionText: 'Review your energy dashboard to see how solar generation and household demand interact.',
            actionLink: { label: 'Explore Recommendations', href: '/recommendations' },
        }
    }

    const type = insight.type || ''

    switch (type) {
        case 'DAYTIME_SURPLUS_EVENING_DEFICIT':
            return {
                whyItMatters:
                    'Your solar panels produce surplus power in the middle of the day, but your home uses the most electricity after sunset. Shifting daytime appliance use helps you use free solar power instead of paying for grid electricity.',
                actionText:
                    'Run flexible high-draw appliances — such as washing machines, dishwashers, water pumps, and water heaters — between 10:00 AM and 3:00 PM during peak sunlight.',
                actionLink: { label: 'View Load Shifting Recommendations', href: '/recommendations' },
            }

        case 'SOLAR_DEMAND_MISMATCH':
            return {
                whyItMatters:
                    'Peak solar production hours and household electricity usage hours are misaligned. Excess midday solar is exported, while evening electricity must be purchased from the utility grid.',
                actionText:
                    'Use timers or schedule major appliance runs during sunny daylight hours to increase direct solar self-consumption.',
                actionLink: { label: 'Explore Timing Recommendations', href: '/recommendations' },
            }

        case 'RECURRING_POTENTIAL_SURPLUS':
            return {
                whyItMatters:
                    'Your solar array consistently generates more electricity than estimated home consumption during sunny hours. You have ample clean solar power available.',
                actionText:
                    'You have surplus solar capacity to power discretionary loads like electric vehicle charging, pool pumps, or pre-cooling during midday hours.',
                actionLink: { label: 'View Surplus Opportunities', href: '/recommendations' },
            }

        case 'RECURRING_POTENTIAL_DEFICIT':
            return {
                whyItMatters:
                    'Household electricity consumption regularly exceeds solar power generation, resulting in recurring dependence on utility grid power and higher monthly bills.',
                actionText:
                    'Review high-draw appliances to identify avoidable consumption, or explore energy efficiency measures to lower grid power purchases.',
                actionLink: { label: 'View Conservation Recommendations', href: '/recommendations' },
            }

        case 'SOLAR_PRODUCTION_DECLINE':
            return {
                whyItMatters:
                    'Solar generation dropped noticeably compared to prior periods. This could be due to seasonal cloud cover, dust or debris accumulation on panels, or shading.',
                actionText:
                    'Check your solar panels for dust, soiling, or physical shading. If conditions are clear, verify inverter telemetry and error logs on the Solar page.',
                actionLink: { label: 'Inspect Solar Telemetry', href: '/solar' },
            }

        case 'SOLAR_PRODUCTION_IMPROVEMENT':
            return {
                whyItMatters:
                    'Solar generation increased noticeably compared to prior periods, providing more clean power to cover household electricity needs.',
                actionText:
                    'Take advantage of higher generation by scheduling household chores and appliance cycles during midday solar hours.',
                actionLink: { label: 'View Generation Curve', href: '/solar' },
            }

        case 'RECURRING_SOLAR_PATTERN':
            return {
                whyItMatters:
                    'Your solar power generation is operating steadily and predictably within normal expected variance across recent observation periods.',
                actionText:
                    'No corrective action required. Routine periodic panel cleaning helps maintain this steady output over time.',
                actionLink: { label: 'View Solar Performance', href: '/solar' },
            }

        case 'SOLAR_PRODUCTION_CHANGE':
            return {
                whyItMatters:
                    'Solar generation shifted moderately between recent periods, reflecting normal seasonal sun angles, weather patterns, or temperature changes.',
                actionText:
                    'Monitor your daily solar curve to track how seasonal daylight hours influence your generation totals.',
                actionLink: { label: 'View Daily Generation', href: '/solar' },
            }

        case 'CONSUMPTION_INCREASE':
            return {
                whyItMatters:
                    'Household electricity use increased noticeably compared to previous periods. Higher consumption leads directly to higher monthly utility bills.',
                actionText:
                    'Check whether seasonal cooling, heating, water pumps, or new appliances have been running longer than usual.',
                actionLink: { label: 'Review Recent Bills', href: '/bills' },
            }

        case 'CONSUMPTION_DECREASE':
            return {
                whyItMatters:
                    'Household electricity consumption dropped noticeably compared to prior periods, reducing your monthly utility charges and reliance on the grid.',
                actionText:
                    'Great job! Continue your current energy-saving habits to sustain lower monthly electricity expenses.',
                actionLink: null,
            }

        case 'RECURRING_HIGH_DEMAND':
            return {
                whyItMatters:
                    'Household electricity use has remained consistently high across multiple observation periods, which may place billing into higher utility tariff brackets.',
                actionText:
                    'Review your monthly bill breakdown to identify peak consumption periods and consider shifting high-wattage loads to solar hours.',
                actionLink: { label: 'Review Bill Breakdown', href: '/bills' },
            }

        case 'UNUSUAL_CONSUMPTION_PATTERN':
            return {
                whyItMatters:
                    'Electricity consumption deviated significantly from your established household baseline, indicating unexpected appliance usage or a continuous load.',
                actionText:
                    'Check whether appliances such as water heaters, air conditioners, or pumps were inadvertently left running continuously.',
                actionLink: { label: 'View Appliance Recommendations', href: '/recommendations' },
            }

        case 'CONSUMPTION_CHANGE':
            return {
                whyItMatters:
                    'Household electricity consumption shifted moderately between recent observation periods, reflecting baseline household routine changes.',
                actionText:
                    'Compare current consumption with past verified billing statements to understand long-term household energy trends.',
                actionLink: { label: 'Compare Electricity Bills', href: '/bills' },
            }

        case 'COST_IMPACT_PATTERN':
            return {
                whyItMatters:
                    'Estimated variable grid electricity costs remained active across multiple periods. Electricity drawn from the grid during non-solar hours drives this recurring expense.',
                actionText:
                    'Aligning high-power appliance cycles with peak solar hours directly reduces variable grid energy charges on your monthly bill.',
                actionLink: { label: 'Explore Cost Saving Actions', href: '/recommendations' },
            }

        case 'COST_INCREASE':
            return {
                whyItMatters:
                    'Estimated energy costs increased noticeably between recent observation periods, driven by higher grid electricity consumption or seasonal tariff shifts.',
                actionText:
                    'Review verified billing records to determine whether the cost increase was caused by higher unit usage or fixed utility charges.',
                actionLink: { label: 'Review Verified Bills', href: '/bills' },
            }

        case 'COST_DECREASE':
            return {
                whyItMatters:
                    'Estimated electricity costs decreased noticeably compared to previous periods, reducing your household energy expenditure.',
                actionText:
                    'Maintain your current energy management routines to keep estimated utility costs low.',
                actionLink: null,
            }

        case 'COST_CHANGE':
            return {
                whyItMatters:
                    'Estimated electricity costs shifted moderately between observation periods, reflecting changes in household usage or billing rates.',
                actionText:
                    'Check your monthly bills to verify actual billed amounts and monitor energy cost trends.',
                actionLink: { label: 'Check Verified Bills', href: '/bills' },
            }

        case 'WEATHER_PLANNING_WINDOW':
            return {
                whyItMatters:
                    'Tomorrow’s forecast indicates clear skies and high direct sunlight during midday hours. Scheduling heavy power usage into this window uses clean solar energy directly.',
                actionText:
                    'Plan power-intensive household chores — such as laundry, water pumping, or water heating — between 10:00 AM and 3:00 PM tomorrow.',
                actionLink: { label: 'View Weather-Aware Recommendations', href: '/recommendations' },
            }

        case 'CLOUDY_PRODUCTION_RISK':
            return {
                whyItMatters:
                    'Forecasted overcast skies and lower solar irradiance tomorrow will reduce photovoltaic yield compared with clear days.',
                actionText:
                    'Postpone heavy optional electricity loads, or schedule essential loads during the clearest daylight hours.',
                actionLink: { label: 'Check Solar Forecast & Recommendations', href: '/recommendations' },
            }

        case 'WEATHER_VARIABILITY_RISK':
            return {
                whyItMatters:
                    'Atmospheric models predict precipitation or variable cloud cover, causing rapid fluctuations in solar output.',
                actionText:
                    'Monitor real-time inverter output and avoid running multiple heavy electric appliances concurrently.',
                actionLink: { label: 'Check Weather & Solar Forecast', href: '/forecasts' },
            }

        case 'WEATHER_DATA_UNAVAILABLE':
            return {
                whyItMatters:
                    'Solar forecasting is currently running on the astronomical clear-sky baseline because geographic coordinates are not configured.',
                actionText:
                    'Configure your city or locality in household settings to enable real-time cloud and weather forecasts.',
                actionLink: { label: 'Configure Household Location', href: '/settings' },
            }

        case 'SOLAR_TELEMETRY_STALE':
            return {
                whyItMatters:
                    'Live inverter measurements have not updated within the expected interval. Real-time solar production cannot be confirmed.',
                actionText:
                    'Check your inverter Wi-Fi or logger connection to ensure real-time telemetry is reaching SPMS.',
                actionLink: { label: 'Review Solar Connection Settings', href: '/solar' },
            }

        case 'INVERTER_DISCONNECTED':
            return {
                whyItMatters:
                    'Inverter communication is reported as offline or fault status. Solar generation monitoring is temporarily paused.',
                actionText:
                    'Inspect your inverter physical display, verify the Wi-Fi dongle, or check power to the logger.',
                actionLink: { label: 'Inspect Solar Hardware Status', href: '/solar' },
            }

        case 'DATA_LIMITATION':
            return {
                whyItMatters:
                    'Available telemetry or billing records are incomplete, stale, or simulated. SPMS never invents or guesses missing data, so real-time insights are temporarily restricted.',
                actionText:
                    'Check your GoodWe solar inverter Wi-Fi connection and sync status on the Solar page to restore live telemetry and unlock real-time insights.',
                actionLink: { label: 'Check Solar Inverter Connection', href: '/solar' },
            }

        case 'SEASONAL_PATTERN':
            return {
                whyItMatters:
                    'Energy generation and consumption patterns follow recurring seasonal cycles as temperatures and daylight hours shift throughout the year.',
                actionText:
                    'Plan seasonal cooling and heating habits around solar peak hours to maintain optimal energy balance across changing seasons.',
                actionLink: { label: 'View Forecasts', href: '/forecasts' },
            }

        case 'SIGNIFICANT_CHANGE':
            return {
                whyItMatters:
                    'A material shift was observed across your household energy evidence, indicating a change in solar performance, household routine, or weather.',
                actionText:
                    'Inspect your Energy Balance and Solar Telemetry pages to see what changed and how your household can adapt.',
                actionLink: { label: 'View Energy Balance', href: '/energy-balance' },
            }

        default:
            return {
                whyItMatters:
                    insight.explanation ||
                    'Understanding household energy patterns helps you maximize solar self-consumption and lower electricity costs.',
                actionText:
                    'Review your solar generation and electricity bills to identify opportunities for load alignment.',
                actionLink: { label: 'View Recommendations', href: '/recommendations' },
            }
    }
}

export function InsightCard({ insight }) {
    const [expanded, setExpanded] = useState(false)
    const detailsId = useId()

    if (!insight) {
        return null
    }

    const significanceTone = SIGNIFICANCE_TONE[insight.significance] ?? TONE_CLASS.muted
    const significanceLabel = SIGNIFICANCE_LABEL[insight.significance] ?? insight.significance
    const guidance = getHouseholdGuidance(insight)

    return (
        <Card className="overflow-hidden transition-all duration-150 hover:border-border/80">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <CategoryIconBadge category={insight.category} type={insight.type} />
                        <div>
                            <CardTitle className="text-base font-semibold text-heading leading-snug">
                                {insight.title}
                            </CardTitle>
                            <p className="mt-0.5 text-xs uppercase tracking-wide text-muted font-medium">
                                {humanizeToken(insight.category)} · {humanizeToken(insight.type)}
                                {insight.observation_count > 1 ? ` · observed ${insight.observation_count} times` : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {insight.data_nature && <DataNatureBadge nature={insight.data_nature} />}
                        {insight.data_status && insight.data_status !== 'FRESH' && (
                            <StatusBadge status={insight.data_status} />
                        )}
                        <span
                            className={cn(
                                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                significanceTone,
                            )}
                        >
                            {significanceLabel}
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {/* 1. What SPMS Observed */}
                <div className="space-y-1">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted">What SPMS Observed</p>
                    <p className="text-sm font-medium text-heading leading-relaxed">{insight.summary}</p>
                </div>

                {/* 2. Why It Matters */}
                <div className="space-y-1">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted">Why It Matters</p>
                    <p className="text-sm text-body leading-relaxed">{guidance.whyItMatters}</p>
                </div>

                {/* 3. Recommended Next Step */}
                <div className="rounded-lg border border-primary/20 bg-primary/5 p-3.5 space-y-1.5">
                    <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-primary-active">
                        <Sparkles className="size-3.5" aria-hidden="true" />
                        <span>Recommended Next Step</span>
                    </div>
                    <p className="text-sm text-heading leading-relaxed">{guidance.actionText}</p>
                    {guidance.actionLink && (
                        <div className="pt-1">
                            <a
                                href={guidance.actionLink.href}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-primary-active hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded"
                            >
                                <span>{guidance.actionLink.label}</span>
                                <ArrowRight className="size-3" aria-hidden="true" />
                            </a>
                        </div>
                    )}
                </div>

                {/* 4. Context & Quality Summary Bar */}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/50 pt-3 text-xs text-muted">
                    <div className="flex items-center gap-1.5">
                        <Calendar className="size-3.5 shrink-0 text-muted" aria-hidden="true" />
                        <span>Affected period:</span>
                        <span className="font-medium text-body">{periodLabel(insight.affected_period)}</span>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        {insight.confidence && <ConfidenceIndicator level={insight.confidence} />}
                        {insight.quality && <QualityIndicator level={insight.quality} />}
                    </div>
                </div>

                {/* 5. Expandable Detailed Evidence */}
                <div>
                    <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={detailsId}
                        aria-label={`Why this insight: ${insight.title}?`}
                        onClick={() => setExpanded((current) => !current)}
                        className="inline-flex items-center gap-1.5 rounded-md text-xs font-medium text-primary-active hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                        <span>Why this insight? (Detailed evidence & calculations)</span>
                        <ChevronDown
                            className={cn('size-3.5 transition-transform', expanded && 'rotate-180')}
                            aria-hidden="true"
                        />
                    </button>

                    {expanded && (
                        <div id={detailsId} className="mt-3 flex flex-col gap-3 rounded-lg border border-border bg-surface-alt px-4 py-3.5 text-sm">
                            {insight.explanation && (
                                <div>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
                                        Deterministic Explanation
                                    </p>
                                    <p className="text-sm text-body leading-relaxed">{insight.explanation}</p>
                                </div>
                            )}

                            {Array.isArray(insight.supporting_facts) && insight.supporting_facts.length > 0 && (
                                <div>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
                                        Supporting facts
                                    </p>
                                    <ul className="space-y-1.5">
                                        {insight.supporting_facts.map((fact, index) => (
                                            <li key={`${index}-${fact}`} className="flex items-start gap-2 text-xs text-body leading-relaxed">
                                                <span aria-hidden="true" className="mt-1.5 size-1 shrink-0 rounded-full bg-primary" />
                                                <span>{fact}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {Array.isArray(insight.limitations) && insight.limitations.length > 0 && (
                                <div>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
                                        Limitations
                                    </p>
                                    <ul className="space-y-1.5">
                                        {insight.limitations.map((limitation, index) => (
                                            <li key={`${index}-${limitation}`} className="flex items-start gap-2 text-xs text-muted leading-relaxed">
                                                <span aria-hidden="true" className="mt-1.5 size-1 shrink-0 rounded-full bg-muted" />
                                                <span>{limitation}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div className="border-t border-border/60 pt-2.5 text-xs text-muted flex flex-wrap gap-x-4 gap-y-1">
                                <span>Engine: {insight.explanation_source || 'F6_DETERMINISTIC_ENGINE_V1'}</span>
                                {insight.status && <span>Lifecycle: {humanizeToken(insight.status)}</span>}
                                {insight.last_observed_at && <span>Last observed: {insight.last_observed_at} UTC</span>}
                                {insight.first_detected_at && <span>First detected: {insight.first_detected_at} UTC</span>}
                            </div>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    )
}

