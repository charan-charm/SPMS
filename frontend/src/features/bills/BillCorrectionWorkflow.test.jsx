import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BillsWorkspace } from './BillsWorkspace'

function createTestBill(status = 'NEEDS_REVIEW', values = {}, extra = {}) {
    return {
        bill_id: 'bill-audit-1',
        status,
        verified_at: status === 'VERIFIED' ? '2026-10-06 12:00:00' : null,
        created_at: '2026-10-06 10:00:00',
        updated_at: '2026-10-06 10:05:00',
        values: {
            billing_period_start: '2026-09-01',
            billing_period_end: '2026-10-01',
            bill_date: '2026-10-03',
            due_date: '2026-10-18',
            service_number: '142345678901',
            consumption_kwh: '480.000',
            previous_meter_reading: '1500.000',
            present_meter_reading: '1980.000',
            previous_solar_reading: '2000.000',
            present_solar_reading: '2303.000',
            solar_units_kwh: '177.000', // Conflict with present - previous = 303.000
            net_units_kwh: '303.000',
            bill_amount: '1250.00',
            energy_charges: '950.00',
            fixed_charges: '120.00',
            customer_service_charges: '50.00',
            other_charges: '30.00',
            taxes_adjustments: '100.00',
            utility_provider: 'APSPDCL',
            tariff_category: 'LT-I(A) Domestic',
            meter_type: 'Bi-directional Net Meter',
            contracted_load: '3.00',
            ...values,
        },
        provenance: extra.provenance ?? {
            solar_units_kwh: { source: 'AI_EXTRACTION', confidence: 0.92 },
            consumption_kwh: { source: 'AI_EXTRACTION', confidence: 0.95 },
            net_units_kwh: { source: 'AI_EXTRACTION', confidence: 0.94 },
        },
        extraction: {
            assessment: 'VALID',
            document_confidence: '0.9400',
            raw_values: {
                solar_units_kwh: '177.000',
                consumption_kwh: '480.000',
                net_units_kwh: '303.000',
                bill_amount: '1250.00',
                ...extra.raw_values,
            },
        },
        validations: extra.validations ?? [
            {
                rule: 'SOLAR_PRODUCTION',
                status: 'CONFLICT',
                expected_value: '303.000',
                extracted_value: '177.000',
                reason_code: null,
            },
        ],
        quality_flags: extra.qualityFlags ?? [
            { code: 'CONFLICTING_SOLAR_READINGS', field_name: 'solar_units_kwh' },
        ],
        ownership: { status: 'MATCH', household_service_number_masked: '•••• •••• 8901' },
    }
}

describe('Bill User Correction Workflow', () => {
    it('renders attention notice and pre-verification checklist when bill has conflicts', () => {
        const detail = createTestBill('NEEDS_REVIEW')
        render(<BillsWorkspace detail={detail} bills={[detail]} />)

        expect(screen.getAllByRole('alert').length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText(/1 value needs your attention/i)).toBeInTheDocument()
        expect(screen.getByText(/Pre-verification checklist/i)).toBeInTheDocument()
        expect(screen.getByText(/Resolve the 1 flagged inconsistencies before verifying/i)).toBeInTheDocument()

        // Verify button must be disabled while conflict exists
        const verifyBtn = screen.getByRole('button', { name: /verify bill/i })
        expect(verifyBtn).toBeDisabled()
    })

    it('allows opening inline editor on conflicting energy card and displays original AI extraction', async () => {
        const user = userEvent.setup()
        const onCorrect = vi.fn().mockResolvedValue({})
        const detail = createTestBill('NEEDS_REVIEW')

        render(<BillsWorkspace detail={detail} bills={[detail]} onCorrect={onCorrect} />)

        // Find Edit button for Solar export (appears on summary card and detailed row)
        const editSolarBtns = screen.getAllByRole('button', { name: /edit solar export/i })
        expect(editSolarBtns.length).toBeGreaterThanOrEqual(1)
        await user.click(editSolarBtns[0])

        // Inline editor appears with form and input focused
        const editorForm = screen.getByRole('form', { name: /edit solar export/i })
        expect(editorForm).toBeInTheDocument()
        expect(screen.getByText(/AI extracted:/i)).toBeInTheDocument()
        expect(screen.getByText(/177.000 kWh/i)).toBeInTheDocument()

        // Cancel button cancels without triggering onCorrect
        const cancelBtn = screen.getByRole('button', { name: /cancel/i })
        await user.click(cancelBtn)
        expect(onCorrect).not.toHaveBeenCalled()
        expect(screen.queryByRole('form', { name: /edit solar export/i })).not.toBeInTheDocument()
    })

    it('validates user input and submits correction when valid number is entered', async () => {
        const user = userEvent.setup()
        const onCorrect = vi.fn().mockResolvedValue({})
        const detail = createTestBill('NEEDS_REVIEW')

        render(<BillsWorkspace detail={detail} bills={[detail]} onCorrect={onCorrect} />)

        const editSolarBtns = screen.getAllByRole('button', { name: /edit solar export/i })
        await user.click(editSolarBtns[0])
        const input = screen.getByRole('textbox', { name: /edit solar export/i })

        // Negative numbers are rejected for energy readings
        await user.clear(input)
        await user.type(input, '-50')
        await user.click(screen.getByRole('button', { name: /save correction/i }))
        expect(screen.getByText(/Energy readings cannot be negative/i)).toBeInTheDocument()
        expect(onCorrect).not.toHaveBeenCalled()

        // Valid correction
        await user.clear(input)
        await user.type(input, '303.000')
        await user.click(screen.getByRole('button', { name: /save correction/i }))

        expect(onCorrect).toHaveBeenCalledWith('bill-audit-1', { solar_units_kwh: '303.000' })
    })

    it('displays USER_CORRECTION provenance badge and preserves original AI extracted reference', () => {
        const detail = createTestBill('EXTRACTED', { solar_units_kwh: '303.000' }, {
            provenance: {
                solar_units_kwh: { source: 'USER_CORRECTION', confidence: null },
            },
            raw_values: {
                solar_units_kwh: '177.000',
            },
            validations: [
                { rule: 'SOLAR_PRODUCTION', status: 'PASS', expected_value: '303.000', extracted_value: '303.000', reason_code: null },
            ],
            qualityFlags: [],
        })

        render(<BillsWorkspace detail={detail} bills={[detail]} />)

        // Shows Corrected by you provenance badge
        expect(screen.getAllByText('Corrected by you').length).toBeGreaterThanOrEqual(1)
        // Original AI value is visible with line-through
        expect(screen.getAllByText(/177.000/i).length).toBeGreaterThanOrEqual(1)
        // Banner shows All values validated
        expect(screen.getByText('All values validated')).toBeInTheDocument()
        // Verification button is enabled
        expect(screen.getByRole('button', { name: /verify bill/i })).toBeEnabled()
    })

    it('locks all fields and displays downstream usage list when bill is VERIFIED', () => {
        const detail = createTestBill('VERIFIED', { solar_units_kwh: '303.000' }, {
            validations: [
                { rule: 'SOLAR_PRODUCTION', status: 'PASS', expected_value: '303.000', extracted_value: '303.000', reason_code: null },
            ],
            qualityFlags: [],
        })

        render(<BillsWorkspace detail={detail} bills={[detail]} />)

        // Verified statement badge and downstream SPMS usage list are visible
        expect(screen.getByText('This bill has been verified')).toBeInTheDocument()
        expect(screen.getByText('Downstream SPMS usage')).toBeInTheDocument()
        expect(screen.getByText(/Demand baseline calibrated/i)).toBeInTheDocument()
        expect(screen.getByText(/Consumption & solar generation forecasts updated/i)).toBeInTheDocument()
        expect(screen.getByText(/Energy balance & solar self-consumption verified/i)).toBeInTheDocument()

        // Edit buttons must NOT be present when verified
        expect(screen.queryByRole('button', { name: /edit solar export/i })).not.toBeInTheDocument()
        expect(screen.queryByRole('button', { name: /edit consumption/i })).not.toBeInTheDocument()
        expect(screen.queryByRole('button', { name: /verify bill/i })).not.toBeInTheDocument()
    })

    it('offers Enter bill manually for UNREADABLE bills and transitions to manual entry mode', async () => {
        const user = userEvent.setup()
        const unreadableBill = {
            bill_id: 'bill-unreadable',
            status: 'UNREADABLE',
            values: {
                billing_period_start: null,
                billing_period_end: null,
                consumption_kwh: null,
                bill_amount: null,
            },
            provenance: {},
            extraction: null,
            validations: [],
            quality_flags: [],
        }

        render(<BillsWorkspace detail={unreadableBill} bills={[unreadableBill]} />)

        const enterManuallyBtn = screen.getByRole('button', { name: /enter bill manually/i })
        expect(enterManuallyBtn).toBeInTheDocument()

        await user.click(enterManuallyBtn)

        // Transitions into BillReviewPanel where user can fill in details
        expect(screen.getByText(/Review this bill|Bill information extracted/i)).toBeInTheDocument()
        expect(screen.getByText(/Energy summary/i)).toBeInTheDocument()
    })
})
