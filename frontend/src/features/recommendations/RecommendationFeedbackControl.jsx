import { useState } from 'react'
import { ThumbsUp, ThumbsDown, Check, X, Loader2 } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { submitRecommendationFeedback } from '../../services/api/recommendations'
import { formatFeedbackReason, REASON_LABELS } from './recommendationFormatting'
import { cn } from '../../lib/utils'

export function RecommendationFeedbackControl({
    recommendationId,
    initialFeedback = null,
    isHistorical = false,
    onFeedbackSubmitted,
    className,
}) {
    const [feedback, setFeedback] = useState(initialFeedback)
    const [prevInitial, setPrevInitial] = useState(initialFeedback)
    const [step, setStep] = useState(initialFeedback ? (initialFeedback.usefulness === 'USEFUL' && !initialFeedback.followed_status ? 'follow_up' : 'done') : 'initial')
    const [selectedReason, setSelectedReason] = useState(initialFeedback?.reason || '')
    const [comment, setComment] = useState(initialFeedback?.optional_comment || '')
    const [isSaving, setIsSaving] = useState(false)
    const [saveError, setSaveError] = useState(null)

    if (initialFeedback !== prevInitial) {
        setPrevInitial(initialFeedback)
        setFeedback(initialFeedback)
        if (initialFeedback) {
            setSelectedReason(initialFeedback.reason || '')
            setComment(initialFeedback.optional_comment || '')
        }
    }

    // If historical, show read-only status without prompts
    if (isHistorical) {
        if (!feedback) {
            return (
                <div className={cn('text-xs text-muted/70 italic flex items-center gap-1', className)} data-testid="feedback-history-state">
                    <span>— No feedback</span>
                </div>
            )
        }

        if (feedback.usefulness === 'USEFUL') {
            return (
                <div className={cn('flex flex-wrap items-center gap-2 text-xs font-medium', className)} data-testid="feedback-history-state">
                    <span className="flex items-center gap-1 text-success">
                        <Check className="size-3.5 shrink-0" aria-hidden="true" />
                        <span>Useful</span>
                    </span>
                    {feedback.followed_status === 'YES' && (
                        <span className="flex items-center gap-1 text-success">
                            <Check className="size-3.5 shrink-0" aria-hidden="true" />
                            <span>Followed</span>
                        </span>
                    )}
                    {feedback.followed_status === 'PARTLY' && (
                        <span className="text-muted text-[11px]">
                            (Partly followed)
                        </span>
                    )}
                </div>
            )
        }

        return (
            <div className={cn('flex flex-wrap items-center gap-1 text-xs font-medium', className)} data-testid="feedback-history-state">
                <span className="flex items-center gap-1 text-danger">
                    <X className="size-3.5 shrink-0" aria-hidden="true" />
                    <span>Not useful</span>
                </span>
                {feedback.reason && (
                    <span className="text-muted text-[11px]">
                        — {formatFeedbackReason(feedback.reason)}
                    </span>
                )}
            </div>
        )
    }

    const handleUsefulness = async (usefulness) => {
        setIsSaving(true)
        setSaveError(null)
        try {
            const res = await submitRecommendationFeedback(recommendationId, { usefulness })
            const saved = res?.data?.feedback || {
                recommendation_id: recommendationId,
                usefulness: usefulness === 'YES' ? 'USEFUL' : 'NOT_USEFUL',
            }
            setFeedback(saved)
            if (usefulness === 'YES') {
                setStep('follow_up')
            } else {
                setStep('negative_reason')
            }
            onFeedbackSubmitted?.(saved)
        } catch {
            setSaveError('Unable to save feedback. Please try again.')
        } finally {
            setIsSaving(false)
        }
    }

    const handleFollowedStatus = async (followed_status) => {
        setIsSaving(true)
        setSaveError(null)
        try {
            const res = await submitRecommendationFeedback(recommendationId, {
                usefulness: 'YES',
                followed_status,
            })
            const saved = res?.data?.feedback || {
                ...feedback,
                usefulness: 'USEFUL',
                followed_status,
            }
            setFeedback(saved)
            setStep('done')
            onFeedbackSubmitted?.(saved)
        } catch {
            setSaveError('Unable to save response.')
        } finally {
            setIsSaving(false)
        }
    }

    const handleNegativeReasonSubmit = async (e) => {
        e?.preventDefault?.()
        setIsSaving(true)
        setSaveError(null)
        try {
            const res = await submitRecommendationFeedback(recommendationId, {
                usefulness: 'NO',
                reason: selectedReason || 'OTHER',
                optional_comment: comment.trim() || undefined,
            })
            const saved = res?.data?.feedback || {
                ...feedback,
                usefulness: 'NOT_USEFUL',
                reason: selectedReason,
                optional_comment: comment.trim(),
            }
            setFeedback(saved)
            setStep('done')
            onFeedbackSubmitted?.(saved)
        } catch {
            setSaveError('Unable to save feedback.')
        } finally {
            setIsSaving(false)
        }
    }

    return (
        <div className={cn('pt-3 border-t border-border/70 text-xs', className)} data-testid="recommendation-feedback">
            {saveError && (
                <p className="text-[11px] text-danger mb-2 font-medium" role="alert">
                    {saveError}
                </p>
            )}

            {step === 'initial' && (
                <div className="flex flex-wrap items-center justify-between gap-2.5">
                    <span className="font-medium text-heading text-xs">
                        Was this recommendation useful?
                    </span>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={isSaving}
                            onClick={() => handleUsefulness('YES')}
                            aria-label="Mark recommendation as useful"
                            className="h-7 px-2.5 text-xs text-heading hover:text-success hover:border-success/40"
                        >
                            {isSaving ? (
                                <Loader2 className="size-3 animate-spin mr-1" aria-hidden="true" />
                            ) : (
                                <ThumbsUp className="size-3 mr-1 text-success" aria-hidden="true" />
                            )}
                            <span>Yes</span>
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={isSaving}
                            onClick={() => handleUsefulness('NO')}
                            aria-label="Mark recommendation as not useful"
                            className="h-7 px-2.5 text-xs text-heading hover:text-danger hover:border-danger/40"
                        >
                            {isSaving ? (
                                <Loader2 className="size-3 animate-spin mr-1" aria-hidden="true" />
                            ) : (
                                <ThumbsDown className="size-3 mr-1 text-danger" aria-hidden="true" />
                            )}
                            <span>Not really</span>
                        </Button>
                    </div>
                </div>
            )}

            {step === 'follow_up' && (
                <div className="rounded-lg bg-surface-alt/70 border border-success/30 p-3 space-y-2.5 animate-fadeIn">
                    <div className="flex items-center gap-1.5 text-success font-medium">
                        <Check className="size-3.5 shrink-0" aria-hidden="true" />
                        <span>Thanks. Your feedback helps improve recommendations.</span>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-border/40">
                        <span className="text-muted text-[11px] font-medium">
                            Did you follow it?
                        </span>
                        <div className="flex items-center gap-1.5">
                            <Button
                                variant="tertiary"
                                size="sm"
                                disabled={isSaving}
                                onClick={() => handleFollowedStatus('YES')}
                                className="h-6 px-2 text-[11px] border border-border/80 bg-surface text-heading hover:border-success"
                            >
                                Yes
                            </Button>
                            <Button
                                variant="tertiary"
                                size="sm"
                                disabled={isSaving}
                                onClick={() => handleFollowedStatus('PARTLY')}
                                className="h-6 px-2 text-[11px] border border-border/80 bg-surface text-heading hover:border-primary"
                            >
                                Partly
                            </Button>
                            <Button
                                variant="tertiary"
                                size="sm"
                                disabled={isSaving}
                                onClick={() => handleFollowedStatus('NOT_YET')}
                                className="h-6 px-2 text-[11px] border border-border/80 bg-surface text-heading hover:border-muted"
                            >
                                Not yet
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {step === 'negative_reason' && (
                <form onSubmit={handleNegativeReasonSubmit} className="rounded-lg bg-surface-alt/70 border border-border p-3 space-y-2.5 animate-fadeIn">
                    <p className="font-semibold text-heading text-[11px] uppercase tracking-wider">
                        What could be better?
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        {Object.entries(REASON_LABELS).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setSelectedReason(key)}
                                className={cn(
                                    'text-left px-2.5 py-1.5 rounded border text-[11px] transition-colors',
                                    selectedReason === key
                                        ? 'border-primary bg-primary-soft text-primary font-medium'
                                        : 'border-border/60 bg-surface text-body hover:border-border'
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="pt-1">
                        <input
                            type="text"
                            maxLength={500}
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder="Optional short note (e.g. was away from home)"
                            className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-heading placeholder:text-muted/60 focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                    </div>
                    <div className="flex items-center justify-end gap-2 pt-1">
                        <Button
                            type="button"
                            variant="tertiary"
                            size="sm"
                            onClick={() => setStep('done')}
                            className="h-6 px-2 text-[11px] text-muted"
                        >
                            Skip
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={isSaving}
                            className="h-6 px-2.5 text-[11px] font-medium"
                        >
                            {isSaving ? 'Saving...' : 'Submit Feedback'}
                        </Button>
                    </div>
                </form>
            )}

            {step === 'done' && (
                <div className="flex flex-wrap items-center justify-between gap-2 text-muted">
                    <div className="flex items-center gap-1.5">
                        {feedback?.usefulness === 'USEFUL' ? (
                            <span className="flex items-center gap-1 text-success font-medium">
                                <Check className="size-3.5 shrink-0" aria-hidden="true" />
                                <span>Marked useful</span>
                            </span>
                        ) : (
                            <span className="flex items-center gap-1 text-body font-medium">
                                <Check className="size-3.5 shrink-0 text-muted" aria-hidden="true" />
                                <span>Feedback recorded</span>
                            </span>
                        )}
                        {feedback?.followed_status && (
                            <span className="rounded bg-surface-alt px-1.5 py-0.5 text-[11px]">
                                {feedback.followed_status === 'YES'
                                    ? 'Followed'
                                    : feedback.followed_status === 'PARTLY'
                                    ? 'Partly followed'
                                    : 'Not yet followed'}
                            </span>
                        )}
                        {feedback?.reason && (
                            <span className="text-[11px] text-muted">
                                ({formatFeedbackReason(feedback.reason)})
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setStep('initial')}
                        className="text-[11px] text-muted hover:text-heading underline cursor-pointer"
                    >
                        Change
                    </button>
                </div>
            )}
        </div>
    )
}
