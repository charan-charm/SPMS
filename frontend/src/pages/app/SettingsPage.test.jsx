import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { SettingsPage } from './SettingsPage'

const mockUpdateHousehold = vi.fn()
const mockRefreshHousehold = vi.fn()

let mockAccount = {
    id: 'acc-1',
    email: 'resident@example.com',
    role: 'USER',
    mode: 'REAL',
    environment: 'REAL',
}

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

let mockSolarContext = {
    connection: {
        configured: true,
        provider: 'GOODWE_SEMS',
        station_id: '1000234',
        inverter_sn: '90300NS12345',
        account_identifier: 'us***@example.com',
        connection_status: 'CONNECTED',
        last_successful_sync_at: '2026-10-10 18:00:00',
    },
    connectionStatus: 'CONNECTED',
    isLoading: false,
    error: null,
    refreshConnection: vi.fn(),
    updateConnection: vi.fn(),
}

vi.mock('../../features/auth/useAuth', () => ({
    useAuth: () => ({
        account: mockAccount,
        isAuthenticated: true,
    }),
}))

vi.mock('../../features/household/useHousehold', () => ({
    useHousehold: () => mockHouseholdContext,
}))

vi.mock('../../features/solar/useSolar', () => ({
    useSolar: () => mockSolarContext,
}))

function renderSettings() {
    return render(
        <MemoryRouter>
            <SettingsPage />
        </MemoryRouter>
    )
}

describe('SettingsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockAccount = {
            id: 'acc-1',
            email: 'resident@example.com',
            role: 'USER',
            mode: 'REAL',
            environment: 'REAL',
        }
        mockSolarContext = {
            connection: {
                configured: true,
                provider: 'GOODWE_SEMS',
                station_id: '1000234',
                inverter_sn: '90300NS12345',
                account_identifier: 'us***@example.com',
                connection_status: 'CONNECTED',
                last_successful_sync_at: '2026-10-10 18:00:00',
            },
            connectionStatus: 'CONNECTED',
            isLoading: false,
            error: null,
            refreshConnection: vi.fn(),
            updateConnection: vi.fn(),
        }
    })

    // --- Profile Display & Service Number Baseline ---
    it('renders household profile, masked service number, and solar integration summary', () => {
        renderSettings()

        expect(screen.getByText('Household Settings')).toBeInTheDocument()
        expect(screen.getByText('Test Residence')).toBeInTheDocument()
        expect(screen.getByText('Asia/Kolkata')).toBeInTheDocument()
        expect(screen.getByText('Kurnool')).toBeInTheDocument()
        expect(screen.getByText('resident@example.com')).toBeInTheDocument()

        // Masked service number (SYNTH10002000300 has length 16, last 4 are 0300)
        expect(screen.getByText('••••••••••••0300')).toBeInTheDocument()
        expect(screen.getByRole('button', { name: /correct service number/i })).toBeInTheDocument()

        // Solar Summary
        expect(screen.getByText('Solar Inverter Integration')).toBeInTheDocument()
        expect(screen.getByText('1000234')).toBeInTheDocument()
        expect(screen.getByText('90300NS12345')).toBeInTheDocument()
        expect(screen.getByText('us***@example.com')).toBeInTheDocument()
    })

    // --- DEMO Environment Indicator Tests ---
    it('does NOT render DEMO MODE badge when in REAL mode', () => {
        mockAccount = { ...mockAccount, mode: 'REAL', environment: 'REAL' }
        renderSettings()

        expect(screen.queryByTestId('demo-mode-badge')).not.toBeInTheDocument()
        expect(screen.queryByText('DEMO MODE')).not.toBeInTheDocument()
    })

    it('renders DEMO MODE badge when mode indicates DEMO_TEST', () => {
        mockAccount = { ...mockAccount, mode: 'DEMO_TEST', environment: 'DEMO' }
        renderSettings()

        const badge = screen.getByTestId('demo-mode-badge')
        expect(badge).toBeInTheDocument()
        expect(badge).toHaveTextContent('DEMO MODE')
    })

    // --- Household Profile Editing ---
    it('opens profile edit form, updates label, location and timezone successfully', async () => {
        const user = userEvent.setup()
        renderSettings()

        const editButton = screen.getByRole('button', { name: /edit profile/i })
        await user.click(editButton)

        // Pre-filled inputs
        const labelInput = screen.getByLabelText(/household name \/ label/i)
        const locationInput = screen.getByLabelText(/location \(city \/ region\)/i)
        const timezoneInput = screen.getByLabelText(/timezone \(iana identifier\)/i)

        expect(labelInput).toHaveValue('Test Residence')
        expect(locationInput).toHaveValue('Kurnool')
        expect(timezoneInput).toHaveValue('Asia/Kolkata')

        // Edit values
        await user.clear(labelInput)
        await user.type(labelInput, 'Updated Sunrise Villa')
        await user.clear(locationInput)
        await user.type(locationInput, 'Hyderabad, TS')

        mockUpdateHousehold.mockResolvedValueOnce({
            household: {
                ...mockHouseholdContext.household,
                label: 'Updated Sunrise Villa',
                location_label: 'Hyderabad, TS',
                timezone: 'Asia/Kolkata',
            },
        })

        const saveButton = screen.getByRole('button', { name: /save profile/i })
        await user.click(saveButton)

        await waitFor(() => {
            expect(mockUpdateHousehold).toHaveBeenCalledWith({
                label: 'Updated Sunrise Villa',
                location_label: 'Hyderabad, TS',
                timezone: 'Asia/Kolkata',
            })
            expect(mockRefreshHousehold).toHaveBeenCalled()
            expect(screen.getByText(/household profile updated successfully/i)).toBeInTheDocument()
        })
    })

    it('handles cancellation of profile editing without saving', async () => {
        const user = userEvent.setup()
        renderSettings()

        await user.click(screen.getByRole('button', { name: /edit profile/i }))
        expect(screen.getByLabelText(/household name \/ label/i)).toBeInTheDocument()

        await user.click(screen.getByRole('button', { name: /cancel/i }))
        expect(screen.queryByLabelText(/household name \/ label/i)).not.toBeInTheDocument()
        expect(mockUpdateHousehold).not.toHaveBeenCalled()
    })

    it('displays error message when profile update fails', async () => {
        const user = userEvent.setup()
        renderSettings()

        await user.click(screen.getByRole('button', { name: /edit profile/i }))
        const timezoneInput = screen.getByLabelText(/timezone \(iana identifier\)/i)
        await user.clear(timezoneInput)
        await user.type(timezoneInput, 'Invalid/Timezone_Zone')

        mockUpdateHousehold.mockRejectedValueOnce(new Error('timezone must be a valid IANA time zone identifier.'))

        await user.click(screen.getByRole('button', { name: /save profile/i }))

        await waitFor(() => {
            expect(screen.getByText('timezone must be a valid IANA time zone identifier.')).toBeInTheDocument()
        })
    })

    // --- Service Number Correction & Confirmation Preservation ---
    it('opens service number edit form and enforces confirmation before saving', async () => {
        const user = userEvent.setup()
        renderSettings()

        await user.click(screen.getByRole('button', { name: /correct service number/i }))

        expect(screen.getByLabelText(/authoritative service number/i)).toBeInTheDocument()
        const input = screen.getByLabelText(/authoritative service number/i)
        await user.type(input, 'SYNTH999888777')

        // Submit button is disabled until confirmation checkbox is checked
        const saveButton = screen.getByRole('button', { name: /confirm & update/i })
        expect(saveButton).toBeDisabled()

        // Check confirmation
        const confirmCheckbox = screen.getByRole('checkbox', { id: 'confirm-service-number-update' })
        await user.click(confirmCheckbox)
        expect(saveButton).not.toBeDisabled()

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

    it('handles cancellation of service number editing', async () => {
        const user = userEvent.setup()
        renderSettings()

        await user.click(screen.getByRole('button', { name: /correct service number/i }))
        expect(screen.getByLabelText(/authoritative service number/i)).toBeInTheDocument()

        await user.click(screen.getByRole('button', { name: /cancel/i }))
        expect(screen.queryByLabelText(/authoritative service number/i)).not.toBeInTheDocument()
    })

    it('displays error message when service number update fails', async () => {
        const user = userEvent.setup()
        renderSettings()

        await user.click(screen.getByRole('button', { name: /correct service number/i }))
        await user.type(screen.getByLabelText(/authoritative service number/i), 'INVALID!!!')
        await user.click(screen.getByRole('checkbox', { id: 'confirm-service-number-update' }))

        mockUpdateHousehold.mockRejectedValueOnce(new Error('Invalid service number format.'))

        await user.click(screen.getByRole('button', { name: /confirm & update/i }))

        await waitFor(() => {
            expect(screen.getByText('Invalid service number format.')).toBeInTheDocument()
        })
    })

    // --- Solar Integration Summary & Navigation ---
    it('renders "Not Configured" and link to /solar when no solar connection exists', () => {
        mockSolarContext = {
            connection: null,
            connectionStatus: 'NOT_CONFIGURED',
            isLoading: false,
            error: null,
            refreshConnection: vi.fn(),
            updateConnection: vi.fn(),
        }

        renderSettings()

        expect(screen.getByText('Not Connected')).toBeInTheDocument()
        expect(screen.getByText('No Inverter Connected')).toBeInTheDocument()
        expect(screen.getByText(/Connect your GoodWe SEMS inverter on the Solar page/i)).toBeInTheDocument()
        const manageLink = screen.getByRole('link', { name: /manage on solar page/i })
        expect(manageLink).toHaveAttribute('href', '/solar')
    })

    it('displays loading state honestly while checking solar connection', () => {
        mockSolarContext = {
            ...mockSolarContext,
            isLoading: true,
        }

        renderSettings()

        expect(screen.getByText(/checking solar inverter connection/i)).toBeInTheDocument()
    })

    it('never exposes sensitive credentials or passwords in the document', () => {
        renderSettings()

        // Passwords, tokens, or encryption keys must never be rendered in Settings
        expect(screen.queryByText(/password_hash/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/encrypted_credentials/i)).not.toBeInTheDocument()
        expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument()
    })

    // --- Account Status Lifecycle Tests ---
    it('renders "Pending Verification" when account status is PENDING_VERIFICATION', () => {
        mockAccount = { ...mockAccount, status: 'PENDING_VERIFICATION' }
        renderSettings()

        expect(screen.getByText('Pending Verification')).toBeInTheDocument()
    })

    it('renders "Unknown" and never fabricates "Active" when account status is missing', () => {
        mockAccount = { ...mockAccount, status: null }
        renderSettings()

        expect(screen.getByText('Unknown')).toBeInTheDocument()
        expect(screen.queryByText('Active')).not.toBeInTheDocument()
    })

    // --- DEMO / REAL Mode Contradiction & Null Tests ---
    it('does NOT render DEMO badge when account.mode is explicitly REAL even if environment is DEMO', () => {
        mockAccount = { ...mockAccount, mode: 'REAL', environment: 'DEMO' }
        renderSettings()

        expect(screen.queryByTestId('demo-mode-badge')).not.toBeInTheDocument()
    })

    it('does NOT render DEMO badge when mode and environment are undefined or null', () => {
        mockAccount = { ...mockAccount, mode: null, environment: null }
        renderSettings()

        expect(screen.queryByTestId('demo-mode-badge')).not.toBeInTheDocument()
    })

    // --- Solar Specific Status Badges & Formatted Sync ---
    it('renders Auth Error, Timeout, and Network Error badges appropriately', () => {
        mockSolarContext = {
            ...mockSolarContext,
            connectionStatus: 'AUTHENTICATION_ERROR',
        }
        const { unmount } = renderSettings()
        expect(screen.getByText('Auth Error')).toBeInTheDocument()
        unmount()

        mockSolarContext = {
            ...mockSolarContext,
            connectionStatus: 'TIMEOUT',
        }
        const { unmount: unmount2 } = renderSettings()
        expect(screen.getByText('Timeout')).toBeInTheDocument()
        unmount2()

        mockSolarContext = {
            ...mockSolarContext,
            connectionStatus: 'NETWORK_ERROR',
        }
        renderSettings()
        expect(screen.getByText('Network Error')).toBeInTheDocument()
    })

    it('renders formatted Asia/Kolkata sync timestamp when available', () => {
        mockSolarContext = {
            ...mockSolarContext,
            connection: {
                ...mockSolarContext.connection,
                last_successful_sync_at: '2026-10-10 14:30:00',
            },
        }
        renderSettings()

        const syncElement = screen.getByText(/10 Oct 2026/i)
        expect(syncElement).toBeInTheDocument()
        expect(syncElement).toHaveTextContent('IST')
        expect(syncElement).toHaveTextContent('2:30')
    })

    // --- System & Data Transparency Section ---
    it('renders the System & Data Transparency card with version and navigation links', () => {
        renderSettings()

        expect(screen.getByText(/System & Data Transparency/i)).toBeInTheDocument()
        expect(screen.getByText('Version 0.1.0')).toBeInTheDocument()
        expect(screen.getByText('Actual')).toBeInTheDocument()
        expect(screen.getByText('Verified')).toBeInTheDocument()
        expect(screen.getByText('Estimated')).toBeInTheDocument()
        expect(screen.getByText('Predicted')).toBeInTheDocument()

        const reportsLink = screen.getByRole('link', { name: /monthly reports & exports/i })
        expect(reportsLink).toHaveAttribute('href', '/reports')

        const notificationsLink = screen.getByRole('link', { name: /notifications & alerts/i })
        expect(notificationsLink).toHaveAttribute('href', '/notifications')
    })
})
