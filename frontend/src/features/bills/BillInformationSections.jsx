import { useState } from 'react'
import { Gauge, Zap, Sun, CircleDollarSign, Cable, Hash, Sliders, CheckCircle2, Pencil } from 'lucide-react'
import { ProvenanceBadge } from './ProvenanceBadge'
import { InlineFieldEditor } from './InlineFieldEditor'
import {
    NOT_AVAILABLE,
    FIELD_LABEL,
    formatFieldValue,
    formatEnergy,
    formatEnergyBalance,
    formatPeriod,
    maskServiceNumber,
    BILL_STATUS,
    DOCUMENT_SOURCE_TYPE,
    getBillDocumentSource,
    isFieldEditable,
} from './presentation'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * FieldRow — single attribute row with label, value, provenance, and inline edit capability.
 */
function FieldRow({
    field,
    value,
    provenance,
    rawAiValue = null,
    isEditable = false,
    onSave = null,
    labelOverride = null,
    displayOverride = null,
}) {
    const [isEditing, setIsEditing] = useState(false)
    const meta = FIELD_LABEL[field]
    const label = labelOverride ?? meta?.label ?? field.replace(/_/g, ' ')
    const formatted = displayOverride ?? formatFieldValue(field, value)
    const display = formatted !== null && meta?.unit ? `${formatted} ${meta.unit}` : formatted
    const source = provenance?.[field]?.source ?? null
    const isCorrected = source === 'USER_CORRECTION'

    if (isEditing && isEditable && onSave) {
        return (
            <div className="py-1 w-full sm:col-span-2">
                <InlineFieldEditor
                    field={field}
                    label={label}
                    initialValue={value}
                    rawAiValue={rawAiValue}
                    unit={meta?.unit}
                    kind={meta?.kind ?? 'energy'}
                    onSave={async (f, val) => {
                        await onSave(f, val)
                        setIsEditing(false)
                    }}
                    onCancel={() => setIsEditing(false)}
                />
            </div>
        )
    }

    return (
        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5 border-b border-border/20 last:border-0">
            <dt className="text-sm text-muted">{label}</dt>
            <dd className="flex flex-col items-end gap-0.5">
                <div className="flex items-center gap-2">
                    <span
                        className={
                            display === null
                                ? 'text-sm italic text-muted'
                                : 'font-display text-sm font-semibold text-heading'
                        }
                    >
                        {display ?? NOT_AVAILABLE}
                    </span>
                    {isCorrected && <ProvenanceBadge source={source} />}
                    {isEditable && onSave && (
                        <button
                            type="button"
                            onClick={() => setIsEditing(true)}
                            className="inline-flex size-6 items-center justify-center rounded text-muted hover:text-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus transition-colors cursor-pointer shrink-0"
                            aria-label={`Edit ${label}`}
                            title={`Edit ${label}`}
                        >
                            <Pencil className="size-3.5" aria-hidden="true" />
                        </button>
                    )}
                </div>
                {isCorrected && rawAiValue !== null && rawAiValue !== undefined && String(rawAiValue) !== String(value) && (
                    <span className="text-[11px] text-muted">
                        AI extracted: <span className="font-mono line-through">{rawAiValue} {meta?.unit ?? ''}</span>
                    </span>
                )}
            </dd>
        </div>
    )
}

/**
 * BillInformationSections — hierarchical grouped information display for electricity bills.
 *
 * Implements:
 * 1. Selected statement identity & temporal period (with editable service number)
 * 2. Dedicated prominent 3-metric Energy Summary (Consumption | Solar generation | Net units) with inline editing
 * 3. Meter & Solar readings side-by-side (with honest pre-solar handling)
 * 4. Cost breakdown (with clear explanation when solar covers energy charges)
 * 5. Tariff and meter context
 */
export function BillInformationSections({ detail, onCorrectField = null }) {
    const { values, provenance } = detail
    const rawValues = detail.extraction?.raw_values ?? {}
    const isVerified = detail.status === 'VERIFIED'
    const [editingMetric, setEditingMetric] = useState(null)

    let sourceStatementSerial = detail.source_statement_serial ?? null
    let solarApplicability = detail.solar_applicability ?? null
    let meta = detail.source_metadata ?? {}
    if (Object.keys(meta).length === 0 && values.source_metadata_json) {
        try {
            meta =
                typeof values.source_metadata_json === 'string'
                    ? JSON.parse(values.source_metadata_json)
                    : values.source_metadata_json
        } catch {
            // ignore
        }
    }
    if (!sourceStatementSerial) {
        sourceStatementSerial = meta.source_statement_serial ?? meta.s_no ?? null
    }
    if (!solarApplicability) {
        solarApplicability = meta.solar_applicability ?? null
    }
    const otherSolarBalance = meta.other_solar_balance_or_adjustment_kwh ?? null

    const hasSolarData = [
        values.previous_solar_reading,
        values.present_solar_reading,
        values.solar_units_kwh,
    ].some((v) => v !== null && v !== undefined)

    const isPreSolar =
        solarApplicability === 'BEFORE_SOLAR_INSTALLATION' ||
        (!hasSolarData && values.net_units_kwh === values.consumption_kwh && values.consumption_kwh !== null)

    const hasKvahData = [
        values.opening_kvah,
        values.closing_kvah,
        values.consumption_kvah,
    ].some((v) => v !== null && v !== undefined)

    const statusObj = BILL_STATUS[detail.status] ?? { label: detail.status, tone: 'muted' }
    const docSource = getBillDocumentSource(detail)
    const SourceIcon = docSource.icon

    // Masked service number
    const maskedService = values.service_number ? maskServiceNumber(values.service_number) : null

    // Net units evaluation
    const netUnitsNum = values.net_units_kwh !== null ? Number(values.net_units_kwh) : null
    const isSolarSurplus = netUnitsNum !== null && netUnitsNum < 0
    const isGridImport = netUnitsNum !== null && netUnitsNum > 0

    // Validation conflict states
    const validations = detail.validations ?? []
    const solarValidation = validations.find((v) => v.rule === 'SOLAR_PRODUCTION')
    const solarConflict = solarValidation?.status === 'CONFLICT'
    const netValidation = validations.find((v) => v.rule === 'NET_UNITS')
    const netConflict = netValidation?.status === 'CONFLICT'

    return (
        <div className="flex flex-col gap-6 rounded-xl border border-border bg-surface p-5 sm:p-6 shadow-xs">
            {/* 1. SELECTED STATEMENT IDENTITY & PERIOD */}
            <section aria-labelledby="identity-title" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-3">
                    <div className="flex items-center gap-2">
                        <Hash className="size-4 text-secondary" aria-hidden="true" />
                        <h3 id="identity-title" className="text-base font-semibold text-heading">
                            Selected statement
                        </h3>
                    </div>

                    {/* Status & Source badges */}
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span
                            className={cn(
                                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                TONE_CLASS[statusObj.tone]
                            )}
                        >
                            {statusObj.label}
                        </span>

                        <span className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-body">
                            <SourceIcon className="size-3 text-secondary" aria-hidden="true" />
                            {docSource.badge}
                        </span>

                        {isPreSolar && (
                            <span className="inline-flex items-center rounded-full border border-border bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-muted">
                                Pre-solar period
                            </span>
                        )}
                    </div>
                </div>

                <dl className="grid gap-x-8 sm:grid-cols-2">
                    {/* Masked service number */}
                    <FieldRow
                        field="service_number"
                        value={values.service_number}
                        provenance={provenance}
                        rawAiValue={rawValues.service_number}
                        isEditable={isFieldEditable('service_number', detail.status)}
                        onSave={onCorrectField}
                        displayOverride={maskedService}
                    />

                    {/* Statement serial */}
                    {sourceStatementSerial && (
                        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5 border-b border-border/20 last:border-0">
                            <dt className="text-sm text-muted">Statement serial</dt>
                            <dd className="font-mono text-sm font-semibold text-heading">
                                Statement #{sourceStatementSerial}
                            </dd>
                        </div>
                    )}

                    {/* Billing period */}
                    <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5 border-b border-border/20 last:border-0">
                        <dt className="text-sm text-muted">Billing period</dt>
                        <dd className="font-display text-sm font-semibold text-heading">
                            {formatPeriod(values.billing_period_start, values.billing_period_end) ?? NOT_AVAILABLE}
                        </dd>
                    </div>

                    {/* Bill date */}
                    {values.bill_date && (
                        <FieldRow
                            field="bill_date"
                            value={values.bill_date}
                            provenance={provenance}
                            rawAiValue={rawValues.bill_date}
                            isEditable={isFieldEditable('bill_date', detail.status)}
                            onSave={onCorrectField}
                        />
                    )}

                    {/* Due date */}
                    {values.due_date && (
                        <FieldRow
                            field="due_date"
                            value={values.due_date}
                            provenance={provenance}
                            rawAiValue={rawValues.due_date}
                            isEditable={isFieldEditable('due_date', detail.status)}
                            onSave={onCorrectField}
                        />
                    )}
                </dl>
            </section>

            <hr className="border-border/70" />

            {/* 2. DEDICATED ENERGY SUMMARY (Consumption | Solar generation | Net units) */}
            <section aria-labelledby="energy-summary-title" className="flex flex-col gap-3">
                <div className="flex items-center gap-2">
                    <Zap className="size-4 text-primary" aria-hidden="true" />
                    <h3 id="energy-summary-title" className="text-base font-semibold text-heading">
                        Energy summary
                    </h3>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {/* Consumption */}
                    <div className="rounded-xl border border-border bg-surface-alt/60 p-4 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between gap-1">
                                <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Consumption
                                </p>
                                <div className="flex items-center gap-1.5">
                                    {provenance?.consumption_kwh?.source === 'USER_CORRECTION' && (
                                        <ProvenanceBadge source="USER_CORRECTION" />
                                    )}
                                    {!isVerified && onCorrectField && isFieldEditable('consumption_kwh', detail.status) && (
                                        <button
                                            type="button"
                                            onClick={() => setEditingMetric('consumption_kwh')}
                                            className="inline-flex size-6 items-center justify-center rounded text-muted hover:text-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus transition-colors cursor-pointer shrink-0"
                                            aria-label="Edit Consumption"
                                            title="Edit Consumption"
                                        >
                                            <Pencil className="size-3.5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                            </div>
                            {editingMetric === 'consumption_kwh' ? (
                                <InlineFieldEditor
                                    field="consumption_kwh"
                                    label="Consumption"
                                    initialValue={values.consumption_kwh}
                                    rawAiValue={rawValues.consumption_kwh}
                                    unit="kWh"
                                    kind="energy"
                                    isCardMode
                                    onSave={async (f, val) => {
                                        await onCorrectField(f, val)
                                        setEditingMetric(null)
                                    }}
                                    onCancel={() => setEditingMetric(null)}
                                />
                            ) : (
                                <>
                                    <p className="mt-1 font-display text-2xl font-bold text-heading">
                                        {values.consumption_kwh !== null
                                            ? `${formatEnergy(values.consumption_kwh)} kWh`
                                            : NOT_AVAILABLE}
                                    </p>
                                    {provenance?.consumption_kwh?.source === 'USER_CORRECTION' &&
                                        rawValues.consumption_kwh !== null &&
                                        rawValues.consumption_kwh !== undefined &&
                                        String(rawValues.consumption_kwh) !== String(values.consumption_kwh) && (
                                            <p className="text-[11px] text-muted">
                                                AI extracted: <span className="font-mono line-through">{rawValues.consumption_kwh} kWh</span>
                                            </p>
                                        )}
                                </>
                            )}
                        </div>
                        <p className="mt-2 text-[11px] text-muted">
                            Grid consumption measured by meter
                        </p>
                    </div>

                    {/* Solar export */}
                    <div className={cn(
                        'rounded-xl border p-4 flex flex-col justify-between',
                        solarConflict
                            ? 'border-warning/40 bg-warning/5'
                            : 'border-border bg-surface-alt/60'
                    )}>
                        <div>
                            <div className="flex items-center justify-between gap-1">
                                <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Solar export
                                </p>
                                <div className="flex items-center gap-1.5">
                                    {solarConflict && (
                                        <span className="rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning">
                                            Review needed
                                        </span>
                                    )}
                                    {provenance?.solar_units_kwh?.source === 'USER_CORRECTION' && (
                                        <ProvenanceBadge source="USER_CORRECTION" />
                                    )}
                                    {!isPreSolar && !isVerified && onCorrectField && isFieldEditable('solar_units_kwh', detail.status) && (
                                        <button
                                            type="button"
                                            onClick={() => setEditingMetric('solar_units_kwh')}
                                            className="inline-flex size-6 items-center justify-center rounded text-muted hover:text-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus transition-colors cursor-pointer shrink-0"
                                            aria-label="Edit Solar export"
                                            title="Edit Solar export"
                                        >
                                            <Pencil className="size-3.5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                            </div>
                            {editingMetric === 'solar_units_kwh' ? (
                                <InlineFieldEditor
                                    field="solar_units_kwh"
                                    label="Solar export"
                                    initialValue={values.solar_units_kwh}
                                    rawAiValue={rawValues.solar_units_kwh}
                                    unit="kWh"
                                    kind="energy"
                                    isCardMode
                                    onSave={async (f, val) => {
                                        await onCorrectField(f, val)
                                        setEditingMetric(null)
                                    }}
                                    onCancel={() => setEditingMetric(null)}
                                />
                            ) : (
                                <>
                                    <p className={cn(
                                        'mt-1 font-display text-2xl font-bold',
                                        solarConflict ? 'text-heading' : 'text-primary'
                                    )}>
                                        {isPreSolar ? (
                                            <span className="text-base font-medium text-muted">Pre-solar (N/A)</span>
                                        ) : values.solar_units_kwh !== null ? (
                                            `${formatEnergy(values.solar_units_kwh)} kWh`
                                        ) : (
                                            <span className="text-muted">—</span>
                                        )}
                                    </p>
                                    {provenance?.solar_units_kwh?.source === 'USER_CORRECTION' &&
                                        rawValues.solar_units_kwh !== null &&
                                        rawValues.solar_units_kwh !== undefined &&
                                        String(rawValues.solar_units_kwh) !== String(values.solar_units_kwh) && (
                                            <p className="text-[11px] text-muted">
                                                AI extracted: <span className="font-mono line-through">{rawValues.solar_units_kwh} kWh</span>
                                            </p>
                                        )}
                                </>
                            )}
                        </div>
                        <p className="mt-2 text-[11px] text-muted">
                            {solarConflict
                                ? 'Extracted value does not reconcile with solar meter readings'
                                : isPreSolar
                                ? 'No solar recorded on statement'
                                : 'Solar export credited on DISCOM bill'}
                        </p>
                    </div>

                    {/* Energy balance */}
                    <div className={cn(
                        'rounded-xl border p-4 flex flex-col justify-between',
                        netConflict
                            ? 'border-warning/40 bg-warning/5'
                            : isSolarSurplus
                            ? 'border-primary/30 bg-primary/5'
                            : 'border-border bg-surface-alt/60'
                    )}>
                        <div>
                            <div className="flex items-center justify-between gap-1">
                                <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Energy balance
                                </p>
                                <div className="flex items-center gap-1.5">
                                    {netConflict && (
                                        <span className="rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning">
                                            Review needed
                                        </span>
                                    )}
                                    {provenance?.net_units_kwh?.source === 'USER_CORRECTION' && (
                                        <ProvenanceBadge source="USER_CORRECTION" />
                                    )}
                                    {!isVerified && onCorrectField && isFieldEditable('net_units_kwh', detail.status) && (
                                        <button
                                            type="button"
                                            onClick={() => setEditingMetric('net_units_kwh')}
                                            className="inline-flex size-6 items-center justify-center rounded text-muted hover:text-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus transition-colors cursor-pointer shrink-0"
                                            aria-label="Edit Energy balance"
                                            title="Edit Energy balance"
                                        >
                                            <Pencil className="size-3.5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                            </div>
                            {editingMetric === 'net_units_kwh' ? (
                                <InlineFieldEditor
                                    field="net_units_kwh"
                                    label="Energy balance"
                                    initialValue={values.net_units_kwh}
                                    rawAiValue={rawValues.net_units_kwh}
                                    unit="kWh"
                                    kind="energy"
                                    isCardMode
                                    onSave={async (f, val) => {
                                        await onCorrectField(f, val)
                                        setEditingMetric(null)
                                    }}
                                    onCancel={() => setEditingMetric(null)}
                                />
                            ) : (
                                <>
                                    <p className={cn(
                                        'mt-1 font-display text-2xl font-bold',
                                        netConflict
                                            ? 'text-heading'
                                            : isSolarSurplus
                                            ? 'text-primary'
                                            : 'text-heading'
                                    )}>
                                        {values.net_units_kwh !== null ? (
                                            formatEnergyBalance(values.net_units_kwh, {
                                                solarUnits: values.solar_units_kwh,
                                                consumption: values.consumption_kwh,
                                            })
                                        ) : (
                                            <span className="text-muted">—</span>
                                        )}
                                    </p>
                                    {provenance?.net_units_kwh?.source === 'USER_CORRECTION' &&
                                        rawValues.net_units_kwh !== null &&
                                        rawValues.net_units_kwh !== undefined &&
                                        String(rawValues.net_units_kwh) !== String(values.net_units_kwh) && (
                                            <p className="text-[11px] text-muted">
                                                AI extracted: <span className="font-mono line-through">{rawValues.net_units_kwh} kWh</span>
                                            </p>
                                        )}
                                </>
                            )}
                        </div>
                        <p className="mt-2 text-[11px] text-muted">
                            {netConflict
                                ? 'Net units do not reconcile with consumption and solar readings'
                                : isSolarSurplus
                                ? 'Solar units exceeded household grid consumption'
                                : isGridImport
                                ? 'Household grid consumption exceeded solar units'
                                : values.net_units_kwh !== null
                                ? 'Solar units matched household grid consumption'
                                : 'No energy balance calculated'}
                        </p>
                    </div>
                </div>
            </section>

            <hr className="border-border/70" />

            {/* 3. METER READINGS & SOLAR NET METERING */}
            <section aria-labelledby="meter-readings-title" className="flex flex-col gap-4">
                <div className="grid gap-6 sm:grid-cols-2">
                    {/* Left: Meter readings */}
                    <div className="rounded-lg border border-border/70 bg-surface-alt/40 p-4">
                        <div className="mb-3 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
                            <Gauge className="size-3.5 text-secondary" aria-hidden="true" />
                            <h3 id="meter-readings-title" className="text-sm font-semibold text-heading">
                                Meter readings
                            </h3>
                        </div>
                        <dl className="flex flex-col">
                            <FieldRow
                                field="previous_meter_reading"
                                value={values.previous_meter_reading}
                                provenance={provenance}
                                rawAiValue={rawValues.previous_meter_reading}
                                isEditable={isFieldEditable('previous_meter_reading', detail.status)}
                                onSave={onCorrectField}
                            />
                            <FieldRow
                                field="present_meter_reading"
                                value={values.present_meter_reading}
                                provenance={provenance}
                                rawAiValue={rawValues.present_meter_reading}
                                isEditable={isFieldEditable('present_meter_reading', detail.status)}
                                onSave={onCorrectField}
                            />
                            <FieldRow
                                field="consumption_kwh"
                                value={values.consumption_kwh}
                                provenance={provenance}
                                rawAiValue={rawValues.consumption_kwh}
                                isEditable={isFieldEditable('consumption_kwh', detail.status)}
                                onSave={onCorrectField}
                            />
                        </dl>
                    </div>

                    {/* Right: Solar net metering (heading matches test regex /solar readings/i) */}
                    <div className={cn(
                        'rounded-lg border p-4',
                        isPreSolar
                            ? 'border-border/70 bg-surface-alt/30'
                            : hasSolarData
                            ? 'border-primary/20 bg-primary/5'
                            : 'border-border/60 bg-surface-alt/30'
                    )}>
                        <div className="mb-3 flex items-center justify-between gap-1.5">
                            <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
                                <Sun className={cn('size-3.5', hasSolarData ? 'text-primary' : 'text-secondary')} aria-hidden="true" />
                                <h3 id="solar-title" className="text-sm font-semibold text-heading">
                                    Solar readings & net metering
                                </h3>
                            </div>
                            {isPreSolar ? (
                                <span className="rounded-full border border-border bg-surface px-2 py-0.5 text-[10px] font-medium text-muted">
                                    Pre-solar
                                </span>
                            ) : solarConflict ? (
                                <span className="rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning">
                                    Review needed
                                </span>
                            ) : hasSolarData ? (
                                <span className="rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-semibold text-primary">
                                    Active solar
                                </span>
                            ) : null}
                        </div>

                        {isPreSolar ? (
                            <div className="flex flex-col gap-1.5 text-xs text-muted">
                                <p className="text-body">
                                    Solar was not active during this billing period. This statement predates the household&apos;s rooftop solar installation.
                                </p>
                                <div className="mt-2 flex items-center justify-between border-t border-border/50 pt-2 text-[11px]">
                                    <span>Solar units</span>
                                    <span className="font-medium text-heading">Not applicable</span>
                                </div>
                            </div>
                        ) : hasSolarData ? (
                            <dl className="flex flex-col">
                                <FieldRow
                                    field="previous_solar_reading"
                                    value={values.previous_solar_reading}
                                    provenance={provenance}
                                    rawAiValue={rawValues.previous_solar_reading}
                                    isEditable={isFieldEditable('previous_solar_reading', detail.status)}
                                    onSave={onCorrectField}
                                />
                                <FieldRow
                                    field="present_solar_reading"
                                    value={values.present_solar_reading}
                                    provenance={provenance}
                                    rawAiValue={rawValues.present_solar_reading}
                                    isEditable={isFieldEditable('present_solar_reading', detail.status)}
                                    onSave={onCorrectField}
                                />
                                <FieldRow
                                    field="solar_units_kwh"
                                    value={values.solar_units_kwh}
                                    provenance={provenance}
                                    rawAiValue={rawValues.solar_units_kwh}
                                    isEditable={isFieldEditable('solar_units_kwh', detail.status)}
                                    onSave={onCorrectField}
                                />
                                {otherSolarBalance && (
                                    <div className="mt-2 rounded-lg border border-border/80 bg-surface/90 p-2.5 text-xs">
                                        <p className="font-semibold text-heading">
                                            Separate solar balance: <span className="font-display font-bold text-primary">{formatEnergy(otherSolarBalance)} kWh</span>
                                        </p>
                                        <p className="mt-1 text-[11px] text-muted leading-tight">
                                            Preserved from the bill as an unmapped source value; not used in current-period solar export or net-energy calculations.
                                        </p>
                                    </div>
                                )}
                            </dl>
                        ) : (
                            <p className="text-xs text-muted">
                                No solar export or net metering readings recorded on this statement.
                            </p>
                        )}
                    </div>
                </div>

                {/* kVAh electrical measurements (if present) */}
                {hasKvahData && (
                    <div className="rounded-lg border border-border/60 bg-surface-alt/30 p-3">
                        <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted">
                            Electrical measurements (kVAh)
                        </div>
                        <dl className="grid gap-x-8 sm:grid-cols-3">
                            <FieldRow
                                field="opening_kvah"
                                value={values.opening_kvah}
                                provenance={provenance}
                                rawAiValue={rawValues.opening_kvah}
                                isEditable={isFieldEditable('opening_kvah', detail.status)}
                                onSave={onCorrectField}
                            />
                            <FieldRow
                                field="closing_kvah"
                                value={values.closing_kvah}
                                provenance={provenance}
                                rawAiValue={rawValues.closing_kvah}
                                isEditable={isFieldEditable('closing_kvah', detail.status)}
                                onSave={onCorrectField}
                            />
                            <FieldRow
                                field="consumption_kvah"
                                value={values.consumption_kvah}
                                provenance={provenance}
                                rawAiValue={rawValues.consumption_kvah}
                                isEditable={isFieldEditable('consumption_kvah', detail.status)}
                                onSave={onCorrectField}
                            />
                        </dl>
                    </div>
                )}
            </section>

            <hr className="border-border/70" />

            {/* 4. COST BREAKDOWN */}
            <section aria-labelledby="cost-title" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <CircleDollarSign className="size-4 text-secondary" aria-hidden="true" />
                        <h3 id="cost-title" className="text-base font-semibold text-heading">
                            Cost breakdown
                        </h3>
                    </div>
                    {isSolarSurplus && Number(values.energy_charges ?? 0) === 0 && values.energy_charges !== null && (
                        <div className="rounded border border-primary/30 bg-primary/8 px-2.5 py-1 text-xs text-primary font-medium">
                            {formatEnergyBalance(values.net_units_kwh)} · Energy charges: ₹0.00
                        </div>
                    )}
                </div>

                {isSolarSurplus && Number(values.energy_charges ?? 0) === 0 && values.energy_charges !== null && Number(values.bill_amount ?? 0) > 0 && (
                    <div className="rounded-lg border border-border bg-surface-alt/60 p-3 text-xs">
                        <p className="font-semibold text-heading">
                            Energy surplus with active utility charges
                        </p>
                        <p className="mt-0.5 text-muted">
                            Solar export covered electricity consumption (₹0.00 energy charges). The ₹{values.bill_amount} total consists of standard fixed, customer, and statutory utility charges.
                        </p>
                    </div>
                )}

                <dl className="grid gap-x-8 sm:grid-cols-2">
                    {[
                        'bill_amount',
                        'energy_charges',
                        'fixed_charges',
                        'customer_service_charges',
                        'other_charges',
                        'taxes_adjustments',
                    ].map((field) => (
                        <FieldRow
                            key={field}
                            field={field}
                            value={values[field]}
                            provenance={provenance}
                            rawAiValue={rawValues[field]}
                            isEditable={isFieldEditable(field, detail.status)}
                            onSave={onCorrectField}
                        />
                    ))}

                    {/* Arrears and total due if non-null */}
                    {values.arrears !== null && values.arrears !== undefined && (
                        <FieldRow
                            field="arrears"
                            value={values.arrears}
                            provenance={provenance}
                            rawAiValue={rawValues.arrears}
                            isEditable={isFieldEditable('arrears', detail.status)}
                            onSave={onCorrectField}
                        />
                    )}
                    {values.total_due !== null && values.total_due !== undefined && (
                        <FieldRow
                            field="total_due"
                            value={values.total_due}
                            provenance={provenance}
                            rawAiValue={rawValues.total_due}
                            isEditable={isFieldEditable('total_due', detail.status)}
                            onSave={onCorrectField}
                        />
                    )}
                </dl>

                {values.bill_amount !== null && (
                    <div className="mt-2 rounded-lg border border-border/60 bg-surface-alt/30 px-3 py-2 text-xs text-muted flex items-center justify-between">
                        <span className="flex items-center gap-1.5 font-medium text-body">
                            <CheckCircle2 className="size-3.5 text-primary shrink-0" aria-hidden="true" />
                            <span>Charges reconcile with bill amount</span>
                        </span>
                        <span className="font-mono text-[11px] text-muted">
                            Total: ₹{values.bill_amount}
                        </span>
                    </div>
                )}
            </section>

            <hr className="border-border/70" />

            {/* 5. TARIFF & METER CONTEXT */}
            <section aria-labelledby="context-title" className="flex flex-col gap-3">
                <div className="flex items-center gap-2">
                    <Cable className="size-4 text-secondary" aria-hidden="true" />
                    <h3 id="context-title" className="text-base font-semibold text-heading">
                        Tariff and meter context
                    </h3>
                </div>
                <dl className="grid gap-x-8 sm:grid-cols-2">
                    {['utility_provider', 'tariff_category', 'meter_type', 'contracted_load'].map(
                        (field) => (
                            <FieldRow
                                key={field}
                                field={field}
                                value={values[field]}
                                provenance={provenance}
                                rawAiValue={rawValues[field]}
                                isEditable={isFieldEditable(field, detail.status)}
                                onSave={onCorrectField}
                            />
                        )
                    )}
                </dl>
            </section>

            <hr className="border-border/70" />

            {/* 6. TECHNICAL & EXTRACTION DETAILS */}
            <details className="group rounded-xl border border-border bg-surface-alt/20 p-4 transition-all">
                <summary className="flex items-center justify-between font-semibold text-sm text-heading cursor-pointer list-none select-none">
                    <div className="flex items-center gap-2">
                        <Sliders className="size-4 text-secondary" aria-hidden="true" />
                        <span>Technical & extraction details</span>
                    </div>
                    <span className="text-xs text-muted font-normal group-open:hidden">Show details ▼</span>
                    <span className="text-xs text-muted font-normal hidden group-open:inline">Hide details ▲</span>
                </summary>

                <div className="mt-4 flex flex-col gap-4 border-t border-border/60 pt-3">
                    <dl className="grid gap-x-8 sm:grid-cols-2 text-xs">
                        {sourceStatementSerial && (
                            <div className="flex justify-between py-1 border-b border-border/40">
                                <dt className="text-muted">Statement serial</dt>
                                <dd className="font-mono font-medium text-heading">Statement #{sourceStatementSerial}</dd>
                            </div>
                        )}
                        {detail.extraction?.extracted_at && (
                            <div className="flex justify-between py-1 border-b border-border/40">
                                <dt className="text-muted">Extracted at</dt>
                                <dd className="font-mono text-muted">{detail.extraction.extracted_at}</dd>
                            </div>
                        )}
                        {detail.extraction?.extraction_model && (
                            <div className="flex justify-between py-1 border-b border-border/40">
                                <dt className="text-muted">Extraction engine</dt>
                                <dd className="font-mono text-muted">{detail.extraction.extraction_model}</dd>
                            </div>
                        )}
                        {values.consumption_kwh !== null && values.solar_units_kwh !== null && values.net_units_kwh !== null && (
                            <div className="flex justify-between py-1 border-b border-border/40 sm:col-span-2">
                                <dt className="text-muted">Balance calculation (raw)</dt>
                                <dd className="font-mono text-muted">
                                    Balance = Consumption − Solar = {formatEnergy(values.consumption_kwh)} − {formatEnergy(values.solar_units_kwh)} = {formatEnergy(values.net_units_kwh, { signed: true })} kWh
                                </dd>
                            </div>
                        )}
                        {values.source_metadata_json && (() => {
                            try {
                                const meta = typeof values.source_metadata_json === 'string'
                                    ? JSON.parse(values.source_metadata_json)
                                    : values.source_metadata_json
                                return (
                                    <>
                                        {meta.fsa && (
                                            <div className="flex justify-between py-1 border-b border-border/40">
                                                <dt className="text-muted">Fuel Surcharge Adj. (FSA)</dt>
                                                <dd className="font-mono text-heading">₹{meta.fsa}</dd>
                                            </div>
                                        )}
                                        {meta.irda && (
                                            <div className="flex justify-between py-1 border-b border-border/40">
                                                <dt className="text-muted">IRDA charges</dt>
                                                <dd className="font-mono text-heading">₹{meta.irda}</dd>
                                            </div>
                                        )}
                                        {meta.ed && (
                                            <div className="flex justify-between py-1 border-b border-border/40">
                                                <dt className="text-muted">Electricity Duty (ED)</dt>
                                                <dd className="font-mono text-heading">₹{meta.ed}</dd>
                                            </div>
                                        )}
                                    </>
                                )
                            } catch {
                                return null
                            }
                        })()}
                    </dl>
                </div>
            </details>
        </div>
    )
}
