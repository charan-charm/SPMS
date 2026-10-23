import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { DemoScenarioSwitcher } from './DemoScenarioSwitcher'

const mockNavigate = vi.fn()
vi.mock('react-router', async () => {
    const actual = await vi.importActual('react-router')
    return {
        ...actual,
        useNavigate: () => mockNavigate,
    }
})

let mockAccount = {
    email: 'demo.surplus@spms.local',
    mode: 'DEMO_TEST',
}

const mockRefreshSession = vi.fn()
const mockRefreshHousehold = vi.fn()
const mockRefreshConnection = vi.fn()

vi.mock('../../features/auth/useAuth', () => ({
    useAuth: () => ({
        account: mockAccount,
        refreshSession: mockRefreshSession,
    }),
}))

vi.mock('../../features/household/useHousehold', () => ({
    useHousehold: () => ({
        refreshHousehold: mockRefreshHousehold,
    }),
}))

vi.mock('../../features/solar/useSolar', () => ({
    useSolar: () => ({
        refreshConnection: mockRefreshConnection,
    }),
}))

vi.mock('../../services/api/scenarios', () => ({
    scenariosApi: {
        list: vi.fn(),
        switchScenario: vi.fn(),
    },
}))

import { scenariosApi } from '../../services/api/scenarios'

const sampleScenarios = [
    {
        id: 'SCEN-01',
        name: 'Solar Surplus Residence',
        description: 'Daytime solar surplus and evening deficit',
        narrative: 'Explore daytime solar surplus for a 3 kW residential solar installation.',
        is_active: true,
    },
    {
        id: 'SCEN-02',
        name: 'Persistent Grid Deficit & High Demand',
        description: 'Recurring household demand exceeding solar generation',
        narrative: 'Explore recurring evening and night loads exceeding solar generation.',
        is_active: false,
    },
    {
        id: 'SCEN-03',
        name: 'Sustained Solar Overproduction',
        description: 'Net surplus with zero variable energy cost',
        narrative: 'Explore sustained solar overproduction with zero variable energy cost.',
        is_active: false,
    },
    {
        id: 'SCEN-04',
        name: 'Solar Generation Decline',
        description: 'Production decline with degraded data quality flags',
        narrative: 'Explore solar production decline detection with degraded quality flags.',
        is_active: false,
    },
    {
        id: 'SCEN-05',
        name: 'Consumption Surge & Anomaly',
        description: 'Sudden 60% surge above 30-day baseline',
        narrative: 'Explore household consumption surge anomaly detection.',
        is_active: false,
    },
]

describe('DemoScenarioSwitcher Component', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockAccount = {
            email: 'demo.surplus@spms.local',
            mode: 'DEMO_TEST',
        }
        scenariosApi.list.mockResolvedValue({
            scenarios: sampleScenarios,
            active_scenario: sampleScenarios[0],
        })
    })

    it('renders nothing in REAL mode', () => {
        mockAccount = {
            email: 'charan@example.com',
            mode: 'REAL',
        }

        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        expect(screen.queryByTestId('demo-scenario-switcher')).not.toBeInTheDocument()
    })

    it('renders nothing when mode is missing or unknown', () => {
        // Missing mode
        mockAccount = {
            email: 'user@example.com',
        }

        const { unmount } = render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )
        expect(screen.queryByTestId('demo-scenario-switcher')).not.toBeInTheDocument()
        unmount()

        // Unknown mode
        mockAccount = {
            email: 'user@example.com',
            mode: 'UNKNOWN_OR_STAGING',
        }
        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )
        expect(screen.queryByTestId('demo-scenario-switcher')).not.toBeInTheDocument()
    })

    it('renders trigger button showing active scenario in DEMO mode', async () => {
        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByTestId('demo-scenario-switcher')).toBeInTheDocument()
        })

        expect(screen.getByText(/SCEN-01 · Solar Surplus Residence/i)).toBeInTheDocument()
    })

    it('opens dropdown menu showing all 5 preseeded scenarios on click', async () => {
        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Demo Scenario Switcher/i })).toBeInTheDocument()
        })

        const trigger = screen.getByRole('button', { name: /Demo Scenario Switcher/i })
        fireEvent.click(trigger)

        await waitFor(() => {
            expect(screen.getByText('Switch Demo Scenario')).toBeInTheDocument()
        })

        expect(screen.getByText('SCEN-01')).toBeInTheDocument()
        expect(screen.getByText('SCEN-02')).toBeInTheDocument()
        expect(screen.getByText('SCEN-03')).toBeInTheDocument()
        expect(screen.getByText('SCEN-04')).toBeInTheDocument()
        expect(screen.getByText('SCEN-05')).toBeInTheDocument()
        expect(screen.getByText('Persistent Grid Deficit & High Demand')).toBeInTheDocument()
    })

    it('switches scenario safely, refreshes contexts, and navigates to dashboard', async () => {
        scenariosApi.switchScenario.mockResolvedValue({
            switched: true,
            active_scenario: sampleScenarios[1],
            account: { email: 'demo.deficit@spms.local', mode: 'DEMO_TEST' },
            household: { id: 'hh-scen02', label: 'DEMO — Persistent Grid Deficit' },
            session: { csrf_token: 'new-csrf-token' },
        })

        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Demo Scenario Switcher/i })).toBeInTheDocument()
        })

        const trigger = screen.getByRole('button', { name: /Demo Scenario Switcher/i })
        fireEvent.click(trigger)

        await waitFor(() => {
            expect(screen.getByText('Persistent Grid Deficit & High Demand')).toBeInTheDocument()
        })

        const scen02Option = screen.getByText('Persistent Grid Deficit & High Demand')
        fireEvent.click(scen02Option)

        await waitFor(() => {
            expect(scenariosApi.switchScenario).toHaveBeenCalledWith('SCEN-02')
        })

        expect(mockRefreshSession).toHaveBeenCalled()
        expect(mockRefreshHousehold).toHaveBeenCalled()
        expect(mockRefreshConnection).toHaveBeenCalled()
        expect(mockNavigate).toHaveBeenCalledWith('/dashboard')
    })

    it('renders error notice calmly when switching API fails', async () => {
        scenariosApi.switchScenario.mockRejectedValue(new Error('Network error switching scenario'))

        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Demo Scenario Switcher/i })).toBeInTheDocument()
        })

        const trigger = screen.getByRole('button', { name: /Demo Scenario Switcher/i })
        fireEvent.click(trigger)

        await waitFor(() => {
            expect(screen.getByText('Persistent Grid Deficit & High Demand')).toBeInTheDocument()
        })

        const scen02Option = screen.getByText('Persistent Grid Deficit & High Demand')
        fireEvent.click(scen02Option)

        await waitFor(() => {
            expect(screen.getByText('Network error switching scenario')).toBeInTheDocument()
        })
    })

    it('closes menu when selecting already active scenario without triggering switch API', async () => {
        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Demo Scenario Switcher/i })).toBeInTheDocument()
        })

        const trigger = screen.getByRole('button', { name: /Demo Scenario Switcher/i })
        fireEvent.click(trigger)

        await waitFor(() => {
            expect(screen.getByText('Solar Surplus Residence')).toBeInTheDocument()
        })

        // Click the already active scenario SCEN-01
        const activeOption = screen.getByText('Solar Surplus Residence')
        fireEvent.click(activeOption)

        // switchScenario should NOT be called
        expect(scenariosApi.switchScenario).not.toHaveBeenCalled()
    })

    it('renders each scenario with single-line ID and title, tooltips, and highlighted active item', async () => {
        render(
            <MemoryRouter>
                <DemoScenarioSwitcher />
            </MemoryRouter>
        )

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Demo Scenario Switcher/i })).toBeInTheDocument()
        })

        fireEvent.click(screen.getByRole('button', { name: /Demo Scenario Switcher/i }))

        await waitFor(() => {
            expect(screen.getByText('SCEN-01')).toBeInTheDocument()
        })

        const scen01Item = screen.getByRole('menuitem', { name: /Select SCEN-01: Solar Surplus Residence/i })
        expect(scen01Item).toHaveAttribute('title', expect.stringContaining('SCEN-01: Solar Surplus Residence'))
        expect(scen01Item).toHaveAttribute('aria-selected', 'true')

        const scen02Item = screen.getByRole('menuitem', { name: /Select SCEN-02: Persistent Grid Deficit & High Demand/i })
        expect(scen02Item).toHaveAttribute('title', expect.stringContaining('SCEN-02: Persistent Grid Deficit & High Demand'))
        expect(scen02Item).toHaveAttribute('aria-selected', 'false')
    })
})

