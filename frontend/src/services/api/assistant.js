import { apiClient } from './client'

/**
 * SPMS AI Energy Assistant API Client (F8).
 *
 * Backend is authoritative for all data, forecasts, bills, insights, and recommendations.
 * Keys and LLM calls remain strictly server-side.
 */

/**
 * Send a question to the SPMS Energy Assistant.
 *
 * @param {Object} params
 * @param {string} params.message - User question
 * @param {string} [params.pageContext] - Optional active page context (e.g. 'forecasts', 'bills')
 * @param {Array<{role: string, content: string}>} [params.history] - Optional client conversation turns
 * @param {AbortSignal} [params.signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function askAssistant({ message, pageContext, history = [], signal } = {}) {
    return apiClient.post(
        '/assistant',
        {
            message,
            page_context: pageContext,
            history,
        },
        { signal }
    )
}

/**
 * Fetch recent conversation history for the active household.
 *
 * @param {Object} [options]
 * @param {number} [options.limit=20]
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: {messages: Array<Object>, count: number}, meta: Object, requestId: string|null, status: number}>}
 */
export function getAssistantHistory({ limit = 20, signal } = {}) {
    return apiClient.get(`/assistant?limit=${encodeURIComponent(limit)}`, { signal })
}

/**
 * Clear assistant conversation history for the active household.
 *
 * @param {Object} [options]
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<{data: {cleared: boolean}, meta: Object, requestId: string|null, status: number}>}
 */
export function clearAssistantHistory({ signal } = {}) {
    return apiClient.delete('/assistant', { signal })
}
