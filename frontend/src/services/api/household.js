import { apiClient } from './client'

/**
 * Household API service (W3 / O3 — docs/10 D-050, D-065, D-071).
 *
 * Interacts with backend household endpoints via the centralized apiClient:
 * - GET /api/v1/household: Caller's own active household + derived onboarding readiness.
 * - POST /api/v1/household: V1 onboarding primitive (creates household + OWNER membership).
 * - POST /api/v1/household/demo/ensure: Backend-authoritative DEMO household provisioning in spms_demo.
 * - PATCH /api/v1/household: Modifies current profile fields (timezone, label, location_label, service_number).
 */
export const householdApi = {
    /**
     * Get the authenticated caller's own household context.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ authenticated: boolean, account: object, household: object|null, membership: object|null, readiness: object }>}
     */
    async getHousehold(signal = undefined) {
        const { data } = await apiClient.get('/household', { signal })
        return data
    },

    /**
     * Create the caller's household and OWNER membership (v1 onboarding primitive).
     * @param {{ label?: string, timezone: string, location_label?: string, service_number?: string }} payload
     * @returns {Promise<{ household: object, membership: { role: string } }>}
     */
    async createHousehold({ label, timezone, location_label, service_number }) {
        const payload = { timezone }
        if (typeof label === 'string' && label.trim() !== '') {
            payload.label = label.trim()
        }
        if (typeof location_label === 'string' && location_label.trim() !== '') {
            payload.location_label = location_label.trim()
        }
        if (typeof service_number === 'string' && service_number.trim() !== '') {
            payload.service_number = service_number.trim()
        }

        const { data } = await apiClient.post('/household', payload)
        return data
    },

    /**
     * Backend-authoritative ensure/provision of the prepared demo household (DEMO only).
     * @returns {Promise<{ authenticated: boolean, account: object, household: object, membership: object, readiness: object }>}
     */
    async ensureDemoHousehold() {
        const { data } = await apiClient.post('/household/demo/ensure')
        return data
    },

    /**
     * Update the caller's active household profile.
     * @param {{ label?: string, timezone?: string, location_label?: string, service_number?: string }} changes
     * @returns {Promise<{ household: object, membership: { role: string } }>}
     */
    async updateHousehold(changes) {
        const { data } = await apiClient.patch('/household', changes)
        return data
    },
}
