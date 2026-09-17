import { ArrowRight, Check, FileCheck2, UploadCloud } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { ProvenanceBadge } from '../bills/ProvenanceBadge'
import { FIELD_LABEL, formatBytes, formatFieldValue } from '../bills/presentation'
import { IllustrativeChip } from './IllustrativeChip'
import { cn } from '../../lib/utils'

/**
 * BillShowcase (O1 refinement §2–§4) — a PRESENTATION-ONLY preview of
 * the real B4 Bills workspace, integrated into the solar-vs-consumption
 * story (bills supply the household side of the comparison).
 *
 * Zero interactivity by design: no file input, no drop handlers, no
 * upload callback, no API call, no state. The visual language is
 * deliberately the workspace's own — same Card primitives, the same
 * dashed dropzone treatment and copy rhythm as BillUploadPanel, the
 * same ProvenanceBadge + FIELD_LABEL/formatFieldValue vocabulary from
 * features/bills/presentation.js — so a visitor recognizes the exact
 * workflow they will use inside SPMS. Sample values are fixed, clearly
 * illustrative, and use one USER_CORRECTION example to show the
 * verify-before-trusting principle.
 */

const SAMPLE_FILE = { name: 'aug-2026-bill.pdf', sizeBytes: 421_888 }

const SAMPLE_VALUES = {
    billing_period_start: '2026-08-01',
    billing_period_end: '2026-09-01',
    consumption_kwh: '145.000',
    solar_units_kwh: '322.000',
    net_units_kwh: '-177.000',
    bill_amount: '2437.00',
    fixed_charges: '30.00',
}

const SAMPLE_PROVENANCE = {
    billing_period_start: { source: 'AI_EXTRACTION' },
    billing_period_end: { source: 'AI_EXTRACTION' },
    consumption_kwh: { source: 'AI_EXTRACTION' },
    solar_units_kwh: { source: 'AI_EXTRACTION' },
    net_units_kwh: { source: 'USER_CORRECTION' },
    bill_amount: { source: 'AI_EXTRACTION' },
    fixed_charges: { source: 'AI_EXTRACTION' },
}

const SAMPLE_FIELDS = [
    'billing_period_start',
    'billing_period_end',
    'consumption_kwh',
    'solar_units_kwh',
    'net_units_kwh',
    'bill_amount',
    'fixed_charges',
]

const CONTEXT_POINTS = [
    'The billing period the numbers actually cover',
    'Household consumption — the home side of the day',
    'Cost information, kept separate from energy quantities',
    'A verified history SPMS can learn patterns from',
]

const PIPELINE = ['Upload', 'Extract', 'Verify', 'Trusted history']

/** Same row language as the workspace's BillInformationSections. */
function SampleFieldRow({ field }) {
    const meta = FIELD_LABEL[field]
    const formatted = formatFieldValue(field, SAMPLE_VALUES[field])
    const display = formatted !== null && meta?.unit ? `${formatted} ${meta.unit}` : formatted
    const source = SAMPLE_PROVENANCE[field]?.source ?? null

    return (
        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
            <dt className="text-sm text-muted">{meta?.label ?? field.replace(/_/g, ' ')}</dt>
            <dd className="flex items-center gap-2">
                <span className="font-display text-sm font-semibold text-heading">{display}</span>
                {source && <ProvenanceBadge source={source} />}
            </dd>
        </div>
    )
}

export function BillShowcase() {
    return (
        <div className="mt-14">
            <IllustrativeChip className="mx-auto w-fit" label="Product preview — nothing here uploads, processes, or saves" />
            <h3 className="mx-auto mt-4 max-w-2xl text-center font-display text-xl font-semibold text-heading sm:text-2xl">
                Your electricity bill gives SPMS the household context solar data alone cannot
                provide.
            </h3>
            <p className="mx-auto mt-3 max-w-2xl text-center text-sm text-body sm:text-base">
                Solar readings show what your panels delivered. The bill reveals what the home
                actually used, over which period, and at what cost — together they are what the
                comparison above is built from.
            </p>

            <Card className="mx-auto mt-8 max-w-4xl">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <UploadCloud className="size-5 text-secondary" aria-hidden="true" />
                        Upload your electricity bill
                    </CardTitle>
                    <CardDescription>
                        This is the Bills workspace as you will meet it inside SPMS. PDF, JPG or
                        PNG — and every value stays a draft until you verify it.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="flex flex-col gap-3">
                            <div
                                aria-hidden="true"
                                className="flex grow flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed border-border bg-surface-alt/50 px-6 py-8 text-center"
                            >
                                <UploadCloud className="size-6 text-muted" aria-hidden="true" />
                                <p className="text-sm text-body">Drag your bill here, or</p>
                                <Button type="button" variant="secondary" disabled>
                                    Choose file
                                </Button>
                                <p className="text-xs text-muted">
                                    Inside SPMS, the server checks the file — this page only shows
                                    what that looks like.
                                </p>
                            </div>
                            <p aria-hidden="true" className="flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-3 text-sm text-body">
                                <FileCheck2 className="size-4 shrink-0 text-primary" />
                                <span className="truncate font-medium">{SAMPLE_FILE.name}</span>
                                <span className="shrink-0 text-xs text-muted">{formatBytes(SAMPLE_FILE.sizeBytes)}</span>
                            </p>
                        </div>

                        <div aria-label="Extracted bill information, illustrative sample" role="group">
                            <p className="flex items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
                                Extracted information
                                <span className="rounded-full border border-warning/40 bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning">
                                    Draft — awaiting your verification
                                </span>
                            </p>
                            <dl className="mt-2 flex flex-col">
                                {SAMPLE_FIELDS.map((field) => (
                                    <SampleFieldRow key={field} field={field} />
                                ))}
                            </dl>
                            <p className="mt-3 text-xs text-muted">
                                Example values for presentation only — not real household data.
                                Corrections like the one above are always yours to make.
                            </p>
                        </div>
                    </div>

                    <ol
                        aria-label="From bill upload to trusted household history"
                        className="mt-6 flex flex-wrap items-center justify-center gap-x-2 gap-y-2 border-t border-border pt-6"
                    >
                        {PIPELINE.map((stage, index) => (
                            <li key={stage} className="flex items-center gap-2">
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold',
                                        index === PIPELINE.length - 1
                                            ? 'border-primary/40 bg-primary-soft text-primary-active'
                                            : 'border-border bg-surface-alt text-body',
                                    )}
                                >
                                    {index === 2 && <Check className="size-3" aria-hidden="true" />}
                                    {stage}
                                </span>
                                {index < PIPELINE.length - 1 && (
                                    <ArrowRight className="size-3.5 text-muted" aria-hidden="true" />
                                )}
                            </li>
                        ))}
                    </ol>
                </CardContent>
            </Card>

            <ul className="mx-auto mt-6 grid max-w-3xl gap-2 sm:grid-cols-2" aria-label="What bill information contributes">
                {CONTEXT_POINTS.map((point) => (
                    <li key={point} className="flex items-start gap-2 text-xs text-muted">
                        <Check className="mt-0.5 size-3.5 shrink-0 text-primary" aria-hidden="true" />
                        {point}
                    </li>
                ))}
            </ul>
        </div>
    )
}
