import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route, useLocation } from 'react-router'
import { ApiError } from '../../services/api/client'
import { AuthProvider } from './AuthProvider'
import { useAuth } from './useAuth'
import { ProtectedRoute } from './ProtectedRoute'
import { PublicAuthRoute } from './PublicAuthRoute'
import { LoginPage } from '../../pages/auth/LoginPage'
import { RegisterPage } from '../../pages/auth/RegisterPage'
import { VerifyPage } from '../../pages/auth/VerifyPage'
import { RecoverPage } from '../../pages/auth/RecoverPage'
import { authApi } from '../../services/api/auth'
import * as sessionModule from '../../services/api/session'
import * as clientModule from '../../services/api/client'

vi.mock('../../services/api/auth', () => ({
    authApi: {
        register: vi.fn(),
        verifyEmail: vi.fn(),
        resendVerification: vi.fn(),
        login: vi.fn(),
        logout: vi.fn(),
        requestRecovery: vi.fn(),
        verifyRecovery: vi.fn(),
        confirmRecovery: vi.fn(),
    },
}))

vi.mock('../../services/api/session', () => ({
    loadServerSession: vi.fn(),
}))

function LocationDisplay() {
    const location = useLocation()
    return <div data-testid="location">{location.pathname + location.search}</div>
}

describe('SPMS O2 Authentication Flow', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        localStorage.clear()
        sessionStorage.clear()
        sessionModule.loadServerSession.mockResolvedValue({
            authenticated: false,
            account: null,
        })
    })

    describe('Register page', () => {
        it('validates required fields, email format, and password length client-side', async () => {
            const user = userEvent.setup()
            render(
                <MemoryRouter initialEntries={['/register']}>
                    <RegisterPage />
                </MemoryRouter>,
            )

            const submitBtn = screen.getByRole('button', { name: /create account/i })
            await user.click(submitBtn)

            expect(screen.getByText(/email address is required/i)).toBeInTheDocument()
            expect(screen.getByText(/password is required/i)).toBeInTheDocument()

            // Invalid email
            await user.type(screen.getByLabelText(/email address/i), 'invalid-email')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'short12')
            await user.type(screen.getByLabelText(/^confirm password$/i, { selector: 'input' }), 'different12')
            await user.click(submitBtn)

            expect(screen.getByText(/please enter a valid email address/i)).toBeInTheDocument()
            expect(screen.getByText(/password must be at least 12 characters/i)).toBeInTheDocument()
            expect(screen.getByText(/passwords do not match/i)).toBeInTheDocument()
            expect(authApi.register).not.toHaveBeenCalled()
        })

        it('submits valid registration and redirects to /verify with email context', async () => {
            const user = userEvent.setup()
            authApi.register.mockResolvedValueOnce({
                account: { id: 'acc-1', email: 'test@example.com', status: 'PENDING_VERIFICATION' },
                verification: { required: true, sent: true },
            })

            render(
                <MemoryRouter initialEntries={['/register']}>
                    <Routes>
                        <Route path="/register" element={<RegisterPage />} />
                        <Route path="/verify" element={<LocationDisplay />} />
                    </Routes>
                </MemoryRouter>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'test@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.type(screen.getByLabelText(/^confirm password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.click(screen.getByRole('button', { name: /create account/i }))

            await waitFor(() => {
                expect(authApi.register).toHaveBeenCalledWith({
                    email: 'test@example.com',
                    password: 'correct-horse-battery-42',
                    environment: 'DEMO',
                })
                expect(screen.getByTestId('location')).toHaveTextContent('/verify')
            })
        })

        it('defaults to DEMO mode with supporting text and allows toggling to REAL', async () => {
            const user = userEvent.setup()
            authApi.register.mockResolvedValueOnce({
                account: { id: 'acc-1', email: 'real@example.com', status: 'PENDING_VERIFICATION' },
                verification: { required: true, sent: true },
            })

            render(
                <MemoryRouter initialEntries={['/register']}>
                    <Routes>
                        <Route path="/register" element={<RegisterPage />} />
                        <Route path="/verify" element={<LocationDisplay />} />
                    </Routes>
                </MemoryRouter>,
            )

            // Radiogroup semantics
            const radiogroup = screen.getByRole('radiogroup', { name: /spms mode/i })
            expect(radiogroup).toBeInTheDocument()

            const demoRadio = screen.getByRole('radio', { name: /demo/i })
            const realRadio = screen.getByRole('radio', { name: /real/i })

            expect(demoRadio).toHaveAttribute('aria-checked', 'true')
            expect(realRadio).toHaveAttribute('aria-checked', 'false')

            // Default demo supporting text
            expect(
                screen.getByText(/explore spms using prepared demonstration data\. no goodwe connection required\./i),
            ).toBeInTheDocument()

            // Toggle to Real
            await user.click(realRadio)
            expect(realRadio).toHaveAttribute('aria-checked', 'true')
            expect(demoRadio).toHaveAttribute('aria-checked', 'false')
            expect(
                screen.getByText(/use your actual household and connect your goodwe solar system\./i),
            ).toBeInTheDocument()

            // Submitting while REAL is selected sends environment: 'REAL'
            await user.type(screen.getByLabelText(/email address/i), 'real@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.type(screen.getByLabelText(/^confirm password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.click(screen.getByRole('button', { name: /create account/i }))

            await waitFor(() => {
                expect(authApi.register).toHaveBeenCalledWith({
                    email: 'real@example.com',
                    password: 'correct-horse-battery-42',
                    environment: 'REAL',
                })
            })
        })

        it('supports keyboard navigation (arrow keys) in mode toggle', async () => {
            const user = userEvent.setup()
            render(
                <MemoryRouter initialEntries={['/register']}>
                    <RegisterPage />
                </MemoryRouter>,
            )

            const demoRadio = screen.getByRole('radio', { name: /demo/i })
            const realRadio = screen.getByRole('radio', { name: /real/i })

            demoRadio.focus()
            expect(demoRadio).toHaveFocus()

            // Press ArrowRight to select Real
            await user.keyboard('{ArrowRight}')
            expect(realRadio).toHaveAttribute('aria-checked', 'true')

            // Press ArrowLeft to select Demo
            await user.keyboard('{ArrowLeft}')
            expect(demoRadio).toHaveAttribute('aria-checked', 'true')
        })

        it('displays server validation errors and rate-limiting safely', async () => {
            const user = userEvent.setup()
            authApi.register.mockRejectedValueOnce(
                new ApiError({
                    code: 'VALIDATION_ERROR',
                    message: 'email is already registered.',
                    status: 422,
                    context: { field: 'email' },
                }),
            )

            render(
                <MemoryRouter initialEntries={['/register']}>
                    <RegisterPage />
                </MemoryRouter>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'duplicate@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.type(screen.getByLabelText(/^confirm password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.click(screen.getByRole('button', { name: /create account/i }))

            await waitFor(() => {
                expect(screen.getByText(/email is already registered/i)).toBeInTheDocument()
            })
        })
    })

    describe('Verify page', () => {
        it('shows masked email from navigation state and accepts 6-digit code', async () => {
            userEvent.setup()

            render(
                <MemoryRouter initialEntries={[{ pathname: '/verify', state: { email: 'user@example.com', registered: true } }]}>
                    <VerifyPage />
                </MemoryRouter>,
            )

            // Should show masked email
            expect(screen.getByText(/u\*\*r@example\.com/i)).toBeInTheDocument()

            const codeInput = screen.getByLabelText(/6-digit verification code/i)
            expect(codeInput).toBeInTheDocument()
            expect(codeInput).toHaveAttribute('inputMode', 'numeric')
        })

        it('auto-submits code when all 6 digits are typed', async () => {
            const user = userEvent.setup()
            authApi.verifyEmail.mockResolvedValueOnce({
                account: { id: 'acc-1', email: 'user@example.com', status: 'ACTIVE', email_verified: true },
            })

            render(
                <MemoryRouter initialEntries={['/verify']}>
                    <VerifyPage />
                </MemoryRouter>,
            )

            const codeInput = screen.getByLabelText(/6-digit verification code/i)
            await user.type(codeInput, '482731')

            await waitFor(() => {
                expect(authApi.verifyEmail).toHaveBeenCalledWith({ code: '482731' })
                expect(screen.getByText(/email verified!/i)).toBeInTheDocument()
            })
        })

        it('displays error state when code is invalid or expired', async () => {
            const user = userEvent.setup()
            authApi.verifyEmail.mockRejectedValueOnce(
                new ApiError({
                    code: 'VALIDATION_ERROR',
                    message: 'verification code is invalid or expired.',
                    status: 422,
                    context: { field: 'code' },
                }),
            )

            render(
                <MemoryRouter initialEntries={['/verify']}>
                    <VerifyPage />
                </MemoryRouter>,
            )

            const codeInput = screen.getByLabelText(/6-digit verification code/i)
            await user.type(codeInput, '000000')

            await waitFor(() => {
                expect(screen.getByRole('alert')).toBeInTheDocument()
            })
        })

        it('displays rate-limited state when too many wrong attempts', async () => {
            const user = userEvent.setup()
            authApi.verifyEmail.mockRejectedValueOnce(
                new ApiError({
                    code: 'RATE_LIMITED',
                    message: 'Too many failed verification attempts. Please request a new code.',
                    status: 429,
                    context: { field: 'code' },
                }),
            )

            render(
                <MemoryRouter initialEntries={['/verify']}>
                    <VerifyPage />
                </MemoryRouter>,
            )

            const codeInput = screen.getByLabelText(/6-digit verification code/i)
            await user.type(codeInput, '111111')

            await waitFor(() => {
                expect(screen.getByRole('alert')).toBeInTheDocument()
                expect(screen.getByText(/too many.*attempts/i)).toBeInTheDocument()
            })
        })

        it('resends verification code with uniform success message', async () => {
            const user = userEvent.setup()
            authApi.resendVerification.mockResolvedValueOnce({ accepted: true, sent: true })

            render(
                <MemoryRouter initialEntries={[{ pathname: '/verify', state: { email: 'user@example.com' } }]}>
                    <VerifyPage />
                </MemoryRouter>,
            )

            const resendBtn = screen.getByRole('button', { name: /resend code/i })
            await user.click(resendBtn)

            await waitFor(() => {
                expect(authApi.resendVerification).toHaveBeenCalled()
                expect(screen.getByText(/if your account exists and is pending verification/i)).toBeInTheDocument()
            })
        })
    })

    describe('Login page', () => {
        it('validates required fields before submitting', async () => {
            const user = userEvent.setup()
            render(
                <MemoryRouter initialEntries={['/login']}>
                    <LoginPage />
                </MemoryRouter>,
            )

            await user.click(screen.getByRole('button', { name: /sign in/i }))
            expect(screen.getByText(/email address is required/i)).toBeInTheDocument()
            expect(screen.getByText(/password is required/i)).toBeInTheDocument()
            expect(authApi.login).not.toHaveBeenCalled()
        })

        it('authenticates successfully and redirects to intended destination', async () => {
            const user = userEvent.setup()
            const setCsrfSpy = vi.spyOn(clientModule, 'setCsrfToken')

            authApi.login.mockResolvedValueOnce({
                account: { id: 'acc-1', email: 'solar@example.com', role: 'USER', status: 'ACTIVE' },
                session: { csrf_token: 'csrf-secret-123' },
            })

            render(
                <AuthProvider>
                    <MemoryRouter
                        initialEntries={[
                            { pathname: '/login', state: { from: '/bills' } },
                        ]}
                    >
                        <Routes>
                            <Route path="/login" element={<LoginPage />} />
                            <Route path="/bills" element={<LocationDisplay />} />
                        </Routes>
                    </MemoryRouter>
                </AuthProvider>,
            )

            // Wait for initial session restore to settle as unauthenticated
            await waitFor(() => {
                expect(screen.getByLabelText(/email address/i)).toBeInTheDocument()
            })

            await user.type(screen.getByLabelText(/email address/i), 'solar@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.click(screen.getByRole('button', { name: /sign in/i }))

            await waitFor(() => {
                expect(authApi.login).toHaveBeenCalledWith({
                    email: 'solar@example.com',
                    password: 'correct-horse-battery-42',
                    environment: 'DEMO',
                })
                expect(screen.getByTestId('location')).toHaveTextContent('/bills')
            })

            setCsrfSpy.mockRestore()
        })

        it('handles INVALID_CREDENTIALS with uniform user-friendly message', async () => {
            const user = userEvent.setup()
            authApi.login.mockRejectedValueOnce(
                new ApiError({
                    code: 'INVALID_CREDENTIALS',
                    message: 'Invalid email or password.',
                    status: 401,
                }),
            )

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/login']}>
                        <LoginPage />
                    </MemoryRouter>
                </AuthProvider>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'solar@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'wrong-password')
            await user.click(screen.getByRole('button', { name: /sign in/i }))

            await waitFor(() => {
                expect(screen.getByText(/invalid email or password/i)).toBeInTheDocument()
            })
        })

        it('handles RATE_LIMITED error with retry details', async () => {
            const user = userEvent.setup()
            authApi.login.mockRejectedValueOnce(
                new ApiError({
                    code: 'RATE_LIMITED',
                    message: 'Too many attempts. Try again in 15 minutes.',
                    status: 429,
                    context: { retry_after_seconds: 900 },
                }),
            )

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/login']}>
                        <LoginPage />
                    </MemoryRouter>
                </AuthProvider>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'solar@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'wrong-password')
            await user.click(screen.getByRole('button', { name: /sign in/i }))

            await waitFor(() => {
                expect(screen.getByText(/too many attempts\. please try again in 15 minutes/i)).toBeInTheDocument()
            })
        })
    })

    describe('Session restoration and AuthContext', () => {
        it('restores authenticated session from GET /auth/session on mount', async () => {
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: true,
                account: { id: 'acc-1', email: 'restored@example.com', role: 'USER' },
            })

            function StatusDisplay() {
                const { status, account } = useAuth()
                return (
                    <div>
                        <span data-testid="status">{status}</span>
                        <span data-testid="email">{account?.email}</span>
                    </div>
                )
            }

            render(
                <AuthProvider>
                    <StatusDisplay />
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.getByTestId('status')).toHaveTextContent('authenticated')
                expect(screen.getByTestId('email')).toHaveTextContent('restored@example.com')
            })
        })

        it('settles as unauthenticated when session call returns 401 unauthenticated', async () => {
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: false,
                account: null,
            })

            function StatusDisplay() {
                const { status, account } = useAuth()
                return (
                    <div>
                        <span data-testid="status">{status}</span>
                        <span data-testid="account">{account === null ? 'null' : 'present'}</span>
                    </div>
                )
            }

            render(
                <AuthProvider>
                    <StatusDisplay />
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated')
                expect(screen.getByTestId('account')).toHaveTextContent('null')
            })
        })
    })

    describe('Route guards', () => {
        it('ProtectedRoute redirects unauthenticated users to /login preserving destination', async () => {
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: false,
                account: null,
            })

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/dashboard']}>
                        <Routes>
                            <Route element={<ProtectedRoute />}>
                                <Route path="/dashboard" element={<div>Protected Dashboard Content</div>} />
                            </Route>
                            <Route path="/login" element={<LocationDisplay />} />
                        </Routes>
                    </MemoryRouter>
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.queryByText(/protected dashboard content/i)).not.toBeInTheDocument()
                expect(screen.getByTestId('location')).toHaveTextContent('/login')
            })
        })

        it('ProtectedRoute renders protected content when authenticated', async () => {
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: true,
                account: { id: 'acc-1', email: 'owner@example.com' },
            })

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/dashboard']}>
                        <Routes>
                            <Route element={<ProtectedRoute />}>
                                <Route path="/dashboard" element={<div>Protected Dashboard Content</div>} />
                            </Route>
                        </Routes>
                    </MemoryRouter>
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.getByText(/protected dashboard content/i)).toBeInTheDocument()
            })
        })

        it('PublicAuthRoute redirects authenticated users to /dashboard', async () => {
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: true,
                account: { id: 'acc-1', email: 'owner@example.com' },
            })

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/login']}>
                        <Routes>
                            <Route element={<PublicAuthRoute />}>
                                <Route path="/login" element={<div>Login Form</div>} />
                            </Route>
                            <Route path="/dashboard" element={<LocationDisplay />} />
                        </Routes>
                    </MemoryRouter>
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.queryByText(/login form/i)).not.toBeInTheDocument()
                expect(screen.getByTestId('location')).toHaveTextContent('/dashboard')
            })
        })
    })

    describe('Logout flow', () => {
        it('calls logout API and clears local session state', async () => {
            const user = userEvent.setup()
            sessionModule.loadServerSession.mockResolvedValueOnce({
                authenticated: true,
                account: { id: 'acc-1', email: 'user@example.com' },
            })
            authApi.logout.mockResolvedValueOnce({ logged_out: true })

            function LogoutComponent() {
                const { logout, status } = useAuth()
                return (
                    <div>
                        <span data-testid="status">{status}</span>
                        <button type="button" onClick={logout}>Sign out</button>
                    </div>
                )
            }

            render(
                <AuthProvider>
                    <LogoutComponent />
                </AuthProvider>,
            )

            await waitFor(() => {
                expect(screen.getByTestId('status')).toHaveTextContent('authenticated')
            })

            await user.click(screen.getByRole('button', { name: /sign out/i }))

            await waitFor(() => {
                expect(authApi.logout).toHaveBeenCalled()
                expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated')
            })
        })
    })

    describe('Password recovery', () => {
        it('completes 3-step journey: Request -> Enter 6-digit code -> Set new password', async () => {
            const user = userEvent.setup()
            authApi.requestRecovery.mockResolvedValueOnce({ accepted: true })
            authApi.verifyRecovery.mockResolvedValueOnce({ valid: true, account: { id: 'acc-1', email: 'recover@example.com' } })
            authApi.confirmRecovery.mockResolvedValueOnce({
                password_reset: true,
                account: { id: 'acc-1', email: 'recover@example.com' },
            })

            render(
                <MemoryRouter initialEntries={['/recover']}>
                    <RecoverPage />
                </MemoryRouter>,
            )

            // Step 1: Request reset code
            await user.type(screen.getByLabelText(/email address/i), 'recover@example.com')
            await user.click(screen.getByRole('button', { name: /send recovery code/i }))

            await waitFor(() => {
                expect(authApi.requestRecovery).toHaveBeenCalledWith({ email: 'recover@example.com' })
                expect(screen.getByText(/enter recovery code/i)).toBeInTheDocument()
                expect(screen.getByLabelText(/6-digit recovery code/i)).toBeInTheDocument()
            })

            // Step 2: Enter 6-digit recovery code
            const codeInput = screen.getByLabelText(/6-digit recovery code/i)
            await user.type(codeInput, '482731')

            await waitFor(() => {
                expect(authApi.verifyRecovery).toHaveBeenCalledWith({
                    code: '482731',
                    email: 'recover@example.com',
                })
                expect(screen.getByText(/set new password/i)).toBeInTheDocument()
            })

            // Step 3: Enter new password and confirm
            await user.type(screen.getByLabelText(/^new password$/i, { selector: 'input' }), 'a-new-strong-password-123')
            await user.type(screen.getByLabelText(/^confirm new password$/i, { selector: 'input' }), 'a-new-strong-password-123')
            await user.click(screen.getByRole('button', { name: /reset password/i }))

            await waitFor(() => {
                expect(authApi.confirmRecovery).toHaveBeenCalledWith({
                    code: '482731',
                    email: 'recover@example.com',
                    password: 'a-new-strong-password-123',
                })
                expect(screen.getByText(/password reset complete/i)).toBeInTheDocument()
                expect(screen.getByText(/all previous active sessions across all devices have been signed out/i)).toBeInTheDocument()
                expect(screen.getByRole('link', { name: /sign in with new password/i })).toBeInTheDocument()
            })
        })

        it('displays error in Step 2 when recovery code is invalid', async () => {
            const user = userEvent.setup()
            authApi.requestRecovery.mockResolvedValueOnce({ accepted: true })
            authApi.verifyRecovery.mockRejectedValueOnce(
                new ApiError({
                    code: 'VALIDATION_ERROR',
                    message: 'recovery code is invalid or expired.',
                    status: 422,
                }),
            )

            render(
                <MemoryRouter initialEntries={['/recover']}>
                    <RecoverPage />
                </MemoryRouter>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'recover@example.com')
            await user.click(screen.getByRole('button', { name: /send recovery code/i }))

            await waitFor(() => {
                expect(screen.getByLabelText(/6-digit recovery code/i)).toBeInTheDocument()
            })

            const codeInput = screen.getByLabelText(/6-digit recovery code/i)
            await user.type(codeInput, '000000')

            await waitFor(() => {
                expect(screen.getByRole('alert')).toBeInTheDocument()
                expect(screen.getByText(/recovery code is invalid or expired/i)).toBeInTheDocument()
            })
        })

        it('supports direct entry via URL token query param', async () => {
            const user = userEvent.setup()
            authApi.confirmRecovery.mockResolvedValueOnce({
                password_reset: true,
                account: { id: 'acc-1', email: 'user@example.com' },
            })

            render(
                <MemoryRouter initialEntries={['/recover?token=recovery-token-xyz']}>
                    <RecoverPage />
                </MemoryRouter>,
            )

            await user.type(screen.getByLabelText(/^new password$/i, { selector: 'input' }), 'a-new-strong-password-123')
            await user.type(screen.getByLabelText(/^confirm new password$/i, { selector: 'input' }), 'a-new-strong-password-123')
            await user.click(screen.getByRole('button', { name: /reset password/i }))

            await waitFor(() => {
                expect(authApi.confirmRecovery).toHaveBeenCalledWith({
                    token: 'recovery-token-xyz',
                    password: 'a-new-strong-password-123',
                })
                expect(screen.getByText(/password reset complete/i)).toBeInTheDocument()
            })
        })
    })

    describe('Security assertions', () => {
        it('never writes authentication tokens, session secrets, or CSRF tokens to localStorage or sessionStorage', async () => {
            const user = userEvent.setup()
            authApi.login.mockResolvedValueOnce({
                account: { id: 'acc-1', email: 'secure@example.com', role: 'USER', status: 'ACTIVE' },
                session: { csrf_token: 'secret-csrf-value-999' },
            })

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/login']}>
                        <LoginPage />
                    </MemoryRouter>
                </AuthProvider>,
            )

            await user.type(screen.getByLabelText(/email address/i), 'secure@example.com')
            await user.type(screen.getByLabelText(/^password$/i, { selector: 'input' }), 'correct-horse-battery-42')
            await user.click(screen.getByRole('button', { name: /sign in/i }))

            await waitFor(() => {
                expect(authApi.login).toHaveBeenCalled()
            })

            // Assert neither storage mechanism contains any auth secrets
            const localKeys = Object.keys(localStorage)
            const sessionKeys = Object.keys(sessionStorage)

            for (const key of [...localKeys, ...sessionKeys]) {
                const lower = key.toLowerCase()
                expect(lower).not.toContain('csrf')
                expect(lower).not.toContain('session')
                expect(lower).not.toContain('token')
                expect(lower).not.toContain('auth')
            }
        })
    })

    describe('Mode toggle persistence between Login and Register', () => {
        it('preserves selected mode when navigating from Login to Register and back', async () => {
            const user = userEvent.setup()

            render(
                <AuthProvider>
                    <MemoryRouter initialEntries={['/login']}>
                        <Routes>
                            <Route path="/login" element={<LoginPage />} />
                            <Route path="/register" element={<RegisterPage />} />
                        </Routes>
                    </MemoryRouter>
                </AuthProvider>,
            )

            // On /login, defaults to DEMO
            const loginRealRadio = screen.getByRole('radio', { name: /real/i })
            expect(screen.getByRole('radio', { name: /demo/i })).toHaveAttribute('aria-checked', 'true')

            // Toggle to REAL
            await user.click(loginRealRadio)
            expect(loginRealRadio).toHaveAttribute('aria-checked', 'true')

            // Navigate to /register via "Create an account"
            const createAccountLink = screen.getByRole('link', { name: /create an account/i })
            await user.click(createAccountLink)

            // On /register, REAL should be preserved!
            const registerRealRadio = screen.getByRole('radio', { name: /real/i })
            expect(registerRealRadio).toHaveAttribute('aria-checked', 'true')
            expect(
                screen.getByText(/use your actual household and connect your goodwe solar system\./i),
            ).toBeInTheDocument()

            // Switch back to DEMO on /register
            const registerDemoRadio = screen.getByRole('radio', { name: /demo/i })
            await user.click(registerDemoRadio)
            expect(registerDemoRadio).toHaveAttribute('aria-checked', 'true')

            // Navigate back to /login via "Sign in"
            const signInLink = screen.getByRole('link', { name: /sign in/i })
            await user.click(signInLink)

            // On /login, DEMO should be preserved!
            const finalLoginDemoRadio = screen.getByRole('radio', { name: /demo/i })
            expect(finalLoginDemoRadio).toHaveAttribute('aria-checked', 'true')
            expect(
                screen.getByText(/explore spms using prepared demonstration data\. no goodwe connection required\./i),
            ).toBeInTheDocument()
        })
    })
})
