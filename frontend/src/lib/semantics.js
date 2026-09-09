/**
 * SPMS semantic identifiers and presentation metadata (docs/04 §59,
 * docs/06 §11–14, docs/12 §9–10).
 *
 * These are presentation mappings for backend-authoritative semantic
 * values. The frontend never derives or fabricates these values — they
 * always arrive from API data. Color is never the only signal: every
 * entry carries a text label and (where meaningful) an icon.
 */

import { Activity, AlarmClockOff, Archive, CircleSlash, Gauge, Link2, ShieldCheck, Sparkles, TrendingUp } from 'lucide-react'

/** Data nature (docs/12 §9): ACTUAL | PREDICTED | ESTIMATED | SIMULATED */
export const DATA_NATURE = {
    ACTUAL: {
        label: 'Actual',
        className: 'border-primary/40 bg-primary-soft text-primary-active',
        icon: Activity,
    },
    PREDICTED: {
        label: 'Predicted',
        className: 'border-secondary/40 bg-secondary-soft text-secondary-active',
        icon: TrendingUp,
    },
    ESTIMATED: {
        label: 'Estimated',
        className: 'border-warning/40 bg-warning/10 text-warning',
        icon: Gauge,
    },
    SIMULATED: {
        label: 'Simulated',
        className: 'border-chart-demo/40 bg-chart-demo/10 text-chart-demo',
        icon: Sparkles,
    },
}

/** Data status (docs/12 §9): FRESH | STALE | DEGRADED | UNAVAILABLE | PROCESSING | ERROR */
export const DATA_STATUS = {
    FRESH: {
        label: 'Fresh',
        className: 'border-primary/40 bg-primary-soft text-primary-active',
        icon: ShieldCheck,
    },
    STALE: {
        label: 'Stale',
        className: 'border-muted/40 bg-surface-alt text-muted',
        icon: AlarmClockOff,
    },
    DEGRADED: {
        label: 'Degraded',
        className: 'border-warning/40 bg-warning/10 text-warning',
        icon: Gauge,
    },
    UNAVAILABLE: {
        label: 'Unavailable',
        className: 'border-muted/40 bg-surface-alt text-muted',
        icon: CircleSlash,
    },
    PROCESSING: {
        label: 'Processing',
        className: 'border-info/40 bg-info/10 text-info',
        icon: Activity,
    },
    ERROR: {
        label: 'Error',
        className: 'border-danger/40 bg-danger/10 text-danger',
        icon: AlarmClockOff,
    },
}

/** Confidence levels (docs/12 §10) — never color alone; level text is always rendered. */
export const CONFIDENCE_LEVEL = {
    HIGH: { label: 'High confidence', bar: 'bg-primary', icon: ShieldCheck },
    MODERATE: { label: 'Moderate confidence', bar: 'bg-info', icon: Activity },
    LOW: { label: 'Low confidence', bar: 'bg-warning', icon: Gauge },
    VERY_LOW: { label: 'Very low confidence', bar: 'bg-warning', icon: Gauge },
    INSUFFICIENT: { label: 'Insufficient evidence', bar: 'bg-muted', icon: CircleSlash },
}

/** Data quality (docs/04 §15 dimensions) — labels are always rendered with the indicator. */
export const QUALITY_LEVEL = {
    VERIFIED: { label: 'Verified', bar: 'bg-primary', icon: ShieldCheck },
    GOOD: { label: 'Good', bar: 'bg-primary', icon: ShieldCheck },
    PARTIAL: { label: 'Partial', bar: 'bg-info', icon: Activity },
    UNVERIFIED: { label: 'Unverified', bar: 'bg-warning', icon: Gauge },
    POOR: { label: 'Poor', bar: 'bg-danger', icon: AlarmClockOff },
}

/** Operation lifecycle states (docs/06 §19, docs/07 §15). */
export const OPERATION_STATUS = {
    NOT_STARTED: { label: 'Not started', tone: 'muted' },
    QUEUED: { label: 'Queued', tone: 'info' },
    PROCESSING: { label: 'Processing', tone: 'info' },
    COMPLETED: { label: 'Completed', tone: 'success' },
    FAILED: { label: 'Failed', tone: 'danger' },
    CANCELLED: { label: 'Cancelled', tone: 'muted' },
    RECOVERING: { label: 'Recovering', tone: 'warning' },
    RECOVERED: { label: 'Recovered', tone: 'success' },
}

/** Chart line semantics (docs/12 §12) — stroke style carries meaning, not color alone. */
export const CHART_LINE_STYLE = {
    ACTUAL: { strokeDasharray: undefined, label: 'Actual' },
    PREDICTED: { strokeDasharray: '8 7', label: 'Predicted' },
    ESTIMATED: { strokeDasharray: '2 6', label: 'Estimated' },
    STALE: { strokeDasharray: undefined, muted: true, label: 'Stale' },
    DEMO: { strokeDasharray: '10 4 2 4', label: 'Demo' },
}

/** Provenance-related presentation helpers (docs/06 §15). */
export const PROVENANCE_ICON = Link2

/** Generic semantic tone → class mapping used by badges. */
export const TONE_CLASS = {
    success: 'border-success/40 bg-success/10 text-success',
    warning: 'border-warning/40 bg-warning/10 text-warning',
    danger: 'border-danger/40 bg-danger/10 text-danger',
    info: 'border-info/40 bg-info/10 text-info',
    muted: 'border-muted/40 bg-surface-alt text-muted',
}
