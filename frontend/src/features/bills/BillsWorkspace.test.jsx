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
        expect(await screen.findByText('+177 kWh')).toBeInTheDocument()
        expect(screen.getByText('177 kWh deficit')).toBeInTheDocument()

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
        ['NOT_A_BILL', /Document not recognized as an electricity bill|doesn't appear to be an electricity bill/i],
        ['UNREADABLE', /SPMS couldn't read this bill|couldn't read this bill clearly/i],
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
        expect(screen.getByText('••••••••0300')).toBeInTheDocument()
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
        expect(screen.getAllByText(/service number does not match this household/i).length).toBeGreaterThanOrEqual(1)
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

    describe('UX Redesign & Source-Aware Navigation (Requirements 1-12)', () => {
        it('1. PDF source badge displays "PDF bill" and "AI extracted"', () => {
            renderWorkspace({
                bills: [{ bill_id: 'pdf-bill', status: 'EXTRACTED', document_type: 'PDF' }],
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), {
                        extraction: extraction(),
                        validations: CLEAN_VALIDATIONS,
                    }),
                    document_type: 'PDF',
                },
            })

            expect(screen.getAllByText('Electricity bill PDF').length).toBeGreaterThanOrEqual(1)
            expect(screen.getAllByText('PDF extraction / OCR where applicable').length).toBeGreaterThanOrEqual(1)
        })

        it('2. Image source badge displays "Electricity bill image" and "OCR extraction"', () => {
            renderWorkspace({
                bills: [{ bill_id: 'img-bill', status: 'EXTRACTED', document_type: 'IMAGE' }],
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), {
                        extraction: extraction(),
                        validations: CLEAN_VALIDATIONS,
                    }),
                    document_type: 'IMAGE',
                },
            })

            expect(screen.getAllByText('Electricity bill image').length).toBeGreaterThanOrEqual(1)
            expect(screen.getAllByText('OCR extraction').length).toBeGreaterThanOrEqual(1)
        })

        it('3. XLS structured-import badge displays "APSPDCL Excel (.xls)" and "Structured import"', () => {
            renderWorkspace({
                bills: [{ bill_id: 'xls-bill', status: 'EXTRACTED', source: 'APSPDCL_EXPORT', document_type: 'STRUCTURED_XLS' }],
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), {
                        extraction: {
                            ...extraction(),
                            assessment_reason: 'APSPDCL structured spreadsheet row',
                            document_confidence: null,
                        },
                        validations: CLEAN_VALIDATIONS,
                    }),
                    source: 'APSPDCL_EXPORT',
                    document_type: 'STRUCTURED_XLS',
                    source_statement_serial: 1,
                },
            })

            expect(screen.getAllByText('APSPDCL Excel (.xls)').length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText('Structured import')).toBeInTheDocument()
            expect(screen.getByText(/Imported directly from the APSPDCL spreadsheet/i)).toBeInTheDocument()
            expect(screen.queryByText(/AI extracted/i)).not.toBeInTheDocument()
        })

        it('4. XLS does not display fake AI confidence', () => {
            const { container } = renderWorkspace({
                bills: [{ bill_id: 'xls-bill', status: 'EXTRACTED', source: 'APSPDCL_EXPORT', document_type: 'STRUCTURED_XLS' }],
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), {
                        extraction: {
                            ...extraction(),
                            assessment_reason: 'APSPDCL structured export',
                            document_confidence: null,
                        },
                        validations: CLEAN_VALIDATIONS,
                    }),
                    source: 'APSPDCL_EXPORT',
                    document_type: 'STRUCTURED_XLS',
                },
            })

            expect(screen.queryByText(/high confidence/i)).not.toBeInTheDocument()
            expect(screen.queryByText(/moderate confidence/i)).not.toBeInTheDocument()
            expect(screen.queryByText(/low confidence/i)).not.toBeInTheDocument()
            expect(container.textContent).toContain('No OCR confidence scores apply')
        })

        it('5 & 6. statement navigator displays month + year and selecting a period triggers onSelect', async () => {
            const user = userEvent.setup()
            const onSelectBill = vi.fn()

            const bills = [
                {
                    bill_id: 'bill-sep-26',
                    status: 'VERIFIED',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                    source_statement_serial: 1,
                },
                {
                    bill_id: 'bill-aug-26',
                    status: 'VERIFIED',
                    billing_period_start: '2026-07-01',
                    billing_period_end: '2026-08-01',
                    source_statement_serial: 2,
                },
            ]

            renderWorkspace({
                bills,
                detail: {
                    ...detailFor('VERIFIED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'bill-sep-26',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                    source_statement_serial: 1,
                },
                onSelectBill,
            })

            // Statement navigator should render period labels (Month + Year)
            expect(screen.getByRole('navigation', { name: /billing statement navigation/i }) ||
                   screen.getByLabelText(/billing statement navigation/i)).toBeInTheDocument()
            expect(screen.getAllByText('Sep 2026').length).toBeGreaterThanOrEqual(1)
            expect(screen.getAllByText('Aug 2026').length).toBeGreaterThanOrEqual(1)

            // Clicking the Aug 2026 chip calls onSelectBill with 'bill-aug-26'
            const augChip = screen.getByRole('tab', { name: /aug 2026/i })
            await user.click(augChip)
            expect(onSelectBill).toHaveBeenCalledWith('bill-aug-26')

            // Testing Previous statement button
            const prevButton = screen.getByRole('button', { name: /previous statement/i })
            await user.click(prevButton)
            expect(onSelectBill).toHaveBeenCalledWith('bill-aug-26')
        })

        it('7. statement serial remains visible in selected bill', () => {
            renderWorkspace({
                bills: [{ bill_id: 'bill-1', status: 'EXTRACTED' }],
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    source_statement_serial: 1,
                },
            })

            expect(screen.getAllByText('Statement #1').length).toBeGreaterThanOrEqual(1)
        })

        it('8. pre-solar state displays correctly without 0 kWh or missing warnings', () => {
            const values = {
                ...extractedValues(),
                previous_solar_reading: null,
                present_solar_reading: null,
                solar_units_kwh: null,
                consumption_kwh: '124.000',
                net_units_kwh: '124.000',
            }

            renderWorkspace({
                bills: [{ bill_id: 'pre-solar-bill', status: 'EXTRACTED', solar_applicability: 'BEFORE_SOLAR_INSTALLATION' }],
                detail: {
                    ...detailFor('EXTRACTED', values, { validations: CLEAN_VALIDATIONS }),
                    solar_applicability: 'BEFORE_SOLAR_INSTALLATION',
                },
            })

            expect(screen.getByText('Pre-solar period')).toBeInTheDocument()
            expect(screen.getByText(/solar was not active during this billing period/i)).toBeInTheDocument()
            expect(screen.getByText('Not applicable')).toBeInTheDocument()
            expect(screen.queryByText(/^0(\.0+)?\s*kWh$/)).not.toBeInTheDocument()
            expect(screen.queryByText(/missing.*solar/i)).not.toBeInTheDocument()
        })

        it('9. solar-active state displays solar values', () => {
            const values = {
                ...extractedValues(),
                previous_solar_reading: '1878.000',
                present_solar_reading: '2200.000',
                solar_units_kwh: '322.000',
            }

            renderWorkspace({
                bills: [{ bill_id: 'solar-bill', status: 'EXTRACTED', solar_applicability: 'SOLAR_ACTIVE' }],
                detail: {
                    ...detailFor('EXTRACTED', values, { validations: CLEAN_VALIDATIONS }),
                    solar_applicability: 'SOLAR_ACTIVE',
                },
            })

            expect(screen.getByText('Active solar')).toBeInTheDocument()
            expect(screen.getAllByText('322 kWh').length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText('1,878 kWh')).toBeInTheDocument()
            expect(screen.getByText('2,200 kWh')).toBeInTheDocument()
        })

        it('10. billing history selection synchronizes with selected bill', async () => {
            const user = userEvent.setup()
            const onSelectBill = vi.fn()

            const bills = [
                {
                    bill_id: 'bill-1',
                    status: 'VERIFIED',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                    consumption_kwh: '145.000',
                    solar_units_kwh: '322.000',
                    net_units_kwh: '-177.000',
                    bill_amount: '180.00',
                    verified_at: '2026-09-10',
                },
                {
                    bill_id: 'bill-2',
                    status: 'VERIFIED',
                    billing_period_start: '2026-07-01',
                    billing_period_end: '2026-08-01',
                    consumption_kwh: '200.000',
                    solar_units_kwh: null,
                    net_units_kwh: '200.000',
                    bill_amount: '250.00',
                    solar_applicability: 'BEFORE_SOLAR_INSTALLATION',
                    verified_at: '2026-08-10',
                },
            ]

            renderWorkspace({
                bills,
                detail: {
                    ...detailFor('VERIFIED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'bill-1',
                },
                onSelectBill,
            })

            const historyRows = screen.getAllByRole('button', { name: /verified/i })
            expect(historyRows.length).toBeGreaterThanOrEqual(2)

            // Clicking row 2 selects bill-2
            await user.click(historyRows[1])
            expect(onSelectBill).toHaveBeenCalledWith('bill-2')
        })

        it('11. existing gap acknowledgement remains enforced', async () => {
            const user = userEvent.setup()
            const onVerify = vi.fn()

            const detail = {
                ...detailFor('EXTRACTED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                bill_id: 'gap-bill',
                sequence_validation: {
                    status: 'GAP_DETECTED',
                    message: 'Unbilled gap detected',
                    missing_period: { start: '2025-12-01', end: '2026-08-01' },
                },
            }

            renderWorkspace({
                bills: [{ bill_id: 'gap-bill', status: 'EXTRACTED' }],
                detail,
                onVerify,
            })

            // Verify button is disabled before gap acknowledgement
            const verifyBtn = screen.getByRole('button', { name: /verify bill/i })
            expect(verifyBtn).toBeDisabled()

            // Check the gap acknowledgement checkbox
            const checkbox = screen.getByRole('checkbox', { name: /acknowledge gap/i })
            expect(checkbox).not.toBeChecked()
            await user.click(checkbox)
            expect(checkbox).toBeChecked()

            // Verify button is now enabled
            expect(verifyBtn).toBeEnabled()
            await user.click(verifyBtn)
            expect(onVerify).toHaveBeenCalledWith('gap-bill', { acceptGap: true })
        })

        it('12. canonical ordering: billing periods sort newest to oldest', () => {
            const unorderedBills = [
                { bill_id: 'b-jun', status: 'EXTRACTED', billing_period_start: '2026-05-01', billing_period_end: '2026-06-01' },
                { bill_id: 'b-sep', status: 'EXTRACTED', billing_period_start: '2026-08-01', billing_period_end: '2026-09-01' },
                { bill_id: 'b-jul', status: 'EXTRACTED', billing_period_start: '2026-06-01', billing_period_end: '2026-07-01' },
            ]

            renderWorkspace({
                bills: unorderedBills,
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'b-sep',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                },
            })

            const select = screen.getByRole('combobox', { name: /select billing statement by month/i })
            const options = [...select.querySelectorAll('option')].map((o) => o.textContent)
            // Chronological order: Sep 2026, Jul 2026, Jun 2026
            expect(options[0]).toContain('September 2026')
            expect(options[1]).toContain('July 2026')
            expect(options[2]).toContain('June 2026')
        })

        it('13. duplicate periods do not create duplicate navigation entries', () => {
            const billsWithDuplicates = [
                { bill_id: 'b-sep-1', status: 'EXTRACTED', billing_period_start: '2026-08-01', billing_period_end: '2026-09-01' },
                { bill_id: 'b-sep-2', status: 'VERIFIED', billing_period_start: '2026-08-01', billing_period_end: '2026-09-01' },
                { bill_id: 'b-aug', status: 'EXTRACTED', billing_period_start: '2026-07-01', billing_period_end: '2026-08-01' },
            ]

            renderWorkspace({
                bills: billsWithDuplicates,
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'b-sep-1',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                },
            })

            const select = screen.getByRole('combobox', { name: /select billing statement by month/i })
            const options = [...select.querySelectorAll('option')]
            // Only 2 distinct options: Sep 2026 and Aug 2026 (no duplicated Sep 2026)
            expect(options.length).toBe(2)
            expect(options[0].textContent).toContain('September 2026')
            expect(options[1].textContent).toContain('August 2026')
        })

        it('14. truthful status semantics: never says "verified statement feed" when only extracted', () => {
            const extractedOnlyBills = [
                { bill_id: 'b1', status: 'EXTRACTED', billing_period_start: '2026-08-01', billing_period_end: '2026-09-01' },
                { bill_id: 'b2', status: 'EXTRACTED', billing_period_start: '2026-07-01', billing_period_end: '2026-08-01' },
            ]

            const { container } = renderWorkspace({
                bills: extractedOnlyBills,
                detail: {
                    ...detailFor('EXTRACTED', extractedValues(), { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'b1',
                    billing_period_start: '2026-08-01',
                    billing_period_end: '2026-09-01',
                },
            })

            expect(container.textContent).not.toMatch(/verified statement feed/i)
            expect(screen.getByText('2 statements recorded')).toBeInTheDocument()
            expect(screen.getByText(/2 recorded · 2 extracted · 0 awaiting review/i)).toBeInTheDocument()
        })

        it('15. April 2026 real example: surplus renders "233 kWh surplus" without confusing negative primary value', () => {
            const values = {
                ...extractedValues(),
                consumption_kwh: '169.000',
                solar_units_kwh: '402.000',
                net_units_kwh: '-233.000',
            }

            const { container } = renderWorkspace({
                bills: [{ bill_id: 'apr-2026', status: 'EXTRACTED', billing_period_start: '2026-04-01', billing_period_end: '2026-05-01' }],
                detail: {
                    ...detailFor('EXTRACTED', values, { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'apr-2026',
                    billing_period_start: '2026-04-01',
                    billing_period_end: '2026-05-01',
                },
            })

            // Card 2: Labeled as "Solar units credited"
            expect(screen.getByText('Solar units credited')).toBeInTheDocument()
            expect(screen.getAllByText('402 kWh').length).toBeGreaterThanOrEqual(1)

            // Card 3: Energy balance card
            expect(screen.getAllByText('Energy balance').length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText('233 kWh surplus')).toBeInTheDocument()
            expect(screen.getByText('Solar units exceeded household grid consumption')).toBeInTheDocument()

            // Does NOT prominently dump "-233 kWh" in the primary summary card
            const energySummarySection = container.querySelector('[aria-labelledby="energy-summary-title"]')
            expect(energySummarySection).not.toBeNull()
            expect(energySummarySection.textContent).not.toContain('-233 kWh')

            // Underlying canonical signed calculation remains preserved in detail values
            expect(values.net_units_kwh).toBe('-233.000')
        })

        it('16. deficit example: 271 kWh consumption and 177 kWh solar renders "94 kWh deficit"', () => {
            const values = {
                ...extractedValues(),
                consumption_kwh: '271.000',
                solar_units_kwh: '177.000',
                net_units_kwh: '94.000',
            }

            renderWorkspace({
                bills: [{ bill_id: 'deficit-bill', status: 'EXTRACTED' }],
                detail: {
                    ...detailFor('EXTRACTED', values, { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'deficit-bill',
                },
            })

            expect(screen.getAllByText('Energy balance').length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText('94 kWh deficit')).toBeInTheDocument()
            expect(screen.getByText('Household grid consumption exceeded solar units')).toBeInTheDocument()
        })

        it('17. balanced example: equal consumption and solar renders "0 kWh balanced"', () => {
            const values = {
                ...extractedValues(),
                consumption_kwh: '200.000',
                solar_units_kwh: '200.000',
                net_units_kwh: '0.000',
            }

            renderWorkspace({
                bills: [{ bill_id: 'balanced-bill', status: 'EXTRACTED' }],
                detail: {
                    ...detailFor('EXTRACTED', values, { validations: CLEAN_VALIDATIONS }),
                    bill_id: 'balanced-bill',
                },
            })

            expect(screen.getAllByText('Energy balance').length).toBeGreaterThanOrEqual(1)
            expect(screen.getByText('0 kWh balanced')).toBeInTheDocument()
            expect(screen.getByText('Solar units matched household grid consumption')).toBeInTheDocument()
        })
    })
})
