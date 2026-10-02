import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { HouseholdContext } from '../household/HouseholdContext'
import { GuidedOnboarding } from './GuidedOnboarding'

// Mock child components that communicate with external APIs
vi.mock('../solar/SolarConnectForm', () => ({
    SolarConnectForm: ({ onConnected }) => (
        <div data-testid="solar-connect-form">
            <span>GoodWe Solar Form</span>
            <button
                type="button"
                onClick={() => onConnected({ inverter_sn: 'GW-TEST-999', connection_status: 'CONNECTED' })}
            >
                Simulate Solar Connect
            </button>
        </div>
    ),
}))

vi.mock('../bills/BillUploadPanel', () => ({
    BillUploadPanel: ({ onUpload }) => (
        <div data-testid="bill-upload-panel">
            <span>Bills Upload Dropzone</span>
            <button
                type="button"
                onClick={() => onUpload(new File(['dummy'], 'bill.pdf', { type: 'application/pdf' }))}
            >
                Simulate Bill Upload
            </button>
        </div>
    ),
}))

vi.mock('../../services/api/bills', () => ({
    uploadBill: vi.fn().mockResolvedValue({ data: { bill_id: 'bill-123', status: 'EXTRACTED' } }),
    listBills: vi.fn().mockResolvedValue({ data: { bills: [] } }),
}))

vi.mock('../../services/api/solar', () => ({
    solarApi: {
        getStatus: vi.fn().mockResolvedValue({ connected: false }),
    },
}))

describe('F11 Guided Onboarding Flow', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    const baseContext = {
        household: null,
        hasHousehold: false,
        isLoading: false,
        createHousehold: vi.fn().mockResolvedValue({
            household: { id: 'hh-1', label: 'Sunrise Villa', location_label: 'Tirupati, AP', timezone: 'Asia/Kolkata' },
        }),
        updateHousehold: vi.fn().mockResolvedValue({
            household: { id: 'hh-1', label: 'Sunrise Villa', location_label: 'Tirupati, AP', timezone: 'Asia/Kolkata', service_number: '10002000300' },
        }),
        refreshHousehold: vi.fn().mockResolvedValue({}),
        readiness: null,
    }

    it('renders the 5-step stepper with all step labels', () => {
        render(
            <MemoryRouter>
                <HouseholdContext.Provider value={baseContext}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        expect(screen.getByText(/guided setup — step 1 of 5/i)).toBeInTheDocument()
        expect(screen.getByText('Household')).toBeInTheDocument()
        expect(screen.getByText('Service')).toBeInTheDocument()
        expect(screen.getByText('Solar')).toBeInTheDocument()
        expect(screen.getByText('Bills')).toBeInTheDocument()
        expect(screen.getByText('Finish')).toBeInTheDocument()
    })

    it('validates Step 1 required fields before allowing progression', async () => {
        const user = userEvent.setup()
        const createHousehold = vi.fn()

        render(
            <MemoryRouter>
                <HouseholdContext.Provider value={{ ...baseContext, createHousehold }}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        // Clear default timezone if needed or leave blank fields
        const continueBtn = screen.getByRole('button', { name: /continue to electricity service/i })
        await user.click(continueBtn)

        expect(screen.getByText(/household name is required/i)).toBeInTheDocument()
        expect(screen.getByText(/location or city is required/i)).toBeInTheDocument()
        expect(createHousehold).not.toHaveBeenCalled()
    })

    it('completes Step 1 and advances to Step 2', async () => {
        const user = userEvent.setup()
        const createHousehold = vi.fn().mockResolvedValue({
            household: { id: 'hh-1', label: 'Green Haven', location_label: 'Nellore, AP', timezone: 'Asia/Kolkata' },
        })

        render(
            <MemoryRouter>
                <HouseholdContext.Provider value={{ ...baseContext, createHousehold }}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        await user.type(screen.getByLabelText(/household name/i), 'Green Haven')
        await user.type(screen.getByLabelText(/location \/ city/i), 'Nellore, AP')

        const continueBtn = screen.getByRole('button', { name: /continue to electricity service/i })
        await user.click(continueBtn)

        await waitFor(() => {
            expect(createHousehold).toHaveBeenCalledWith({
                label: 'Green Haven',
                location_label: 'Nellore, AP',
                timezone: 'Asia/Kolkata',
                service_number: undefined,
            })
            expect(screen.getByText(/guided setup — step 2 of 5/i)).toBeInTheDocument()
            expect(screen.getByRole('heading', { name: /electricity utility service/i })).toBeInTheDocument()
        })
    })

    it('Step 2 allows saving service number or skipping to Step 3', async () => {
        const user = userEvent.setup()
        const updateHousehold = vi.fn().mockResolvedValue({})

        render(
            <MemoryRouter initialEntries={['/onboarding?step=2']}>
                <HouseholdContext.Provider value={{
                    ...baseContext,
                    hasHousehold: true,
                    household: { id: 'hh-1', label: 'Green Haven', location_label: 'Nellore' },
                    updateHousehold,
                }}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        expect(screen.getByText(/guided setup — step 2 of 5/i)).toBeInTheDocument()
        expect(
            screen.getByText(/add your electricity consumer id to help verify uploaded bills and keep billing records linked to your household/i),
        ).toBeInTheDocument()
        expect(
            screen.getByText(/matches uploaded electricity bills to your household and prevents duplicate or foreign statements/i),
        ).toBeInTheDocument()

        // Skip to Step 3
        const skipBtn = screen.getByRole('button', { name: /skip for now/i })
        await user.click(skipBtn)

        await waitFor(() => {
            expect(screen.getByText(/guided setup — step 3 of 5/i)).toBeInTheDocument()
            expect(screen.getByRole('heading', { name: /goodwe solar inverter connection/i })).toBeInTheDocument()
            expect(
                screen.getByText(/connect your goodwe inverter to bring live solar generation data into spms and support solar forecasting/i),
            ).toBeInTheDocument()
        })
    })

    it('Step 3 renders SolarConnectForm and allows skipping to Step 4', async () => {
        const user = userEvent.setup()

        render(
            <MemoryRouter initialEntries={['/onboarding?step=3']}>
                <HouseholdContext.Provider value={{
                    ...baseContext,
                    hasHousehold: true,
                    household: { id: 'hh-1', label: 'Green Haven' },
                }}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        expect(screen.getByText(/goodwe connection can be completed later/i)).toBeInTheDocument()
        expect(
            screen.getByText(/connect your goodwe inverter to bring live solar generation data into spms and support solar forecasting/i),
        ).toBeInTheDocument()
        expect(screen.getByTestId('solar-connect-form')).toBeInTheDocument()

        // Skip Step 3 to advance to Step 4
        const skipBtn = screen.getByRole('button', { name: /skip for now/i })
        await user.click(skipBtn)

        await waitFor(() => {
            expect(screen.getByText(/guided setup — step 4 of 5/i)).toBeInTheDocument()
            expect(screen.getByRole('heading', { name: /electricity bills & historical statements/i })).toBeInTheDocument()
        })
    })

    it('Step 4 renders BillUploadPanel and allows skipping to Step 5', async () => {
        const user = userEvent.setup()

        render(
            <MemoryRouter initialEntries={['/onboarding?step=4']}>
                <HouseholdContext.Provider value={{
                    ...baseContext,
                    hasHousehold: true,
                    household: { id: 'hh-1', label: 'Green Haven' },
                }}>
                    <GuidedOnboarding />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        expect(screen.getByText(/uploading bills can be completed later/i)).toBeInTheDocument()
        expect(screen.getByTestId('bill-upload-panel')).toBeInTheDocument()

        // Skip to Step 5
        const skipBtn = screen.getByRole('button', { name: /skip for now/i })
        await user.click(skipBtn)

        await waitFor(() => {
            expect(screen.getByText(/guided setup — step 5 of 5/i)).toBeInTheDocument()
            expect(screen.getByRole('heading', { name: /ready to enter workspace/i })).toBeInTheDocument()
        })
    })

    it('Step 5 displays configuration summary and invokes onComplete when entering workspace', async () => {
        const user = userEvent.setup()
        const onComplete = vi.fn()

        render(
            <MemoryRouter initialEntries={['/onboarding?step=5']}>
                <HouseholdContext.Provider value={{
                    ...baseContext,
                    hasHousehold: true,
                    household: {
                        id: 'hh-1',
                        label: 'Solar Villa',
                        location_label: 'Tirupati, AP',
                        timezone: 'Asia/Kolkata',
                        service_number: '1000-2000-300',
                    },
                }}>
                    <GuidedOnboarding onComplete={onComplete} />
                </HouseholdContext.Provider>
            </MemoryRouter>,
        )

        expect(screen.getByText('Solar Villa')).toBeInTheDocument()
        expect(screen.getByText(/tirupati, ap · asia\/kolkata/i)).toBeInTheDocument()
        expect(screen.getByText('1000-2000-300')).toBeInTheDocument()

        const finishBtn = screen.getByRole('button', { name: /enter workspace & go to dashboard/i })
        await user.click(finishBtn)

        expect(onComplete).toHaveBeenCalled()
    })
})
