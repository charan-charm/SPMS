import { apiClient } from './client'

/**
 * SPMS Forecasting API module (F3 — docs/02 §2, docs/06 §3, docs/10 D-040).
 *
 * Backend is authoritative for all forecast computations, confidence levels,
 * uncertainty bounds, and limitations.
 */

/**
 * Fetch forecast for the current household.
 *
 * @param {Object} [options]
 * @param {'DAY_AHEAD'|'SEVEN_DAY'|'MONTHLY'} [options.horizon='DAY_AHEAD']
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getForecasts({ horizon = 'DAY_AHEAD', signal } = {}) {
    return apiClient.get(`/forecasts?horizon=${encodeURIComponent(horizon)}`, { signal })
}

/**
 * Trigger on-demand forecast recalculation.
 *
 * @param {Object} [options]
 * @param {'DAY_AHEAD'|'SEVEN_DAY'|'MONTHLY'} [options.horizon='DAY_AHEAD']
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function generateForecast({ horizon = 'DAY_AHEAD', signal } = {}) {
    return apiClient.post('/forecasts/generate', { horizon }, { signal })
}

/**
 * Fetch predicted vs actual solar validation series and metrics.
 *
 * @param {Object} [options]
 * @param {string} [options.forecastId]
 * @param {string} [options.date] Target date (YYYY-MM-DD)
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getForecastValidation({ forecastId, date, signal } = {}) {
    const params = new URLSearchParams()
    if (forecastId) params.append('forecast_id', forecastId)
    if (date) params.append('date', date)
    const qs = params.toString() ? `?${params.toString()}` : ''
    return apiClient.get(`/forecasts/validation${qs}`, { signal })
}

/**
 * Fetch historical validation performance summary.
 *
 * @param {Object} [options]
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getForecastPerformance({ signal } = {}) {
    return apiClient.get('/forecasts/performance', { signal })
}

