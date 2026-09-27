import { apiClient } from './client'

/**
 * SPMS F9 — Notification Center API Client.
 */

/**
 * Fetch paginated notifications for the active household.
 *
 * @param {object} [params]
 * @param {number} [params.limit=50]
 * @param {number} [params.offset=0]
 * @param {boolean} [params.unread_only]
 * @param {AbortSignal} [params.signal]
 * @returns {Promise<{notifications: Array<object>, unread_count: number, total_count: number}>}
 */
export async function listNotifications({ limit = 50, offset = 0, unread_only = null, signal } = {}) {
    const query = new URLSearchParams()
    if (limit) query.set('limit', String(limit))
    if (offset) query.set('offset', String(offset))
    if (unread_only !== null && unread_only !== undefined) {
        query.set('unread_only', unread_only ? '1' : '0')
    }

    const qs = query.toString()
    const endpoint = `/notifications${qs ? `?${qs}` : ''}`
    const { data } = await apiClient.get(endpoint, { signal })
    return data
}

/**
 * Mark a single notification as read.
 *
 * @param {string} id
 * @returns {Promise<{status: string, id: string, is_read: boolean}>}
 */
export async function markNotificationRead(id) {
    const { data } = await apiClient.post(`/notifications/${id}/read`)
    return data
}

/**
 * Mark all notifications as read for the active household.
 *
 * @returns {Promise<{status: string, marked_count: number}>}
 */
export async function markAllNotificationsRead() {
    const { data } = await apiClient.post('/notifications/read-all')
    return data
}

export const notificationsApi = {
    list: listNotifications,
    markRead: markNotificationRead,
    markAllRead: markAllNotificationsRead,
}
