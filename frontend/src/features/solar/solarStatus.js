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

/**
 * Normalizes telemetry freshness and operating state (SPMS O4 / D-059, D-060).
 *
 * Distinct telemetry states:
 *  - LIVE: Fresh telemetry actively generating power (> 0 W)
 *  - NO_GENERATION: Fresh telemetry with 0 W generation (night / standby)
 *  - STALE: Telemetry older than 15-minute polling threshold
 *  - OFFLINE: Inverter reported offline
 *  - WAITING: Connected, waiting for provider response/initial data
 *  - UNKNOWN: Missing or uninterpretable telemetry state
 */
export function deriveTelemetryState(reading, connection) {
    if (!reading) {
        if (connection && (connection.connection_status === 'CONNECTED' || connection.configured)) {
            return 'WAITING'
        }
        return 'UNKNOWN'
    }

    const dataStatus = (reading.data_status || '').toUpperCase()
    const invStatus = (reading.inverter_status || '').toUpperCase()
    const powerW = parseFloat(reading.power_w || '0')

    // Stale telemetry must never masquerade as live or active
    if (dataStatus === 'STALE') {
        return 'STALE'
    }

    if (invStatus === 'OFFLINE') {
        return 'OFFLINE'
    }

    if (powerW > 0) {
        return 'LIVE'
    }

    // Power is 0 W and telemetry is fresh: night standby or zero generation
    return 'NO_GENERATION'
}

/**
 * Returns canonical telemetry freshness: 'FRESH' | 'STALE' | 'UNKNOWN'
 */
export function deriveTelemetryFreshness(reading) {
    if (!reading) return 'UNKNOWN'
    return (reading.data_status || '').toUpperCase() === 'STALE' ? 'STALE' : 'FRESH'
}

/**
 * Honest operating status label distinguishing generation state from freshness:
 * - If power > 0 and stale: "Last observed generating"
 * - If power > 0 and fresh: "Live generating"
 * - If power === 0 and stale: "Last observed standby"
 * - If power === 0 and fresh: "No generation (Standby)"
 * - If offline: "OFFLINE"
 */
export function deriveOperatingStatusLabel(reading) {
    if (!reading) return 'No Telemetry'
    const invStatus = (reading.inverter_status || '').toUpperCase()
    if (invStatus === 'OFFLINE') return 'OFFLINE'
    const isStale = (reading.data_status || '').toUpperCase() === 'STALE'
    const powerW = parseFloat(reading.power_w || '0')

    if (powerW > 0) {
        return isStale ? 'Last observed generating' : 'Live generating'
    }
    return isStale ? 'Last observed standby' : 'No generation (Standby)'
}

/**
 * Format observation timestamp to "HH:mm IST" (or ISO time) without fabricating current time.
 * Never returns current browser/render time. Returns null if timestamp is absent.
 */
export function formatObservationTimestamp(timeStr) {
    if (!timeStr) return null
    if (typeof timeStr !== 'string') {
        try {
            timeStr = timeStr.toISOString()
        } catch {
            return null
        }
    }
    const trimmed = timeStr.trim()
    // Match standard YYYY-MM-DD HH:mm:ss or YYYY-MM-DDTHH:mm:ssZ or HH:mm
    const match = trimmed.match(/(?:(\d{4})-(\d{2})-(\d{2})[T\s])?(\d{1,2}):(\d{2})/)
    if (match) {
        const hh = match[4].padStart(2, '0')
        const mm = match[5]
        return `${hh}:${mm} IST`
    }
    try {
        const dt = new Date(trimmed)
        if (!isNaN(dt.getTime())) {
            return dt.toLocaleTimeString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }) + ' IST'
        }
    } catch {
        // Fallback
    }
    return trimmed
}

/**
 * Formats an instantaneous telemetry metric (Voltage, Current, Power, Frequency, Temperature).
 * Strictly adheres to SPMS data honesty:
 * - A missing / null reading is NOT equivalent to zero (returns 'Unavailable' + reason).
 * - A real measured zero displays as 0.00 / 0 with valid unit and observation time.
 * - Stale metrics show last valid observation value + observation timestamp + STALE status.
 *
 * @param {number|string|null|undefined} value The raw metric value
 * @param {string} unit e.g. 'V', 'A', 'Hz', 'W', 'kW', '°C'
 * @param {object} [options]
 * @param {string|null} [options.timestamp=null] Measurement timestamp of this observation
 * @param {string} [options.dataStatus='FRESH'] 'FRESH' | 'STALE' | 'UNAVAILABLE'
 * @param {number} [options.decimals=2] Number of decimal places
 * @returns {{
 *   valueDisplay: string,
 *   unit: string,
 *   hasValue: boolean,
 *   isZero: boolean,
 *   isStale: boolean,
 *   statusBadge: string|null,
 *   timestampLabel: string,
 *   subtext: string
 * }}
 */
export function formatTelemetryMetric(value, unit, {
    timestamp = null,
    dataStatus = 'FRESH',
    decimals = 2,
} = {}) {
    const hasValue = value !== null && value !== undefined && value !== '' && !isNaN(Number(value))
    if (!hasValue) {
        return {
            valueDisplay: 'Unavailable',
            unit: '',
            hasValue: false,
            isZero: false,
            isStale: false,
            statusBadge: null,
            timestampLabel: 'Timestamp unavailable',
            subtext: 'Awaiting first valid telemetry',
        }
    }

    const num = Number(value)
    const isZero = num === 0
    const isStale = (dataStatus || '').toUpperCase() === 'STALE'
    const formattedNum = decimals > 0 ? num.toFixed(decimals) : String(Math.round(num))
    const valueDisplay = `${formattedNum} ${unit}`
    const obsTime = formatObservationTimestamp(timestamp)

    const timestampLabel = isStale
        ? (obsTime ? `Last observed · ${obsTime}` : 'STALE · Timestamp unavailable')
        : (obsTime ? `Fresh · ${obsTime}` : 'Live')

    return {
        valueDisplay,
        unit,
        hasValue: true,
        isZero,
        isStale,
        statusBadge: isStale ? 'STALE' : null,
        timestampLabel,
        subtext: isStale
            ? (obsTime ? `Last observed ${obsTime}` : 'Telemetry is stale')
            : (obsTime ? `Fresh ${obsTime}` : 'Live telemetry'),
    }
}

