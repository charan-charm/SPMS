/**
 * Helper utilities for forecast chart time positioning and NOW reference marker.
 */

/**
 * Safely parses an ISO date string, timestamp, or Date object into a Date instance.
 */
export function parseDateSafe(dateVal) {
    if (!dateVal) return null
    if (dateVal instanceof Date) {
        return isNaN(dateVal.getTime()) ? null : dateVal
    }
    const parsed = new Date(dateVal)
    return isNaN(parsed.getTime()) ? null : parsed
}

/**
 * Formats a Date into compact 24-hour "HH:mm" (no seconds) using the specified IANA timezone.
 */
export function formatNowTime(date, timezone = 'Asia/Kolkata') {
    try {
        const formatter = new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone || 'Asia/Kolkata',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            hourCycle: 'h23',
        })
        const parts = formatter.formatToParts(date)
        const hour = parts.find((p) => p.type === 'hour')?.value ?? '00'
        const minute = parts.find((p) => p.type === 'minute')?.value ?? '00'
        return `${hour}:${minute}`
    } catch {
        const h = String(date.getUTCHours()).padStart(2, '0')
        const m = String(date.getUTCMinutes()).padStart(2, '0')
        return `${h}:${m}`
    }
}

/**
 * Calculates NOW marker position and label if referenceTime falls inside the displayed horizon.
 * Returns null if outside horizon, missing, or unmatchable.
 */
export function getNowMarkerInfo(referenceTime, horizon, formattedData, timezone = 'Asia/Kolkata', period = null) {
    const refDate = parseDateSafe(referenceTime)
    if (!refDate || !formattedData || formattedData.length === 0) {
        return null
    }

    const refEpoch = refDate.getTime()

    // 1. Check period boundary if explicitly provided
    if (period?.start && period?.end) {
        const periodStart = parseDateSafe(period.start)
        const periodEnd = parseDateSafe(period.end)
        if (periodStart && periodEnd) {
            if (refEpoch < periodStart.getTime() || refEpoch > periodEnd.getTime()) {
                return null
            }
        }
    }

    // 2. Format 24-hr time in household timezone (e.g. "15:41")
    const timeStr = formatNowTime(refDate, timezone)
    const label = `NOW · ${timeStr}`

    // 3. Extract target hour and YYYY-MM-DD date in household timezone
    let targetHour = null
    let refDateStr = null
    try {
        const dFmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone || 'Asia/Kolkata',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        })
        refDateStr = dFmt.format(refDate) // "YYYY-MM-DD"

        const tFmt = new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone || 'Asia/Kolkata',
            hour: 'numeric',
            hour12: false,
            hourCycle: 'h23',
        })
        targetHour = parseInt(tFmt.format(refDate), 10)
    } catch {
        refDateStr = refDate.toISOString().slice(0, 10)
        targetHour = refDate.getUTCHours()
    }

    // 4. Horizon matching logic
    if (horizon === 'DAY_AHEAD') {
        // If data points have a date property, verify it matches today's date
        const firstPointDate = formattedData[0]?.date
        if (firstPointDate && refDateStr && firstPointDate !== refDateStr) {
            return null
        }

        // Also check if data points have timestamps and refDate is outside the timestamp range
        if (formattedData[0]?.timestamp && formattedData[formattedData.length - 1]?.timestamp) {
            const firstTs = parseDateSafe(formattedData[0].timestamp)?.getTime()
            const lastTs = parseDateSafe(formattedData[formattedData.length - 1].timestamp)?.getTime()
            if (firstTs && lastTs) {
                // allow up to 1 hour after the last point (e.g. 23:59:59 for hour 23)
                if (refEpoch < firstTs || refEpoch > lastTs + 3600_000) {
                    return null
                }
            }
        }

        // Find the slot for this hour
        const match = formattedData.find((pt) => {
            if (pt.hour !== undefined && pt.hour !== null) {
                return pt.hour === targetHour
            }
            if (pt.displayLabel) {
                return pt.displayLabel.startsWith(String(targetHour).padStart(2, '0'))
            }
            return false
        })

        if (!match) {
            return null
        }

        return {
            x: match.displayLabel,
            label,
            time: timeStr,
            hour: targetHour,
        }
    } else {
        // Multi-day horizon (SEVEN_DAY or MONTHLY)
        const match = formattedData.find((pt) => {
            if (pt.date) {
                return pt.date === refDateStr
            }
            if (pt.displayLabel && refDateStr) {
                return pt.displayLabel === refDateStr.slice(5) // MM-DD
            }
            return false
        })

        if (!match) {
            return null
        }

        return {
            x: match.displayLabel,
            label,
            time: timeStr,
            date: refDateStr,
        }
    }
}

/**
 * Calculates horizontal pill position for the NOW marker label.
 * Horizontally centers the pill over the reference line's X coordinate,
 * clamping within drawable bounds when near chart borders.
 *
 * @param {number} lineX - The x-coordinate of the vertical reference line
 * @param {number} pillWidth - The width of the label pill (default 76)
 * @param {object|null} bounds - Optional drawable bounds { left, right } or plotArea { x, width }
 * @param {number} padding - Horizontal padding from chart edges (default 4)
 * @returns {number} The clamped x-coordinate for the pill's top-left corner
 */
export function computePillX(lineX, pillWidth = 76, bounds = null, padding = 4) {
    const halfWidth = pillWidth / 2
    const intendedX = (lineX ?? 0) - halfWidth

    if (!bounds) {
        return intendedX
    }

    let minLeft = -Infinity
    let maxRight = Infinity

    if (typeof bounds.left === 'number' && typeof bounds.right === 'number') {
        minLeft = bounds.left + padding
        maxRight = bounds.right - padding
    } else if (typeof bounds.x === 'number' && typeof bounds.width === 'number' && bounds.width > pillWidth) {
        minLeft = bounds.x + padding
        maxRight = bounds.x + bounds.width - padding
    }

    if (minLeft === -Infinity || maxRight === Infinity || minLeft > maxRight) {
        return intendedX
    }

    const minPillX = minLeft
    const maxPillX = maxRight - pillWidth

    if (minPillX > maxPillX) {
        return intendedX
    }

    return Math.max(minPillX, Math.min(intendedX, maxPillX))
}

