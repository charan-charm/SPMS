import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SettingsPage } from './SettingsPage'

const mockUpdateHousehold = vi.fn()
const mockRefreshHousehold = vi.fn()

const mockHouseholdContext = {
    household: {
        id: 'hh-123',
        label: 'Test Residence',
        timezone: 'Asia/Kolkata',
        location_label: 'Kurnool',
        service_number: 'SYNTH10002000300',
        status: 'ACTIVE',
    },
    membership: { role: 'OWNER' },
    readiness: {},
    status: 'loaded',
    error: null,
    isLoading: false,
    hasHousehold: true,
    refreshHousehold: mockRefreshHousehold,
    createHousehold: vi.fn(),
    ensureDemo: vi.fn(),
    updateHousehold: mockUpdateHousehold,
}

vi.mock('../../features/household/useHousehold', () => ({
    useHousehold: () => mockHouseholdContext,
}))

describe('SettingsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders household profile and masked service number', () => {
        render(<SettingsPage />)

        expect(screen.getByText('Household Settings')).toBeInTheDocument()
        expect(screen.getByText('Test Residence')).toBeInTheDocument()
        expect(screen.getByText('Asia/Kolkata')).toBeInTheDocument()
        expect(screen.getByText('Kurnool')).toBeInTheDocument()

        // Masked service number (SYNTH10002000300 has length 16, last 4 are 0300)
        expect(screen.getByText('••••••••••••0300')).toBeInTheDocument()
        expect(screen.getByRole('button', { name: /correct service number/i })).toBeInTheDocument()
    })

    it('opens edit form and enforces confirmation before saving', async () => {
        const user = userEvent.setup()
        render(<SettingsPage />)

        await user.click(screen.getByRole('button', { name: /correct service number/i }))

        expect(screen.getByLabelText(/authoritative service number/i)).toBeInTheDocument()
        const input = screen.getByLabelText(/authoritative service number/i)
        await user.type(input, 'SYNTH999888777')

        // Try submitting without checking confirmation
        const saveButton = screen.getByRole('button', { name: /confirm & update/i })
        expect(saveButton).toBeDisabled()

        // Check confirmation
        const confirmCheckbox = screen.getByRole('checkbox', { id: 'confirm-service-number-update' })
        await user.click(confirmCheckbox)
        expect(saveButton).not.toBeDisabled()

        // Mock update success
        mockUpdateHousehold.mockResolvedValueOnce({
            household: {
                ...mockHouseholdContext.household,
                service_number: 'SYNTH999888777',
            },
        })

        await user.click(saveButton)

        await waitFor(() => {
            expect(mockUpdateHousehold).toHaveBeenCalledWith({ service_number: 'SYNTH999888777' })
            expect(mockRefreshHousehold).toHaveBeenCalled()
            expect(screen.getByText(/service number updated successfully/i)).toBeInTheDocument()
        })
    })

    it('handles cancellation of editing', async () => {
        const user = userEvent.setup()
        render(<SettingsPage />)

        await user.click(screen.getByRole('button', { name: /correct service number/i }))
        expect(screen.getByLabelText(/authoritative service number/i)).toBeInTheDocument()

        await user.click(screen.getByRole('button', { name: /cancel/i }))
        expect(screen.queryByLabelText(/authoritative service number/i)).not.toBeInTheDocument()
    })

    it('displays error message when update fails', async () => {
        const user = userEvent.setup()
        render(<SettingsPage />)

        await user.click(screen.getByRole('button', { name: /correct service number/i }))
        await user.type(screen.getByLabelText(/authoritative service number/i), 'INVALID!!!')
        await user.click(screen.getByRole('checkbox', { id: 'confirm-service-number-update' }))

        mockUpdateHousehold.mockRejectedValueOnce(new Error('Invalid service number format.'))

        await user.click(screen.getByRole('button', { name: /confirm & update/i }))

        await waitFor(() => {
            expect(screen.getByText('Invalid service number format.')).toBeInTheDocument()
        })
    })
})
