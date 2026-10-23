/**
 * Canonical Recharts Axis Formatters for SPMS Analytics
 *
 * Ensures axis tick labels render clean numbers without repeated unit strings,
 * preventing clipping, wrapping, and malformed label rendering.
 */

export function formatKwhAxis(value) {
    if (value == null || isNaN(Number(value))) return ''
    return Math.round(Number(value)).toLocaleString()
}

export function formatKwAxis(value) {
    if (value == null || isNaN(Number(value))) return ''
    const num = Number(value)
    if (num === 0) return '0'
    const rounded = Number(num.toFixed(2))
    return rounded.toString()
}

export function formatCurrencyAxis(value) {
    if (value == null || isNaN(Number(value))) return ''
    return Math.round(Number(value)).toLocaleString()
}
