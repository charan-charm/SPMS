import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { DashboardPage } from './DashboardPage'

let mockAccount = {
    id: 'user-real-123',
    email: 'charan581548@gmail.com',
    mode: 'REAL',
    environment: 'PRODUCTION',
}

let mockHousehold = {
    id: 'hh-charan-123',
    label: 'Charan',
    location_label: 'Kodumur',
    service_number: '1234567890',
    timezone: 'Asia/Kolkata',
}

vi.mock('../../features/auth/useAuth', () => ({
    useAuth: () => ({
        account: mockAccount,
        isAuthenticated: true,
    }),
}))

vi.mock('../../features/household/useHousehold', () => ({
    useHousehold: () => ({
        household: mockHousehold,
        hasHousehold: true,
    }),
}))

vi.mock('../../services/api/solar', () => ({
    solarApi: {
        getLatest: vi.fn(),
        getConnection: vi.fn(),
        autoSyncTelemetry: vi.fn(),
    },
}))

vi.mock('../../services/api/bills', () => ({
    listBills: vi.fn(),
}))

vi.mock('../../services/api/forecast', () => ({
    getForecasts: vi.fn(),
    getForecastPerformance: vi.fn(),
}))

vi.mock('../../services/api/recommendations', () => ({
    getRecommendations: vi.fn(),
}))

vi.mock('../../services/api/intelligence', () => ({
    getInsights: vi.fn(),
}))

vi.mock('../../services/api/energy', () => ({
    classifyEnergyBalance: vi.fn(),
}))

vi.mock('../../services/api/energyCost', () => ({
    estimateEnergyCost: vi.fn(),
}))

vi.mock('../../services/api/scenarios', () => ({
    scenariosApi: {
        list: vi.fn(),
        switchScenario: vi.fn(),
    },
}))

import { solarApi } from '../../services/api/solar'
import { listBills } from '../../services/api/bills'
import { getForecasts, getForecastPerformance } from '../../services/api/forecast'
import { getRecommendations } from '../../services/api/recommendations'
import { getInsights } from '../../services/api/intelligence'
import { classifyEnergyBalance } from '../../services/api/energy'
import { estimateEnergyCost } from '../../services/api/energyCost'
import { scenariosApi } from '../../services/api/scenarios'

const sampleSolarLatest = {
    daily_generation_kwh: 13.4,
    power_w: 0.0,
    measurement_time: '2026-10-05 18:06:00',
    inverter_status: 'NORMAL',
    data_status: 'STALE',
    inverter_sn: '53000N3A258L5685',
}

const sampleForecastData = {
    household_id: 'hh-charan-123',
    horizon: 'DAY_AHEAD',
    solar_forecast: {
        predicted_value: 17.73,
        uncertainty_range: { lower: 15.6, upper: 19.9 },
        confidence: 'HIGH',
        data_nature: 'PREDICTED',
        provenance: {
            model_name: 'SPMS Solar Calibrated V2',
            model_version: 'V2',
        },
    },
    consumption_forecast: {
        predicted_value: 5.05,
        data_nature: 'ESTIMATED',
        confidence: 'HIGH',
    },
    energy_balance_projection: {
        balance_kwh: 12.68,
        classification: 'POTENTIAL_SURPLUS',
        data_nature: 'PREDICTED',
        measurement_context: 'POTENTIAL',
    },
    energy_cost_projection: {
        estimated_cost_inr: 0,
        fixed_charge: { amount: 30 },
    },
}

const sampleBillsData = {
    bills: [
        { id: 'b1', status: 'VERIFIED', total_due: '149.00', bill_amount: '149.00', billing_period_start: '2026-08-01', billing_period_end: '2026-08-31' },
        { id: 'b2', status: 'VERIFIED', total_due: '180.00', bill_amount: '180.00', billing_period_start: '2026-07-01', billing_period_end: '2026-07-31' },
    ],
}

const sampleRecommendations = [
    {
        id: 'rec-1',
        title: 'Shift Flexible Electricity to Solar Window',
        action_text: 'Consider shifting flexible household electricity use to the midday solar window where practical.',
        confidence: 'HIGH',
        priority: 'HIGH',
    },
]

describe('DashboardPage — Control Center V1', () => {
    beforeEach(() => {
        vi.clearAllMocks()

        mockAccount = {
            id: 'user-real-123',
            email: 'charan581548@gmail.com',
            mode: 'REAL',
            environment: 'PRODUCTION',
        }

        solarApi.getLatest.mockResolvedValue({ latest: sampleSolarLatest })
        solarApi.getConnection.mockResolvedValue({ connection: { connection_status: 'CONNECTED' } })
        solarApi.autoSyncTelemetry.mockResolvedValue({ synced: true, status: 'SUCCESS' })
        listBills.mockResolvedValue({ data: sampleBillsData })
        getForecasts.mockResolvedValue({ data: sampleForecastData })
        getForecastPerformance.mockResolvedValue({
            data: {
                v2_average_accuracy_pct: 89.3,
                v2_aggregate_mae_kw: 0.32,
            },
        })
        getRecommendations.mockResolvedValue({ data: { recommendations: sampleRecommendations } })
        getInsights.mockResolvedValue({ data: { insights: [] } })

        classifyEnergyBalance.mockResolvedValue({
            data: {
                result: {
                    balance_kwh: 8.35,
                    classification: 'POTENTIAL_SURPLUS',
                    explanation: 'Solar generation (13.4000 kWh) exceeds household consumption (5.0500 kWh) by 8.3500 kWh — a potential surplus.',
                    data_nature: 'ESTIMATED',
                    data_status: 'STALE',
                    measurement_context: 'POTENTIAL',
                    confidence_level: 'HIGH',
                },
            },
        })

        estimateEnergyCost.mockResolvedValue({
            data: {
                result: {
                    estimated_variable_cost: 0,
                    fixed_charge: { amount: 30, currency: 'INR' },
                    data_nature: 'ESTIMATED',
                },
            },
        })
    })

    it('renders the header context concisely with Real Mode badge, location, and solar status', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Charan Household')).toBeInTheDocument()
        })

        expect(screen.getByText('Real Mode')).toBeInTheDocument()
        expect(screen.getByText(/Kodumur · APSPDCL/)).toBeInTheDocument()
        expect(screen.getByText(/Solar:\s*CONNECTED/i)).toBeInTheDocument()
    })

    it('renders Today\'s Energy State primary KPIs with honest semantic badges', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText("Today's Energy State")).toBeInTheDocument()
        })

        // 1. Solar Generation (ACTUAL, 13.40 kWh)
        expect(screen.getByText('13.40')).toBeInTheDocument()
        expect(screen.getByText('Actual')).toBeInTheDocument()
        expect(screen.getByText('STALE')).toBeInTheDocument()

        // 2. Household Demand (ESTIMATED, 5.05 kWh/day)
        expect(screen.getByText('5.05')).toBeInTheDocument()
        expect(screen.getAllByText('Estimated').length).toBe(2) // Household demand + Energy cost
        expect(screen.getByText('DAILY BASELINE')).toBeInTheDocument()
        expect(screen.queryByText('Predicted Demand')).not.toBeInTheDocument()

        // 3. Energy Balance (POTENTIAL, +8.35 kWh)
        expect(screen.getByText('+8.35')).toBeInTheDocument()
        expect(screen.getAllByText('POTENTIAL').length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('POTENTIAL SURPLUS')).toBeInTheDocument()

        // 4. Energy Cost (ESTIMATED, ₹0.00 variable)
        expect(screen.getByText('₹0.00')).toBeInTheDocument()
        expect(screen.getByText('Variable energy cost')).toBeInTheDocument()
        expect(screen.getByText('No variable import cost estimated')).toBeInTheDocument()
        expect(screen.getByText(/Latest verified bill:/)).toBeInTheDocument()
        expect(screen.getByText('₹149')).toBeInTheDocument()
        expect(screen.getByText(/Fixed\/statutory\/other bill charges are separate\./)).toBeInTheDocument()
    })

    it('renders the Energy Position card showing honest relationship and non-export disclosure', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Position')).toBeInTheDocument()
        })

        expect(screen.getByText('Analytical potential — not measured grid export')).toBeInTheDocument()
        expect(screen.getByText(/Physical export to the utility grid is not measured/)).toBeInTheDocument()
        expect(screen.getByText('Explore complete energy balance & historical validation')).toBeInTheDocument()
    })

    it('renders the System Status card without inverter serial number or internal temperature placeholders', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('System Status')).toBeInTheDocument()
        })

        expect(screen.getByText('GoodWe GW3000-NS')).toBeInTheDocument()
        expect(screen.getByText(/Solar telemetry is stale/)).toBeInTheDocument()

        // Verify APSPDCL Baseline does not display tariff rate detail
        expect(screen.getByText('APSPDCL Utility Baseline')).toBeInTheDocument()
        expect(screen.getByText(/2 verified cycles/i)).toBeInTheDocument()
        expect(screen.queryByText(/₹2\.95\/kWh/i)).not.toBeInTheDocument()

        // Verify technical details removed from Main Dashboard
        expect(screen.queryByText('53000N3A258L5685')).not.toBeInTheDocument()
        expect(screen.queryByText(/Internal Temp/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/Inverter SN/i)).not.toBeInTheDocument()
    })

    it('renders the Solar Outlook card with canonical forecast values and no hardcoded benchmarks', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Outlook')).toBeInTheDocument()
        })

        expect(screen.getByText('17.73')).toBeInTheDocument()
        expect(screen.getByText('15.6 – 19.9 kWh')).toBeInTheDocument()
        expect(screen.getByText('SPMS Solar Calibrated V2')).toBeInTheDocument()
        expect(screen.getByText('HIGH CONFIDENCE')).toBeInTheDocument()
        expect(screen.queryByText('17.92')).not.toBeInTheDocument()

        // Verify Capacity-Normalized Performance Score is labelled correctly and never called accuracy
        expect(screen.getByText(/Historical Capacity-Normalized Performance Score:\s*89\.3%/i)).toBeInTheDocument()
        expect(screen.queryByText(/Historical capacity-normalized score is 89\.3%/i)).not.toBeInTheDocument()
    })

    it('renders the Recommended Action card with compact guidance and no appliance-control claims', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Recommended Action')).toBeInTheDocument()
        })

        expect(screen.getByText('Shift Flexible Electricity to Solar Window')).toBeInTheDocument()
        expect(screen.getByText(/No automated appliance control or guaranteed financial return is implied/)).toBeInTheDocument()
        expect(screen.getByText('10:00 – 14:00 (Midday)')).toBeInTheDocument()
    })

    it('renders the Quick Navigation affordance at the bottom linking to core pages', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Quick Navigation')).toBeInTheDocument()
        })

        const navGrid = screen.getByTestId('quick-navigation-grid')
        expect(navGrid.querySelector('a[href="/solar"]')).toBeInTheDocument()
        expect(navGrid.querySelector('a[href="/energy"]')).toBeInTheDocument()
        expect(navGrid.querySelector('a[href="/forecasts"]')).toBeInTheDocument()
        expect(navGrid.querySelector('a[href="/bills"]')).toBeInTheDocument()
        expect(navGrid.querySelector('a[href="/recommendations"]')).toBeInTheDocument()
    })

    it('does NOT render any synthetic hourly chart or projection on Dashboard', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText("Today's Energy State")).toBeInTheDocument()
        })

        // Verify chart completely removed
        expect(screen.queryByText(/Solar Generation & Consumption Outlook/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/24-hour hourly projection/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/uncertainty intervals/i)).not.toBeInTheDocument()
    })

    it('renders Demo Mode badge when user is in DEMO environment', async () => {
        mockAccount = {
            id: 'user-demo-123',
            email: 'demo@spms.local',
            mode: 'DEMO_TEST',
            environment: 'DEMO',
        }

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Demo Mode (Simulated)')).toBeInTheDocument()
        })
    })

    it('triggers data reload and safe auto-sync when clicking the Refresh button', async () => {
        const user = userEvent.setup()

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Refresh/i })).toBeInTheDocument()
        })

        // On initial mount, autoSyncTelemetry is NOT invoked by DashboardPage (delegated to SolarProvider)
        expect(solarApi.autoSyncTelemetry).toHaveBeenCalledTimes(0)
        expect(solarApi.getLatest).toHaveBeenCalledTimes(1)

        const refreshBtn = screen.getByRole('button', { name: /Refresh/i })
        await user.click(refreshBtn)

        // When user explicitly clicks Refresh, autoSyncTelemetry is initiated
        expect(solarApi.autoSyncTelemetry).toHaveBeenCalledTimes(1)
        expect(solarApi.getLatest).toHaveBeenCalledTimes(2)
        expect(getForecasts).toHaveBeenCalledTimes(2)
    })

    it('handles sync failure honestly by displaying notice while preserving last valid solar reading', async () => {
        const user = userEvent.setup()

        // Sync returns error from provider
        solarApi.autoSyncTelemetry.mockResolvedValueOnce({
            synced: false,
            status: 'ERROR',
            error: 'GoodWe SEMS+ network request failed: Could not resolve host: www.semsportal.com',
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Refresh/i })).toBeInTheDocument()
        })

        // Initial solar yield 13.40 kWh is visible
        expect(screen.getByText('13.40')).toBeInTheDocument()

        const refreshBtn = screen.getByRole('button', { name: /Refresh/i })
        await user.click(refreshBtn)

        await waitFor(() => {
            expect(screen.getByText(/Solar synchronization notice: GoodWe SEMS\+ network request failed/i)).toBeInTheDocument()
        })

        // Last valid reading (13.40 kWh) is preserved and NOT erased
        expect(screen.getByText('13.40')).toBeInTheDocument()
        // Stale status remains honest
        expect(screen.getByText('STALE')).toBeInTheDocument()
    })

    it('prevents duplicate synchronization requests on repeated or rapid clicks', async () => {
        const user = userEvent.setup()

        // Create a controllable delayed promise for autoSyncTelemetry
        let resolveAutoSync
        solarApi.autoSyncTelemetry.mockImplementation(
            () => new Promise((resolve) => { resolveAutoSync = resolve })
        )

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Refresh/i })).toBeInTheDocument()
        })

        const refreshBtn = screen.getByRole('button', { name: /Refresh/i })
        // First click starts the refresh
        await user.click(refreshBtn)

        // Rapid second and third clicks while first is in progress
        await user.click(refreshBtn)
        await user.click(refreshBtn)

        // Only one autoSyncTelemetry request must have been dispatched
        expect(solarApi.autoSyncTelemetry).toHaveBeenCalledTimes(1)

        // Resolve the in-progress sync
        resolveAutoSync({ synced: true, status: 'SUCCESS' })

        await waitFor(() => {
            expect(refreshBtn).not.toBeDisabled()
        })
    })

    it('renders canonical variable energy cost distinguishing it from total bill', async () => {
        estimateEnergyCost.mockResolvedValue({
            data: {
                result: {
                    estimated_variable_cost: 0,
                    fixed_charge: { amount: 30, currency: 'INR' },
                    data_nature: 'ESTIMATED',
                },
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Cost')).toBeInTheDocument()
        })

        // Variable energy cost is ₹0.00
        expect(screen.getByText('₹0.00')).toBeInTheDocument()
        expect(screen.getByText('Variable energy cost')).toBeInTheDocument()
        expect(screen.getByText('No variable import cost estimated')).toBeInTheDocument()

        // Distinguishes from verified bill
        expect(screen.getByText(/Latest verified bill:/)).toBeInTheDocument()
        expect(screen.getByText('₹149')).toBeInTheDocument()
        expect(screen.getByText(/Fixed\/statutory\/other bill charges are separate\./)).toBeInTheDocument()
    })

    it('renders dynamic latest verified bill and links to /bills', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Cost')).toBeInTheDocument()
        })

        const billLink = screen.getByRole('link', { name: /₹149/i })
        expect(billLink).toBeInTheDocument()
        expect(billLink).toHaveAttribute('href', '/bills')
    })

    it('renders honest empty state when no verified bill exists', async () => {
        listBills.mockResolvedValue({
            data: {
                bills: [
                    { id: 'b-pending', status: 'PENDING', total_due: '200.00' },
                ],
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Cost')).toBeInTheDocument()
        })

        expect(screen.getByText('No verified bill available')).toBeInTheDocument()
        expect(screen.queryByText('₹149')).not.toBeInTheDocument()
    })

    it('does not hardcode ₹149 and retrieves whatever latest verified bill amount exists', async () => {
        listBills.mockResolvedValue({
            data: {
                bills: [
                    { id: 'b-custom', status: 'VERIFIED', total_due: '345.00', billing_period_start: '2026-08-01', billing_period_end: '2026-08-31' },
                ],
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Energy Cost')).toBeInTheDocument()
        })

        expect(screen.getByText('₹345')).toBeInTheDocument()
        expect(screen.queryByText('₹149')).not.toBeInTheDocument()
    })

    it('labels 89.3% as Capacity-Normalized Performance Score and never calls it accuracy', async () => {
        getForecastPerformance.mockResolvedValue({
            data: {
                v2_average_accuracy_pct: 89.3,
                v2_aggregate_mae_kw: 0.32,
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Outlook')).toBeInTheDocument()
        })

        const scoreElement = screen.getByText(/Historical Capacity-Normalized Performance Score:\s*89\.3%/i)
        expect(scoreElement).toBeInTheDocument()

        // Check tooltip explanation
        expect(scoreElement).toHaveAttribute(
            'title',
            'Capacity-normalized performance score based on MAE relative to the 3.00 kW rated inverter capacity; not conventional percentage accuracy.'
        )

        // Ensure 89.3% is not called forecast accuracy or prediction accuracy
        const dashboard = screen.getByTestId('dashboard-control-center')
        expect(dashboard.textContent).not.toMatch(/89\.3%\s*(?:forecast\s*)?accuracy/i)
        expect(dashboard.textContent).not.toMatch(/accuracy:\s*89\.3%/i)
    })

    it('does not hardcode 89.3% and displays dynamic score from performance endpoint', async () => {
        getForecastPerformance.mockResolvedValue({
            data: {
                v2_average_accuracy_pct: 92.4,
                v2_aggregate_mae_kw: 0.23,
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Solar Outlook')).toBeInTheDocument()
        })

        expect(screen.getByText(/Historical Capacity-Normalized Performance Score:\s*92\.4%/i)).toBeInTheDocument()
        expect(screen.queryByText(/89\.3%/)).not.toBeInTheDocument()
    })

    it('removes tariff rate from Dashboard System Status card', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('System Status')).toBeInTheDocument()
        })

        expect(screen.getByText('APSPDCL Utility Baseline')).toBeInTheDocument()
        expect(screen.getByText(/2 verified cycles/i)).toBeInTheDocument()
        expect(screen.queryByText(/₹2\.95\/kWh/i)).not.toBeInTheDocument()
        expect(screen.queryByText(/tariff rate/i)).not.toBeInTheDocument()
    })

    it('renders Section E Recommended Action using compact canonical recommendation', async () => {
        getRecommendations.mockResolvedValue({
            data: {
                recommendations: [
                    {
                        id: 'rec-dash-001',
                        type: 'SHIFT_TO_SOLAR_WINDOW',
                        priority: 'HIGH',
                        title: 'Shift Flexible Electricity to Solar Window',
                        action: 'Run flexible appliances during the midday solar window.',
                        timing_window: '11:00 – 15:00',
                        confidence: 'HIGH',
                        is_actionable: true,
                    },
                ],
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('Shift Flexible Electricity to Solar Window')).toBeInTheDocument()
        })

        expect(screen.getByText('Run flexible appliances during the midday solar window.')).toBeInTheDocument()
        expect(screen.getByText('11:00 – 15:00')).toBeInTheDocument()
        expect(screen.getByText('HIGH PRIORITY')).toBeInTheDocument()
        expect(screen.getByText(/View all recommendations/i)).toBeInTheDocument()
    })

    it('renders clean "No action is needed right now" empty state when no recommendations exist in Dashboard', async () => {
        getRecommendations.mockResolvedValue({
            data: {
                recommendations: [],
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText('No action is needed right now.')).toBeInTheDocument()
        })

        expect(screen.getByText(/Your energy profile is currently balanced/i)).toBeInTheDocument()
    })

    it('renders scenario narrative card with examiner focus in DEMO mode', async () => {
        mockAccount = {
            id: 'user-demo-01',
            email: 'demo.surplus@spms.local',
            mode: 'DEMO_TEST',
            environment: 'DEMO',
        }

        scenariosApi.list.mockResolvedValue({
            scenarios: [],
            active_scenario: {
                id: 'SCEN-01',
                name: 'Solar Surplus Residence',
                description: 'Daytime solar surplus and evening deficit',
                narrative: 'Solar Surplus Residence — Explore daytime solar surplus and evening energy deficit for a 3 kW residential solar installation.',
                location: 'Kodumur / Andhra Pradesh',
                solar_capacity_kw: 3.0,
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('dashboard-scenario-narrative')).toBeInTheDocument()
        })

        expect(screen.getByText('SCEN-01')).toBeInTheDocument()
        expect(screen.getByText('Solar Surplus Residence')).toBeInTheDocument()
        expect(screen.getByText(/Examiner Focus:/i)).toBeInTheDocument()
        expect(screen.getByText(/Explore daytime solar surplus and evening energy deficit/i)).toBeInTheDocument()

        // Restore mockAccount to default REAL mode for subsequent tests
        mockAccount = {
            id: 'user-real-123',
            email: 'charan581548@gmail.com',
            mode: 'REAL',
            environment: 'PRODUCTION',
        }
    })

    it('renders Quick Navigation with direct link to /insights', async () => {
        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('quick-navigation-grid')).toBeInTheDocument()
        })

        const insightsLink = screen.getByRole('link', { name: /insights/i })
        expect(insightsLink).toBeInTheDocument()
        expect(insightsLink).toHaveAttribute('href', '/insights')
    })

    it('surfaces active intelligence insights link in Section F when active insights exist', async () => {
        getInsights.mockResolvedValue({
            data: {
                insights: [
                    {
                        id: 'ins-001',
                        type: 'WEATHER_PLANNING_WINDOW',
                        title: 'Midday Solar Planning Window Tomorrow',
                        category: 'WEATHER',
                        status: 'ACTIVE',
                    },
                ],
            },
        })

        render(
            <MemoryRouter>
                <DashboardPage />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByText(/1 active insight/i)).toBeInTheDocument()
        })

        expect(screen.getByText(/View insights \(1\)/i)).toBeInTheDocument()
    })
})
