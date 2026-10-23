export const REASON_LABELS = {
    NOT_RELEVANT: 'Not relevant to me',
    WRONG_TIMING: 'Wrong timing',
    COULD_NOT_FOLLOW: "I couldn't follow it",
    ALREADY_DOING: 'I was already doing this',
    OTHER: 'Other',
}

export function formatFeedbackReason(reason) {
    if (!reason) return ''
    return REASON_LABELS[reason] || reason
}

/**
 * Format consumer-facing copy directly from canonical backend recommendation fields.
 * No brittle string matching, regex parsing, or heuristic prose rewrites.
 */
export function formatConsumerCopy(recommendation) {
    if (!recommendation) {
        return { displayTitle: '', displayAction: '' }
    }

    return {
        displayTitle: recommendation.title || '',
        displayAction: recommendation.action || '',
    }
}
