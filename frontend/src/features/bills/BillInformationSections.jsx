import { Gauge, Zap, Sun, CircleDollarSign, Cable, Hash } from 'lucide-react'
import { ProvenanceBadge } from './ProvenanceBadge'
import {
    NOT_AVAILABLE,
    FIELD_LABEL,
    formatFieldValue,
    formatEnergy,
    formatPeriod,
    formatDate,
    maskServiceNumber,
    BILL_STATUS,
    DOCUMENT_SOURCE_TYPE,
    getBillDocumentSource,
} from './presentation'
import { TONE_CLASS } from '../../lib/semantics'
import { cn } from '../../lib/utils'

/**
 * FieldRow — single attribute row with label, value, and provenance.
 */
function FieldRow({ field, value, provenance }) {
    const meta = FIELD_LABEL[field]
    const formatted = formatFieldValue(field, value)
    const display = formatted !== null && meta?.unit ? `${formatted} ${meta.unit}` : formatted
    const source = provenance?.[field]?.source ?? null

    return (
        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
            <dt className="text-sm text-muted">{meta?.label ?? field.replace(/_/g, ' ')}</dt>
            <dd className="flex items-center gap-2">
                <span
                    className={
                        display === null
                            ? 'text-sm italic text-muted'
                            : 'font-display text-sm font-semibold text-heading'
                    }
                >
                    {display ?? NOT_AVAILABLE}
                </span>
                {source === 'USER_CORRECTION' && <ProvenanceBadge source={source} />}
            </dd>
        </div>
    )
}

/**
 * BillInformationSections — hierarchical grouped information display for electricity bills.
 *
 * Implements:
 * 1. Selected statement identity & temporal period (with masked service number)
 * 2. Dedicated prominent 3-metric Energy Summary (Consumption | Solar generation | Net units)
 * 3. Meter & Solar readings side-by-side (with honest pre-solar handling)
 * 4. Cost breakdown (with clear explanation when solar covers energy charges)
 * 5. Tariff and meter context
 */
export function BillInformationSections({ detail }) {
    const { values, provenance } = detail

    let sourceStatementSerial = detail.source_statement_serial ?? null
    let solarApplicability = detail.solar_applicability ?? null
    if (values.source_metadata_json) {
        try {
            const meta =
                typeof values.source_metadata_json === 'string'
                    ? JSON.parse(values.source_metadata_json)
                    : values.source_metadata_json
            if (!sourceStatementSerial) {
                sourceStatementSerial = meta.source_statement_serial ?? meta.s_no ?? null
            }
            if (!solarApplicability) {
                solarApplicability = meta.solar_applicability ?? null
            }
        } catch {
            // ignore
        }
    }

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
                    <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                        <dt className="text-sm text-muted">Service number</dt>
                        <dd className="font-mono text-sm font-semibold text-heading">
                            {maskedService ?? NOT_AVAILABLE}
                        </dd>
                    </div>

                    {/* Statement serial */}
                    {sourceStatementSerial && (
                        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                            <dt className="text-sm text-muted">Statement serial</dt>
                            <dd className="font-mono text-sm font-semibold text-heading">
                                Statement #{sourceStatementSerial}
                            </dd>
                        </div>
                    )}

                    {/* Billing period */}
                    <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                        <dt className="text-sm text-muted">Billing period</dt>
                        <dd className="font-display text-sm font-semibold text-heading">
                            {formatPeriod(values.billing_period_start, values.billing_period_end) ?? NOT_AVAILABLE}
                        </dd>
                    </div>

                    {/* Bill date */}
                    {values.bill_date && (
                        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                            <dt className="text-sm text-muted">Bill date</dt>
                            <dd className="font-display text-sm font-semibold text-heading">
                                {formatDate(values.bill_date)}
                            </dd>
                        </div>
                    )}

                    {/* Due date */}
                    {values.due_date && (
                        <div className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                            <dt className="text-sm text-muted">Due date</dt>
                            <dd className="font-display text-sm font-semibold text-heading">
                                {formatDate(values.due_date)}
                            </dd>
                        </div>
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
                    <div className="rounded-xl border border-border bg-surface-alt/60 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                            Consumption
                        </p>
                        <p className="mt-1 font-display text-2xl font-bold text-heading">
                            {values.consumption_kwh !== null
                                ? `${formatEnergy(values.consumption_kwh)} kWh`
                                : NOT_AVAILABLE}
                        </p>
                        <p className="mt-1 text-[11px] text-muted">
                            Grid consumption measured by meter
                        </p>
                    </div>

                    {/* Solar generation */}
                    <div className="rounded-xl border border-border bg-surface-alt/60 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                            Solar generation
                        </p>
                        <p className="mt-1 font-display text-2xl font-bold text-primary">
                            {isPreSolar ? (
                                <span className="text-base font-medium text-muted">Pre-solar (N/A)</span>
                            ) : values.solar_units_kwh !== null ? (
                                `${formatEnergy(values.solar_units_kwh)} kWh`
                            ) : (
                                <span className="text-muted">—</span>
                            )}
                        </p>
                        <p className="mt-1 text-[11px] text-muted">
                            {isPreSolar
                                ? 'No solar recorded on statement'
                                : 'Solar units credited on DISCOM bill'}
                        </p>
                    </div>

                    {/* Net units */}
                    <div className={cn(
                        'rounded-xl border p-4',
                        isSolarSurplus
                            ? 'border-primary/30 bg-primary/5'
                            : 'border-border bg-surface-alt/60'
                    )}>
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                            Net units
                        </p>
                        <p className={cn(
                            'mt-1 font-display text-2xl font-bold',
                            isSolarSurplus ? 'text-primary' : 'text-heading'
                        )}>
                            {values.net_units_kwh !== null ? (
                                `${formatEnergy(values.net_units_kwh, { signed: true })} kWh`
                            ) : (
                                <span className="text-muted">—</span>
                            )}
                        </p>
                        <p className="mt-1 text-[11px] text-muted">
                            {isSolarSurplus
                                ? 'Net solar surplus (exported to grid)'
                                : isGridImport
                                ? 'Net grid consumption billed'
                                : 'Balanced grid exchange'}
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
                            <h4 id="meter-readings-title" className="font-semibold text-heading">
                                Meter readings
                            </h4>
                        </div>
                        <dl className="flex flex-col">
                            <FieldRow
                                field="previous_meter_reading"
                                value={values.previous_meter_reading}
                                provenance={provenance}
                            />
                            <FieldRow
                                field="present_meter_reading"
                                value={values.present_meter_reading}
                                provenance={provenance}
                            />
                            <FieldRow
                                field="consumption_kwh"
                                value={values.consumption_kwh}
                                provenance={provenance}
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
                                <h4 id="solar-title" className="font-semibold text-heading">
                                    Solar readings & net metering
                                </h4>
                            </div>
                            {isPreSolar ? (
                                <span className="rounded-full border border-border bg-surface px-2 py-0.5 text-[10px] font-medium text-muted">
                                    Pre-solar
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
                                />
                                <FieldRow
                                    field="present_solar_reading"
                                    value={values.present_solar_reading}
                                    provenance={provenance}
                                />
                                <FieldRow
                                    field="solar_units_kwh"
                                    value={values.solar_units_kwh}
                                    provenance={provenance}
                                />
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
                            <FieldRow field="opening_kvah" value={values.opening_kvah} provenance={provenance} />
                            <FieldRow field="closing_kvah" value={values.closing_kvah} provenance={provenance} />
                            <FieldRow field="consumption_kvah" value={values.consumption_kvah} provenance={provenance} />
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
                        <span className="text-[11px] text-primary font-medium">
                            Solar net-metering coverage: Solar generation exceeded consumption, resulting in ₹0.00 energy charges for this billing cycle.
                        </span>
                    )}
                </div>

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
                        />
                    ))}

                    {/* Arrears and total due if non-null */}
                    {values.arrears !== null && values.arrears !== undefined && (
                        <FieldRow field="arrears" value={values.arrears} provenance={provenance} />
                    )}
                    {values.total_due !== null && values.total_due !== undefined && (
                        <FieldRow field="total_due" value={values.total_due} provenance={provenance} />
                    )}
                </dl>
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
                            />
                        )
                    )}
                </dl>
            </section>
        </div>
    )
}
