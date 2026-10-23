/**
 * Energy Period and Numerical Formatting Utilities (SPMS Energy V1).
 *
 * Strict Data Presentation Rules:
 * - Never expose raw ISO strings (e.g., 2026-10-03T16:00:00Z) as primary labels.
 * - Timezone-safe formatting using the household's configured timezone.
 * - Clean numerical formatting (sensible 2-decimal display precision while
 *   preserving full precision in underlying calculations).
 */

/**
 * Format a forecast period into concise, human-readable language.
 *
 * Examples:
 * - "Tomorrow · Hourly"
 * - "Oct 4, 2026 · Hourly"
 * - "Next 24 Hours · Hourly"
 *
 * @param {{ start?: string, end?: string, resolution?: string }|null} period
 * @param {string} [timezone='Asia/Kolkata']
 * @returns {string}
 */
export function formatHumanForecastPeriod(period, timezone = 'Asia/Kolkata') {
    const periodObj = typeof period === 'string' ? { start: period } : period
    if (!periodObj || !periodObj.start) {
        return 'Day-Ahead · Forecast'
    }

    try {
        const start = new Date(periodObj.start)

        if (isNaN(start.getTime())) {
            return 'Day-Ahead · Forecast'
        }

        const now = new Date()

        // Format date strings in target timezone (YYYY-MM-DD)
        const nowInTz = now.toLocaleDateString('en-CA', { timeZone: timezone })
        const startInTz = start.toLocaleDateString('en-CA', { timeZone: timezone })

        const nowDate = new Date(nowInTz)
        const startDate = new Date(startInTz)
        const diffDays = Math.round((startDate.getTime() - nowDate.getTime()) / 86400000)

        let resolutionLabel = ' · Day-Ahead'
        if (period.resolution) {
            const res = period.resolution.toUpperCase()
            if (res === 'DAY_AHEAD' || res === 'DAY') {
                resolutionLabel = ' · Day-Ahead'
            } else if (res === 'HOURLY' || res === 'HOUR') {
                resolutionLabel = ' · Hourly'
            } else {
                resolutionLabel = ` · ${period.resolution.charAt(0).toUpperCase() + period.resolution.slice(1).toLowerCase()}`
            }
        }

        let dayLabel = ''
        if (diffDays === 0) {
            // If the start is later today or covers the 24h horizon
            dayLabel = 'Next 24 Hours'
        } else if (diffDays === 1) {
            dayLabel = 'Tomorrow'
        } else {
            dayLabel = start.toLocaleDateString(undefined, {
                timeZone: timezone,
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            })
        }

        return `${dayLabel}${resolutionLabel}`
    } catch {
        return 'Day-Ahead · Forecast'
    }
}

/**
 * Format a billing month label.
 *
 * Example: "Sep 2026" or "Aug 2026"
 *
 * @param {string|null} startStr
 * @param {string|null} endStr
 * @param {string} [timezone='Asia/Kolkata']
 * @returns {string}
 */
export function formatBillingMonth(startStr, endStr, timezone = 'Asia/Kolkata') {
    if (!startStr && !endStr) return 'Billing Period'
    try {
        const start = startStr ? new Date(startStr.replace(' ', 'T')) : null
        if (start && !isNaN(start.getTime())) {
            return start.toLocaleDateString(undefined, {
                timeZone: timezone,
                month: 'short',
                year: 'numeric',
            })
        }
        return 'Billing Period'
    } catch {
        return 'Billing Period'
    }
}

/**
 * Format a historical billing period range.
 *
 * Example: "Aug 2026 (01 Aug – 01 Sep)"
 *
 * @param {string|null} startStr
 * @param {string|null} endStr
 * @param {string} [timezone='Asia/Kolkata']
 * @returns {string}
 */
export function formatBillingPeriod(startStr, endStr, timezone = 'Asia/Kolkata') {
    if (!startStr && !endStr) return 'Billing Cycle'
    try {
        const start = startStr ? new Date(startStr.replace(' ', 'T')) : null
        const end = endStr ? new Date(endStr.replace(' ', 'T')) : null

        if (start && !isNaN(start.getTime()) && end && !isNaN(end.getTime())) {
            const monthLabel = start.toLocaleDateString(undefined, {
                timeZone: timezone,
                month: 'short',
                year: 'numeric',
            })
            const startDay = start.toLocaleDateString(undefined, {
                timeZone: timezone,
                day: '2-digit',
                month: 'short',
            })
            const endDay = end.toLocaleDateString(undefined, {
                timeZone: timezone,
                day: '2-digit',
                month: 'short',
            })
            return `${monthLabel} (${startDay} – ${endDay})`
        }

        if (start && !isNaN(start.getTime())) {
            return start.toLocaleDateString(undefined, { timeZone: timezone, month: 'short', year: 'numeric' })
        }

        return startStr || endStr || 'Billing Cycle'
    } catch {
        return `${startStr} – ${endStr}`
    }
}

/**
 * Safely parse a date string, timestamp, or Date object into a Date.
 *
 * @param {string|number|Date|null|undefined} input
 * @returns {Date}
 */
export function parseSafeDate(input) {
    if (!input) return new Date(NaN)
    if (input instanceof Date) return input
    if (typeof input === 'string') {
        const normalized = input.includes(' ') && !input.includes('T')
            ? input.replace(' ', 'T')
            : input
        return new Date(normalized)
    }
    return new Date(input)
}

/**
 * Format kWh values with standard sensible precision (2 decimals by default).
 * Preserves full precision for math, formats strictly for presentation.
 *
 * @param {number|string|null|undefined} value
 * @param {number} [decimals=2]
 * @returns {string}
 */
export function formatKwh(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—'
    const num = Number(value)
    if (isNaN(num)) return '—'
    return num.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

/**
 * Format energy units concisely (integer or 1 decimal if fractional).
 *
 * Examples:
 * - 203 -> "203 kWh"
 * - 145.5 -> "145.5 kWh"
 *
 * @param {number|string|null|undefined} value
 * @returns {string}
 */
export function formatEnergyUnits(value) {
    if (value === null || value === undefined || value === '') return '—'
    const num = Number(value)
    if (isNaN(num)) return '—'
    if (Number.isInteger(num)) {
        return `${num} kWh`
    }
    return `${num.toFixed(1)} kWh`
}

/**
 * Format net energy into precise terminology.
 *
 * Positive surplus (solar > consumption): "100 kWh surplus"
 * Deficit (consumption > solar): "94 kWh deficit"
 *
 * @param {number|string|null} consumptionKwh
 * @param {number|string|null} solarKwh
 * @param {number|string|null} [netUnitsKwh=null]
 * @returns {{ text: string, isSurplus: boolean|null }}
 */
export function formatNetEnergy(consumptionKwh, solarKwh, netUnitsKwh = null) {
    const cons = consumptionKwh !== null && consumptionKwh !== undefined && consumptionKwh !== '' ? Number(consumptionKwh) : null
    const solar = solarKwh !== null && solarKwh !== undefined && solarKwh !== '' ? Number(solarKwh) : null
    const net = netUnitsKwh !== null && netUnitsKwh !== undefined && netUnitsKwh !== '' ? Number(netUnitsKwh) : null

    let diff = null
    if (solar !== null && cons !== null) {
        diff = solar - cons
    } else if (net !== null) {
        // In utility billing: negative net is net generation/surplus, positive net is net import
        diff = -net
    }

    if (diff === null || isNaN(diff)) {
        return { text: '—', isSurplus: null }
    }

    if (diff > 0.05) {
        const val = Number.isInteger(diff) ? diff : Math.round(diff)
        return { text: `${val} kWh surplus`, isSurplus: true }
    }
    if (diff < -0.05) {
        const val = Number.isInteger(Math.abs(diff)) ? Math.abs(diff) : Math.round(Math.abs(diff))
        return { text: `${val} kWh deficit`, isSurplus: false }
    }
    return { text: '0 kWh', isSurplus: null }
}

/**
 * Format kWh values with sign (+ for positive, - for negative).
 *
 * @param {number|string|null|undefined} value
 * @param {number} [decimals=2]
 * @returns {string}
 */
export function formatKwhSigned(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—'
    const num = Number(value)
    if (isNaN(num)) return '—'
    const formatted = Math.abs(num).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
    if (num > 0) return `+${formatted}`
    if (num < 0) return `-${formatted}`
    return formatted
}


