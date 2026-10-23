import { apiClient } from './client'

/**
 * Weather API service.
 *
 * Calls the household-scoped backend weather endpoint:
 * GET /api/v1/weather
 *
 * 100% server-side mediated: zero direct browser-to-Open-Meteo external requests.
 */
export async function getWeather() {
    return apiClient.get('/api/v1/weather')
}
