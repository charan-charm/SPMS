import { apiClient } from './client'

/**
 * SPMS Recommendations API module (F7 — docs/01 §15, docs/02 §2 F7, docs/06 §3, docs/10 D-072).
 *
 * All recommendation eligibility, confidence, evidence, deduplication, and persistence
 * rules belong to the backend. The frontend consumes authoritative outputs only.
 */

/**
 * List recommendations for the current household.
 *
 * @param {Object} [filters]
 * @param {'ACTIVE'|'DISMISSED'|'EXPIRED'|'ALL'} [filters.status='ACTIVE']
 * @param {string} [filters.type]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getRecommendations(filters = {}, signal = undefined) {
    const query = {}

    if (filters.status) query.status = filters.status
    if (filters.type) query.type = filters.type

    return apiClient.get('/recommendations', {
        query: Object.keys(query).length > 0 ? query : undefined,
        signal,
    })
}

/**
 * Fetch a single recommendation by ID.
 *
 * @param {string} id
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getRecommendation(id, signal = undefined) {
    return apiClient.get(`/recommendations/${encodeURIComponent(id)}`, { signal })
}

/**
 * Trigger on-demand recommendation generation / refresh.
 *
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function generateRecommendations(signal = undefined) {
    return apiClient.post('/recommendations/generate', {}, { signal })
}

/**
 * Dismiss an active recommendation.
 *
 * @param {string} id
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function dismissRecommendation(id, signal = undefined) {
    return apiClient.post(`/recommendations/${encodeURIComponent(id)}/dismiss`, {}, { signal })
}
