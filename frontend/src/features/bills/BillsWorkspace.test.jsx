import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BillsWorkspace } from './BillsWorkspace'

/**
 * BillsWorkspace state matrix (B4 §30): every lifecycle outcome renders
 * through its honest presentation — and none of them renders as a
 * generic error or as "verified" unless the backend said VERIFIED.
 */

function emptyValues() {
    return {
        billing_period_start: null,
        billing_period_end: null,
        consumption_kwh: null,
        previous_meter_reading: null,
        present_meter_reading: null,
        previous_solar_reading: null,
        present_solar_reading: null,
        solar_units_kwh: null,
        net_units_kwh: null,
        bill_amount: null,
        arrears: null,
        total_due: null,
        energy_charges: null,
        fixed_charges: null,
        customer_service_charges: null,
        other_charges: null,
        taxes_adjustments: null,
        utility_provider: null,
        tariff_category: null,
        meter_type: null,
        contracted_load: null,
    }
}

function detailFor(status, values = emptyValues(), extra = {}) {
    return {
        bill_id: 'bill-1',
        status,
        verified_at: status === 'VERIFIED' ? '2026-09-10 09:30:00' : null,
        created_at: '2026-09-10 09:00:00',
        updated_at: '2026-09-10 09:05:00',
        values,
        provenance: extra.provenance ?? {},
        extraction: extra.extraction ?? null,
        validations: extra.validations ?? [],
        quality_flags: extra.qualityFlags ?? [],
        // D-071: the read model carries the ownership projection; the
        // fixture passes it through when the test states one.
        ownership: extra.ownership ?? null,
    }
}

const AI_ALL = Object.fromEntries(
    Object.keys(emptyValues()).map((field) => [field, { source: 'AI_EXTRACTION', confidence: null }]),
)

function extractedValues() {
    return {
        ...emptyValues(),
        billing_period_start: '2026-08-01',
        billing_period_end: '2026-09-01',
        consumption_kwh: '145.000',
        previous_meter_reading: '1315.000',
        present_meter_reading: '1460.000',
        previous_solar_reading: '1878.000',
        present_solar_reading: '2200.000',
        solar_units_kwh: '322.000',
        net_units_kwh: '-177.000',
        fixed_charges: '30.00',
    }
}

const CLEAN_VALIDATIONS = [
    { rule: 'METER_CONSUMPTION', status: 'PASS', expected_value: '145.000', extracted_value: '145.000', reason_code: null },
    { rule: 'SOLAR_PRODUCTION', status: 'PASS', expected_value: '322.000', extracted_value: '322.000', reason_code: null },
    { rule: 'NET_UNITS', status: 'PASS', expected_value: '-177.000', extracted_value: '-177.000', reason_code: null },
]

const CONFLICT_VALIDATIONS = [
    CLEAN_VALIDATIONS[0],
    CLEAN_VALIDATIONS[1],
    { rule: 'NET_UNITS', status: 'CONFLICT', expected_value: '-177.000', extracted_value: '177.000', reason_code: null },
]

function extraction(confidence = '0.9900') {
    return {
        assessment: 'VALID',
        assessment_reason: 'clear bill',
        document_confidence: confidence,
        extracted_at: '2026-09-10 09:04:00',
    }
}

function renderWorkspace(props = {}) {
    return render(<BillsWorkspace onUpload={vi.fn()} {...props} />)
}

describe('BillsWorkspace lifecycle states', () => {
    it('shows the single Bills heading and upload entry point', () => {
        renderWorkspace({ bills: [], detail: null })

        expect(screen.getByRole('heading', { level: 1, name: /your electricity bills/i })).toBeInTheDocument()
        expect(screen.getByText(/add your monthly electricity bill/i)).toBeInTheDocument()
        // Panel auto-opens for a first bill, so both the header action and
        // the panel submit carry the upload affordance.
        expect(screen.getAllByRole('button', { name: /upload bill/i }).length).toBeGreaterThanOrEqual(1)
        // §17 empty history never invents records.
        expect(screen.getByText('No bills uploaded yet.')).toBeInTheDocument()
    })

    it('DEMO_TEST accounts (server-provided mode) visibly mark the workspace (D-071 §S)', () => {
        const demo = renderWorkspace({ bills: [], detail: null, accountMode: 'DEMO_TEST' })
        expect(screen.getByText('DEMO data')).toBeInTheDocument()
        demo.unmount()

        renderWorkspace({ bills: [], detail: null })
        expect(screen.queryByText('DEMO data')).not.toBeInTheDocument()
    })

    it('PROCESSING renders the processing card (no fake progress, aria-live)', () => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'PROCESSING', verified_at: null }],
            detail: detailFor('PROCESSING'),
        })

        const status = screen.getByRole('status')
        expect(within(status).getByText(/analyzing your bill/i)).toBeInTheDocument()
        expect(within(status).getByText(/spms is reading the bill/i)).toBeInTheDocument()
        expect(status).not.toHaveTextContent(/%/)
        expect(screen.queryByText('Verified')).not.toBeInTheDocument()
    })

    it('uploading stage reads as uploading, not as done', () => {
        renderWorkspace({ bills: [], detail: null, pipelineStage: 'uploading' })

        expect(screen.getByRole('status')).toHaveTextContent(/uploading your bill/i)
        expect(screen.queryByRole('heading', { name: /review your bill/i })).not.toBeInTheDocument()
    })

    it('EXTRACTED shows the review notice without ever claiming correctness or verification', () => {
        const { container } = renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: AI_ALL,
            }),
        })

        expect(screen.getByRole('heading', { name: /bill information extracted/i })).toBeInTheDocument()
        expect(screen.getByText(/review it before verification/i)).toBeInTheDocument()
        expect(screen.queryByText('Verified')).not.toBeInTheDocument()
        expect(container.textContent).not.toMatch(/everything is correct/i)
    })

    it('renders honest nulls as "Not available", never 0/₹0/Estimated', () => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), { extraction: extraction(), provenance: AI_ALL }),
        })

        // bill_amount, arrears, total_due, energy/other/taxes, readings…
        expect(screen.getAllByText('Not available').length).toBeGreaterThan(5)
        expect(screen.queryByText(/^₹?0(\.00)?$/)).not.toBeInTheDocument()
        expect(screen.queryByText(/estimated/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/unknown/i)).not.toBeInTheDocument()
    })

    it('NEEDS_REVIEW presents the conflict calmly and keeps +177 exactly as persisted', async () => {
        const user = userEvent.setup()
        const values = { ...extractedValues(), net_units_kwh: '177.000', bill_amount: '180.00', arrears: '-105.00', total_due: '75.00' }
        const { container } = renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'NEEDS_REVIEW', verified_at: null }],
            detail: detailFor('NEEDS_REVIEW', values, {
                extraction: extraction(),
                validations: CONFLICT_VALIDATIONS,
                provenance: AI_ALL,
                qualityFlags: [{ code: 'INCONSISTENT_NET_UNITS', field_name: 'net_units_kwh' }],
            }),
        })

        // The validation summary reports the conflict count…
        expect(screen.getByRole('heading', { name: /bill validation/i })).toBeInTheDocument()
        expect(screen.getByText(/1 check needs review/i)).toBeInTheDocument()
        expect(screen.getAllByText('Needs review').length).toBeGreaterThanOrEqual(1)

        // …and the review alert (calm, never red) carries the affected field with its persisted value.
        expect(screen.getByRole('heading', { name: /review needed/i })).toBeInTheDocument()
        expect(screen.getByText(/some information from this bill needs your attention/i)).toBeInTheDocument()
        expect(screen.getByText(/spms found a difference between this value and the other readings/i)).toBeInTheDocument()
        expect((await screen.findAllByText('+177 kWh')).length).toBeGreaterThanOrEqual(2)

        // The expectation of -177 is NOT dumped on the main screen…
        expect(container.textContent).not.toContain('-177')

        // …it appears only under progressive disclosure.
        await user.click(screen.getByRole('button', { name: /why does this need review\?/i }))
        expect(await screen.findByText('-177 kWh')).toBeInTheDocument()
        expect(screen.getByText(/extracted value/i)).toBeInTheDocument()
        expect(screen.getByText(/expected from bill readings/i)).toBeInTheDocument()
        expect(screen.getByText(/conflict \(the two values differ\)/i)).toBeInTheDocument()

        // Review journey order (refinement §11): extracted information is
        // read first, the validation verdict explains it, and the specific
        // conflict closes the review — nothing silently corrects anything.
        const headings = [...container.querySelectorAll('h2, h3')].map((node) => node.textContent)
        const indexOf = (re) => headings.findIndex((t) => re.test(t))
        expect(indexOf(/bill validation/i)).toBeGreaterThan(-1)
        expect(indexOf(/solar readings/i)).toBeLessThan(indexOf(/bill validation/i))
        expect(indexOf(/bill validation/i)).toBeLessThan(indexOf(/review needed/i))
    })

    it.each([
        ['NOT_A_BILL', /doesn't appear to be an electricity bill/i],
        ['UNREADABLE', /couldn't read this bill clearly/i],
        ['UNCERTAIN', /couldn't confidently identify this document/i],
        ['PROCESSING_FAILED', /couldn't finish analyzing this bill/i],
    ])('%s renders its dedicated outcome card, never fabricated fields', (status, title) => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status, verified_at: null }],
            detail: detailFor(status),
        })

        expect(screen.getByRole('heading', { name: title })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: /upload another bill/i })).toBeInTheDocument()
        // No bill-information sections are shown for a document with no data.
        expect(screen.queryByRole('heading', { name: /billing period/i })).not.toBeInTheDocument()
        expect(screen.queryByRole('heading', { name: /solar readings/i })).not.toBeInTheDocument()
    })

    it('PROCESSING_FAILED offers retry only when the API state allows it', () => {
        renderWorkspace({
            bills: [],
            detail: detailFor('PROCESSING'),
            processFailure: { retryable: true },
        })

        expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
    })

    it('VERIFIED renders the backend-reported verification, and nothing else does', () => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'VERIFIED', verified_at: '2026-09-10 09:30:00' }],
            detail: detailFor('VERIFIED', extractedValues(), { extraction: extraction(), validations: CLEAN_VALIDATIONS }),
        })

        expect(screen.getAllByText('Verified').length).toBeGreaterThan(0)
    })

    it('document confidence is presented as document-level, never as field certainty', () => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: AI_ALL,
            }),
        })

        expect(screen.getAllByText(/document recognized/i).length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText(/high confidence/i)).toBeInTheDocument()
        // §8: the "99% accurate" implication is explicitly refused.
        expect(screen.queryByText(/all information is .* accurate/i)).not.toBeInTheDocument()
        expect(screen.getByText(/not a per-field guarantee/i)).toBeInTheDocument()
    })

    it('groups provenance instead of badging every field', () => {
        const { container } = renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: AI_ALL,
            }),
        })

        const aiChips = [...container.querySelectorAll('*')].filter((node) => node.textContent === 'AI extracted')
        expect(aiChips.length).toBeLessThan(8)
    })

    it('D-071 ownership states stay their own answer — visible, honest, never "verified"', () => {
        const values = { ...extractedValues(), service_number: '10002000300' }

        // MATCH on a clean EXTRACTED bill.
        const { rerender, container } = renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', values, {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: { ...AI_ALL, service_number: { source: 'AI_EXTRACTION', confidence: null } },
                ownership: { status: 'MATCH', bill_service_number: '10002000300', household_service_number_masked: '•••••••0300' },
            }),
        })
        expect(screen.getByText('Service number matches this household')).toBeInTheDocument()
        expect(screen.getByText('10002000300')).toBeInTheDocument()
        expect(container.textContent).not.toMatch(/bill verified|ownership verified/i)

        // MISMATCH: amber review words, masked registered side only.
        rerender(
            <BillsWorkspace
                bills={[{ bill_id: 'bill-1', status: 'NEEDS_REVIEW', verified_at: null }]}
                detail={detailFor('NEEDS_REVIEW', { ...values, service_number: '999888777' }, {
                    extraction: extraction(),
                    validations: CLEAN_VALIDATIONS,
                    provenance: AI_ALL,
                    qualityFlags: [{ code: 'SERVICE_NUMBER_MISMATCH', field_name: 'service_number' }],
                    ownership: { status: 'MISMATCH', bill_service_number: '999888777', household_service_number_masked: '•••••••0300' },
                })}
                onUpload={vi.fn()}
            />
        )
        expect(screen.getByText("Service number doesn't match this household")).toBeInTheDocument()
        // The mismatch flag also reads in the review alert with words, not codes.
        expect(screen.getByText(/service number does not match this household/i)).toBeInTheDocument()
        expect(screen.queryByText('SERVICE_NUMBER_MISMATCH')).not.toBeInTheDocument()
        // The full registered number never appears unmasked.
        expect(container.textContent).not.toContain('10002000300')

        // NOT_ON_BILL: neutral confirmation failure, no fake pass.
        rerender(
            <BillsWorkspace
                bills={[{ bill_id: 'bill-1', status: 'NEEDS_REVIEW', verified_at: null }]}
                detail={detailFor('NEEDS_REVIEW', { ...values, service_number: null }, {
                    extraction: extraction(),
                    validations: CLEAN_VALIDATIONS,
                    provenance: AI_ALL,
                    qualityFlags: [{ code: 'SERVICE_NUMBER_MISSING', field_name: 'service_number' }],
                    ownership: { status: 'NOT_ON_BILL', bill_service_number: null, household_service_number_masked: '•••••••0300' },
                })}
                onUpload={vi.fn()}
            />
        )
        expect(screen.getByText('Service number could not be confirmed')).toBeInTheDocument()
        expect(container.textContent).not.toMatch(/matches this household/i)
    })

    it('renders PASS as subtle confirmation and NOT_CHECKED neutrally', () => {
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), {
                extraction: extraction(),
                validations: [
                    { rule: 'METER_CONSUMPTION', status: 'PASS', expected_value: '145.000', extracted_value: '145.000', reason_code: null },
                    { rule: 'SOLAR_PRODUCTION', status: 'NOT_CHECKED', expected_value: null, extracted_value: null, reason_code: 'MISSING_OPERANDS' },
                    { rule: 'NET_UNITS', status: 'NOT_CHECKED', expected_value: null, extracted_value: null, reason_code: 'MISSING_OPERANDS' },
                ],
                provenance: AI_ALL,
            }),
        })

        // The backend's three states stay three: no fourth invented.
        expect(screen.getByText('Consistent')).toBeInTheDocument()
        expect(screen.getAllByText(/could not be checked/i).length).toBe(2)
        expect(screen.getByText(/1 check passed · 2 not checked/i)).toBeInTheDocument()

        // NOT_CHECKED is neutral: nothing here demands review or verification.
        expect(screen.queryByText('Review needed')).not.toBeInTheDocument()
        expect(screen.queryByText('Needs review')).not.toBeInTheDocument()
        expect(screen.queryByText('Verified')).not.toBeInTheDocument()
    })

    it('a passed validation summary stays a review prompt — never "verified" (refinement §5)', () => {
        const { container } = renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', extractedValues(), {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: AI_ALL,
            }),
        })

        expect(screen.getByRole('heading', { name: /bill validation/i })).toBeInTheDocument()
        expect(screen.getByText('Validation checks passed')).toBeInTheDocument()
        expect(screen.getByText(/extracted values are internally consistent\. please review them before verification/i)).toBeInTheDocument()
        expect(screen.getAllByText('Consistent').length).toBe(3)
        // §1/§5: PASS ≠ VERIFIED — none of the forbidden claims appear anywhere.
        expect(screen.queryByText('Verified')).not.toBeInTheDocument()
        expect(container.textContent).not.toMatch(/bill verified|all information is correct|ai verified/i)
    })

    it('renders the backend verdict verbatim instead of recalculating it (refinement §2)', () => {
        // Consumption disagrees with the meter delta, yet the backend says
        // PASS: React must display PASS/Consistent regardless — it owns no
        // arithmetic. A matching "CONFLICT"-shaped number must also stay out.
        const values = { ...extractedValues(), consumption_kwh: '999.000' }
        renderWorkspace({
            bills: [{ bill_id: 'bill-1', status: 'EXTRACTED', verified_at: null }],
            detail: detailFor('EXTRACTED', values, {
                extraction: extraction(),
                validations: CLEAN_VALIDATIONS,
                provenance: AI_ALL,
            }),
        })

        expect(screen.getAllByText('Consistent')).toHaveLength(3)
        expect(screen.getByText(/validation checks passed/i)).toBeInTheDocument()
        expect(screen.queryByText('Needs review')).not.toBeInTheDocument()
    })

    it('lists bills in history with their true statuses and no verified claim', () => {
        renderWorkspace({
            bills: [
                {
                    bill_id: 'bill-2',
                    status: 'NEEDS_REVIEW',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                    consumption_kwh: '145.000',
                    solar_units_kwh: '322.000',
                    net_units_kwh: '177.000',
                    bill_amount: '180.00',
                    total_due: '75.00',
                    verified_at: null,
                },
                {
                    bill_id: 'bill-1',
                    status: 'PROCESSING',
                    billing_period_start: null,
                    billing_period_end: null,
                    consumption_kwh: null,
                    solar_units_kwh: null,
                    net_units_kwh: null,
                    bill_amount: null,
                    total_due: null,
                    verified_at: null,
                },
            ],
            detail: detailFor('NEEDS_REVIEW', extractedValues(), {
                extraction: extraction(),
                validations: CONFLICT_VALIDATIONS,
                provenance: AI_ALL,
                qualityFlags: [{ code: 'INCONSISTENT_NET_UNITS', field_name: 'net_units_kwh' }],
            }),
        })

        const history = screen.getByRole('button', { name: /review needed/i })
        expect(history).toBeInTheDocument()
        expect(screen.getByText(/no verified bills yet/i)).toBeInTheDocument()
    })
})
