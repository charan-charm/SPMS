import { apiClient } from './client'

/**
 * Solar Integration API Client (SPMS O4 — docs/10 D-059, D-060, D-061).
 *
 * Exposes methods to interact with GoodWe SEMS+ inverter integration endpoints:
 * - GET    /api/v1/solar/connection
 * - POST   /api/v1/solar/connection/test
 * - POST   /api/v1/solar/connection
 * - DELETE /api/v1/solar/connection
 * - GET    /api/v1/solar/latest
 * - GET    /api/v1/solar/history
 * - POST   /api/v1/solar/sync
 */
export const solarApi = {
    /**
     * Fetch connection state for the active household.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ connection: object }>}
     */
    async getConnection(signal = undefined) {
        const { data } = await apiClient.get('/solar/connection', { signal })
        return data
    },

    /**
     * Discover power stations associated with external solar credentials.
     * @param {{ account: string, password: string }} credentials
     * @returns {Promise<{ stations: Array<{ station_id: string, station_name: string, capacity_kw: number|null }> }>}
     */
    async discoverStations(credentials) {
        const { data } = await apiClient.post('/solar/stations/discover', credentials)
        return data
    },

    /**
     * Test credentials against external solar provider without saving.
     * @param {{ account: string, password: string, station_id?: string, inverter_sn?: string }} credentials
     * @returns {Promise<{ test_result: object }>}
     */
    async testConnection(credentials) {
        const { data } = await apiClient.post('/solar/connection/test', credentials, { timeoutMs: 30000 })
        return data
    },

    /**
     * Save solar connection with encrypted credentials.
     * @param {{ account: string, password: string, station_id?: string, inverter_sn?: string }} credentials
     * @returns {Promise<{ connection: object, test_result: object }>}
     */
    async saveConnection(credentials) {
        const { data } = await apiClient.post('/solar/connection', credentials, { timeoutMs: 30000 })
        return data
    },

    /**
     * Disconnect and remove solar integration for active household.
     * @returns {Promise<{ disconnected: boolean }>}
     */
    async disconnect() {
        const { data } = await apiClient.delete('/solar/connection')
        return data
    },

    /**
     * Fetch the most recent 5-minute telemetry point.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ latest: object|null }>}
     */
    async getLatest(signal = undefined) {
        const { data } = await apiClient.get('/solar/latest', { signal })
        return data
    },

    /**
     * Fetch telemetry history for charts and tables.
     * @param {{ from?: string, to?: string, limit?: number, date?: string }} [params]
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ history: Array<object>, count: number }>}
     */
    async getHistory(params = {}, signal = undefined) {
        const query = new URLSearchParams()
        if (params.date) query.set('date', params.date)
        if (params.from) query.set('from', params.from)
        if (params.to) query.set('to', params.to)
        if (params.limit) query.set('limit', String(params.limit))

        const qs = query.toString()
        const url = qs ? `/solar/history?${qs}` : '/solar/history'
        const { data } = await apiClient.get(url, { signal })
        return data
    },

    /**
     * Fetch real daily GoodWe telemetry summaries for date selection and history table.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ days: Array<object>, count: number }>}
     */
    async getDays(signal = undefined) {
        const { data } = await apiClient.get('/solar/days', { signal })
        return data
    },

    /**
     * Trigger manual telemetry ingestion synchronization.
     * @returns {Promise<{ synced: boolean, snapshot: object|null, latest: object|null }>}
     */
    async syncTelemetry() {
        const { data } = await apiClient.post('/solar/sync')
        return data
    },

    /**
     * Trigger opportunistic background visit synchronization.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ synced: boolean, status: string, snapshot?: object|null, latest?: object|null }>}
     */
    async autoSyncTelemetry(signal = undefined) {
        const { data } = await apiClient.post('/solar/sync/auto', {}, { signal })
        return data
    },
}
