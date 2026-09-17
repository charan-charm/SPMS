/**
 * Illustrative day-curve model (O1 landing) — generates the solar-vs-
 * consumption comparison for the landing page from two deterministic
 * analytical shapes (a gaussian solar bell and a two-peak demand
 * pattern). Everything is presentation math for an EXAMPLE day that is
 * clearly labeled illustrative: it models no household and never reads
 * from the API.
 */

const HOUR_START = 5
const HOUR_END = 21
const STEP_MIN = 15
const PLOT = { left: 48, right: 704, top: 24, bottom: 284 }
const MAX_KWH = 16

function solarAt(hour) {
    if (hour < 6 || hour > 19) return 0
    return 13.5 * Math.exp(-((hour - 12.8) ** 2) / (2 * 1.15 ** 2))
}

function usageAt(hour) {
    return (
        2.2 +
        3.0 * Math.exp(-((hour - 7.5) ** 2) / (2 * 0.9 ** 2)) +
        4.2 * Math.exp(-((hour - 19.5) ** 2) / (2 * 1.1 ** 2)) +
        0.8 * Math.exp(-((hour - 13) ** 2) / (2 * 2.5 ** 2))
    )
}

function xAt(hour) {
    return PLOT.left + ((hour - HOUR_START) / (HOUR_END - HOUR_START)) * (PLOT.right - PLOT.left)
}

function yAt(kwh) {
    return PLOT.bottom - (Math.min(kwh, MAX_KWH) / MAX_KWH) * (PLOT.bottom - PLOT.top)
}

function fmt(value) {
    return Math.round(value * 10) / 10
}

function lineThrough(points) {
    return points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${fmt(p.x)} ${fmt(p.y)}`).join(' ')
}

/** Sample the whole example day in 15-minute steps. */
function sampleDay() {
    const samples = []
    for (let minutes = HOUR_START * 60; minutes <= HOUR_END * 60; minutes += STEP_MIN) {
        const hour = minutes / 60
        samples.push({
            hour,
            x: xAt(hour),
            solar: solarAt(hour),
            usage: usageAt(hour),
        })
    }
    return samples
}

/**
 * Build every drawing primitive the section needs, split at
 * `nowHour` (values before it are "actual", after it "forecast").
 */
export function buildDayComparison(nowHour = 12.5) {
    const day = sampleDay()
    const nowX = xAt(nowHour)

    const solid = day.filter((p) => p.hour <= nowHour)
    const dashed = day.filter((p) => p.hour >= nowHour)

    const surplusPoints = []
    const deficitPoints = []
    for (const p of day) {
        if (p.solar >= p.usage) surplusPoints.push(p)
        else deficitPoints.push(p)
    }

    // Estimate band around the forecast solar curve (widens with horizon).
    const bandTop = []
    const bandBottom = []
    for (const p of dashed) {
        const spread = p.hour <= nowHour ? 0 : 0.4 + (p.hour - nowHour) * 0.22
        bandTop.push(`${fmt(p.x)} ${fmt(yAt(p.solar + spread))}`)
        bandBottom.push(`${fmt(p.x)} ${fmt(yAt(Math.max(p.solar - spread, 0)))}`)
    }
    const estimateBand = `M ${bandTop.join(' L ')} L ${[...bandBottom].reverse().join(' L ')} Z`

    const at = (hour) => {
        const p = day.reduce((best, q) => (Math.abs(q.hour - hour) < Math.abs(best.hour - hour) ? q : best))
        return { x: fmt(p.x), solarY: fmt(yAt(p.solar)), usageY: fmt(yAt(p.usage)) }
    }

    return {
        plot: PLOT,
        xNow: fmt(nowX),
        solarSolid: lineThrough(solid.map((p) => ({ x: p.x, y: yAt(p.solar) }))),
        solarForecast: lineThrough(dashed.map((p) => ({ x: p.x, y: yAt(p.solar) }))),
        usageSolid: lineThrough(solid.map((p) => ({ x: p.x, y: yAt(p.usage) }))),
        usageForecast: lineThrough(dashed.map((p) => ({ x: p.x, y: yAt(p.usage) }))),
        estimateBand,
        surplusArea: areaPath(surplusPoints),
        deficitAreas: areaSegments(deficitPoints) ?? [],
        nowAnchor: { solar: at(nowHour), usage: at(nowHour) },
        ticks: [
            { hour: 6, label: '6 AM' },
            { hour: 9, label: '9 AM' },
            { hour: 12, label: '12 PM' },
            { hour: 15, label: '3 PM' },
            { hour: 18, label: '6 PM' },
            { hour: 21, label: '9 PM' },
        ].map((t) => ({ ...t, x: fmt(xAt(t.hour)) })),
        grid: [0, 4, 8, 12, 16].map((kwh) => ({ kwh, y: fmt(yAt(kwh)) })),
    }
}

function areaPath(points) {
    if (points.length < 2) return null
    const forward = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${fmt(p.x)} ${fmt(yAt(p.solar))}`).join(' ')
    const back = [...points]
        .reverse()
        .map((p) => `L ${fmt(p.x)} ${fmt(yAt(p.usage))}`)
        .join(' ')
    return `${forward} ${back} Z`
}

function areaSegments(points) {
    // Contiguous runs only: a single combined area across disjoint hours
    // would draw bridges through the wrong regions.
    if (points.length < 2) return null
    const runs = []
    let run = [points[0]]
    for (let i = 1; i < points.length; i += 1) {
        if (points[i].hour - points[i - 1].hour <= STEP_MIN / 60 + 1e-9) {
            run.push(points[i])
        } else {
            runs.push(run)
            run = [points[i]]
        }
    }
    runs.push(run)
    return runs.map(areaPath).filter(Boolean)
}
