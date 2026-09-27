import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { AppHeader } from '../../components/navigation/AppHeader'
import { SolarContext } from './SolarContext'
import { deriveNormalizedStatus } from './solarStatus'
import { SolarLiveReadingCard } from './SolarLiveReadingCard'
import { SolarConnectionCard } from './SolarConnectionCard'
import { AuthContext } from '../auth/AuthContext'

// Mock ThemeSwitch to avoid theme provider dependencies in isolated tests
vi.mock('../../components/navigation/ThemeSwitch', () => ({
    ThemeSwitch: () => <div data-testid="theme-switch" />,
}))

// Mock BrandMark
vi.mock('../../components/navigation/BrandMark', () => ({
    BrandMark: () => <div data-testid="brand-mark" />,
}))

function renderHeaderWithSolar(solarValue, authValue = {}) {
    const defaultAuth = {
        account: { email: 'user@example.com', role: 'OWNER', mode: 'REAL' },
        isAuthenticated: true,
        status: 'authenticated',
        logout: vi.fn(),
        ...authValue,
    }

    return render(
        <MemoryRouter>
            <AuthContext.Provider value={defaultAuth}>
                <SolarContext.Provider value={solarValue}>
                    <AppHeader onOpenNavigation={vi.fn()} />
                </SolarContext.Provider>
            </AuthContext.Provider>
        </MemoryRouter>
    )
}

describe('SPMS O4.7 — Solar Header Synchronization & Semantics', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    describe('Workstream A — AppHeader connection status synchronization', () => {
        it('renders "Solar: Connected" when solar connection is active', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'CONNECTED' },
                connectionStatus: 'CONNECTED',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Connected')).toBeInTheDocument()
            expect(screen.getByTitle(/Solar inverter connected and active/i)).toBeInTheDocument()
        })

        it('renders "Solar: Offline" when solar connection is offline (night/standby)', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'OFFLINE' },
                connectionStatus: 'OFFLINE',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Offline')).toBeInTheDocument()
            expect(screen.getByTitle(/Solar inverter offline/i)).toBeInTheDocument()
        })

        it('renders "Solar: Degraded" when connection is degraded', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'DEGRADED' },
                connectionStatus: 'DEGRADED',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Degraded')).toBeInTheDocument()
        })

        it('renders "Solar: Auth Error" when authentication fails', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'UNREACHABLE', last_error_code: 'AUTH_FAILED' },
                connectionStatus: 'AUTHENTICATION_ERROR',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Auth Error')).toBeInTheDocument()
        })

        it('renders "Solar: API Error" when provider API reports an error', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'UNREACHABLE', last_error_code: 'API_ERROR' },
                connectionStatus: 'API_ERROR',
                isLoading: false,
            })

            expect(screen.getByText('Solar: API Error')).toBeInTheDocument()
        })

        it('renders "Solar: Timeout" when provider request times out', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'UNREACHABLE', last_error_code: 'TIMEOUT' },
                connectionStatus: 'TIMEOUT',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Timeout')).toBeInTheDocument()
        })

        it('renders "Solar: Network Error" when provider network is unreachable', () => {
            renderHeaderWithSolar({
                connection: { configured: true, connection_status: 'UNREACHABLE', last_error_code: 'NETWORK_ERROR' },
                connectionStatus: 'NETWORK_ERROR',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Network Error')).toBeInTheDocument()
        })

        it('renders "Solar: Not connected" when unconfigured', () => {
            renderHeaderWithSolar({
                connection: { configured: false },
                connectionStatus: 'NOT_CONFIGURED',
                isLoading: false,
            })

            expect(screen.getByText('Solar: Not connected')).toBeInTheDocument()
        })

        it('gracefully renders "Solar: Not connected" when rendered outside SolarProvider', () => {
            render(
                <MemoryRouter>
                    <AuthContext.Provider value={{ account: null, isAuthenticated: false }}>
                        <AppHeader onOpenNavigation={vi.fn()} />
                    </AuthContext.Provider>
                </MemoryRouter>
            )

            expect(screen.getByText('Solar: Not connected')).toBeInTheDocument()
        })
    })

    describe('Workstream A — Status Derivation Helper', () => {
        it('normalizes unconfigured connections to NOT_CONFIGURED', () => {
            expect(deriveNormalizedStatus(null)).toBe('NOT_CONFIGURED')
            expect(deriveNormalizedStatus({ configured: false })).toBe('NOT_CONFIGURED')
        })

        it('normalizes raw statuses correctly', () => {
            expect(deriveNormalizedStatus({ configured: true, connection_status: 'CONNECTED' })).toBe('CONNECTED')
            expect(deriveNormalizedStatus({ configured: true, connection_status: 'OFFLINE' })).toBe('OFFLINE')
            expect(deriveNormalizedStatus({ configured: true, connection_status: 'DEGRADED' })).toBe('DEGRADED')
        })

        it('prioritizes error codes when connection is unreachable/failed', () => {
            expect(deriveNormalizedStatus({
                configured: true,
                connection_status: 'UNREACHABLE',
                last_error_code: 'AUTH_FAILED',
            })).toBe('AUTHENTICATION_ERROR')

            expect(deriveNormalizedStatus({
                configured: true,
                connection_status: 'UNREACHABLE',
                last_error_code: 'TIMEOUT',
            })).toBe('TIMEOUT')

            expect(deriveNormalizedStatus({
                configured: true,
                connection_status: 'UNREACHABLE',
                last_error_code: 'NETWORK_ERROR',
            })).toBe('NETWORK_ERROR')

            expect(deriveNormalizedStatus({
                configured: true,
                connection_status: 'UNREACHABLE',
                last_error_code: 'SYNC_ERROR',
            })).toBe('NETWORK_ERROR')

            expect(deriveNormalizedStatus({
                configured: true,
                connection_status: 'UNREACHABLE',
                last_error_code: 'API_ERROR',
            })).toBe('API_ERROR')
        })
    })

    describe('Workstream C — Telemetry Semantics & Smart Meter Invariant', () => {
        it('preserves NULL for unmetered grid and consumption power', () => {
            render(
                <SolarLiveReadingCard
                    reading={{
                        power_w: 2450.5,
                        daily_generation_kwh: 7.100,
                        total_generation_kwh: 3325.6,
                        measurement_time: '2026-09-21T14:30:00Z',
                        inverter_status: 'NORMAL',
                        data_status: 'FRESH',
                        data_nature: 'ACTUAL',
                        grid_power_w: null,
                        consumption_power_w: null,
                        ac_voltage_v: 238.4,
                        ac_current_a: 10.3,
                        ac_frequency_hz: 50.02,
                        pv_power_w: 2490.0,
                        temperature_c: 42.1,
                    }}
                />
            )

            // Verify active generation values are shown
            expect(screen.getByText('2,450.5')).toBeInTheDocument()
            expect(screen.getByText('7.100')).toBeInTheDocument()
            expect(screen.getByText('3,325.6')).toBeInTheDocument()

            // Verify smart meter invariant: NULL values are stated honestly, never fabricated
            expect(screen.getByText(/Smart Meter Distinction:/i)).toBeInTheDocument()
            expect(screen.getByText(/No bidirectional smart meter is detected/i)).toBeInTheDocument()
            expect(screen.getByText('NULL')).toBeInTheDocument()
            expect(screen.getByText(/never fabricated/i)).toBeInTheDocument()
        })

        it('communicates STALE data honestly when data_status is STALE', () => {
            render(
                <SolarLiveReadingCard
                    reading={{
                        power_w: 0.0,
                        daily_generation_kwh: 7.100,
                        total_generation_kwh: 3325.6,
                        measurement_time: '2026-09-20T18:30:00Z',
                        inverter_status: 'OFFLINE',
                        data_status: 'STALE',
                        data_nature: 'ACTUAL',
                        grid_power_w: null,
                        consumption_power_w: null,
                    }}
                />
            )

            expect(screen.getByText(/Telemetry is STALE/i)).toBeInTheDocument()
            expect(screen.getByText(/night standby or offline/i)).toBeInTheDocument()
            expect(screen.getByText('STALE')).toBeInTheDocument()
            expect(screen.getByText('OFFLINE')).toBeInTheDocument()
        })
    })

    describe('Workstream E — SolarConnectionCard Semantic Badges', () => {
        it('renders Connected badge for CONNECTED status', () => {
            render(
                <SolarConnectionCard
                    connection={{
                        configured: true,
                        connection_status: 'CONNECTED',
                        station_id: 'station-123',
                        account_identifier: 'j***@gmail.com',
                        last_successful_sync_at: '2026-09-21T10:00:00Z',
                    }}
                    onSync={vi.fn()}
                    onDisconnect={vi.fn()}
                />
            )

            expect(screen.getByText('Connected')).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Sync Now/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Disconnect/i })).toBeInTheDocument()
        })

        it('renders Auth Error badge when connection has auth failure', () => {
            render(
                <SolarConnectionCard
                    connection={{
                        configured: true,
                        connection_status: 'UNREACHABLE',
                        last_error_code: 'AUTH_FAILED',
                        station_id: 'station-123',
                        account_identifier: 'j***@gmail.com',
                    }}
                    onSync={vi.fn()}
                    onDisconnect={vi.fn()}
                />
            )

            expect(screen.getByText('Auth Error')).toBeInTheDocument()
        })
    })
})
