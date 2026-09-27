import { apiClient, setCsrfToken } from './client'

/**
 * Authentication API service (W1 — docs/02 §W1, docs/06 §22, docs/10 D-049–D-054).
 *
 * Interacts with backend auth endpoints via the centralized apiClient:
 * - Credentials (cookies) are automatically attached (credentials: 'include').
 * - CSRF tokens (X-SPMS-CSRF) are automatically attached on mutating calls.
 * - Session tokens are never touched by JavaScript (HttpOnly).
 * - CSRF token is kept in-memory only via setCsrfToken().
 */
export const authApi = {
    /**
     * Register a new account.
     * POST /api/v1/auth/register
     * @param {{ email: string, password: string }} credentials
     * @returns {Promise<{ account: object, verification: { required: boolean, sent: boolean } }>}
     */
    async register({ email, password, environment = 'REAL' }) {
        const { data } = await apiClient.post('/auth/register', { email, password, environment })
        return data
    },

    /**
     * Activate account using 6-digit verification code delivered by email.
     * POST /api/v1/auth/verify
     * @param {{ code: string, email?: string, environment?: string }} payload
     * @returns {Promise<{ account: object }>}
     */
    async verifyEmail({ code, email, environment }) {
        const payload = { code }
        if (typeof email === 'string' && email.trim() !== '') {
            payload.email = email.trim()
        }
        if (typeof environment === 'string' && environment.trim() !== '') {
            payload.environment = environment.trim()
        }
        const { data } = await apiClient.post('/auth/verify', payload)
        return data
    },

    /**
     * Request a fresh verification email.
     * POST /api/v1/auth/verify/resend
     * @param {{ email: string, environment?: string }} payload
     * @returns {Promise<{ accepted: boolean, sent: boolean }>}
     */
    async resendVerification({ email, environment }) {
        const payload = { email }
        if (typeof environment === 'string' && environment.trim() !== '') {
            payload.environment = environment.trim()
        }
        const { data } = await apiClient.post('/auth/verify/resend', payload)
        return data
    },

    /**
     * Authenticate and establish a session cookie.
     * POST /api/v1/auth/login
     * @param {{ email: string, password: string, environment?: string }} credentials
     * @returns {Promise<{ account: object, session: { csrf_token: string } }>}
     */
    async login({ email, password, environment = 'REAL' }) {
        const { data } = await apiClient.post('/auth/login', { email, password, environment })
        const csrf = data?.session?.csrf_token ?? null
        if (typeof csrf === 'string' && csrf !== '') {
            setCsrfToken(csrf)
        }
        return data
    },

    /**
     * Revoke server session and clear local CSRF token.
     * POST /api/v1/auth/logout
     * @returns {Promise<{ logged_out: boolean }>}
     */
    async logout() {
        try {
            const { data } = await apiClient.post('/auth/logout')
            return data
        } finally {
            setCsrfToken(null)
        }
    },

    /**
     * Request a password recovery email.
     * POST /api/v1/auth/recovery/request
     * @param {{ email: string }} payload
     * @returns {Promise<{ accepted: boolean }>}
     */
    async requestRecovery({ email }) {
        const { data } = await apiClient.post('/auth/recovery/request', { email })
        return data
    },

    /**
     * Verify a 6-digit recovery code.
     * POST /api/v1/auth/recovery/verify
     * @param {{ code: string, email?: string }} payload
     * @returns {Promise<{ verified: boolean }>}
     */
    async verifyRecovery({ code, email }) {
        const payload = { code }
        if (typeof email === 'string' && email.trim() !== '') {
            payload.email = email.trim()
        }
        const { data } = await apiClient.post('/auth/recovery/verify', payload)
        return data
    },

    /**
     * Reset password using 6-digit code or token delivered by email.
     * POST /api/v1/auth/recovery/confirm
     * @param {{ token?: string, code?: string, password: string }} payload
     * @returns {Promise<{ password_reset: boolean, account: object }>}
     */
    async confirmRecovery({ token, code, password }) {
        const payload = { password }
        if (typeof token === 'string' && token.trim() !== '') {
            payload.token = token.trim()
        } else if (typeof code === 'string' && code.trim() !== '') {
            payload.token = code.trim()
        }
        const { data } = await apiClient.post('/auth/recovery/confirm', payload)
        return data
    },
}
