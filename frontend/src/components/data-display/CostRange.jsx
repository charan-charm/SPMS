import { formatMoney } from '../../lib/money'
import { cn } from '../../lib/utils'

/**
 * CostRange (F5 — docs/01 §19, docs/10 D-042) — presents a
 * backend-provided estimated cost range. The backend is the sole
 * authority for numerical cost calculation: this component never widens,
 * narrows, or fabricates a range, and always labels the band as an
 * estimate rather than a guaranteed bill.
 *
 * Zero-width presentation (owner-ratified, docs/10 D-042): when the
 * backend returns a 0–0 range (genuine zero variable cost), no band is
 * shown — the zero-cost outcome is stated instead. The backend's
 * disclosed range triggers remain in the API payload and notes; nothing
 * is fabricated or removed to change presentation.
 *
 * Props:
 * - low / high: backend-computed range bounds (numbers)
 * - currency: explicit currency data from the backend (display-formatted)
 * - halfWidthPercent: the disclosed uncertainty factor applied by the backend
 * - triggers: backend-provided reasons the range was required
 */
export function CostRange({
    range = null,
    low: lowProp,
    high: highProp,
    currency = 'INR',
    halfWidthPercent = null,
    triggers = null,
    className,
}) {
    const low = lowProp !== undefined ? lowProp : (range?.low ?? range?.min_inr);
    const high = highProp !== undefined ? highProp : (range?.high ?? range?.max_inr);
    const effectiveHalfWidth = halfWidthPercent !== null ? halfWidthPercent : (range?.half_width_percent ?? range?.half_width_pct ?? range?.halfWidthPercent);
    const effectiveTriggers = triggers !== null ? triggers : (range?.triggers ?? null);

    if (low === null || low === undefined || high === null || high === undefined) {
        return null
    }

    // Zero-width suppression (Locked Rule 8): for genuine zero-cost surplus/balanced cases,
    // suppress zero-width cost ranges in the UI. Do not display meaningless ranges.
    if (Number(low) === 0 && Number(high) === 0) {
        return null
    }

    return (
        <div
            className={cn('rounded-md border border-warning/40 bg-warning/10 px-4 py-3', className)}
            role="note"
        >
            <p className="text-xs font-semibold uppercase tracking-wide text-warning">
                Estimated range
            </p>
            <p className="mt-1 font-display text-xl font-semibold text-heading">
                {formatMoney(low, currency)} – {formatMoney(high, currency)}
            </p>
            {effectiveHalfWidth !== null && (
                <p className="mt-1 text-xs text-muted">
                    Disclosed uncertainty band: ±{effectiveHalfWidth}% around the point estimate.
                </p>
            )}
            {Array.isArray(effectiveTriggers) && effectiveTriggers.length > 0 && (
                <ul className="mt-2 space-y-1">
                    {effectiveTriggers.map((trigger) => (
                        <li key={trigger} className="flex items-start gap-1.5 text-xs text-body">
                            <span aria-hidden="true" className="mt-[7px] size-1 shrink-0 rounded-full bg-warning" />
                            {trigger}
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-2 text-xs text-muted">
                A range is shown because the evidence is uncertain — it is not a guaranteed bill.
            </p>
        </div>
    )
}
