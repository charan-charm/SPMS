import { ApiError, apiClient, setCsrfToken } from './client'

/**
 * Auth session bootstrap (B4 — read-only consumption of the existing
 * W1 endpoint, docs/10 D-052/D-049).
 *
 * GET /api/v1/auth/session is the ratified safe identity read: for an
 * authenticated browser session it returns the account plus the CSRF
 * token every subsequent mutation needs, and the token is handed to the
 * centralized client (in-memory only, never browser storage). An
 * anonymous caller gets the standard 401, which resolves here to an
 * honest signed-out result — this module adds no login UI and makes no
 * authorization decisions; the backend remains the sole authority.
 */
export async function loadServerSession(signal = undefined) {
    try {
        const { data } = await apiClient.get('/auth/session', { signal })
        const csrf = data?.session?.csrf_token ?? null
        if (typeof csrf === 'string' && csrf !== '') {
            setCsrfToken(csrf)
        }

        return { authenticated: true, account: data?.account ?? null }
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            return { authenticated: false, account: null }
        }
        throw error
    }
}
