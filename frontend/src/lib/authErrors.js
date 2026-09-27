import { ApiError } from '../services/api/client'

/**
 * Format retry duration in seconds into a friendly human-readable phrase.
 * @param {number|null} seconds
 * @returns {string}
 */
export function formatRetryTime(seconds) {
    if (!seconds || seconds <= 0) {
        return 'a few minutes'
    }
    if (seconds < 60) {
        return `${seconds} second${seconds === 1 ? '' : 's'}`
    }
    const minutes = Math.ceil(seconds / 60)
    return `${minutes} minute${minutes === 1 ? '' : 's'}`
}

/**
 * Parse an API or network error into safe UI-presentable field and general errors.
 *
 * Rules:
 * - Server diagnostics, SQL exceptions, or stack traces are never exposed.
 * - Field-specific validation failures map to context.field.
 * - Uniform failure semantics (INVALID_CREDENTIALS) never disclose account existence.
 * - Rate limiting shows retry information without indefinite blocking.
 *
 * @param {unknown} err
 * @returns {{
 *   fieldErrors: Record<string, string>,
 *   generalError: string | null,
 *   isRateLimited: boolean,
 *   retryAfterSeconds: number | null,
 *   code: string | null
 * }}
 */
export function parseAuthError(err) {
    const result = {
        fieldErrors: {},
        generalError: null,
        isRateLimited: false,
        retryAfterSeconds: null,
        code: null,
    }

    if (!err) {
        return result
    }

    if (!(err instanceof ApiError)) {
        result.generalError = err instanceof Error && err.message
            ? 'A network or unexpected error occurred. Please try again.'
            : 'An unexpected error occurred. Please try again.'
        return result
    }

    result.code = err.code

    switch (err.code) {
        case 'INVALID_CREDENTIALS':
            result.generalError = 'Invalid email or password.'
            break

        case 'VALIDATION_ERROR': {
            const field = err.context?.field
            const message = err.message || 'Please check the information provided.'
            if (field && typeof field === 'string') {
                result.fieldErrors[field] = message
            } else {
                result.generalError = message
            }
            break
        }

        case 'RATE_LIMITED': {
            result.isRateLimited = true
            const seconds = typeof err.context?.retry_after_seconds === 'number'
                ? err.context.retry_after_seconds
                : null
            result.retryAfterSeconds = seconds
            result.generalError = `Too many attempts. Please try again in ${formatRetryTime(seconds)}.`
            break
        }

        case 'CSRF_REJECTED':
            result.generalError = 'Your session security token is no longer valid. Please sign in again.'
            break

        case 'UNAUTHENTICATED':
            result.generalError = 'Your session has expired. Please sign in again.'
            break

        case 'NETWORK_ERROR':
            result.generalError = 'The service could not be reached. Check your connection and try again.'
            break

        case 'TIMEOUT':
            result.generalError = 'The request took too long. Please try again.'
            break

        case 'MALFORMED_BODY':
            result.generalError = 'Invalid request format. Please try again.'
            break

        case 'INTERNAL_ERROR':
        default:
            result.generalError = 'Something went wrong on our side. Please try again later.'
            break
    }

    return result
}
