import { useState, useEffect, useRef, useCallback } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import { CheckCircle2, AlertCircle, Mail, RotateCcw, ArrowRight, LoaderCircle, Clock } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '../../components/ui/card'
import { authApi } from '../../services/api/auth'
import { parseAuthError } from '../../lib/authErrors'

/** Mask an email for privacy: c*****n@gmail.com */
function maskEmail(email) {
    if (!email || !email.includes('@')) return email || ''
    const [local, domain] = email.split('@')
    if (local.length <= 2) return `${local[0]}*@${domain}`
    return `${local[0]}${'*'.repeat(Math.min(local.length - 2, 5))}${local[local.length - 1]}@${domain}`
}

/** Resend cooldown in seconds */
const RESEND_COOLDOWN = 60

export function VerifyPage() {
    const location = useLocation()
    const [searchParams] = useSearchParams()

    const queryCode = (searchParams.get('code') || searchParams.get('token') || '').replace(/\D/g, '').slice(0, 6)
    const queryEmail = searchParams.get('email') || ''
    const emailFromState = location.state?.email || queryEmail || ''
    const environmentFromState = location.state?.environment || searchParams.get('env') || null
    const registeredFromState = Boolean(location.state?.registered)

    // 6-digit code input
    const [code, setCode] = useState(queryCode)
    const inputRef = useRef(null)

    // Verification states: 'idle' | 'verifying' | 'success' | 'error'
    const [verifyStatus, setVerifyStatus] = useState('idle')
    const [verifyError, setVerifyError] = useState(null)

    // Resend state
    const [resending, setResending] = useState(false)
    const [resendSuccess, setResendSuccess] = useState(false)
    const [resendError, setResendError] = useState(null)

    // Resend cooldown countdown
    const [cooldown, setCooldown] = useState(registeredFromState ? RESEND_COOLDOWN : 0)

    const startCooldown = useCallback(() => {
        setCooldown(RESEND_COOLDOWN)
    }, [])

    useEffect(() => {
        if (cooldown <= 0) return

        const timer = setInterval(() => {
            setCooldown((prev) => (prev <= 1 ? 0 : prev - 1))
        }, 1000)

        return () => clearInterval(timer)
    }, [cooldown])

    const handleVerify = useCallback(async (codeToVerify) => {
        if (verifyStatus === 'verifying') return
        const trimmed = (codeToVerify || '').replace(/\D/g, '').slice(0, 6)
        if (trimmed.length !== 6) {
            setVerifyError('Please enter the 6-digit code from your email.')
            return
        }

        setVerifyStatus('verifying')
        setVerifyError(null)

        try {
            const payload = { code: trimmed }
            if (emailFromState) payload.email = emailFromState
            if (environmentFromState) payload.environment = environmentFromState

            await authApi.verifyEmail(payload)
            setVerifyStatus('success')
        } catch (err) {
            const parsed = parseAuthError(err)
            setVerifyStatus('error')
            if (parsed.isRateLimited) {
                setVerifyError('Too many failed attempts. Please request a new code.')
            } else {
                const apiMsg = parsed.fieldErrors?.code || parsed.fieldErrors?.token || parsed.generalError
                if (apiMsg && apiMsg.toLowerCase().includes('expired')) {
                    setVerifyError('This verification code has expired. Please request a new code below.')
                } else if (apiMsg && apiMsg.toLowerCase().includes('already been used')) {
                    setVerifyError('This verification code has already been used. Please request a new code if needed.')
                } else if (apiMsg && apiMsg.toLowerCase().includes('invalid')) {
                    setVerifyError('This verification code is invalid. Please check the code and try again.')
                } else {
                    setVerifyError(apiMsg || 'This code is invalid or has expired. Request a new one.')
                }
            }
        }
    }, [emailFromState, environmentFromState, verifyStatus])

    const handleCodeChange = (e) => {
        const digits = e.target.value.replace(/\D/g, '').slice(0, 6)
        setCode(digits)
        setVerifyError(null)
        // Auto-submit when all 6 digits are entered
        if (digits.length === 6) {
            handleVerify(digits)
        }
    }

    const handlePaste = (e) => {
        e.preventDefault()
        const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6)
        setCode(pasted)
        if (pasted.length === 6) {
            handleVerify(pasted)
        }
    }

    // Auto-verify once on mount if 6-digit code was provided in URL query params
    const autoVerifiedRef = useRef(false)
    useEffect(() => {
        if (!autoVerifiedRef.current && queryCode.length === 6) {
            autoVerifiedRef.current = true
            handleVerify(queryCode)
        }
    }, [queryCode, handleVerify])

    const [resendEmail, setResendEmail] = useState(emailFromState)

    const handleResend = async (e) => {
        e?.preventDefault()
        const emailToResend = resendEmail.trim()
        if (cooldown > 0 || !emailToResend) return

        setResending(true)
        setResendError(null)
        setResendSuccess(false)
        setCode('')
        setVerifyStatus('idle')
        setVerifyError(null)

        try {
            const payload = { email: emailToResend }
            if (environmentFromState) payload.environment = environmentFromState

            await authApi.resendVerification(payload)
            setResendSuccess(true)
            startCooldown()
            // Focus code input after resend
            setTimeout(() => inputRef.current?.focus(), 100)
        } catch (err) {
            const parsed = parseAuthError(err)
            if (parsed.isRateLimited) {
                setResendError('Too many resend requests. Please wait before trying again.')
            } else {
                setResendError(parsed.generalError || 'Unable to resend the code. Please try again.')
            }
        } finally {
            setResending(false)
        }
    }

    const isVerifying = verifyStatus === 'verifying'

    if (verifyStatus === 'success') {
        return (
            <Card className="w-full border-border bg-surface shadow-2">
                <CardHeader className="text-center">
                    <div className="mx-auto mb-3 grid size-14 place-items-center rounded-full bg-success/15 text-success">
                        <CheckCircle2 className="size-8" aria-hidden="true" />
                    </div>
                    <CardTitle className="font-display text-2xl font-semibold text-heading">
                        Email verified!
                    </CardTitle>
                    <CardDescription className="text-sm text-muted">
                        Your account is now active and ready to use.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 pt-2 text-center">
                    <Button asChild variant="primary" className="w-full">
                        <Link to="/login">
                            Proceed to Sign In
                            <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        )
    }

    return (
        <Card className="w-full border-border bg-surface shadow-2">
            <CardHeader className="space-y-1">
                <div className="flex items-center gap-2">
                    <div className="grid size-9 place-items-center rounded-lg bg-primary-soft text-primary">
                        <Mail className="size-5" aria-hidden="true" />
                    </div>
                    <div>
                        <CardTitle className="font-display text-xl font-semibold text-heading">
                            Verify your email
                        </CardTitle>
                        <CardDescription className="text-xs text-muted">
                            Enter the 6-digit code from your inbox
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-5">
                {/* Email target display */}
                {emailFromState && (
                    <div className="rounded-md border border-primary/20 bg-primary-soft/40 px-3 py-2.5 text-xs">
                        <p className="text-muted">
                            We&apos;ve sent a 6-digit code to:
                        </p>
                        <p className="mt-0.5 font-semibold text-heading tracking-wide">
                            {maskEmail(emailFromState)}
                        </p>
                    </div>
                )}

                {/* Error banner */}
                {verifyError && verifyStatus !== 'verifying' && (
                    <div
                        role="alert"
                        className="flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                    >
                        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div>{verifyError}</div>
                    </div>
                )}

                {/* Code input */}
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        handleVerify(code)
                    }}
                    className="space-y-3"
                >
                    <div className="space-y-1.5">
                        <label
                            htmlFor="verify-code"
                            className="text-xs font-medium text-heading"
                        >
                            Verification code
                        </label>

                        {/* Single wide input — numeric, large, clear */}
                        <input
                            ref={inputRef}
                            id="verify-code"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            value={code}
                            onChange={handleCodeChange}
                            onPaste={handlePaste}
                            disabled={isVerifying}
                            placeholder="_ _ _ _ _ _"
                            aria-label="6-digit verification code"
                            aria-describedby={verifyError ? 'verify-code-error' : undefined}
                            className={[
                                'w-full rounded-md border bg-input px-4 py-3',
                                'text-center font-mono text-2xl font-semibold tracking-[0.45em] text-heading',
                                'placeholder:text-muted/40 placeholder:text-xl placeholder:tracking-[0.5em]',
                                'focus:outline-none focus:ring-2 focus:ring-focus/50',
                                'transition-colors duration-150',
                                verifyError && verifyStatus === 'error'
                                    ? 'border-danger/50 bg-danger/5'
                                    : 'border-border',
                                isVerifying ? 'cursor-wait opacity-60' : '',
                            ].filter(Boolean).join(' ')}
                        />

                        <p className="text-[11px] text-muted">
                            The code expires in {AccountService_VERIFICATION_TTL_HOURS} hours. Digits only — paste works too.
                        </p>
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        className="w-full"
                        disabled={isVerifying || code.length < 6}
                    >
                        {isVerifying ? (
                            <>
                                <LoaderCircle className="mr-2 size-4 animate-spin" aria-hidden="true" />
                                Verifying…
                            </>
                        ) : (
                            'Verify email'
                        )}
                    </Button>
                </form>

                {/* Resend section */}
                <div className="relative border-t border-border/60 pt-4 space-y-3">
                    <div>
                        <h3 className="text-xs font-semibold text-heading">Didn&apos;t receive the email?</h3>
                        <p className="mt-0.5 text-[11px] text-muted">
                            Check your spam folder, then request a new code below.
                        </p>
                    </div>

                    {/* Email input — shown only when no email was passed in navigation state */}
                    {!emailFromState && (
                        <div className="space-y-1">
                            <label htmlFor="resend-email" className="text-xs font-medium text-heading">
                                Email address
                            </label>
                            <input
                                id="resend-email"
                                type="email"
                                autoComplete="email"
                                value={resendEmail}
                                onChange={(e) => setResendEmail(e.target.value)}
                                placeholder="Enter your email to resend"
                                className="w-full rounded-md border border-border bg-input px-3 py-2 text-sm text-heading placeholder:text-muted/50 focus:outline-none focus:ring-2 focus:ring-focus/50"
                            />
                        </div>
                    )}

                    {resendSuccess && (
                        <div
                            role="status"
                            aria-live="polite"
                            className="flex items-start gap-2 rounded-md border border-success/30 bg-success/10 p-2.5 text-xs text-success"
                        >
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>
                                If your account exists and is pending verification, a fresh code has been sent.
                            </span>
                        </div>
                    )}

                    {resendError && (
                        <div
                            role="alert"
                            className="flex items-start gap-2 rounded-md border border-danger/20 bg-danger/5 p-2.5 text-xs text-danger"
                        >
                            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>{resendError}</span>
                        </div>
                    )}

                    <Button
                        type="button"
                        variant="secondary"
                        size="default"
                        onClick={handleResend}
                        disabled={resending || cooldown > 0 || !resendEmail.trim()}
                        className="w-full"
                    >
                        {resending ? (
                            <>
                                <LoaderCircle className="mr-2 size-3.5 animate-spin" aria-hidden="true" />
                                Sending…
                            </>
                        ) : cooldown > 0 ? (
                            <>
                                <Clock className="mr-2 size-3.5" aria-hidden="true" />
                                Resend code in {cooldown}s
                            </>
                        ) : (
                            <>
                                <RotateCcw className="mr-2 size-3.5" aria-hidden="true" />
                                Resend code
                            </>
                        )}
                    </Button>
                </div>
            </CardContent>

            <CardFooter className="flex justify-center border-t border-border/60 pt-4 text-xs text-muted gap-4">
                <Link
                    to="/register"
                    className="font-medium text-muted hover:text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus transition-colors"
                >
                    Change email
                </Link>
                <span aria-hidden="true" className="text-border">|</span>
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

// TTL constant used in UI copy — mirrors backend AccountService::VERIFICATION_TTL_HOURS
const AccountService_VERIFICATION_TTL_HOURS = 24
