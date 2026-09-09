/**
 * Centralized API client configuration (F1 foundation).
 *
 * Only public, non-secret frontend configuration lives here. The API
 * base path points at the versioned PHP REST boundary (/api/v1) per
 * docs/06. Endpoint inventory is established in later milestones —
 * this module invents no endpoints.
 */

const rawBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api/v1'

export const API_BASE_URL = rawBaseUrl.replace(/\/+$/, '')

export const DEFAULT_REQUEST_TIMEOUT_MS = 15000

export function buildApiUrl(path, query) {
    let url = API_BASE_URL + path

    if (query && Object.keys(query).length > 0) {
        const params = new URLSearchParams()
        for (const [key, value] of Object.entries(query)) {
            if (value !== undefined && value !== null) {
                params.append(key, String(value))
            }
        }
        const queryString = params.toString()
        if (queryString !== '') {
            url += `?${queryString}`
        }
    }

    return url
}
