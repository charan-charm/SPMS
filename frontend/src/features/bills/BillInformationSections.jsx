import { CalendarRange, Gauge, Zap, Sun, CircleDollarSign, Cable, Hash } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { ProvenanceBadge } from './ProvenanceBadge'
import { NOT_AVAILABLE, FIELD_LABEL, formatFieldValue } from './presentation'

/**
 * BillInformationSections (B4 §5/§6) — the extracted bill organized
 * into human groups (period, energy, meter, solar, cost, context) so
 * the page reads as energy information, never as a raw database dump.
 *
 * Field order and labels come from the presentation vocabulary; values
 * are the backend strings formatted for display only. A null from the
 * API renders as "Not available" — never 0, never "Unknown", never an
 * invented unit. USER_CORRECTION provenance is noted on the field only
 * when the backend actually reports it.
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
                <span className={display === null ? 'text-sm italic text-muted' : 'font-display text-sm font-semibold text-heading'}>
                    {display ?? NOT_AVAILABLE}
                </span>
                {source === 'USER_CORRECTION' && <ProvenanceBadge source={source} />}
            </dd>
        </div>
    )
}

function BillSection({ id, title, description, icon: Icon, fields, values, provenance, columns = 1 }) {
    return (
        <Card aria-labelledby={`${id}-title`}>
            <CardHeader>
                <CardTitle id={`${id}-title`} className="flex items-center gap-2 text-base">
                    <Icon className="size-4 text-secondary" aria-hidden="true" />
                    {title}
                </CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent>
                <dl className={columns === 2 ? 'grid gap-x-8 sm:grid-cols-2' : 'flex flex-col'}>
                    {fields.map((field) => (
                        <FieldRow key={field} field={field} value={values[field]} provenance={provenance} />
                    ))}
                </dl>
            </CardContent>
        </Card>
    )
}

export function BillInformationSections({ detail }) {
    const { values, provenance } = detail

    return (
        <div className="flex flex-col gap-4">
            <BillSection
                id="identity"
                title="Bill identity"
                description="The electricity service this bill was issued for, as printed on the document."
                icon={Hash}
                fields={['service_number']}
                values={values}
                provenance={provenance}
            />
            <BillSection
                id="period"
                title="Billing period"
                icon={CalendarRange}
                fields={['billing_period_start', 'billing_period_end']}
                values={values}
                provenance={provenance}
            />
            <div className="grid gap-4 lg:grid-cols-2">
                <BillSection
                    id="energy"
                    title="Energy"
                    icon={Zap}
                    fields={['consumption_kwh']}
                    values={values}
                    provenance={provenance}
                />
                <BillSection
                    id="meter"
                    title="Meter readings"
                    icon={Gauge}
                    fields={['previous_meter_reading', 'present_meter_reading']}
                    values={values}
                    provenance={provenance}
                />
            </div>
            <BillSection
                id="solar"
                title="Solar readings"
                description="Generation shown alongside the net units the bill settles."
                icon={Sun}
                fields={['previous_solar_reading', 'present_solar_reading', 'solar_units_kwh', 'net_units_kwh']}
                values={values}
                provenance={provenance}
                columns={2}
            />
            <BillSection
                id="cost"
                title="Cost"
                description="Each amount is kept separate — totals are only ever what your bill itself states."
                icon={CircleDollarSign}
                fields={[
                    'bill_amount',
                    'arrears',
                    'total_due',
                    'energy_charges',
                    'fixed_charges',
                    'customer_service_charges',
                    'other_charges',
                    'taxes_adjustments',
                ]}
                values={values}
                provenance={provenance}
                columns={2}
            />
            <BillSection
                id="context"
                title="Tariff and meter context"
                icon={Cable}
                fields={['utility_provider', 'tariff_category', 'meter_type', 'contracted_load']}
                values={values}
                provenance={provenance}
                columns={2}
            />
        </div>
    )
}
