import { useState } from 'react'
import { Link, useNavigate, useLocation } from 'react-router'
import { Eye, EyeOff, AlertCircle } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '../../components/ui/card'
import { AuthModeToggle } from '../../components/auth/AuthModeToggle'
import { useAuth } from '../../features/auth/useAuth'
import { parseAuthError } from '../../lib/authErrors'

export function LoginPage() {
    const navigate = useNavigate()
    const location = useLocation()
    const { login } = useAuth()

    const [environment, setEnvironment] = useState(() => {
        return location.state?.environment || 'DEMO'
    })
    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [showPassword, setShowPassword] = useState(false)

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
        }

        return fieldErrors
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setErrors({ fieldErrors: {}, generalError: null })

        const clientErrors = validateForm()
        if (Object.keys(clientErrors).length > 0) {
            setErrors({ fieldErrors: clientErrors, generalError: null })
            return
        }

        setSubmitting(true)
        try {
            await login({
                email: email.trim(),
                password,
                environment,
            })

            // Navigate to original requested route or default to /dashboard
            const destination = location.state?.from || '/dashboard'
            navigate(destination, { replace: true })
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
                    Sign in to SPMS
                </CardTitle>
                <CardDescription className="text-sm text-muted">
                    Enter your email and password to access your solar dashboard.
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
                            htmlFor="login-email"
                            className="text-xs font-medium text-heading"
                        >
                            Email address
                        </label>
                        <Input
                            id="login-email"
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
                        <div className="flex items-center justify-between">
                            <label
                                htmlFor="login-password"
                                className="text-xs font-medium text-heading"
                            >
                                Password
                            </label>
                            <Link
                                to="/recover"
                                className="text-xs font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                            >
                                Forgot password?
                            </Link>
                        </div>
                        <div className="relative">
                            <Input
                                id="login-password"
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="current-password"
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
                                placeholder="••••••••••••"
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
                        {errors.fieldErrors.password && (
                            <p role="alert" className="text-xs text-danger">
                                {errors.fieldErrors.password}
                            </p>
                        )}
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={submitting}
                        className="mt-2 w-full"
                    >
                        Sign in
                    </Button>
                </form>
            </CardContent>

            <CardFooter className="flex flex-col items-center justify-center border-t border-border/60 pt-4 text-xs text-muted">
                <p>
                    Don&apos;t have an account?{' '}
                    <Link
                        to="/register"
                        state={{ environment }}
                        className="font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                    >
                        Create an account
                    </Link>
                </p>
            </CardFooter>
        </Card>
    )
}
