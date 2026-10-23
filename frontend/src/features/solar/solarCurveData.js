/**
 * Solar telemetry segmentation and range filtering utilities.
 *
 * GAP STRATEGY: Segment-splitting + null gap intervals.
 * Contiguous real-data telemetry blocks are partitioned into independent
 * segments (seg_0, seg_1, ...). Across gaps, all segment keys are null —
 * guaranteeing zero line, zero fill, and zero baseline across missing intervals.
 *
 * Three visual states are strictly preserved:
 * 1. REAL TELEMETRY WITH POWER > 0: Plotted curve with line and gradient fill
 * 2. REAL TELEMETRY AT 0 kW (Night / Standby): Plotted line at y = 0 kW
 * 3. NO TELEMETRY / DATA GAP: Completely blank (no line, no fill, no baseline)
 */

export const RANGES = [
    { id: 'today', label: 'Today', days: 1 },
    { id: '7d',    label: '7 Days', days: 7 },
    { id: '30d',   label: '30 Days', days: 30 },
]

// Material data gap thresholds per range
export const GAP_THRESHOLD_MS = {
    'today': 30 * 60 * 1000,          // 30 minutes for intraday
    '7d':     6 * 60 * 60 * 1000,      // 6 hours for 7-day view
    '30d':    6 * 60 * 60 * 1000,      // 6 hours for 30-day view
}

// Spacing step for gap timeline entries
export const GAP_STEP_MS = {
    'today': 15 * 60 * 1000,          // 15 min steps
    '7d':     6 * 60 * 60 * 1000,      // 6 hour steps (~4 points/day)
    '30d':   12 * 60 * 60 * 1000,      // 12 hour steps (~2 points/day)
}

export function parseSafeDate(dateStr) {
    if (!dateStr) return new Date(NaN)
    const normalized = typeof dateStr === 'string' ? dateStr.replace(' ', 'T') : dateStr
    return new Date(normalized)
}

export function filterByRange(records, rangeId) {
    if (!records || records.length === 0) return []
    const now = new Date()
    const sorted = [...records].sort(
        (a, b) => parseSafeDate(a.measurement_time) - parseSafeDate(b.measurement_time)
    )
    if (rangeId === 'today') {
        const todayLocalStr = now.toDateString()
        const todayIsoStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
        return sorted.filter((r) => {
            const d = parseSafeDate(r.measurement_time)
            if (isNaN(d.getTime())) return false
            const rDatePrefix = typeof r.measurement_time === 'string' ? r.measurement_time.slice(0, 10) : ''
            return d.toDateString() === todayLocalStr || rDatePrefix === todayIsoStr
        })
    }
    const rangeConfig = RANGES.find((r) => r.id === rangeId)
    const cutoffMs = now.getTime() - (rangeConfig ? rangeConfig.days : 7) * 24 * 60 * 60 * 1000
    return sorted.filter((r) => parseSafeDate(r.measurement_time).getTime() >= cutoffMs)
}

export function formatAxisTime(iso, rangeId) {
    if (!iso) return ''
    try {
        const d = parseSafeDate(iso)
        if (isNaN(d.getTime())) return iso
        if (rangeId === 'today') {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' })
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' })
    } catch {
        return iso
    }
}

export function buildSegmentedData(filtered, rangeId) {
    if (!filtered || filtered.length === 0) {
        return { flatData: [], segmentCount: 0, peakKw: null }
    }
    let thresholdMs = GAP_THRESHOLD_MS[rangeId] ?? GAP_THRESHOLD_MS['7d']
    let stepMs = GAP_STEP_MS[rangeId] ?? GAP_STEP_MS['7d']

    if (rangeId === 'today' && filtered.length > 1) {
        let minDeltaMs = Infinity
        for (let i = 1; i < filtered.length; i++) {
            const tPrev = parseSafeDate(filtered[i - 1].measurement_time).getTime()
            const tCurr = parseSafeDate(filtered[i].measurement_time).getTime()
            if (!isNaN(tPrev) && !isNaN(tCurr) && tCurr > tPrev) {
                const diff = tCurr - tPrev
                if (diff < minDeltaMs) {
                    minDeltaMs = diff
                }
            }
        }
        if (minDeltaMs !== Infinity && minDeltaMs > 15 * 60 * 1000) {
            thresholdMs = Math.max(90 * 60 * 1000, minDeltaMs * 1.8)
            stepMs = minDeltaMs
        }
    }

    // Group items into segments by gap threshold
    const segments = []
    let currentSegment = []

    for (let i = 0; i < filtered.length; i++) {
        const curr = filtered[i]
        if (i > 0) {
            const tPrev = parseSafeDate(filtered[i - 1].measurement_time).getTime()
            const tCurr = parseSafeDate(curr.measurement_time).getTime()
            if (!isNaN(tPrev) && !isNaN(tCurr) && tCurr - tPrev > thresholdMs) {
                if (currentSegment.length > 0) {
                    segments.push(currentSegment)
                    currentSegment = []
                }
            }
        }
        currentSegment.push(curr)
    }
    if (currentSegment.length > 0) {
        segments.push(currentSegment)
    }

    const totalSegments = segments.length
    const flatData = []

    let maxPowerKw = 0

    for (let s = 0; s < segments.length; s++) {
        const seg = segments[s]
        const isShortSegment = seg.length <= 2

        // If there is a previous segment, insert gap timeline points between them
        if (s > 0) {
            const prevSeg = segments[s - 1]
            const lastTime = parseSafeDate(prevSeg[prevSeg.length - 1].measurement_time).getTime()
            const firstTime = parseSafeDate(seg[0].measurement_time).getTime()

            if (!isNaN(lastTime) && !isNaN(firstTime)) {
                let t = lastTime + stepMs
                while (t < firstTime - stepMs / 2) {
                    const gapIso = new Date(t).toISOString()
                    const gapEntry = {
                        displayTime: formatAxisTime(gapIso, rangeId),
                        _measurementTime: gapIso,
                        _powerW: null,
                        _powerKw: null,
                        _dailyKwh: null,
                        _isGap: true,
                    }
                    for (let k = 0; k < totalSegments; k++) {
                        gapEntry[`seg_${k}`] = null
                    }
                    flatData.push(gapEntry)
                    t += stepMs
                }
            }
        }

        // Add real data points for this segment
        for (const r of seg) {
            const powerW = parseFloat(r.power_w ?? '0')
            const powerKw = parseFloat((powerW / 1000).toFixed(3))
            if (powerKw > maxPowerKw) {
                maxPowerKw = powerKw
            }
            const dailyKwh =
                r.daily_generation_kwh !== null && r.daily_generation_kwh !== undefined
                    ? parseFloat(r.daily_generation_kwh).toFixed(3)
                    : null

            const entry = {
                displayTime: formatAxisTime(r.measurement_time, rangeId),
                _measurementTime: r.measurement_time,
                _powerW: powerW,
                _powerKw: powerKw,
                _dailyKwh: dailyKwh,
                _dataStatus: r.data_status || 'FRESH',
                _dataNature: r.data_nature || 'ACTUAL',
                _inverterStatus: r.inverter_status || 'NORMAL',
                _id: r.id,
                _isShortSegment: isShortSegment,
                _segIdx: s,
            }

            for (let k = 0; k < totalSegments; k++) {
                entry[`seg_${k}`] = k === s ? powerKw : null
            }
            flatData.push(entry)
        }
    }

    const peakKw = maxPowerKw > 0 ? maxPowerKw.toFixed(2) : null
    return { flatData, segmentCount: totalSegments, peakKw }
}

/**
 * Builds segmented intraday curve data for a single selected date.
 * Strictly represents ONLY the selected date.
 * Supports:
 * - 'power': instantaneous solar power in kW vs time
 * - 'energy': cumulative/running energy in kWh vs time
 *
 * @param {Array<object>} readings Persisted GoodWe readings for the selected date
 * @param {'power'|'energy'} mode Chart mode toggle
 * @returns {{ flatData: Array<object>, segmentCount: number, peakKw: string, totalEnergyKwh: string, observations: number }}
 */
export function buildSingleDayCurveData(readings = [], mode = 'power') {
    if (!readings || readings.length === 0) {
        return {
            flatData: [],
            segmentCount: 0,
            peakKw: '0.00',
            totalEnergyKwh: '0.00',
            observations: 0,
        }
    }

    // Sort chronologically ascending
    const sorted = [...readings].sort(
        (a, b) => parseSafeDate(a.measurement_time) - parseSafeDate(b.measurement_time)
    )

    // Detect actual telemetry interval (cadence) across readings
    let minDeltaMs = Infinity
    for (let i = 1; i < sorted.length; i++) {
        const tPrev = parseSafeDate(sorted[i - 1].measurement_time).getTime()
        const tCurr = parseSafeDate(sorted[i].measurement_time).getTime()
        if (!isNaN(tPrev) && !isNaN(tCurr) && tCurr > tPrev) {
            const diff = tCurr - tPrev
            if (diff < minDeltaMs) {
                minDeltaMs = diff
            }
        }
    }

    // Intraday telemetry gap threshold:
    // - For 5-minute nominal telemetry: 30 minutes (3-6 poll intervals)
    // - For hourly nominal telemetry (demo scenarios): 90 minutes (so consecutive hourly readings
    //   connect into a continuous curve, while missing intervals >= 2h still form an honest gap)
    let thresholdMs = 30 * 60 * 1000
    let stepMs = 15 * 60 * 1000
    if (minDeltaMs !== Infinity && minDeltaMs > 15 * 60 * 1000) {
        thresholdMs = Math.max(90 * 60 * 1000, minDeltaMs * 1.8)
        stepMs = minDeltaMs
    }

    const segments = []
    let currentSegment = []

    for (let i = 0; i < sorted.length; i++) {
        const curr = sorted[i]
        if (i > 0) {
            const tPrev = parseSafeDate(sorted[i - 1].measurement_time).getTime()
            const tCurr = parseSafeDate(curr.measurement_time).getTime()
            if (!isNaN(tPrev) && !isNaN(tCurr) && tCurr - tPrev > thresholdMs) {
                if (currentSegment.length > 0) {
                    segments.push(currentSegment)
                    currentSegment = []
                }
            }
        }
        currentSegment.push(curr)
    }
    if (currentSegment.length > 0) {
        segments.push(currentSegment)
    }

    const totalSegments = segments.length
    const flatData = []
    let maxPowerKw = 0
    let maxDailyKwh = 0

    for (let s = 0; s < segments.length; s++) {
        const seg = segments[s]
        const isShortSegment = seg.length <= 2

        if (s > 0) {
            const prevSeg = segments[s - 1]
            const lastTime = parseSafeDate(prevSeg[prevSeg.length - 1].measurement_time).getTime()
            const firstTime = parseSafeDate(seg[0].measurement_time).getTime()

            if (!isNaN(lastTime) && !isNaN(firstTime)) {
                let t = lastTime + stepMs
                while (t < firstTime - stepMs / 2) {
                    const gapIso = new Date(t).toISOString()
                    const dt = parseSafeDate(gapIso)
                    const displayTime = !isNaN(dt.getTime())
                        ? dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' })
                        : ''

                    const gapEntry = {
                        displayTime,
                        _measurementTime: gapIso,
                        _powerW: null,
                        _powerKw: null,
                        _dailyKwh: null,
                        _isGap: true,
                    }
                    for (let k = 0; k < totalSegments; k++) {
                        gapEntry[`seg_${k}`] = null
                    }
                    flatData.push(gapEntry)
                    t += stepMs
                }
            }
        }

        for (const r of seg) {
            const powerW = parseFloat(r.power_w ?? '0')
            const powerKw = parseFloat((powerW / 1000).toFixed(3))
            if (powerKw > maxPowerKw) {
                maxPowerKw = powerKw
            }
            const dailyKwh = r.daily_generation_kwh !== null && r.daily_generation_kwh !== undefined
                ? parseFloat(r.daily_generation_kwh)
                : 0.0
            if (dailyKwh > maxDailyKwh) {
                maxDailyKwh = dailyKwh
            }

            const dt = parseSafeDate(r.measurement_time)
            const displayTime = !isNaN(dt.getTime())
                ? dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' })
                : (typeof r.measurement_time === 'string' ? r.measurement_time.slice(11, 16) : '')

            const plotValue = mode === 'energy' ? dailyKwh : powerKw

            const entry = {
                displayTime,
                _measurementTime: r.measurement_time,
                _powerW: powerW,
                _powerKw: powerKw,
                _dailyKwh: dailyKwh,
                _dataStatus: r.data_status || 'FRESH',
                _dataNature: r.data_nature || 'ACTUAL',
                _inverterStatus: r.inverter_status || 'NORMAL',
                _id: r.id,
                _isShortSegment: isShortSegment,
                _segIdx: s,
            }

            for (let k = 0; k < totalSegments; k++) {
                entry[`seg_${k}`] = k === s ? plotValue : null
            }
            flatData.push(entry)
        }
    }

    return {
        flatData,
        segmentCount: totalSegments,
        peakKw: maxPowerKw > 0 ? maxPowerKw.toFixed(2) : '0.00',
        totalEnergyKwh: maxDailyKwh > 0 ? maxDailyKwh.toFixed(2) : '0.00',
        observations: sorted.length,
    }
}

