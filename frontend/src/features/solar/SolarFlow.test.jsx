import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SolarConnectForm } from './SolarConnectForm'
import { SolarConnectionCard } from './SolarConnectionCard'
import { SolarLiveReadingCard } from './SolarLiveReadingCard'
import { SolarHistoryTable } from './SolarHistoryTable'
import { SolarPage } from '../../pages/app/SolarPage'
import { solarApi } from '../../services/api/solar'

vi.mock('../../services/api/solar', () => ({
    solarApi: {
        getConnection: vi.fn(),
        testConnection: vi.fn(),
        saveConnection: vi.fn(),
        discoverStations: vi.fn(),
        disconnect: vi.fn(),
        getLatest: vi.fn(),
        getHistory: vi.fn(),
        getDays: vi.fn(),
        syncTelemetry: vi.fn(),
    },
}))

/**
 * SPMSLoadingState uses motion/react and body class manipulation.
 * Stub it so tests stay deterministic; the overlay renders when
 * isLoading=true and calls onExited when isLoading flips to false.
 */
vi.mock('../../components/feedback/SPMSLoadingState', () => ({
    SPMSLoadingState: ({ isLoading, onExited, statusSequence, title }) => {
        // Simulate the departure completing immediately when isLoading becomes false.
        if (!isLoading && onExited) {
            // Use queueMicrotask so React batches the state update normally.
            queueMicrotask(() => onExited())
        }
        return isLoading ? (
            <div data-testid="spms-loading-state" aria-label={title}>
                {statusSequence && (
                    <ul>
                        {statusSequence.map((s) => (
                            <li key={s}>{s}</li>
                        ))}
                    </ul>
                )}
            </div>
        ) : null
    },
    SunOrb: () => <div data-testid="sun-orb" />,
}))

// ─── Helpers ───────────────────────────────────────────────────────────────

function makeConfiguredConnection(overrides = {}) {
    return {
        configured: true,
        station_id: 'station-xyz-123',
        inverter_sn: 'GW6000SN',
        account_identifier: 'j***e@gmail.com',
        connection_status: 'CONNECTED',
        last_successful_sync_at: '2026-09-20T14:30:00Z',
        ...overrides,
    }
}

// ─── Tests ─────────────────────────────────────────────────────────────────

describe('SPMS O4 Solar Telemetry Components & Flow', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        solarApi.getDays?.mockResolvedValue({ days: [] })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarConnectForm — core form rendering
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarConnectForm', () => {
        it('renders the form with account, password and discovery actions (no manual station ID)', () => {
            render(<SolarConnectForm onConnected={vi.fn()} />)

            expect(screen.getByLabelText(/SEMS\+ Account/i)).toBeInTheDocument()
            expect(screen.getByLabelText(/SEMS\+ Password/i)).toBeInTheDocument()
            expect(screen.queryByLabelText(/Power Station ID/i)).not.toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Test Connection/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /Connect Inverter/i })).toBeInTheDocument()
        })

        it('tests connection and renders discovery result details', async () => {
            const user = userEvent.setup()
            solarApi.testConnection.mockResolvedValueOnce({
                test_result: {
                    success: true,
                    status: 'CONNECTED',
                    station_id: 'st-101',
                    station_name: 'My Rooftop Station',
                    inverter_model: 'GW6000-DNS-G3',
                    inverter_sn: '96000DNS123',
                    rated_power_kw: 6.0,
                },
            })

            render(<SolarConnectForm onConnected={vi.fn()} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'secret123')

            await user.click(screen.getByRole('button', { name: /Test Connection/i }))

            await waitFor(() => {
                expect(screen.getByText(/Discovery Successful/i)).toBeInTheDocument()
                expect(screen.getByText('My Rooftop Station')).toBeInTheDocument()
                expect(screen.getByText('GW6000-DNS-G3')).toBeInTheDocument()
            })
        })

        it('saves connection and triggers onConnected callback without manual station ID', async () => {
            const user = userEvent.setup()
            const onConnected = vi.fn()
            const mockConn = {
                configured: true,
                station_id: 'st-101',
                account_identifier: 'u***r@solar.org',
                connection_status: 'CONNECTED',
            }
            solarApi.saveConnection.mockResolvedValueOnce({
                connection: mockConn,
                test_result: { success: true },
            })

            render(<SolarConnectForm onConnected={onConnected} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'secret123')

            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            await waitFor(() => {
                expect(onConnected).toHaveBeenCalledWith(mockConn)
            })
        })

        it('presents station selection when multiple stations are found', async () => {
            const user = userEvent.setup()
            solarApi.testConnection.mockResolvedValueOnce({
                test_result: {
                    success: false,
                    status: 'MULTIPLE_STATIONS',
                    stations: [
                        { station_id: 'st-alpha-1', station_name: 'Farm House PV', capacity_kw: 5.0 },
                        { station_id: 'st-beta-2', station_name: 'City Home Rooftop', capacity_kw: 3.5 },
                    ],
                },
            })

            render(<SolarConnectForm onConnected={vi.fn()} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'multi@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'secret123')

            await user.click(screen.getByRole('button', { name: /Test Connection/i }))

            await waitFor(() => {
                expect(screen.getByText(/Select Power Station/i)).toBeInTheDocument()
                expect(screen.getByText('Farm House PV')).toBeInTheDocument()
                expect(screen.getByText('City Home Rooftop')).toBeInTheDocument()
            })
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarConnectForm — loading lifecycle (O4.5)
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarConnectForm — loading lifecycle', () => {
        it('calls onConnecting with a promise when Connect Inverter is clicked', async () => {
            const user = userEvent.setup()
            const onConnecting = vi.fn()

            // saveConnection resolves after a tick — long enough to check onConnecting was called
            solarApi.saveConnection.mockResolvedValueOnce({
                connection: { configured: true, station_id: 'st-1', connection_status: 'CONNECTED' },
                test_result: { success: true },
            })

            render(<SolarConnectForm onConnected={vi.fn()} onConnecting={onConnecting} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')

            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            expect(onConnecting).toHaveBeenCalledTimes(1)
            // The argument must be a Promise (thenable)
            const arg = onConnecting.mock.calls[0][0]
            expect(typeof arg.then).toBe('function')
        })

        it('disables Connect Inverter while submission is active (prevents duplicate clicks)', async () => {
            const user = userEvent.setup()

            // Use a deferred promise so we control when it resolves
            let resolveSave
            solarApi.saveConnection.mockReturnValue(
                new Promise((resolve) => { resolveSave = resolve })
            )

            render(<SolarConnectForm onConnected={vi.fn()} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')

            const btn = screen.getByRole('button', { name: /Connect Inverter/i })
            await user.click(btn)

            // Button must be disabled during in-flight request
            expect(btn).toBeDisabled()
            // A second click must not trigger another save call
            await user.click(btn)
            expect(solarApi.saveConnection).toHaveBeenCalledTimes(1)

            // Resolve so the test and promise chain can clean up
            act(() => {
                resolveSave({
                    connection: { configured: true, station_id: 'st-1', connection_status: 'CONNECTED' },
                    test_result: { success: true },
                })
            })
        })

        it('does not expose credentials in the error UI on auth failure', async () => {
            const user = userEvent.setup()
            const secretPassword = 'mySecretPassw0rd!'
            solarApi.saveConnection.mockRejectedValueOnce(
                new Error('Authentication failed. Please check your SEMS+ credentials.')
            )

            render(<SolarConnectForm onConnected={vi.fn()} />)

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), secretPassword)

            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            await waitFor(() => {
                expect(screen.getByText(/Connection Notice/i)).toBeInTheDocument()
            })

            // Password must not appear anywhere in the rendered output
            expect(screen.queryByText(secretPassword)).not.toBeInTheDocument()
            // Email field should still be populated (not erased)
            expect(screen.getByLabelText(/SEMS\+ Account/i)).toHaveValue('user@solar.org')
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarConnectionCard
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarConnectionCard', () => {
        const mockConn = makeConfiguredConnection()

        it('displays connection metadata and handles sync and disconnect', async () => {
            const user = userEvent.setup()
            const onSync = vi.fn()
            const onDisconnect = vi.fn()

            render(
                <SolarConnectionCard
                    connection={mockConn}
                    onSync={onSync}
                    onDisconnect={onDisconnect}
                />
            )

            expect(screen.getByText('station-xyz-123')).toBeInTheDocument()
            expect(screen.getByText('GW6000SN')).toBeInTheDocument()
            expect(screen.getByText('j***e@gmail.com')).toBeInTheDocument()
            expect(screen.getByText('Connected')).toBeInTheDocument()

            // Trigger sync
            await user.click(screen.getByRole('button', { name: /Sync Now/i }))
            expect(onSync).toHaveBeenCalled()

            // Trigger disconnect with 2-step confirmation
            const discBtn = screen.getByRole('button', { name: /Disconnect/i })
            await user.click(discBtn)
            expect(screen.getByRole('button', { name: /Confirm Disconnect\?/i })).toBeInTheDocument()
            await user.click(screen.getByRole('button', { name: /Confirm Disconnect\?/i }))
            expect(onDisconnect).toHaveBeenCalled()
        })

        it('strictly separates provider connection status from telemetry freshness', () => {
            render(
                <SolarConnectionCard
                    connection={mockConn}
                    latestReading={{
                        data_status: 'STALE',
                        measurement_time: '2026-09-28T04:47:58Z',
                    }}
                    onSync={vi.fn()}
                    onDisconnect={vi.fn()}
                />
            )

            // Provider connection is Connected
            expect(screen.getByText('Connected')).toBeInTheDocument()
            // Telemetry freshness is Stale
            expect(screen.getByText('Stale')).toBeInTheDocument()
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarLiveReadingCard
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarLiveReadingCard', () => {
        it('renders live power, daily generation, and smart meter absence disclosure', () => {
            const reading = {
                power_w: '3450.50',
                daily_generation_kwh: '14.820',
                total_generation_kwh: '1845.6',
                ac_voltage_v: '232.40',
                ac_current_a: '14.85',
                ac_frequency_hz: '50.02',
                pv_power_w: '3600.00',
                temperature_c: '41.50',
                grid_power_w: null,
                consumption_power_w: null,
                inverter_status: 'NORMAL',
                data_nature: 'ACTUAL',
                data_status: 'FRESH',
            }

            render(<SolarLiveReadingCard reading={reading} />)

            expect(screen.getByText('3,450.5')).toBeInTheDocument()
            expect(screen.getByText('14.820')).toBeInTheDocument()
            expect(screen.getByText(/1,845.6/)).toBeInTheDocument()
            expect(screen.getByText('232.40 V')).toBeInTheDocument()
            expect(screen.getByText('14.85 A')).toBeInTheDocument()
            expect(screen.getByText('LIVE GENERATING')).toBeInTheDocument()

            // Smart meter absence disclosure
            expect(screen.getByText(/Smart Meter Distinction:/i)).toBeInTheDocument()
            expect(screen.getByText(/No bidirectional smart meter is detected/i)).toBeInTheDocument()
            expect(screen.getByText(/strictly in accordance with SPMS data integrity rules/i)).toBeInTheDocument()
        })

        it('clearly displays STALE state and last recorded timestamp for stale telemetry', () => {
            const staleReading = {
                power_w: '0.00',
                daily_generation_kwh: '7.400',
                total_generation_kwh: '3325.9',
                measurement_time: '2026-09-28T04:47:58Z',
                inverter_status: 'NORMAL',
                data_nature: 'ACTUAL',
                data_status: 'STALE',
            }

            render(<SolarLiveReadingCard reading={staleReading} />)

            expect(screen.getByText(/Telemetry is STALE:/i)).toBeInTheDocument()
            expect(screen.getByText('STALE')).toBeInTheDocument()
        })

        it('displays NO GENERATION when telemetry is fresh with 0 W power (night standby)', () => {
            const zeroReading = {
                power_w: '0.00',
                daily_generation_kwh: '0.000',
                inverter_status: 'WAITING',
                data_nature: 'ACTUAL',
                data_status: 'FRESH',
            }

            render(<SolarLiveReadingCard reading={zeroReading} />)

            expect(screen.getByText('NO GENERATION')).toBeInTheDocument()
            expect(screen.queryByText(/^WAITING$/)).not.toBeInTheDocument()
        })

        it('renders Unavailable when electrical metrics are null without fabricating values', () => {
            const nullMetricsReading = {
                power_w: '1500.00',
                daily_generation_kwh: '3.500',
                ac_voltage_v: null,
                ac_current_a: null,
                ac_frequency_hz: null,
                pv_power_w: null,
                temperature_c: null,
                grid_power_w: null,
                consumption_power_w: null,
                inverter_status: 'NORMAL',
                data_status: 'FRESH',
            }

            render(<SolarLiveReadingCard reading={nullMetricsReading} />)

            const unavailables = screen.getAllByText('Unavailable')
            expect(unavailables.length).toBeGreaterThanOrEqual(4)
            expect(screen.getAllByText('Awaiting first valid telemetry').length).toBeGreaterThanOrEqual(4)
        })

        // ────────────────────────────────────────────────────────────────────
        // Section 14 Tests: Cases A through H
        // ────────────────────────────────────────────────────────────────────
        it('Case A: Fresh telemetry renders exact values with fresh/live state', () => {
            const freshReading = {
                power_w: '2200.00',
                daily_generation_kwh: '4.100',
                ac_voltage_v: '257.50',
                ac_current_a: '8.70',
                ac_frequency_hz: '49.96',
                temperature_c: '66.60',
                pv_power_w: '2358.50',
                grid_power_w: null,
                consumption_power_w: null,
                measurement_time: '2026-10-06 10:30:00',
                inverter_status: 'NORMAL',
                data_status: 'FRESH',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={freshReading} />)

            expect(screen.getByText('257.50 V')).toBeInTheDocument()
            expect(screen.getByText('8.70 A')).toBeInTheDocument()
            expect(screen.getByText('49.96 Hz')).toBeInTheDocument()
            expect(screen.getByText('66.60 °C')).toBeInTheDocument()
            expect(screen.getByText('LIVE GENERATING')).toBeInTheDocument()
            expect(screen.getAllByText(/Fresh · 10:30 IST/).length).toBeGreaterThanOrEqual(1)
        })

        it('Case B: Stale telemetry with previous value renders last observed value and STALE badge', () => {
            const staleReading = {
                power_w: '2200.00',
                daily_generation_kwh: '4.100',
                ac_voltage_v: '257.50',
                ac_current_a: '8.70',
                ac_frequency_hz: '49.96',
                temperature_c: '66.60',
                grid_power_w: null,
                consumption_power_w: null,
                measurement_time: '2026-10-05 10:24:00',
                inverter_status: 'NORMAL',
                data_status: 'STALE',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={staleReading} />)

            expect(screen.getByText('257.50 V')).toBeInTheDocument()
            expect(screen.getByText('8.70 A')).toBeInTheDocument()
            expect(screen.getByText('49.96 Hz')).toBeInTheDocument()
            // Telemetry values have visible Last observed timestamp and STALE badge
            expect(screen.getAllByText('Last observed · 10:24 IST').length).toBeGreaterThanOrEqual(1)
            expect(screen.getAllByText('STALE').length).toBeGreaterThanOrEqual(2)
        })

        it('Case C: Missing telemetry with no previous value renders Unavailable with honest reason', () => {
            const missingReading = {
                power_w: null,
                daily_generation_kwh: null,
                ac_voltage_v: null,
                ac_current_a: null,
                ac_frequency_hz: null,
                pv_power_w: null,
                temperature_c: null,
                grid_power_w: null,
                consumption_power_w: null,
                measurement_time: null,
                inverter_status: 'DISCONNECTED',
                data_status: 'UNAVAILABLE',
            }

            render(<SolarLiveReadingCard reading={missingReading} />)

            const unavailables = screen.getAllByText('Unavailable')
            expect(unavailables.length).toBeGreaterThanOrEqual(5)
            expect(screen.getAllByText('Awaiting first valid telemetry').length).toBeGreaterThanOrEqual(5)
            // NEVER shows fake 0 V, 0 A, 0 Hz for missing values
            expect(screen.queryByText('0.00 V')).not.toBeInTheDocument()
            expect(screen.queryByText('0.00 A')).not.toBeInTheDocument()
            expect(screen.queryByText('0.00 Hz')).not.toBeInTheDocument()
        })

        it('Case D: Actual measured zero renders 0 because zero is a real measurement', () => {
            const measuredZeroReading = {
                power_w: '0.00',
                daily_generation_kwh: '0.000',
                ac_voltage_v: '0.00',
                ac_current_a: '0.00',
                ac_frequency_hz: '0.00',
                pv_power_w: '0.00',
                temperature_c: '25.00',
                grid_power_w: null,
                consumption_power_w: null,
                measurement_time: '2026-10-06 01:00:00',
                inverter_status: 'WAITING',
                data_status: 'FRESH',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={measuredZeroReading} />)

            // Real measured zero must display as 0.00 / 0.0, NOT Unavailable
            expect(screen.getByText('0.00 V')).toBeInTheDocument()
            expect(screen.getByText('0.00 A')).toBeInTheDocument()
            expect(screen.getByText('0.00 Hz')).toBeInTheDocument()
            expect(screen.getByText('0.00 W')).toBeInTheDocument()
            expect(screen.getByText('0.0')).toBeInTheDocument()
        })

        it('Case E: Stale active power shows last valid kW value, NOT 0 kW', () => {
            const stalePowerReading = {
                power_w: '2200.00',
                daily_generation_kwh: '4.100',
                measurement_time: '2026-10-05 10:24:00',
                inverter_status: 'NORMAL',
                data_status: 'STALE',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={stalePowerReading} />)

            // Must show 2,200 W and (2.20 kW), NOT 0 kW!
            expect(screen.getByText('2,200.0')).toBeInTheDocument()
            expect(screen.getByText('(2.20 kW)')).toBeInTheDocument()
            expect(screen.queryByText('(0.00 kW)')).not.toBeInTheDocument()
            expect(screen.getByText('Last Observed Active Power')).toBeInTheDocument()
            expect(screen.queryByText('Current Active Power')).not.toBeInTheDocument()
        })

        it('Case F & G: Stale AC current and grid frequency show last valid values, NOT 0 A / 0 Hz', () => {
            const staleElectrical = {
                power_w: '963.00',
                daily_generation_kwh: '0.000',
                ac_voltage_v: '257.50',
                ac_current_a: '8.70',
                ac_frequency_hz: '49.96',
                temperature_c: '66.60',
                electrical_measurement_time: '2026-10-05 10:24:00',
                electrical_data_status: 'STALE',
                measurement_time: '2026-10-06 11:12:00',
                data_status: 'FRESH',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={staleElectrical} />)

            expect(screen.getByText('257.50 V')).toBeInTheDocument()
            expect(screen.getByText('8.70 A')).toBeInTheDocument()
            expect(screen.getByText('49.96 Hz')).toBeInTheDocument()
            expect(screen.queryByText('0.00 A')).not.toBeInTheDocument()
            expect(screen.queryByText('0.00 Hz')).not.toBeInTheDocument()
            expect(screen.getAllByText('Last observed · 10:24 IST').length).toBeGreaterThanOrEqual(3)
        })

        it('Case H: Timestamp integrity ensures displayed time is the measurement time, not render time', () => {
            const observationTimestamp = '2026-10-05 10:24:00'
            const reading = {
                power_w: '2200.00',
                daily_generation_kwh: '4.100',
                ac_voltage_v: '257.50',
                ac_current_a: '8.70',
                ac_frequency_hz: '49.96',
                measurement_time: observationTimestamp,
                data_status: 'STALE',
                data_nature: 'ACTUAL',
            }

            render(<SolarLiveReadingCard reading={reading} />)

            // Exactly 10:24 IST must appear, never the current wall-clock time
            expect(screen.getAllByText(/10:24 IST/).length).toBeGreaterThanOrEqual(1)
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarHistoryTable
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarHistoryTable', () => {
        it('renders daily history rows with exact values and columns', () => {
            const days = [
                {
                    date: '2026-09-20',
                    date_label: '20 Sep 2026',
                    generation_kwh: 12.80,
                    peak_power_kw: 3.60,
                    observation_count: 2,
                    status: 'COMPLETE',
                    status_label: 'Complete',
                },
            ]

            render(<SolarHistoryTable days={days} />)

            expect(screen.getByText('Solar Generation History')).toBeInTheDocument()
            expect(screen.getByText('20 Sep 2026')).toBeInTheDocument()
            expect(screen.getByText('12.80 kWh')).toBeInTheDocument()
            expect(screen.getByText('3.60 kW')).toBeInTheDocument()
            expect(screen.getByText('2')).toBeInTheDocument()
            expect(screen.getByText('COMPLETE')).toBeInTheDocument()
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarPage Container — base behavior
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarPage Container', () => {
        it('shows SolarConnectForm when unconfigured', async () => {
            solarApi.getConnection.mockResolvedValueOnce({
                connection: { configured: false },
            })

            render(<SolarPage />)

            await waitFor(() => {
                expect(screen.getByText(/Connect GoodWe Solar Inverter/i)).toBeInTheDocument()
            })
        })

        it('shows Live Reading and History when configured', async () => {
            solarApi.getConnection.mockResolvedValueOnce({
                connection: makeConfiguredConnection({ station_id: 'station-100' }),
            })
            solarApi.getLatest.mockResolvedValueOnce({
                latest: {
                    power_w: '3200.00',
                    daily_generation_kwh: '10.000',
                    inverter_status: 'NORMAL',
                    data_nature: 'ACTUAL',
                    data_status: 'FRESH',
                },
            })
            solarApi.getHistory.mockResolvedValueOnce({
                history: [
                    {
                        id: 'hist-1',
                        measurement_time: '2026-09-20T11:00:00Z',
                        power_w: '3200.00',
                        daily_generation_kwh: '10.000',
                        inverter_status: 'NORMAL',
                        data_nature: 'ACTUAL',
                    },
                ],
            })

            render(<SolarPage />)

            await waitFor(() => {
                expect(screen.getByText('GoodWe SEMS+ Connection')).toBeInTheDocument()
                expect(screen.getAllByText('3,200.0').length).toBeGreaterThanOrEqual(1)
                expect(screen.getAllByText('10.000').length).toBeGreaterThanOrEqual(1)
            })
        })
    })

    // ────────────────────────────────────────────────────────────────────────
    // SolarPage Container — O4.5 connection loading lifecycle
    // ────────────────────────────────────────────────────────────────────────
    describe('SolarPage — connection loading lifecycle (O4.5)', () => {
        async function setupUnconfiguredPage() {
            solarApi.getConnection.mockResolvedValueOnce({
                connection: { configured: false },
            })
            render(<SolarPage />)
            // Wait for the form to be visible
            await waitFor(() => {
                expect(screen.getByText(/Connect GoodWe Solar Inverter/i)).toBeInTheDocument()
            })
        }

        it('shows SPMSLoadingState when Connect Inverter is clicked', async () => {
            const user = userEvent.setup()

            // Use a deferred promise so loading stays active until we control it
            let resolveSave
            solarApi.saveConnection.mockReturnValue(
                new Promise((resolve) => { resolveSave = resolve })
            )
            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            await waitFor(() => {
                expect(screen.getByTestId('spms-loading-state')).toBeInTheDocument()
            })

            // Clean up — resolve so the promise chain settles
            act(() => {
                resolveSave({
                    connection: makeConfiguredConnection(),
                    test_result: { success: true },
                })
            })
        })

        it('shows GoodWe-specific status messages in the loading state', async () => {
            const user = userEvent.setup()

            let resolveSave
            solarApi.saveConnection.mockReturnValue(
                new Promise((resolve) => { resolveSave = resolve })
            )
            // Second call for loadData after success
            solarApi.getConnection.mockResolvedValue({ connection: makeConfiguredConnection() })
            solarApi.getLatest.mockResolvedValue({ latest: null })
            solarApi.getHistory.mockResolvedValue({ history: [] })

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            await waitFor(() => {
                expect(screen.getByText('Connecting to GoodWe')).toBeInTheDocument()
                expect(screen.getByText('Discovering your solar station')).toBeInTheDocument()
            })

            act(() => {
                resolveSave({
                    connection: makeConfiguredConnection(),
                    test_result: { success: true },
                })
            })
        })

        it('loading remains active while the request is pending', async () => {
            const user = userEvent.setup()

            let resolveSave
            solarApi.saveConnection.mockReturnValue(
                new Promise((resolve) => {
                    resolveSave = resolve
                })
            )

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            // Loading visible while pending
            await waitFor(() => {
                expect(screen.getByTestId('spms-loading-state')).toBeInTheDocument()
            })

            // Form is always mounted (preserves state) but must not be visible during loading.
            // SolarPage hides the form wrapper with visibility:hidden while the orb is active.
            const formTitle = screen.getByText(/Connect GoodWe Solar Inverter/i)
            expect(formTitle).not.toBeVisible()

            // Let it resolve now (cleanup)
            act(() => {
                resolveSave({ connection: makeConfiguredConnection(), test_result: { success: true } })
            })
        })

        it('successful connection exits loading and displays returned solar telemetry', async () => {
            const user = userEvent.setup()
            const mockConn = makeConfiguredConnection({ station_id: 'st-success' })

            solarApi.saveConnection.mockResolvedValueOnce({
                connection: mockConn,
                test_result: { success: true },
            })
            // loadData re-runs after success
            solarApi.getConnection.mockResolvedValue({ connection: mockConn })
            solarApi.getLatest.mockResolvedValue({
                latest: {
                    power_w: '4000.00',
                    daily_generation_kwh: '18.000',
                    inverter_status: 'NORMAL',
                    data_nature: 'ACTUAL',
                    data_status: 'FRESH',
                },
            })
            solarApi.getHistory.mockResolvedValue({ history: [] })

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            // Loading should eventually disappear
            await waitFor(
                () => {
                    expect(screen.queryByTestId('spms-loading-state')).not.toBeInTheDocument()
                },
                { timeout: 3000 }
            )

            // Telemetry UI should appear
            await waitFor(() => {
                expect(screen.getByText('GoodWe SEMS+ Connection')).toBeInTheDocument()
            })
        })

        it('connection failure exits loading and displays the error form without erasing email', async () => {
            const user = userEvent.setup()
            solarApi.saveConnection.mockRejectedValueOnce(
                new Error('GoodWe authentication failed.')
            )

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            // Loading must stop
            await waitFor(
                () => {
                    expect(screen.queryByTestId('spms-loading-state')).not.toBeInTheDocument()
                },
                { timeout: 3000 }
            )

            // Form must be back with error notice
            await waitFor(() => {
                expect(screen.getByText(/Connection Notice/i)).toBeInTheDocument()
            })

            // Email must still be there
            expect(screen.getByLabelText(/SEMS\+ Account/i)).toHaveValue('user@solar.org')
        })

        it('multiple-station response exits loading and shows station selection', async () => {
            const user = userEvent.setup()

            solarApi.saveConnection.mockRejectedValueOnce(
                new Error('Multiple power stations found.')
            )
            solarApi.discoverStations.mockResolvedValueOnce({
                stations: [
                    { station_id: 'st-1', station_name: 'Farm House PV', capacity_kw: 5.0 },
                    { station_id: 'st-2', station_name: 'City Home Rooftop', capacity_kw: 3.5 },
                ],
            })

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'multi@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'pass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            // Loading must stop
            await waitFor(
                () => {
                    expect(screen.queryByTestId('spms-loading-state')).not.toBeInTheDocument()
                },
                { timeout: 3000 }
            )

            // Station selection UI must appear
            await waitFor(() => {
                expect(screen.getByText(/Select Power Station/i)).toBeInTheDocument()
                expect(screen.getByText('Farm House PV')).toBeInTheDocument()
                expect(screen.getByText('City Home Rooftop')).toBeInTheDocument()
            })
        })

        it('loading status messages do not expose API internals or credentials', async () => {
            const user = userEvent.setup()

            let resolveSave
            solarApi.saveConnection.mockReturnValue(
                new Promise((resolve) => { resolveSave = resolve })
            )

            await setupUnconfiguredPage()

            await user.type(screen.getByLabelText(/SEMS\+ Account/i), 'user@solar.org')
            await user.type(screen.getByLabelText(/SEMS\+ Password/i), 'secretpass')
            await user.click(screen.getByRole('button', { name: /Connect Inverter/i }))

            await waitFor(() => {
                expect(screen.getByTestId('spms-loading-state')).toBeInTheDocument()
            })

            const loadingEl = screen.getByTestId('spms-loading-state')

            // No credentials in loading UI
            expect(loadingEl).not.toHaveTextContent('secretpass')
            expect(loadingEl).not.toHaveTextContent('user@solar.org')

            // No API/technical internals
            const technicalTerms = ['/api/', 'token', 'uuid', 'station_id', 'inverter_sn', 'endpoint']
            technicalTerms.forEach((term) => {
                expect(loadingEl.textContent.toLowerCase()).not.toContain(term)
            })

            // Clean up
            act(() => {
                resolveSave({
                    connection: makeConfiguredConnection(),
                    test_result: { success: true },
                })
            })
        })
    })
})
