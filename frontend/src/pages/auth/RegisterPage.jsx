import { useState } from 'react'
import { Link, useNavigate, useLocation } from 'react-router'
import { Eye, EyeOff, AlertCircle } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '../../components/ui/card'
import { AuthModeToggle } from '../../components/auth/AuthModeToggle'
import { authApi } from '../../services/api/auth'
import { parseAuthError } from '../../lib/authErrors'

export function RegisterPage() {
    const navigate = useNavigate()
    const location = useLocation()

    const [environment, setEnvironment] = useState(() => {
        return location.state?.environment || 'DEMO'
    })
    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [confirmPassword, setConfirmPassword] = useState('')
    const [showPassword, setShowPassword] = useState(false)
    const [showConfirmPassword, setShowConfirmPassword] = useState(false)

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({ fieldErrors: {}, generalError: null })

    const validateForm = () => {
        const fieldErrors = {}

        const trimmedEmail = email.trim()
        if (!trimmedEmail) {
            fieldErrors.email = 'Email address is required.'
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
            fieldErrors.email = 'Please enter a valid email address.'
        }

        if (!password) {
            fieldErrors.password = 'Password is required.'
        } else if (password.length < 12) {
            fieldErrors.password = 'Password must be at least 12 characters.'
        } else if (password.length > 200) {
            fieldErrors.password = 'Password must be at most 200 characters.'
        }

        if (!confirmPassword) {
            fieldErrors.confirmPassword = 'Please confirm your password.'
        } else if (password !== confirmPassword) {
            fieldErrors.confirmPassword = 'Passwords do not match.'
        }

        return fieldErrors
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        if (submitting) return
        setErrors({ fieldErrors: {}, generalError: null })

        const clientErrors = validateForm()
        if (Object.keys(clientErrors).length > 0) {
            setErrors({ fieldErrors: clientErrors, generalError: null })
            return
        }

        setSubmitting(true)
        try {
            await authApi.register({
                email: email.trim(),
                password,
                environment,
            })

            // Set cookie for environment hint persistence across tabs/reloads
            document.cookie = `spms_env=${encodeURIComponent(environment.toLowerCase())}; path=/; SameSite=Lax; max-age=86400`

            // Successful registration redirects to /verify with email and environment context in navigation state
            navigate('/verify', {
                state: {
                    email: email.trim(),
                    environment,
                    registered: true,
                },
            })
        } catch (err) {
            const parsed = parseAuthError(err)
            setErrors({
                fieldErrors: parsed.fieldErrors,
                generalError: parsed.generalError,
            })
        } finally {
            setSubmitting(false)
        }
    }

    return (
        <Card className="w-full border-border bg-surface shadow-2">
            <CardHeader className="space-y-1">
                <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                    Create your account
                </CardTitle>
                <CardDescription className="text-sm text-muted">
                    Get started with solar power monitoring and energy intelligence.
                </CardDescription>
            </CardHeader>

            <CardContent>
                {errors.generalError && (
                    <div
                        role="alert"
                        className="mb-5 flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                    >
                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div>{errors.generalError}</div>
                    </div>
                )}

                <form onSubmit={handleSubmit} noValidate className="space-y-4">
                    <AuthModeToggle
                        value={environment}
                        onChange={setEnvironment}
                        className="mb-4"
                    />
                    <div className="space-y-1.5">
                        <label
                            htmlFor="register-email"
                            className="text-xs font-medium text-heading"
                        >
                            Email address
                        </label>
                        <Input
                            id="register-email"
                            type="email"
                            inputMode="email"
                            autoComplete="email"
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            value={email}
                            onChange={(e) => {
                                setEmail(e.target.value)
                                if (errors.fieldErrors.email) {
                                    setErrors((prev) => ({
                                        ...prev,
                                        fieldErrors: { ...prev.fieldErrors, email: undefined },
                                    }))
                                }
                            }}
                            invalid={Boolean(errors.fieldErrors.email)}
                            placeholder="name@example.com"
                            disabled={submitting}
                            required
                        />
                        {errors.fieldErrors.email && (
                            <p role="alert" className="text-xs text-danger">
                                {errors.fieldErrors.email}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <label
                            htmlFor="register-password"
                            className="text-xs font-medium text-heading"
                        >
                            Password
                        </label>
                        <div className="relative">
                            <Input
                                id="register-password"
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="new-password"
                                value={password}
                                onChange={(e) => {
                                    setPassword(e.target.value)
                                    if (errors.fieldErrors.password) {
                                        setErrors((prev) => ({
                                            ...prev,
                                            fieldErrors: { ...prev.fieldErrors, password: undefined },
                                        }))
                                    }
                                }}
                                invalid={Boolean(errors.fieldErrors.password)}
                                placeholder="At least 12 characters"
                                disabled={submitting}
                                className="pr-10"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((prev) => !prev)}
                                className="absolute top-1/2 right-2.5 -translate-y-1/2 text-muted hover:text-body focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                {showPassword ? (
                                    <EyeOff className="size-4" aria-hidden="true" />
                                ) : (
                                    <Eye className="size-4" aria-hidden="true" />
                                )}
                            </button>
                        </div>
                        <p className="text-[11px] text-muted">
                            Must be at least 12 characters long.
                        </p>
                        {errors.fieldErrors.password && (
                            <p role="alert" className="text-xs text-danger">
                                {errors.fieldErrors.password}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <label
                            htmlFor="register-confirm-password"
                            className="text-xs font-medium text-heading"
                        >
                            Confirm password
                        </label>
                        <div className="relative">
                            <Input
                                id="register-confirm-password"
                                type={showConfirmPassword ? 'text' : 'password'}
                                autoComplete="new-password"
                                value={confirmPassword}
                                onChange={(e) => {
                                    setConfirmPassword(e.target.value)
                                    if (errors.fieldErrors.confirmPassword) {
                                        setErrors((prev) => ({
                                            ...prev,
                                            fieldErrors: { ...prev.fieldErrors, confirmPassword: undefined },
                                        }))
                                    }
                                }}
                                invalid={Boolean(errors.fieldErrors.confirmPassword)}
                                placeholder="Re-enter password"
                                disabled={submitting}
                                className="pr-10"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowConfirmPassword((prev) => !prev)}
                                className="absolute top-1/2 right-2.5 -translate-y-1/2 text-muted hover:text-body focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                aria-label={showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'}
                            >
                                {showConfirmPassword ? (
                                    <EyeOff className="size-4" aria-hidden="true" />
                                ) : (
                                    <Eye className="size-4" aria-hidden="true" />
                                )}
                            </button>
                        </div>
                        {errors.fieldErrors.confirmPassword && (
                            <p role="alert" className="text-xs text-danger">
                                {errors.fieldErrors.confirmPassword}
                            </p>
                        )}
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={submitting}
                        className="mt-2 w-full"
                    >
                        Create account
                    </Button>
                </form>
            </CardContent>

            <CardFooter className="flex flex-col items-center justify-center border-t border-border/60 pt-4 text-xs text-muted">
                <p>
                    Already have an account?{' '}
                    <Link
                        to="/login"
                        state={{ environment }}
                        className="font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                    >
                        Sign in
                    </Link>
                </p>
            </CardFooter>
        </Card>
    )
}
