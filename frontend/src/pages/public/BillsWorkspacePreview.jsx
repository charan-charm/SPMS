import { useState } from 'react'
import { FlaskConical } from 'lucide-react'
import { BillsWorkspace } from '../../features/bills/BillsWorkspace'

/**
 * BillsWorkspacePreview — DEV ONLY (B4 visual acceptance aid).
 *
 * Mounts the real BillsWorkspace against sample payloads that mirror
 * the B4 read contract shape exactly, so every lifecycle state can be
 * inspected in the browser without contacting the backend. Clearly
 * marked sample data end to end (docs/12 §12 demo discipline): nothing
 * here is real bill data, nothing here can verify anything, and the
 * route is dead code in production builds.
 *
 * Route: /dev/bills
 */

const SAMPLE_CLEAN_VALUES = {
    service_number: '10002000300',
    billing_period_start: '2026-08-01',
    billing_period_end: '2026-09-01',
    consumption_kwh: '145.000',
    previous_meter_reading: '1315.000',
    present_meter_reading: '1460.000',
    previous_solar_reading: '1878.000',
    present_solar_reading: '2200.000',
    solar_units_kwh: '322.000',
    net_units_kwh: '-177.000',
    bill_amount: null,
    arrears: null,
    total_due: null,
    energy_charges: '0.00',
    fixed_charges: '30.00',
    customer_service_charges: '25.00',
    other_charges: '45.00',
    taxes_adjustments: '54.82',
    utility_provider: 'DEMO GRID UTILITY',
    tariff_category: 'DOMESTIC (A)',
    meter_type: 'SMART SOLAR',
    contracted_load: '3.00',
}

const SAMPLE_CONFLICT_VALUES = {
    ...SAMPLE_CLEAN_VALUES,
    net_units_kwh: '177.000',
    bill_amount: '180.00',
    arrears: '-105.00',
    total_due: '75.00',
}

// D-071 dev sample: an otherwise-consistent bill carrying ANOTHER
// service's number (illustrative values, not real identifiers).
const SAMPLE_MISMATCH_VALUES = {
    ...SAMPLE_CLEAN_VALUES,
    service_number: '999888777',
}

const PASS_VALIDATIONS = [
    { rule: 'METER_CONSUMPTION', status: 'PASS', expected_value: '145.000', extracted_value: '145.000', reason_code: null },
    { rule: 'SOLAR_PRODUCTION', status: 'PASS', expected_value: '322.000', extracted_value: '322.000', reason_code: null },
    { rule: 'NET_UNITS', status: 'PASS', expected_value: '-177.000', extracted_value: '-177.000', reason_code: null },
]

const PARTIAL_VALIDATIONS = [
    PASS_VALIDATIONS[0],
    { rule: 'SOLAR_PRODUCTION', status: 'NOT_CHECKED', expected_value: null, extracted_value: null, reason_code: 'MISSING_OPERANDS' },
    { rule: 'NET_UNITS', status: 'NOT_CHECKED', expected_value: null, extracted_value: null, reason_code: 'MISSING_OPERANDS' },
]

const SAMPLE_NO_SOLAR_VALUES = {
    ...SAMPLE_CLEAN_VALUES,
    previous_solar_reading: null,
    present_solar_reading: null,
    solar_units_kwh: null,
    net_units_kwh: null,
}

function sampleProvenance(fields) {
    const map = {}
    for (const field of Object.keys(fields)) {
        if (fields[field] !== null) {
            map[field] = { source: 'AI_EXTRACTION', confidence: field === 'net_units_kwh' ? null : '0.9800' }
        }
    }
    return map
}

function sampleDetail(id, status, values, validations, qualityFlags, extra = {}) {
    const extracted = ['EXTRACTED', 'NEEDS_REVIEW', 'VERIFIED'].includes(status)

    return {
        bill_id: id,
        status,
        verified_at: status === 'VERIFIED' ? '2026-09-10 09:30:00' : null,
        created_at: '2026-09-10 09:00:00',
        updated_at: '2026-09-10 09:05:00',
        values,
        // D-071 ownership projection, shaped exactly like the API. A
        // mismatched sample shows the REGISTERED side masked, like the
        // real read model.
        ownership: extracted
            ? (extra.ownership ?? {
                  status: 'MATCH',
                  bill_service_number: values.service_number ?? null,
                  household_service_number_masked: '•••••••0300',
              })
            : null,
        provenance: extracted ? sampleProvenance(values) : {},
        extraction: extracted
            ? {
                  assessment: status === 'NEEDS_REVIEW' ? 'VALID_WITH_LIMITATIONS' : 'VALID',
                  assessment_reason: 'clear bill',
                  document_confidence: '0.9900',
                  extracted_at: '2026-09-10 09:04:00',
              }
            : null,
        validations,
        quality_flags: qualityFlags,
        ...extra_without_ownership(extra),
    }
}

function extra_without_ownership(extra) {
    if (!('ownership' in extra)) {
        return extra
    }
    const rest = { ...extra }
    delete rest.ownership
    return rest
}

const SCENARIOS = {
    empty: {
        label: 'Empty workspace',
        bills: [],
        detail: null,
    },
    processing: {
        label: 'Processing',
        bills: [sampleRow('sample-processing', 'PROCESSING', { billing_period_start: null, billing_period_end: null, consumption_kwh: null, solar_units_kwh: null, net_units_kwh: null, bill_amount: null, total_due: null })],
        detail: sampleDetail('sample-processing', 'PROCESSING', emptyValues(), [], []),
    },
    extracted: {
        label: 'Clean extracted',
        bills: [sampleRow('sample-clean', 'EXTRACTED', SAMPLE_CLEAN_VALUES)],
        detail: sampleDetail('sample-clean', 'EXTRACTED', SAMPLE_CLEAN_VALUES, PASS_VALIDATIONS, []),
    },
    'not-checked': {
        label: 'Not checked (missing solar)',
        bills: [sampleRow('sample-partial', 'NEEDS_REVIEW', SAMPLE_NO_SOLAR_VALUES)],
        detail: sampleDetail('sample-partial', 'NEEDS_REVIEW', SAMPLE_NO_SOLAR_VALUES, PARTIAL_VALIDATIONS, [
            { code: 'MISSING_SOLAR_READINGS', field_name: 'previous_solar_reading' },
        ]),
    },
    'service-mismatch': {
        label: 'Service mismatch',
        bills: [sampleRow('sample-mismatch', 'NEEDS_REVIEW', SAMPLE_MISMATCH_VALUES)],
        detail: sampleDetail('sample-mismatch', 'NEEDS_REVIEW', SAMPLE_MISMATCH_VALUES, PASS_VALIDATIONS.slice(0, 2).concat([PASS_VALIDATIONS[2]]), [
            { code: 'SERVICE_NUMBER_MISMATCH', field_name: 'service_number' },
        ], {
            ownership: {
                status: 'MISMATCH',
                bill_service_number: '999888777',
                household_service_number_masked: '•••••••0300',
            },
        }),
    },
    review: {
        label: 'Needs review (+177 conflict)',
        bills: [sampleRow('sample-conflict', 'NEEDS_REVIEW', SAMPLE_CONFLICT_VALUES)],
        detail: sampleDetail(
            'sample-conflict',
            'NEEDS_REVIEW',
            SAMPLE_CONFLICT_VALUES,
            [
                PASS_VALIDATIONS[0],
                PASS_VALIDATIONS[1],
                { rule: 'NET_UNITS', status: 'CONFLICT', expected_value: '-177.000', extracted_value: '177.000', reason_code: null },
            ],
            [{ code: 'INCONSISTENT_NET_UNITS', field_name: 'net_units_kwh' }],
        ),
    },
    'not-a-bill': {
        label: 'Not a bill',
        bills: [sampleRow('sample-not-a-bill', 'NOT_A_BILL', emptyValues())],
        detail: sampleDetail('sample-not-a-bill', 'NOT_A_BILL', emptyValues(), [], []),
    },
    unreadable: {
        label: 'Unreadable',
        bills: [sampleRow('sample-unreadable', 'UNREADABLE', emptyValues())],
        detail: sampleDetail('sample-unreadable', 'UNREADABLE', emptyValues(), [], []),
    },
    uncertain: {
        label: 'Uncertain',
        bills: [sampleRow('sample-uncertain', 'UNCERTAIN', emptyValues())],
        detail: sampleDetail('sample-uncertain', 'UNCERTAIN', emptyValues(), [], []),
    },
    failed: {
        label: 'Processing failed',
        bills: [sampleRow('sample-failed', 'PROCESSING_FAILED', emptyValues())],
        detail: sampleDetail('sample-failed', 'PROCESSING_FAILED', emptyValues(), [], []),
    },
    verified: {
        label: 'Verified (read-only report)',
        bills: [sampleRow('sample-verified', 'VERIFIED', SAMPLE_CLEAN_VALUES)],
        detail: sampleDetail('sample-verified', 'VERIFIED', SAMPLE_CLEAN_VALUES, PASS_VALIDATIONS, []),
    },
}

function emptyValues() {
    const values = {}
    for (const field of Object.keys(SAMPLE_CLEAN_VALUES)) {
        values[field] = null
    }
    return values
}

function sampleRow(id, status, values) {
    return {
        bill_id: id,
        status,
        billing_period_start: values.billing_period_start,
        billing_period_end: values.billing_period_end,
        consumption_kwh: values.consumption_kwh,
        solar_units_kwh: values.solar_units_kwh,
        net_units_kwh: values.net_units_kwh,
        bill_amount: values.bill_amount,
        total_due: values.total_due,
        verified_at: status === 'VERIFIED' ? '2026-09-10 09:30:00' : null,
        updated_at: '2026-09-10 09:05:00',
    }
}

export function BillsWorkspacePreview() {
    const [scenarioKey, setScenarioKey] = useState('review')
    const [stage, setStage] = useState(null)
    const [uploadOpen, setUploadOpen] = useState(false)
    const scenario = SCENARIOS[scenarioKey]

    const simulateUpload = async () => {
        setStage('uploading')
        await sleep(700)
        setStage('processing')
        await sleep(1200)
        setStage(null)
        setUploadOpen(false)
        setScenarioKey('extracted')
        return { uploaded: true }
    }

    return (
        <div className="flex flex-col gap-6">
            <p className="flex items-center gap-2 rounded-md border border-chart-demo/40 bg-chart-demo/10 px-3 py-2 text-sm font-medium text-chart-demo" role="note">
                <FlaskConical className="size-4 shrink-0" aria-hidden="true" />
                Dev preview — sample payloads shaped like the real /api/v1/bills read contract. Not real bill
                data; nothing here is or can be verified.
            </p>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Preview scenario">
                {Object.entries(SCENARIOS).map(([key, value]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => {
                            setScenarioKey(key)
                            setStage(null)
                            setUploadOpen(false)
                        }}
                        aria-pressed={scenarioKey === key}
                        className={
                            scenarioKey === key
                                ? 'rounded-full border border-primary/50 bg-primary-soft px-3 py-1 text-xs font-semibold text-primary-active'
                                : 'rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-body hover:bg-surface-alt'
                        }
                    >
                        {value.label}
                    </button>
                ))}
            </div>
            <BillsWorkspace
                sessionPhase="ready"
                bills={scenario.bills}
                detail={scenario.detail}
                detailPhase="ready"
                pipelineStage={stage}
                processFailure={null}
                onUpload={simulateUpload}
                onSelectBill={() => {}}
                onRetryProcess={() => setStage('processing')}
                onRetryDetail={() => {}}
                onRetrySession={() => {}}
                uploadOpen={uploadOpen}
                onUploadOpenChange={setUploadOpen}
            />
        </div>
    )
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms))
}
