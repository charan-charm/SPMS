import { StrictMode } from 'react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route, useLocation } from 'react-router'
import { AuthContext } from '../auth/AuthContext'
import { HouseholdContext } from '../household/HouseholdContext'
import { OnboardingPage } from '../../pages/onboarding/OnboardingPage'
import { RealOnboardingForm } from '../../components/onboarding/RealOnboardingForm'
import { DemoOnboardingNotice } from '../../components/onboarding/DemoOnboardingNotice'
import { ProtectedRoute } from '../auth/ProtectedRoute'
import { AppHeader } from '../../components/navigation/AppHeader'
import { ThemeProvider } from '../../app/theme/ThemeProvider'
import { LeafBackdrop } from '../../components/decorative/LeafBackdrop'
import { OnboardingAmbience } from '../../components/decorative/OnboardingAmbience'
import { OnboardingLayout } from '../../layouts/OnboardingLayout'

function LocationDisplay() {
    const location = useLocation()
    return <div data-testid="location">{location.pathname}</div>
}

describe('SPMS O3 Onboarding Flow', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    describe('REAL Onboarding Journey', () => {
        it('renders the REAL onboarding form with refined heading, guidance, required fields, and GoodWe deferral notice', () => {
            const createHousehold = vi.fn()

            render(
                <HouseholdContext.Provider value={{ createHousehold, isLoading: false, hasHousehold: false }}>
                    <RealOnboardingForm />
                </HouseholdContext.Provider>,
            )

            // Refined heading and supporting text
            expect(screen.getByRole('heading', { name: /set up your household/i })).toBeInTheDocument()
            expect(
                screen.getByText(/tell spms a little about your home so we can personalize your solar and energy insights\./i),
            ).toBeInTheDocument()

            // What you'll need guidance block
            expect(screen.getByText(/what you'll need/i)).toBeInTheDocument()
            expect(screen.getByText(/found on your electricity utility bill/i)).toBeInTheDocument()

            // Form inputs
            expect(screen.getByLabelText(/household name/i)).toBeInTheDocument()
            expect(screen.getByLabelText(/location \/ city/i)).toBeInTheDocument()
            expect(screen.getByLabelText(/timezone/i)).toHaveValue('Asia/Kolkata')
            expect(screen.getByLabelText(/electricity service number/i)).toBeInTheDocument()

            // Exact GoodWe deferral notice
            expect(
                screen.getByText(/goodwe connection can be completed later\. live solar data remains unavailable until your inverter is connected\./i),
            ).toBeInTheDocument()

            // Strictly NO hardware collection fields in O3
            expect(screen.queryByLabelText(/serial number/i)).not.toBeInTheDocument()
            expect(screen.queryByLabelText(/inverter model/i)).not.toBeInTheDocument()
            expect(screen.queryByLabelText(/sems portal/i)).not.toBeInTheDocument()
            expect(screen.queryByLabelText(/wifi password/i)).not.toBeInTheDocument()

            // Card surface and width follows AuthLayout pattern
            const card = screen.getByRole('heading', { name: /set up your household/i }).closest('.w-full')
            expect(card).toHaveClass('border-border')
            expect(card).toHaveClass('bg-surface')
            expect(card).toHaveClass('shadow-2')
            expect(card).not.toHaveClass('max-w-xl')
        })

        it('validates required fields client-side before submission', async () => {
            const user = userEvent.setup()
            const createHousehold = vi.fn()

            render(
                <HouseholdContext.Provider value={{ createHousehold, isLoading: false, hasHousehold: false }}>
                    <RealOnboardingForm />
                </HouseholdContext.Provider>,
            )

            const submitBtn = screen.getByRole('button', { name: /complete setup/i })
            await user.click(submitBtn)

            expect(screen.getByText(/household name is required/i)).toBeInTheDocument()
            expect(screen.getByText(/location or city is required/i)).toBeInTheDocument()
            expect(screen.getByText(/electricity service number is required/i)).toBeInTheDocument()
            expect(createHousehold).not.toHaveBeenCalled()
        })

        it('submits valid household data and invokes onComplete', async () => {
            const user = userEvent.setup()
            const createHousehold = vi.fn().mockResolvedValueOnce({
                household: { id: 'hh-1', label: 'Primary Residence', service_number: '10002000300' },
            })
            const onComplete = vi.fn()

            render(
                <HouseholdContext.Provider value={{ createHousehold, isLoading: false, hasHousehold: false }}>
                    <RealOnboardingForm onComplete={onComplete} />
                </HouseholdContext.Provider>,
            )

            await user.type(screen.getByLabelText(/household name/i), 'Primary Residence')
            await user.type(screen.getByLabelText(/location \/ city/i), 'Hyderabad, Telangana')
            await user.type(screen.getByLabelText(/electricity service number/i), '1000-2000-300')

            const submitBtn = screen.getByRole('button', { name: /complete setup/i })
            await user.click(submitBtn)

            await waitFor(() => {
                expect(createHousehold).toHaveBeenCalledWith({
                    label: 'Primary Residence',
                    location_label: 'Hyderabad, Telangana',
                    timezone: 'Asia/Kolkata',
                    service_number: '1000-2000-300',
                })
                expect(onComplete).toHaveBeenCalled()
            })
        })
    })

    describe('DEMO Onboarding Journey', () => {
        it('renders DEMO notice with simulated data marking and invokes ensureDemo', async () => {
            const ensureDemo = vi.fn().mockResolvedValueOnce({
                household: { id: 'hh-demo', label: 'SPMS Demonstration Residence' },
            })
            const onComplete = vi.fn()

            render(
                <HouseholdContext.Provider value={{ ensureDemo, hasHousehold: false, isLoading: false }}>
                    <DemoOnboardingNotice onComplete={onComplete} />
                </HouseholdContext.Provider>,
            )

            // Prominent DEMO badge
            expect(screen.getByText(/demo — simulated demonstration data/i)).toBeInTheDocument()
            expect(screen.getByText(/preparing demonstration environment/i)).toBeInTheDocument()

            // Triggered ensureDemo server call
            await waitFor(() => {
                expect(ensureDemo).toHaveBeenCalled()
            })

            // Completes and transitions
            await waitFor(() => {
                expect(onComplete).toHaveBeenCalled()
            }, { timeout: 2000 })
        })

        it('OnboardingPage renders DemoOnboardingNotice for DEMO accounts and RealOnboardingForm for REAL accounts', () => {
            const demoAccount = { id: 'acc-demo', email: 'demo@demo.spms.local', mode: 'DEMO_TEST' }
            const realAccount = { id: 'acc-real', email: 'owner@example.com', mode: 'REAL' }

            const { rerender } = render(
                <AuthContext.Provider value={{ account: demoAccount }}>
                    <HouseholdContext.Provider value={{ hasHousehold: false, isLoading: false, ensureDemo: vi.fn() }}>
                        <MemoryRouter>
                            <OnboardingPage />
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByText(/demo — simulated demonstration data/i)).toBeInTheDocument()
            expect(screen.queryByLabelText(/household name/i)).not.toBeInTheDocument()

            rerender(
                <AuthContext.Provider value={{ account: realAccount }}>
                    <HouseholdContext.Provider value={{ hasHousehold: false, isLoading: false, createHousehold: vi.fn() }}>
                        <MemoryRouter>
                            <OnboardingPage />
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByLabelText(/household name/i)).toBeInTheDocument()
            expect(screen.queryByText(/demo — simulated demonstration data/i)).not.toBeInTheDocument()
        })

        it('resolves existing DEMO1/prepared household successfully without calling ensureDemo or hanging', async () => {
            const ensureDemo = vi.fn()
            const onComplete = vi.fn()

            render(
                <HouseholdContext.Provider
                    value={{
                        household: { id: '10c06071-ebfe-4eb8-819a-4b1cb4fad0a1', label: 'Solar Surplus Residence' },
                        hasHousehold: true,
                        isLoading: false,
                        ensureDemo,
                    }}
                >
                    <DemoOnboardingNotice onComplete={onComplete} />
                </HouseholdContext.Provider>,
            )

            expect(screen.getByText(/demo — simulated demonstration data/i)).toBeInTheDocument()
            expect(screen.getByText(/preparing demonstration environment/i)).toBeInTheDocument()

            // Since household already exists, ensureDemo is NOT called unnecessarily
            expect(ensureDemo).not.toHaveBeenCalled()

            // Smoothly resolves and completes
            await waitFor(() => {
                expect(onComplete).toHaveBeenCalled()
            }, { timeout: 2000 })
        })

        it('resolves successfully even when wrapped in React StrictMode (no unmount/remount deadlock)', async () => {
            const ensureDemo = vi.fn().mockResolvedValueOnce({
                household: { id: 'hh-demo', label: 'Demonstration Residence' },
            })
            const onComplete = vi.fn()

            render(
                <StrictMode>
                    <HouseholdContext.Provider value={{ ensureDemo, hasHousehold: false, isLoading: false }}>
                        <DemoOnboardingNotice onComplete={onComplete} />
                    </HouseholdContext.Provider>
                </StrictMode>,
            )

            await waitFor(() => {
                expect(onComplete).toHaveBeenCalled()
            }, { timeout: 2000 })
        })

        it('shows clear error state with Retry action on provisioning failure and recovers when retried', async () => {
            const user = userEvent.setup()
            const ensureDemo = vi
                .fn()
                .mockRejectedValueOnce(new Error('Network error provisioning demo household'))
                .mockResolvedValueOnce({
                    household: { id: 'hh-demo-retry', label: 'Demonstration Residence' },
                })
            const onComplete = vi.fn()

            render(
                <HouseholdContext.Provider value={{ ensureDemo, hasHousehold: false, isLoading: false }}>
                    <DemoOnboardingNotice onComplete={onComplete} />
                </HouseholdContext.Provider>,
            )

            // Spinner stops and clear error state is displayed with Retry button
            await waitFor(() => {
                expect(screen.getByText(/workspace provisioning failed/i)).toBeInTheDocument()
                expect(screen.getByText(/network error provisioning demo household/i)).toBeInTheDocument()
                expect(screen.getByRole('button', { name: /retry setup/i })).toBeInTheDocument()
            })

            // onComplete is NOT called while in error state
            expect(onComplete).not.toHaveBeenCalled()

            // User clicks Retry button
            await user.click(screen.getByRole('button', { name: /retry setup/i }))

            // Second ensureDemo call is triggered
            await waitFor(() => {
                expect(ensureDemo).toHaveBeenCalledTimes(2)
            })

            // Now successfully completes and transitions
            await waitFor(() => {
                expect(onComplete).toHaveBeenCalled()
            }, { timeout: 2000 })
        })
    })

    describe('Route Guarding & Redirection', () => {
        it('redirects authenticated users without a household from /dashboard to /onboarding', () => {
            render(
                <AuthContext.Provider value={{ status: 'authenticated' }}>
                    <HouseholdContext.Provider value={{ hasHousehold: false, isLoading: false, status: 'loaded' }}>
                        <MemoryRouter initialEntries={['/dashboard']}>
                            <Routes>
                                <Route element={<ProtectedRoute requireHousehold />}>
                                    <Route path="/dashboard" element={<div>Dashboard Content</div>} />
                                </Route>
                                <Route path="/onboarding" element={<LocationDisplay />} />
                            </Routes>
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByTestId('location')).toHaveTextContent('/onboarding')
            expect(screen.queryByText(/dashboard content/i)).not.toBeInTheDocument()
        })

        it('redirects users with an active household away from /onboarding to /dashboard', () => {
            render(
                <AuthContext.Provider value={{ status: 'authenticated' }}>
                    <HouseholdContext.Provider value={{ hasHousehold: true, isLoading: false, status: 'loaded' }}>
                        <MemoryRouter initialEntries={['/onboarding']}>
                            <Routes>
                                <Route element={<ProtectedRoute />}>
                                    <Route path="/onboarding" element={<div>Onboarding Form</div>} />
                                </Route>
                                <Route path="/dashboard" element={<LocationDisplay />} />
                            </Routes>
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByTestId('location')).toHaveTextContent('/dashboard')
            expect(screen.queryByText(/onboarding form/i)).not.toBeInTheDocument()
        })
    })

    describe('AppHeader DEMO Badge', () => {
        it('displays DEMO — Simulated Demonstration Data badge in AppHeader for DEMO_TEST accounts', () => {
            const demoAccount = { id: 'acc-demo', email: 'demo@spms.local', mode: 'DEMO_TEST' }
            const realAccount = { id: 'acc-real', email: 'real@spms.local', mode: 'REAL' }

            const { rerender } = render(
                <ThemeProvider>
                    <AuthContext.Provider value={{ account: demoAccount, logout: vi.fn() }}>
                        <MemoryRouter>
                            <AppHeader />
                        </MemoryRouter>
                    </AuthContext.Provider>
                </ThemeProvider>,
            )

            expect(screen.getByText('DEMO — Simulated Demonstration Data')).toBeInTheDocument()

            rerender(
                <ThemeProvider>
                    <AuthContext.Provider value={{ account: realAccount, logout: vi.fn() }}>
                        <MemoryRouter>
                            <AppHeader />
                        </MemoryRouter>
                    </AuthContext.Provider>
                </ThemeProvider>,
            )

            expect(screen.queryByText('DEMO — Simulated Demonstration Data')).not.toBeInTheDocument()
        })

        it('displays Preview Onboarding link in AppHeader exclusively for DEMO_TEST accounts', () => {
            const demoAccount = { id: 'acc-demo', email: 'demo@spms.local', mode: 'DEMO_TEST' }
            const realAccount = { id: 'acc-real', email: 'real@spms.local', mode: 'REAL' }

            const { rerender } = render(
                <ThemeProvider>
                    <AuthContext.Provider value={{ account: demoAccount, logout: vi.fn() }}>
                        <MemoryRouter>
                            <AppHeader />
                        </MemoryRouter>
                    </AuthContext.Provider>
                </ThemeProvider>,
            )

            expect(screen.getByRole('link', { name: /preview onboarding/i })).toBeInTheDocument()

            rerender(
                <ThemeProvider>
                    <AuthContext.Provider value={{ account: realAccount, logout: vi.fn() }}>
                        <MemoryRouter>
                            <AppHeader />
                        </MemoryRouter>
                    </AuthContext.Provider>
                </ThemeProvider>,
            )

            expect(screen.queryByRole('link', { name: /preview onboarding/i })).not.toBeInTheDocument()
        })
    })

    describe('DEMO Onboarding Inspection Sandbox (Development / Demo Only)', () => {
        it('allows DEMO accounts to open onboarding inspection via ?preview=demo with clean inputs and sandbox banner', () => {
            const demoAccount = { id: 'acc-demo', email: 'demo@demo.spms.local', mode: 'DEMO_TEST' }

            render(
                <AuthContext.Provider value={{ account: demoAccount }}>
                    <HouseholdContext.Provider
                        value={{
                            household: { label: 'Existing Demo Residence', location_label: 'Hyderabad', timezone: 'Asia/Kolkata' },
                            hasHousehold: true,
                            isLoading: false,
                        }}
                    >
                        <MemoryRouter initialEntries={['/onboarding?preview=demo']}>
                            <OnboardingPage />
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            // Banner is visible
            expect(screen.getByText(/onboarding inspection preview/i)).toBeInTheDocument()
            // Form inputs start fresh and clean, not pre-populated from existing household
            const nameInput = screen.getByLabelText(/household name/i)
            expect(nameInput).toHaveValue('')
        })

        it('strictly redirects REAL accounts with existing household away from /onboarding even with ?preview=demo', () => {
            const realAccount = { id: 'acc-real', email: 'real@example.com', mode: 'REAL' }

            render(
                <AuthContext.Provider value={{ status: 'authenticated', account: realAccount }}>
                    <HouseholdContext.Provider value={{ hasHousehold: true, isLoading: false, status: 'loaded' }}>
                        <MemoryRouter initialEntries={['/onboarding?preview=demo']}>
                            <Routes>
                                <Route element={<ProtectedRoute />}>
                                    <Route path="/onboarding" element={<OnboardingPage />} />
                                </Route>
                                <Route path="/dashboard" element={<LocationDisplay />} />
                            </Routes>
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByTestId('location')).toHaveTextContent('/dashboard')
            expect(screen.queryByText(/onboarding inspection preview/i)).not.toBeInTheDocument()
        })

        it('allows REAL accounts WITHOUT household to reach onboarding and perform real setup', () => {
            const realAccount = { id: 'acc-real', email: 'real@example.com', mode: 'REAL' }

            render(
                <AuthContext.Provider value={{ status: 'authenticated', account: realAccount }}>
                    <HouseholdContext.Provider value={{ hasHousehold: false, isLoading: false, status: 'loaded' }}>
                        <MemoryRouter initialEntries={['/onboarding']}>
                            <Routes>
                                <Route element={<ProtectedRoute />}>
                                    <Route path="/onboarding" element={<OnboardingPage />} />
                                </Route>
                                <Route path="/dashboard" element={<LocationDisplay />} />
                            </Routes>
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            expect(screen.getByLabelText(/household name/i)).toBeInTheDocument()
            expect(screen.queryByText(/onboarding inspection preview/i)).not.toBeInTheDocument()
        })

        it('does NOT mutate the server household when submitting steps in inspection mode', async () => {
            const user = userEvent.setup()
            const demoAccount = { id: 'acc-demo', email: 'demo@demo.spms.local', mode: 'DEMO_TEST' }
            const createHousehold = vi.fn()
            const updateHousehold = vi.fn()

            render(
                <AuthContext.Provider value={{ account: demoAccount }}>
                    <HouseholdContext.Provider
                        value={{
                            household: { label: 'Existing Residence' },
                            hasHousehold: true,
                            isLoading: false,
                            createHousehold,
                            updateHousehold,
                        }}
                    >
                        <MemoryRouter initialEntries={['/onboarding?preview=demo']}>
                            <OnboardingPage />
                        </MemoryRouter>
                    </HouseholdContext.Provider>
                </AuthContext.Provider>,
            )

            // Fill Step 1
            await user.type(screen.getByLabelText(/household name/i), 'Inspected Household')
            await user.type(screen.getByLabelText(/location \/ city/i), 'Test City')
            await user.click(screen.getByRole('button', { name: /continue to electricity service/i }))

            // Neither createHousehold nor updateHousehold should have been called
            expect(createHousehold).not.toHaveBeenCalled()
            expect(updateHousehold).not.toHaveBeenCalled()

            // Advanced to Step 2 locally
            await waitFor(() => {
                expect(screen.getByLabelText(/electricity service number/i)).toBeInTheDocument()
            })
        })
    })

    describe('Onboarding Botanical Watermark', () => {
        it('renders LeafBackdrop with onboarding variant without sidebar offset and with peripheral positioning', () => {
            const { container, rerender } = render(<LeafBackdrop variant="onboarding" />)

            const layer = container.querySelector('.spms-leaf-layer')
            expect(layer).toBeInTheDocument()
            expect(layer).toHaveClass('spms-leaf-layer-onboarding')
            expect(layer).toHaveClass('pointer-events-none')
            expect(layer).not.toHaveClass('lg:left-64')

            rerender(<LeafBackdrop variant="default" />)
            const defaultLayer = container.querySelector('.spms-leaf-layer')
            expect(defaultLayer).toHaveClass('lg:left-64')
            expect(defaultLayer).not.toHaveClass('spms-leaf-layer-onboarding')
        })
    })

    describe('Onboarding Background Ambience', () => {
        it('renders OnboardingAmbience with wide-square grid and localized solar glow', () => {
            const { container } = render(<OnboardingAmbience />)

            const ambience = screen.getByTestId('onboarding-ambience')
            expect(ambience).toBeInTheDocument()
            expect(ambience).toHaveClass('pointer-events-none')
            expect(ambience).toHaveAttribute('aria-hidden', 'true')

            const grid = screen.getByTestId('onboarding-grid')
            expect(grid).toBeInTheDocument()
            expect(grid.style.backgroundSize).toBe('56px 56px')

            const solarGlow = screen.getByTestId('onboarding-solar-glow')
            expect(solarGlow).toBeInTheDocument()
            expect(solarGlow.style.background).toContain('radial-gradient')
            expect(solarGlow).toHaveClass('rounded-full')

            // Interactive cursor-proximity glow matching AuthLayout
            const cursorGlow = screen.getByTestId('onboarding-cursor-glow')
            expect(cursorGlow).toBeInTheDocument()

            // Does not cause overflow or intercept events
            expect(container.querySelector('.pointer-events-none')).toBeInTheDocument()
        })

        it('OnboardingLayout integrates both OnboardingAmbience and LeafBackdrop onboarding variant', () => {
            const { container } = render(
                <ThemeProvider>
                    <AuthContext.Provider value={{ account: { email: 'test@example.com' } }}>
                        <MemoryRouter>
                            <OnboardingLayout />
                        </MemoryRouter>
                    </AuthContext.Provider>
                </ThemeProvider>,
            )

            expect(screen.getByTestId('onboarding-ambience')).toBeInTheDocument()
            expect(screen.getByTestId('onboarding-grid')).toBeInTheDocument()
            expect(screen.getByTestId('onboarding-cursor-glow')).toBeInTheDocument()
            expect(screen.getByTestId('onboarding-solar-glow')).toBeInTheDocument()
            expect(container.querySelector('.spms-leaf-layer-onboarding')).toBeInTheDocument()

            // Authentication family layout structure
            const root = container.firstChild
            expect(root).toHaveClass('bg-surface-alt')
            expect(root).toHaveClass('place-items-center')
            expect(root).toHaveClass('min-h-screen')

            // Centered shell matching AuthLayout rhythm
            const shell = container.querySelector('.max-w-lg')
            expect(shell).toBeInTheDocument()

            // Auth header with BrandMark and ThemeSwitch
            expect(screen.getByLabelText(/spms home/i)).toBeInTheDocument()
            expect(screen.getByRole('switch', { name: /switch to/i })).toBeInTheDocument()
            expect(screen.getByRole('button', { name: /sign out/i })).toBeInTheDocument()

            // Strictly NO sticky dashboard header
            expect(container.querySelector('.sticky')).not.toBeInTheDocument()

            // Auth brand footer note
            expect(screen.getByText(/spms — solar power management system/i)).toBeInTheDocument()
        })
    })
})
