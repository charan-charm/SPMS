import { apiClient, setCsrfToken } from './client'

/**
 * SPMS D1 — Demo Scenario Discovery & Switching API service.
 *
 * Provides safe examiner controls to switch between preseeded demonstration scenarios
 * without exposing credentials or permitting arbitrary account impersonation.
 */
export const scenariosApi = {
    /**
     * List available demo scenarios and current active scenario metadata.
     * @param {AbortSignal} [signal]
     * @returns {Promise<{ scenarios: Array<{ id: string, name: string, description: string, narrative: string, is_active: boolean }>, active_scenario: object|null }>}
     */
    async list(signal = undefined) {
        const { data } = await apiClient.get('/demo/scenarios', { signal })
        return data
    },

    /**
     * Switch authenticated session to another allowed demo scenario.
     * @param {string} scenarioId ('SCEN-01' | 'SCEN-02' | 'SCEN-03' | 'SCEN-04' | 'SCEN-05')
     * @returns {Promise<{ switched: boolean, active_scenario: object, account: object, household: object, session: { csrf_token: string } }>}
     */
    async switchScenario(scenarioId) {
        const { data } = await apiClient.post('/demo/scenarios/switch', { scenario_id: scenarioId })
        if (data?.session?.csrf_token) {
            setCsrfToken(data.session.csrf_token)
        }
        return data
    },
}
