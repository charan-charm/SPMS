import { apiClient } from './client'

/**
 * SPMS Intelligence API module (F6 — docs/02 §2, docs/10 D-047).
 *
 * Thin endpoint definitions over the central API client. The backend is
 * the sole authority for insight detection, significance, confidence and
 * lifecycle — this module only reads persisted insights.
 */

/**
 * List persisted insights with optional validated filters.
 *
 * @param {Object} [filters]
 * @param {string} [filters.householdId]
 * @param {string} [filters.category] SOLAR | CONSUMPTION | ENERGY | COST | GENERAL
 * @param {string} [filters.type] controlled taxonomy type
 * @param {string} [filters.significance] LOW | MODERATE | HIGH
 * @param {string} [filters.confidence] HIGH | MODERATE | LOW | VERY_LOW | INSUFFICIENT
 * @param {string} [filters.status] lifecycle state (ONGOING is accepted as an alias)
 * @param {string} [filters.periodStart] ISO date (Y-m-d)
 * @param {string} [filters.periodEnd] ISO date (Y-m-d)
 * @param {number} [filters.limit]
 * @param {number} [filters.offset]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getInsights(filters = {}, signal = undefined) {
    const query = {}

    if (filters.householdId) query.household_id = filters.householdId
    if (filters.category) query.category = filters.category
    if (filters.type) query.type = filters.type
    if (filters.significance) query.significance = filters.significance
    if (filters.confidence) query.confidence = filters.confidence
    if (filters.status) query.status = filters.status
    if (filters.periodStart) query.period_start = filters.periodStart
    if (filters.periodEnd) query.period_end = filters.periodEnd
    if (filters.limit !== undefined) query.limit = String(filters.limit)
    if (filters.offset !== undefined) query.offset = String(filters.offset)

    return apiClient.get('/intelligence/insights', {
        query: Object.keys(query).length > 0 ? query : undefined,
        signal,
    })
}

/**
 * Fetch a single persisted insight by id.
 *
 * @param {string} id
 * @param {string} [householdId] scope the lookup to a household
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getInsight(id, householdId = undefined, signal = undefined) {
    return apiClient.get(`/intelligence/insights/${encodeURIComponent(id)}`, {
        query: householdId ? { household_id: householdId } : undefined,
        signal,
    })
}
