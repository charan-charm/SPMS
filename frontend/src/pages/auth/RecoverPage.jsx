import { useState, useEffect, useRef, useCallback } from 'react'
import { Link, useSearchParams } from 'react-router'
import { KeyRound, CheckCircle2, AlertCircle, Eye, EyeOff, ArrowRight, ShieldCheck, Mail, RotateCcw, Clock } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '../../components/ui/card'
import { authApi } from '../../services/api/auth'
import { parseAuthError } from '../../lib/authErrors'

/** Mask email address for privacy: c*****n@gmail.com */
function maskEmail(email) {
    if (!email || !email.includes('@')) return email || ''
    const [local, domain] = email.split('@')
    if (local.length <= 2) return `${local[0]}*@${domain}`
    return `${local[0]}${'*'.repeat(Math.min(local.length - 2, 5))}${local[local.length - 1]}@${domain}`
}

const RESEND_COOLDOWN = 60

export function RecoverPage() {
    const [searchParams] = useSearchParams()
    const tokenFromUrl = searchParams.get('token') || searchParams.get('code') || ''

    // Journey stages: 'request' (Step 1) | 'verify' (Step 2) | 'password' (Step 3)
    const [stage, setStage] = useState(() => {
        if (!tokenFromUrl) return 'request'
        if (/^[0-9]{6}$/.test(tokenFromUrl)) {
            return 'verify'
        }
        return 'password'
    })

    // STEP 1: Request state
    const [requestEmail, setRequestEmail] = useState('')
    const [requestSubmitting, setRequestSubmitting] = useState(false)
    const [requestError, setRequestError] = useState(null)

    // STEP 2: 6-digit recovery code state
    const [code, setCode] = useState(() => {
        if (tokenFromUrl && /^[0-9]{6}$/.test(tokenFromUrl)) {
            return tokenFromUrl
        }
        return ''
    })
    const [codeError, setCodeError] = useState(null)
    const [verifySubmitting, setVerifySubmitting] = useState(false)
    const codeInputRef = useRef(null)

    // Resend state
    const [resending, setResending] = useState(false)
    const [resendSuccess, setResendSuccess] = useState(false)
    const [resendError, setResendError] = useState(null)
    const [cooldown, setCooldown] = useState(0)

    // STEP 3: Set new password state
    const [newPassword, setNewPassword] = useState('')
    const [confirmPassword, setConfirmPassword] = useState('')
    const [showNewPassword, setShowNewPassword] = useState(false)
    const [showConfirmPassword, setShowConfirmPassword] = useState(false)
    const [passwordSubmitting, setPasswordSubmitting] = useState(false)
    const [passwordErrors, setPasswordErrors] = useState({ fieldErrors: {}, generalError: null })
    const [resetSuccess, setResetSuccess] = useState(false)

    // Cooldown timer
    useEffect(() => {
        if (cooldown <= 0) return
        const timer = setInterval(() => {
            setCooldown((prev) => (prev <= 1 ? 0 : prev - 1))
        }, 1000)
        return () => clearInterval(timer)
    }, [cooldown])

    // STEP 1 Handler: Request Reset
    const handleRequestSubmit = async (e) => {
        e?.preventDefault()
        const trimmedEmail = requestEmail.trim()
        if (!trimmedEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
            setRequestError('Please enter a valid email address.')
            return
        }

        setRequestSubmitting(true)
        setRequestError(null)

        try {
            await authApi.requestRecovery({ email: trimmedEmail })
            setStage('verify')
            setCooldown(RESEND_COOLDOWN)
            setCode('')
            setCodeError(null)
            setTimeout(() => codeInputRef.current?.focus(), 150)
        } catch (err) {
            const parsed = parseAuthError(err)
            setRequestError(parsed.generalError || 'Unable to submit password recovery request.')
        } finally {
            setRequestSubmitting(false)
        }
    }

    // STEP 2 Handler: Verify 6-digit recovery code
    const handleVerifySubmit = useCallback(async (codeToVerify) => {
        const trimmedCode = (codeToVerify || code).replace(/\D/g, '').slice(0, 6)
        if (trimmedCode.length !== 6) {
            setCodeError('Please enter the 6-digit recovery code.')
            return
        }

        setVerifySubmitting(true)
        setCodeError(null)

        try {
            await authApi.verifyRecovery({
                code: trimmedCode,
                email: requestEmail.trim() || undefined,
            })
            // Code verified! Advance to Step 3
            setCode(trimmedCode)
            setStage('password')
        } catch (err) {
            const parsed = parseAuthError(err)
            if (parsed.isRateLimited) {
                setCodeError('Too many failed attempts. Please request a new recovery code.')
            } else {
                const apiMsg = parsed.fieldErrors?.code || parsed.fieldErrors?.token || parsed.generalError
                setCodeError(apiMsg || 'This recovery code is invalid or expired.')
            }
        } finally {
            setVerifySubmitting(false)
        }
    }, [code, requestEmail])

    const handleCodeChange = (e) => {
        const digits = e.target.value.replace(/\D/g, '').slice(0, 6)
        setCode(digits)
        setCodeError(null)
        if (digits.length === 6) {
            handleVerifySubmit(digits)
        }
    }

    const handlePaste = (e) => {
        e.preventDefault()
        const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6)
        setCode(pasted)
        if (pasted.length === 6) {
            handleVerifySubmit(pasted)
        }
    }

    const handleResend = async () => {
        const trimmedEmail = requestEmail.trim()
        if (cooldown > 0 || !trimmedEmail) return

        setResending(true)
        setResendError(null)
        setResendSuccess(false)
        setCode('')
        setCodeError(null)

        try {
            await authApi.requestRecovery({ email: trimmedEmail })
            setResendSuccess(true)
            setCooldown(RESEND_COOLDOWN)
            setTimeout(() => codeInputRef.current?.focus(), 150)
        } catch (err) {
            const parsed = parseAuthError(err)
            setResendError(parsed.generalError || 'Unable to resend recovery code. Please try again.')
        } finally {
            setResending(false)
        }
    }

    // STEP 3 Handler: Set New Password
    const handlePasswordSubmit = async (e) => {
        e.preventDefault()
        setPasswordErrors({ fieldErrors: {}, generalError: null })

        const fieldErrors = {}
        if (!newPassword) {
            fieldErrors.password = 'New password is required.'
        } else if (newPassword.length < 12) {
            fieldErrors.password = 'Password must be at least 12 characters.'
        } else if (newPassword.length > 200) {
            fieldErrors.password = 'Password must be at most 200 characters.'
        }

        if (!confirmPassword) {
            fieldErrors.confirmPassword = 'Please confirm your new password.'
        } else if (newPassword !== confirmPassword) {
            fieldErrors.confirmPassword = 'Passwords do not match.'
        }

        if (Object.keys(fieldErrors).length > 0) {
            setPasswordErrors({ fieldErrors, generalError: null })
            return
        }

        setPasswordSubmitting(true)

        try {
            const isSixDigit = /^[0-9]{6}$/.test(code.trim())
            await authApi.confirmRecovery({
                ...(isSixDigit ? { code: code.trim(), email: requestEmail.trim() || undefined } : {}),
                ...(!isSixDigit && tokenFromUrl ? { token: tokenFromUrl } : {}),
                password: newPassword,
            })
            setResetSuccess(true)
        } catch (err) {
            const parsed = parseAuthError(err)
            setPasswordErrors({
                fieldErrors: parsed.fieldErrors,
                generalError: parsed.generalError || 'Unable to reset password. The code may be expired.',
            })
        } finally {
            setPasswordSubmitting(false)
        }
    }

    return (
        <Card className="w-full border-border bg-surface shadow-2">
            {/* Password Reset Complete View */}
            {resetSuccess ? (
                <>
                    <CardHeader className="text-center pt-8">
                        <div className="mx-auto mb-3 grid size-14 place-items-center rounded-full bg-success/15 text-success">
                            <ShieldCheck className="size-8" aria-hidden="true" />
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold text-heading">
                            Password reset complete
                        </CardTitle>
                        <CardDescription className="text-sm text-muted max-w-sm mx-auto">
                            Your password has been changed successfully. All previous active sessions across all devices have been signed out for your security.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="pt-2 pb-6 text-center">
                        <Button asChild variant="primary" className="w-full">
                            <Link to="/login">
                                Sign In with New Password
                                <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                    </CardContent>
                </>
            ) : (
                <>
                    {/* Stepper Header */}
                    <CardHeader className="space-y-2 pb-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <div className="grid size-8 place-items-center rounded-lg bg-primary-soft text-primary">
                                    <KeyRound className="size-4" aria-hidden="true" />
                                </div>
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted">
                                    {stage === 'request' && 'Step 1 of 3 · Request'}
                                    {stage === 'verify' && 'Step 2 of 3 · Verification'}
                                    {stage === 'password' && 'Step 3 of 3 · New Password'}
                                </span>
                            </div>

                            {/* Visual Progress Dots */}
                            <div className="flex items-center gap-1.5" aria-hidden="true">
                                <span className={`h-1.5 w-5 rounded-full transition-colors ${stage === 'request' || stage === 'verify' || stage === 'password' ? 'bg-primary' : 'bg-border'}`} />
                                <span className={`h-1.5 w-5 rounded-full transition-colors ${stage === 'verify' || stage === 'password' ? 'bg-primary' : 'bg-border'}`} />
                                <span className={`h-1.5 w-5 rounded-full transition-colors ${stage === 'password' ? 'bg-primary' : 'bg-border'}`} />
                            </div>
                        </div>

                        <div>
                            <CardTitle className="font-display text-xl font-semibold text-heading">
                                {stage === 'request' && 'Reset your password'}
                                {stage === 'verify' && 'Enter recovery code'}
                                {stage === 'password' && 'Set new password'}
                            </CardTitle>
                            <CardDescription className="text-xs text-muted mt-0.5">
                                {stage === 'request' && "Enter your email address and we'll send you a 6-digit recovery code."}
                                {stage === 'verify' && 'Enter the 6-digit code sent to your email to verify your identity.'}
                                {stage === 'password' && 'Choose a strong password with at least 12 characters.'}
                            </CardDescription>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        {/* ─── STEP 1: Request Reset ─── */}
                        {stage === 'request' && (
                            <>
                                {requestError && (
                                    <div
                                        role="alert"
                                        className="flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                                    >
                                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <div>{requestError}</div>
                                    </div>
                                )}

                                <form onSubmit={handleRequestSubmit} noValidate className="space-y-4">
                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="recover-email"
                                            className="text-xs font-medium text-heading"
                                        >
                                            Email address
                                        </label>
                                        <Input
                                            id="recover-email"
                                            type="email"
                                            inputMode="email"
                                            autoComplete="email"
                                            autoCapitalize="none"
                                            autoCorrect="off"
                                            spellCheck={false}
                                            value={requestEmail}
                                            onChange={(e) => {
                                                setRequestEmail(e.target.value)
                                                if (requestError) setRequestError(null)
                                            }}
                                            placeholder="name@example.com"
                                            disabled={requestSubmitting}
                                            required
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={requestSubmitting}
                                        className="w-full"
                                    >
                                        Send recovery code
                                    </Button>
                                </form>

                                <div className="text-center pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setStage('verify')}
                                        className="text-xs text-muted hover:text-body hover:underline"
                                    >
                                        Already have a recovery code? Enter it here
                                    </button>
                                </div>
                            </>
                        )}

                        {/* ─── STEP 2: Enter 6-digit Recovery Code ─── */}
                        {stage === 'verify' && (
                            <>
                                {requestEmail && (
                                    <div className="flex items-center gap-2 rounded-md border border-primary/20 bg-primary/5 p-3 text-xs text-heading">
                                        <Mail className="size-4 shrink-0 text-primary" aria-hidden="true" />
                                        <span>
                                            We sent a 6-digit code to:{' '}
                                            <strong className="font-semibold text-heading">{maskEmail(requestEmail)}</strong>
                                        </span>
                                    </div>
                                )}

                                {resendSuccess && (
                                    <div
                                        role="status"
                                        className="flex items-center gap-2 rounded-md border border-success/30 bg-success/10 p-3 text-xs text-success"
                                    >
                                        <CheckCircle2 className="size-4 shrink-0" aria-hidden="true" />
                                        <span>A fresh recovery code has been sent to your email.</span>
                                    </div>
                                )}

                                {resendError && (
                                    <div
                                        role="alert"
                                        className="flex items-start gap-2 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                                    >
                                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <div>{resendError}</div>
                                    </div>
                                )}

                                {codeError && (
                                    <div
                                        role="alert"
                                        className="flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                                    >
                                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <div>{codeError}</div>
                                    </div>
                                )}

                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault()
                                        handleVerifySubmit(code)
                                    }}
                                    noValidate
                                    className="space-y-4"
                                >
                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="recovery-code"
                                            className="text-xs font-medium text-heading"
                                        >
                                            6-digit recovery code
                                        </label>
                                        <input
                                            ref={codeInputRef}
                                            id="recovery-code"
                                            type="text"
                                            inputMode="numeric"
                                            autoComplete="one-time-code"
                                            pattern="[0-9]{6}"
                                            maxLength={6}
                                            value={code}
                                            onChange={handleCodeChange}
                                            onPaste={handlePaste}
                                            disabled={verifySubmitting}
                                            placeholder="_ _ _ _ _ _"
                                            aria-label="6-digit recovery code"
                                            aria-describedby={codeError ? 'recovery-code-error' : undefined}
                                            className={[
                                                'w-full rounded-md border bg-input px-4 py-3',
                                                'text-center font-mono text-2xl font-semibold tracking-[0.45em] text-heading',
                                                'placeholder:text-muted/40 placeholder:text-xl placeholder:tracking-[0.5em]',
                                                'focus:outline-none focus:ring-2 focus:ring-focus/50',
                                                'transition-colors duration-150',
                                                codeError ? 'border-danger/50 bg-danger/5' : 'border-border',
                                                verifySubmitting ? 'cursor-wait opacity-60' : '',
                                            ].filter(Boolean).join(' ')}
                                        />
                                        <p className="text-[11px] text-muted">
                                            Enter the 6 numeric digits from your reset email.
                                        </p>
                                    </div>

                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={verifySubmitting}
                                        disabled={code.length !== 6}
                                        className="w-full"
                                    >
                                        Verify code
                                    </Button>
                                </form>

                                <div className="flex items-center justify-between border-t border-border/60 pt-3 text-xs">
                                    <button
                                        type="button"
                                        onClick={handleResend}
                                        disabled={resending || cooldown > 0 || !requestEmail}
                                        className="flex items-center gap-1.5 text-primary hover:underline disabled:opacity-50 disabled:no-underline cursor-pointer"
                                    >
                                        {resending ? (
                                            <>
                                                <RotateCcw className="size-3.5 animate-spin" aria-hidden="true" />
                                                Sending...
                                            </>
                                        ) : cooldown > 0 ? (
                                            <>
                                                <Clock className="size-3.5" aria-hidden="true" />
                                                Resend code in {cooldown}s
                                            </>
                                        ) : (
                                            <>
                                                <RotateCcw className="size-3.5" aria-hidden="true" />
                                                Resend code
                                            </>
                                        )}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setStage('request')
                                            setCode('')
                                            setCodeError(null)
                                        }}
                                        className="text-muted hover:text-body hover:underline"
                                    >
                                        Change email
                                    </button>
                                </div>
                            </>
                        )}

                        {/* ─── STEP 3: Set New Password ─── */}
                        {stage === 'password' && (
                            <>
                                {passwordErrors.generalError && (
                                    <div
                                        role="alert"
                                        className="flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                                    >
                                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <div>{passwordErrors.generalError}</div>
                                    </div>
                                )}

                                <form onSubmit={handlePasswordSubmit} noValidate className="space-y-4">
                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="reset-new-password"
                                            className="text-xs font-medium text-heading"
                                        >
                                            New password
                                        </label>
                                        <div className="relative">
                                            <Input
                                                id="reset-new-password"
                                                type={showNewPassword ? 'text' : 'password'}
                                                autoComplete="new-password"
                                                value={newPassword}
                                                onChange={(e) => {
                                                    setNewPassword(e.target.value)
                                                    if (passwordErrors.fieldErrors.password) {
                                                        setPasswordErrors((prev) => ({
                                                            ...prev,
                                                            fieldErrors: { ...prev.fieldErrors, password: undefined },
                                                        }))
                                                    }
                                                }}
                                                invalid={Boolean(passwordErrors.fieldErrors.password)}
                                                placeholder="At least 12 characters"
                                                disabled={passwordSubmitting}
                                                className="pr-10"
                                                required
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowNewPassword((prev) => !prev)}
                                                className="absolute top-1/2 right-2.5 -translate-y-1/2 text-muted hover:text-body focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                                aria-label={showNewPassword ? 'Hide password' : 'Show password'}
                                            >
                                                {showNewPassword ? (
                                                    <EyeOff className="size-4" aria-hidden="true" />
                                                ) : (
                                                    <Eye className="size-4" aria-hidden="true" />
                                                )}
                                            </button>
                                        </div>
                                        <p className="text-[11px] text-muted">
                                            Must be between 12 and 200 characters long.
                                        </p>
                                        {passwordErrors.fieldErrors.password && (
                                            <p role="alert" className="text-xs text-danger">
                                                {passwordErrors.fieldErrors.password}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="reset-confirm-password"
                                            className="text-xs font-medium text-heading"
                                        >
                                            Confirm new password
                                        </label>
                                        <div className="relative">
                                            <Input
                                                id="reset-confirm-password"
                                                type={showConfirmPassword ? 'text' : 'password'}
                                                autoComplete="new-password"
                                                value={confirmPassword}
                                                onChange={(e) => {
                                                    setConfirmPassword(e.target.value)
                                                    if (passwordErrors.fieldErrors.confirmPassword) {
                                                        setPasswordErrors((prev) => ({
                                                            ...prev,
                                                            fieldErrors: { ...prev.fieldErrors, confirmPassword: undefined },
                                                        }))
                                                    }
                                                }}
                                                invalid={Boolean(passwordErrors.fieldErrors.confirmPassword)}
                                                placeholder="Re-enter new password"
                                                disabled={passwordSubmitting}
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
                                        {passwordErrors.fieldErrors.confirmPassword && (
                                            <p role="alert" className="text-xs text-danger">
                                                {passwordErrors.fieldErrors.confirmPassword}
                                            </p>
                                        )}
                                    </div>

                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={passwordSubmitting}
                                        className="w-full"
                                    >
                                        Reset password
                                    </Button>
                                </form>
                            </>
                        )}
                    </CardContent>
                </>
            )}

            <CardFooter className="flex justify-center border-t border-border/60 pt-4 text-xs text-muted">
                <Link
                    to="/login"
                    className="font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                >
                    Back to Sign In
                </Link>
            </CardFooter>
        </Card>
    )
}
