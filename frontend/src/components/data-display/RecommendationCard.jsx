import { useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { Card } from '../ui/card'
import { DataNatureBadge } from '../data-display/DataNatureBadge'
import { ConfidenceIndicator } from '../data-display/ConfidenceIndicator'
import { Button } from '../ui/button'
import { ChevronDown, ThumbsDown, ThumbsUp } from 'lucide-react'
import { cn } from '../../lib/utils'
import { DURATION, EASE } from '../../lib/motion'

/**
 * RecommendationCard (docs/12 §19) — presents one backend-provided
 * recommendation. Advisory only: SPMS suggests, it never controls
 * appliances. All content arrives via props; the component invents
 * nothing.
 *
 * Props:
 * - category, title, explanation: backend content
 * - impact: e.g. HIGH | MEDIUM | LOW | INFORMATIONAL (backend value)
 * - confidence: CONFIDENCE_LEVEL key
 * - timing: optional backend-provided timing context
 * - why: optional object { facts: [{label, value}], explanation }
 * - onFeedback: optional callback (recommendationId, value)
 */
const IMPACT_LABEL = {
    HIGH: 'High impact',
    MEDIUM: 'Medium impact',
    LOW: 'Low impact',
    INFORMATIONAL: 'Informational',
}

const IMPACT_CLASS = {
    HIGH: 'border-primary/40 bg-primary-soft text-primary-active',
    MEDIUM: 'border-warning/40 bg-warning/10 text-warning',
    LOW: 'border-muted/40 bg-surface-alt text-muted',
    INFORMATIONAL: 'border-info/40 bg-info/10 text-info',
}

export function RecommendationCard({
    category = null,
    title,
    explanation,
    impact = null,
    confidence = null,
    timing = null,
    why = null,
    onFeedback = null,
    className,
}) {
    const [whyOpen, setWhyOpen] = useState(false)

    return (
        <Card className={cn('flex flex-col gap-3', className)}>
            {(category || impact) && (
                <div className="flex flex-wrap items-center gap-2">
                    {category && (
                        <span className="text-xs font-semibold uppercase tracking-wide text-secondary">
                            {category}
                        </span>
                    )}
                    {impact && IMPACT_LABEL[impact] && (
                        <span
                            className={cn(
                                'rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                IMPACT_CLASS[impact],
                            )}
                        >
                            {IMPACT_LABEL[impact]}
                        </span>
                    )}
                </div>
            )}

            <div>
                <h4 className="font-display text-base font-semibold text-heading">{title}</h4>
                <p className="mt-1 text-sm text-body">{explanation}</p>
            </div>

            {(timing || confidence) && (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    {timing && <p className="text-xs text-muted">Timing: {timing}</p>}
                    {confidence && <ConfidenceIndicator level={confidence} />}
                </div>
            )}

            {why && (
                <div className="rounded-md border border-border bg-surface-alt px-3 py-2">
                    <button
                        type="button"
                        onClick={() => setWhyOpen((prev) => !prev)}
                        aria-expanded={whyOpen}
                        aria-controls="why-recommendation-details"
                        className="flex w-full cursor-pointer items-center justify-between text-left text-sm font-medium text-heading focus:outline-none"
                    >
                        <span>Why this recommendation?</span>
                        <ChevronDown
                            className={cn(
                                'size-4 text-muted transition-transform duration-200',
                                whyOpen && 'rotate-180',
                            )}
                            aria-hidden="true"
                        />
                    </button>
                    <AnimatePresence initial={false}>
                        {whyOpen && (
                            <motion.div
                                id="why-recommendation-details"
                                initial={{ opacity: 0, height: 0 }}
                                animate={{ opacity: 1, height: 'auto' }}
                                exit={{ opacity: 0, height: 0 }}
                                transition={{
                                    duration: DURATION.expand / 1000,
                                    ease: EASE.standard,
                                }}
                                className="overflow-hidden"
                            >
                                <dl className="mt-3 space-y-2 border-t border-border/60 pt-2.5 text-xs">
                                    {why.facts?.map((fact) => (
                                        <div key={fact.label} className="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
                                            <dt className="font-medium text-muted sm:w-40 sm:shrink-0">{fact.label}</dt>
                                            <dd className="text-body">{fact.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                {why.explanation && <p className="mt-3 text-xs text-body">{why.explanation}</p>}
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            )}

            {onFeedback && (
                <div className="flex items-center gap-2 border-t border-border pt-3">
                    <Button
                        variant="tertiary"
                        size="sm"
                        aria-label="Mark as useful"
                        onClick={() => onFeedback('useful')}
                    >
                        <ThumbsUp className="size-3.5" aria-hidden="true" />
                        Useful
                    </Button>
                    <Button
                        variant="tertiary"
                        size="sm"
                        aria-label="Mark as not useful"
                        onClick={() => onFeedback('not_useful')}
                    >
                        <ThumbsDown className="size-3.5" aria-hidden="true" />
                        Not useful
                    </Button>
                </div>
            )}

            {/* DataNatureBadge import is retained for consumers that pass an
                explicit nature for the recommendation's underlying evidence. */}
            {why?.nature && <DataNatureBadge nature={why.nature} className="self-start" />}
        </Card>
    )
}
