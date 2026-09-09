import { buildApiUrl, DEFAULT_REQUEST_TIMEOUT_MS } from './config'

/**
 * Centralized SPMS API client (F1 foundation — docs/06 §6, docs/11 §7).
 *
 * All backend communication must go through this client. Feature code
 * must not construct arbitrary fetch() calls or invent request/response
 * conventions.
 *
 * Foundation responsibilities:
 * - base path configuration (/api/v1)
 * - native fetch() with JSON encoding/decoding
 * - standard SPMS response envelope handling ({success, data, error, meta})
 * - meaningful HTTP status handling
 * - stable backend error-code mapping into ApiError
 * - correlation/request ID capture (meta.request_id / X-Request-Id)
 * - session cookie handling (credentials: include)
 * - CSRF token forwarding foundation (XSRF-TOKEN cookie → X-CSRF-Token)
 *
 * No SPMS endpoints exist yet — none are defined here.
 */

export class ApiError extends Error {
    constructor({ code, message, status = 0, context = null, requestId = null }) {
        super(message)
        this.name = 'ApiError'
        this.code = code
        this.status = status
        this.context = context
        this.requestId = requestId
    }
}

function readCookie(name) {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${name}=`))
    return match ? decodeURIComponent(match.substring(name.length + 1)) : null
}

function getCsrfToken() {
    // CSRF foundation: forwarded automatically once the backend issues the
    // XSRF-TOKEN cookie. Harmless no-op until then.
    return readCookie('XSRF-TOKEN')
}

async function parseJson(response) {
    const text = await response.text()
    if (text === '') {
        return null
    }
    try {
        return JSON.parse(text)
    } catch {
        throw new ApiError({
            code: 'MALFORMED_RESPONSE',
            message: 'The server returned an unexpected response format.',
            status: response.status,
        })
    }
}

function extractRequestId(payload, response) {
    if (payload && typeof payload === 'object' && payload.meta?.request_id) {
        return payload.meta.request_id
    }
    return response.headers.get('X-Request-Id')
}

async function request(path, { method = 'GET', body = undefined, query = undefined, signal = undefined, headers = {} } = {}) {
    const timeoutSignal = AbortSignal.timeout(DEFAULT_REQUEST_TIMEOUT_MS)
    const combinedSignal = signal ? AbortSignal.any([signal, timeoutSignal]) : timeoutSignal

    const requestHeaders = {
        Accept: 'application/json',
        ...headers,
    }

    const csrfToken = getCsrfToken()
    if (csrfToken) {
        requestHeaders['X-CSRF-Token'] = csrfToken
    }

    if (body !== undefined) {
        requestHeaders['Content-Type'] = 'application/json'
    }

    let response
    try {
        response = await fetch(buildApiUrl(path, query), {
            method,
            headers: requestHeaders,
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'include',
            signal: combinedSignal,
        })
    } catch (error) {
        if (error?.name === 'TimeoutError') {
            throw new ApiError({
                code: 'TIMEOUT',
                message: 'The request took too long. Please try again.',
                status: 0,
            })
        }
        if (error?.name === 'AbortError') {
            throw error
        }
        throw new ApiError({
            code: 'NETWORK_ERROR',
            message: 'The service could not be reached. Check your connection and try again.',
            status: 0,
        })
    }

    const payload = await parseJson(response)
    const requestId = extractRequestId(payload, response)

    const envelope = payload && typeof payload === 'object' && 'success' in payload
        ? payload
        : null

    if (response.ok && envelope?.success === true) {
        return {
            data: envelope.data ?? null,
            meta: envelope.meta ?? {},
            requestId,
            status: response.status,
        }
    }

    if (envelope && envelope.success === false) {
        throw new ApiError({
            code: envelope.error?.code ?? 'REQUEST_FAILED',
            message: envelope.error?.message ?? 'The request could not be completed.',
            status: response.status,
            context: envelope.error?.context ?? null,
            requestId,
        })
    }

    if (response.ok) {
        // Non-envelope 2xx payloads are accepted as-is for now; the
        // envelope contract becomes strict as real endpoints arrive.
        return {
            data: payload,
            meta: {},
            requestId,
            status: response.status,
        }
    }

    throw new ApiError({
        code: 'HTTP_ERROR',
        message: 'The request could not be completed.',
        status: response.status,
        requestId,
    })
}

export const apiClient = {
    get: (path, options = {}) => request(path, { ...options, method: 'GET' }),
    post: (path, body, options = {}) => request(path, { ...options, method: 'POST', body }),
    put: (path, body, options = {}) => request(path, { ...options, method: 'PUT', body }),
    patch: (path, body, options = {}) => request(path, { ...options, method: 'PATCH', body }),
    delete: (path, options = {}) => request(path, { ...options, method: 'DELETE' }),
}
