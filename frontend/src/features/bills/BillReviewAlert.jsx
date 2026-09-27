import { CircleCheck, CircleDashed, TriangleAlert } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Disclosure } from './Disclosure'
import {
    FIELD_LABEL,
    QUALITY_MESSAGE,
    VALIDATION_RESULT_TEXT,
    VALIDATION_RULE,
    VALIDATION_STATUS_ICON,
    formatEnergy,
    formatFieldValue,
} from './presentation'
import { cn } from '../../lib/utils'

/**
 * B4 §9/§10 — the NEEDS_REVIEW treatment.
 *
 * Calm but visible: a warning banner (never danger-red — a conflict is
 * a review obligation, not a broken bill), one item per affected field
 * with the PERSISTED value shown verbatim (+177 stays +177 — this file
 * has no path to change it), plain-language explanations up front and
 * the extracted-vs-expected numbers behind a "Why does this need
 * review?" disclosure for anyone who wants the technical picture.
 */

function valueText(field, raw) {
    const meta = FIELD_LABEL[field]
    const formatted = field !== null && meta ? formatFieldValue(field, raw) : formatEnergy(raw)
    if (formatted === null) {
        return null
    }
    return meta?.unit ? `${formatted} ${meta.unit}` : formatted
}

function ConflictItem({ validation, currentValue }) {
    const rule = VALIDATION_RULE[validation.rule]
    const field = rule?.field ?? null
    const shown = field ? valueText(field, currentValue) : null

    return (
        <li className="flex flex-col gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <p className="font-medium text-heading">{field ? FIELD_LABEL[field].label : rule?.label ?? 'A value'}</p>
                {shown !== null && (
                    <p className="font-display text-lg font-semibold leading-none text-heading">
                        {shown}
                        <span className="ml-2 rounded-full border border-warning/50 px-2 py-0.5 font-body text-xs font-semibold text-warning">
                            Review needed
                        </span>
                    </p>
                )}
            </div>
            <p className="text-sm text-body">
                SPMS found a difference between this value and the other readings on the bill.
            </p>
            <div className="text-sm">
                <Disclosure label="Why does this need review?">
                    <dl className="space-y-1.5 text-sm">
                        <div className="flex gap-2">
                            <dt className="text-muted">Extracted value:</dt>
                            <dd className="font-medium text-heading">
                                {valueText(field, validation.extracted_value) ?? 'Not available'}
                            </dd>
                        </div>
                        <div className="flex gap-2">
                            <dt className="text-muted">Expected from bill readings:</dt>
                            <dd className="font-medium text-heading">
                                {valueText(field, validation.expected_value) ?? 'Not available'}
                            </dd>
                        </div>
                        <div className="flex gap-2">
                            <dt className="text-muted">Check:</dt>
                            <dd className="font-medium text-heading">
                                {rule?.label ?? validation.rule} — conflict (the two values differ)
                            </dd>
                        </div>
                    </dl>
                    <p className="mt-2 text-xs text-muted">
                        SPMS reports the difference — it never changes the value on your bill.
                    </p>
                </Disclosure>
            </div>
        </li>
    )
}

function FlagItem({ flag }) {
    const message = QUALITY_MESSAGE[flag.code] ?? 'Some information on this bill needs your attention'
    const fieldLabel = flag.field_name ? FIELD_LABEL[flag.field_name]?.label : null

    return (
        <li className="flex items-start gap-2 rounded-md border border-border bg-surface-alt px-4 py-3 text-sm text-body">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <span>
                {message}
                {fieldLabel ? ` (${fieldLabel.toLowerCase()})` : ''}
            </span>
        </li>
    )
}

export function BillReviewAlert({ detail }) {
    const conflicts = (detail.validations ?? []).filter((v) => v.status === 'CONFLICT')
    const flags = detail.quality_flags ?? []

    return (
        <section aria-labelledby="review-alert-title">
            <Card className="border-warning/40 bg-warning/5">
                <CardHeader>
                    <CardTitle id="review-alert-title" className="flex items-center gap-2 text-warning">
                        <TriangleAlert className="size-5" aria-hidden="true" />
                        Review needed
                    </CardTitle>
                    <CardDescription>
                        Some information from this bill needs your attention before it can be verified.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul className="flex flex-col gap-3">
                        {conflicts.map((validation) => (
                            <ConflictItem
                                key={validation.rule}
                                validation={validation}
                                currentValue={
                                    VALIDATION_RULE[validation.rule]?.field
                                        ? detail.values[VALIDATION_RULE[validation.rule].field]
                                        : null
                                }
                            />
                        ))}
                        {flags.map((flag) => (
                            <FlagItem key={`${flag.code}:${flag.field_name ?? '-'}`} flag={flag} />
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </section>
    )
}

/**
 * Deterministic validation outcomes (B4 §10, refinement §4-§9).
 *
 * The authoritative statuses arrive from the backend read model — this
 * component only translates and groups them. PASS is a subtle positive,
 * CONFLICT rides the amber review treatment (never danger-red), and
 * NOT_CHECKED stays neutral grey; the three states are the complete
 * vocabulary. Passing a check means "consistent with the other
 * readings", never "verified" — verification stays a separate explicit
 * step (B5).
 */
export function BillValidationSummary({ validations }) {
    if (!validations || validations.length === 0) {
        return null
    }

    const passed = validations.filter((v) => v.status === 'PASS').length
    const conflicts = validations.filter((v) => v.status === 'CONFLICT').length
    const notChecked = validations.filter((v) => v.status === 'NOT_CHECKED').length

    let icon = CircleCheck
    let tone = 'text-primary'
    let headline = 'Validation checks passed'
    let support = 'The extracted values are internally consistent. Please review them before verification.'
    if (conflicts > 0) {
        icon = TriangleAlert
        tone = 'text-warning'
        headline = `${conflicts} ${conflicts === 1 ? 'check needs' : 'checks need'} review`
        support = 'SPMS found a difference between related readings. Please review it before verification.'
    } else if (notChecked > 0) {
        icon = CircleDashed
        tone = 'text-muted'
        headline =
            passed > 0
                ? `${passed} ${passed === 1 ? 'check passed' : 'checks passed'} · ${notChecked} not checked`
                : 'No checks could run on this bill'
        support = 'SPMS compares readings only when the values a check needs are present. Nothing has been assumed.'
    }
    const HeadlineIcon = icon

    return (
        <Card aria-labelledby="bill-validation-title">
            <CardHeader>
                <CardTitle id="bill-validation-title">Bill validation</CardTitle>
                <CardDescription>
                    SPMS checks related readings from your bill to identify inconsistencies before verification.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex items-start gap-2">
                    <HeadlineIcon className={cn('mt-0.5 size-4 shrink-0', tone)} aria-hidden="true" />
                    <div>
                        <p className={cn('text-sm font-semibold', tone)}>{headline}</p>
                        <p className="mt-0.5 text-sm text-body">{support}</p>
                    </div>
                </div>
                <ul className="mt-4 flex flex-col gap-2">
                    {validations.map((validation) => {
                        const rule = VALIDATION_RULE[validation.rule]
                        const Icon = VALIDATION_STATUS_ICON[validation.status] ?? CircleDashed
                        const statusTone =
                            validation.status === 'PASS'
                                ? 'text-primary'
                                : validation.status === 'CONFLICT'
                                  ? 'text-warning'
                                  : 'text-muted'
                        const result =
                            VALIDATION_RESULT_TEXT[validation.status] ??
                            (validation.status === 'CONFLICT' ? 'Needs review' : 'Not checked')

                        return (
                            <li key={validation.rule} className="flex items-start gap-2">
                                <Icon className={cn('mt-0.5 size-4 shrink-0', statusTone)} aria-hidden="true" />
                                <div>
                                    <p className="text-sm font-medium text-heading">
                                        {rule?.short ?? validation.rule.replace(/_/g, ' ')}
                                    </p>
                                    <p className={cn('text-sm', statusTone)}>
                                        {validation.status === 'NOT_CHECKED'
                                            ? validation.reason_code === 'BEFORE_SOLAR_INSTALLATION'
                                                ? 'Solar not active / not recorded for this billing period'
                                                : (rule?.notChecked ?? result)
                                            : result}
                                    </p>
                                </div>
                            </li>
                        )
                    })}
                </ul>
                <p className="mt-4 text-xs text-muted">
                    Consistent values still need your review — these checks compare the readings on the bill with
                    each other, they do not verify it.
                </p>
            </CardContent>
        </Card>
    )
}
