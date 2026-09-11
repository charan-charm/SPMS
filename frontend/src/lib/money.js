/**
 * Presentation-only money formatting (F5 — docs/05 §12, docs/07 §5).
 *
 * The backend is the sole authority for numerical cost values, and
 * currency arrives as explicit data. This helper only formats for
 * display (locale en-IN per locked F5 decision); it never derives,
 * rounds for storage, or fabricates amounts.
 */

export function formatMoney(amount, currency = 'INR') {
    if (amount === null || amount === undefined || Number.isNaN(Number(amount))) {
        return null
    }

    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(Number(amount))
}
