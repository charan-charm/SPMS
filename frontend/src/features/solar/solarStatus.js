/**
 * Normalizes backend connection_status and last_error_code into the
 * 8 semantically distinct SPMS connection states (plus NOT_CONFIGURED).
 *
 * Distinct states:
 *  - CONNECTED
 *  - OFFLINE
 *  - DEGRADED
 *  - AUTHENTICATION_ERROR
 *  - API_ERROR
 *  - TIMEOUT
 *  - NETWORK_ERROR
 *  - UNKNOWN
 *  - NOT_CONFIGURED (neutral unconfigured state)
 */
export function deriveNormalizedStatus(conn) {
    if (!conn || !conn.configured) {
        return 'NOT_CONFIGURED'
    }

    const rawStatus = (conn.connection_status || '').toUpperCase()
    const errorCode = (conn.last_error_code || '').toUpperCase()

    // Check specific error code mappings first
    if (errorCode === 'AUTH_FAILED' || errorCode === 'AUTHENTICATION_ERROR' || errorCode === 'AUTH_TOKEN_MISSING') {
        return 'AUTHENTICATION_ERROR'
    }
    if (errorCode === 'TIMEOUT' || errorCode.includes('TIMEOUT')) {
        return 'TIMEOUT'
    }
    if (errorCode === 'NETWORK_ERROR' || errorCode.includes('CURL') || errorCode === 'SYNC_ERROR') {
        return 'NETWORK_ERROR'
    }
    if (errorCode === 'API_ERROR' || errorCode === 'STATION_NOT_FOUND' || errorCode === 'NO_INVERTER_FOUND') {
        return 'API_ERROR'
    }

    if (rawStatus === 'CONNECTED') {
        return 'CONNECTED'
    }
    if (rawStatus === 'OFFLINE') {
        return 'OFFLINE'
    }
    if (rawStatus === 'DEGRADED') {
        return 'DEGRADED'
    }
    if (rawStatus === 'AUTHENTICATION_ERROR') {
        return 'AUTHENTICATION_ERROR'
    }
    if (rawStatus === 'API_ERROR') {
        return 'API_ERROR'
    }
    if (rawStatus === 'TIMEOUT') {
        return 'TIMEOUT'
    }
    if (rawStatus === 'NETWORK_ERROR') {
        return 'NETWORK_ERROR'
    }
    if (rawStatus === 'UNKNOWN') {
        return 'UNKNOWN'
    }

    // If unreachable without specific error code, treat as NETWORK_ERROR
    if (rawStatus === 'UNREACHABLE') {
        return 'NETWORK_ERROR'
    }

    return 'UNKNOWN'
}
