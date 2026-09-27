import { apiClient } from './client'

/**
 * SPMS F10 — Analytics Workspace API Client.
 *
 * Provides historical analytics aggregated from actual GoodWe solar readings
 * and verified APSPDCL bill data. All returned values carry explicit
 * data_nature labels (ACTUAL | VERIFIED | EXTRACTED | UNAVAILABLE).
 * Grid import/export is never fabricated.
 */

/**
 * Fetch monthly analytics aggregation for the active household.
 *
 * @param {object} [params]
 * @param {string} [params.from_month]  - YYYY-MM, inclusive lower bound
 * @param {string} [params.to_month]    - YYYY-MM, inclusive upper bound
 * @param {AbortSignal} [params.signal]
 * @returns {Promise<{
 *   range: { from_month: string, to_month: string },
 *   points: Array<{
 *     month: string,
 *     solar_kwh: number|null,
 *     solar_nature: string,
 *     solar_days: number,
 *     consumption_kwh: number|null,
 *     bill_nature: string,
 *     bill_count: number,
 *     solar_units_kwh: number|null,
 *     bill_amount: number|null,
 *     net_kwh: number|null,
 *     net_nature: string,
 *   }>,
 *   summary: {
 *     total_months: number,
 *     solar_months_with_data: number,
 *     bill_months_with_data: number,
 *     overlap_months: number,
 *     total_solar_kwh: number|null,
 *     total_consumption_kwh: number|null,
 *   },
 *   data_notes: { solar: string, consumption: string, net: string },
 * }>}
 */
export async function getMonthlyAnalytics({ from_month, to_month, signal } = {}) {
    const query = new URLSearchParams()
    if (from_month) query.set('from_month', from_month)
    if (to_month)   query.set('to_month',   to_month)

    const qs  = query.toString()
    const url = `/analytics/monthly${qs ? `?${qs}` : ''}`
    const { data } = await apiClient.get(url, { signal })
    return data
}

/**
 * Fetch data availability summary for the active household.
 *
 * @param {AbortSignal} [signal]
 * @returns {Promise<{
 *   solar: { reading_count: number, reading_days: number, first_month: string|null, last_month: string|null },
 *   bills: { bill_count: number, first_month: string|null, last_month: string|null },
 *   has_solar_data: boolean,
 *   has_bill_data: boolean,
 * }>}
 */
export async function getAnalyticsSummary(signal) {
    const { data } = await apiClient.get('/analytics/summary', { signal })
    return data
}

export const analyticsApi = {
    getMonthly: getMonthlyAnalytics,
    getSummary: getAnalyticsSummary,
}
