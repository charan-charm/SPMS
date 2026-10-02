import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import {
    Home,
    Zap,
    Sun,
    FileText,
    CheckCircle2,
    ArrowRight,
    ArrowLeft,
    AlertCircle,
    Info,
    ExternalLink,
    Clock,
    Sparkles,
    Check,
} from 'lucide-react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { useHousehold } from '../household/useHousehold'
import { SolarConnectForm } from '../solar/SolarConnectForm'
import { BillUploadPanel } from '../bills/BillUploadPanel'
import { uploadBill, listBills } from '../../services/api/bills'
import { solarApi } from '../../services/api/solar'

const STEPS = [
    { id: 1, title: 'Household Details', short: 'Household', icon: Home },
    { id: 2, title: 'Electricity Service', short: 'Service', icon: Zap },
    { id: 3, title: 'GoodWe Connection', short: 'Solar', icon: Sun },
    { id: 4, title: 'Bill / History Setup', short: 'Bills', icon: FileText },
    { id: 5, title: 'Finish', short: 'Finish', icon: CheckCircle2 },
]

export function GuidedOnboarding({ onComplete, isInspection = false }) {
    const navigate = useNavigate()
    const [searchParams, setSearchParams] = useSearchParams()
    const { household, hasHousehold, createHousehold, updateHousehold, readiness, refreshHousehold } = useHousehold()

    // Determine initial active step
    const initialStepParam = parseInt(searchParams.get('step') || '1', 10)
    const validInitialStep = !isNaN(initialStepParam) && initialStepParam >= 1 && initialStepParam <= 5 ? initialStepParam : 1
    const [currentStep, setCurrentStep] = useState(validInitialStep)

    // Step 1: Household form fields (clean blank start in inspection mode)
    const [label, setLabel] = useState(isInspection ? '' : (household?.label || ''))
    const [locationLabel, setLocationLabel] = useState(isInspection ? '' : (household?.location_label || ''))
    const [timezone, setTimezone] = useState(isInspection ? 'Asia/Kolkata' : (household?.timezone || 'Asia/Kolkata'))

    // Step 2: Service number
    const [serviceNumber, setServiceNumber] = useState(isInspection ? '' : (household?.service_number || ''))

    // Step 3: Solar status
    const [solarConnected, setSolarConnected] = useState(!isInspection && readiness?.solar === 'CONNECTED')
    const [solarInverterSn, setSolarInverterSn] = useState(null)

    // Step 4: Bills status
    const [billsUploadedCount, setBillsUploadedCount] = useState(0)
    const [uploadingBill, setUploadingBill] = useState(false)
    const [billUploadSuccess, setBillUploadSuccess] = useState(null)
    const [billUploadError, setBillUploadError] = useState(null)

    // Form feedback
    const [errors, setErrors] = useState({})
    const [generalError, setGeneralError] = useState(null)
    const [isSubmitting, setIsSubmitting] = useState(false)


    // Check solar status on mount or step 3
    useEffect(() => {
        let mounted = true
        if (hasHousehold && !isInspection) {
            solarApi.getStatus().then((res) => {
                if (!mounted) return
                if (res?.connected) {
                    setSolarConnected(true)
                    setSolarInverterSn(res?.inverter_sn || res?.connection?.inverter_sn || null)
                }
            }).catch(() => {})
        }
        return () => {
            mounted = false
        }
    }, [hasHousehold, currentStep, isInspection])

    // Check bills count on mount or step 4
    useEffect(() => {
        let mounted = true
        if (hasHousehold && !isInspection) {
            listBills().then((res) => {
                if (!mounted) return
                if (Array.isArray(res?.data?.bills)) {
                    setBillsUploadedCount(res.data.bills.length)
                }
            }).catch(() => {})
        }
        return () => {
            mounted = false
        }
    }, [hasHousehold, currentStep, isInspection])

    const goToStep = useCallback((stepNumber) => {
        setErrors({})
        setGeneralError(null)
        setCurrentStep(stepNumber)
        const params = new URLSearchParams(searchParams)
        params.set('step', String(stepNumber))
        if (isInspection) {
            params.set('preview', 'demo')
        } else {
            params.set('continue', 'true')
        }
        setSearchParams(params)
    }, [setSearchParams, searchParams, isInspection])

    // --- STEP 1: Save Household Details ---
    const handleStep1Submit = async (e) => {
        if (e) e.preventDefault()
        const newErrors = {}
        if (!label.trim()) newErrors.label = 'Household name is required.'
        if (!locationLabel.trim()) newErrors.location_label = 'Location or city is required.'
        if (!timezone.trim()) newErrors.timezone = 'Timezone is required.'

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors)
            return
        }

        // In inspection mode, advance locally without mutating database
        if (isInspection) {
            goToStep(2)
            return
        }

        setIsSubmitting(true)
        setGeneralError(null)

        try {
            if (hasHousehold) {
                await updateHousehold({
                    label: label.trim(),
                    location_label: locationLabel.trim(),
                    timezone: timezone.trim(),
                })
            } else {
                await createHousehold({
                    label: label.trim(),
                    location_label: locationLabel.trim(),
                    timezone: timezone.trim(),
                    service_number: serviceNumber.trim() || undefined,
                })
            }
            goToStep(2)
        } catch (err) {
            setGeneralError(err.message || 'Failed to save household details. Please try again.')
        } finally {
            setIsSubmitting(false)
        }
    }

    // --- STEP 2: Save Electricity Service ---
    const handleStep2Submit = async (e) => {
        if (e) e.preventDefault()
        if (!serviceNumber.trim()) {
            setErrors({ service_number: 'Electricity service number is required to save.' })
            return
        }

        // In inspection mode, advance locally without mutating database
        if (isInspection) {
            goToStep(3)
            return
        }

        setIsSubmitting(true)
        setGeneralError(null)

        try {
            await updateHousehold({
                service_number: serviceNumber.trim(),
            })
            goToStep(3)
        } catch (err) {
            setGeneralError(err.message || 'Failed to update electricity service number.')
        } finally {
            setIsSubmitting(false)
        }
    }

    const handleSkipStep2 = () => {
        goToStep(3)
    }

    // --- STEP 3: Solar Connected Callback ---
    const handleSolarConnected = (connection) => {
        setSolarConnected(true)
        if (connection?.inverter_sn) {
            setSolarInverterSn(connection.inverter_sn)
        }
        if (!isInspection) {
            refreshHousehold().catch(() => {})
        }
        goToStep(4)
    }

    const handleSkipStep3 = () => {
        goToStep(4)
    }

    // --- STEP 4: Bill Upload Callback ---
    const handleBillUpload = async (file) => {
        setUploadingBill(true)
        setBillUploadError(null)
        setBillUploadSuccess(null)
        try {
            const res = await uploadBill(file)
            setBillsUploadedCount((prev) => prev + 1)
            setBillUploadSuccess(`Successfully uploaded "${file.name}". Statement recorded for review.`)
            return { uploaded: true, billId: res?.data?.bill_id }
        } catch (err) {
            setBillUploadError(err.message || 'Upload failed. Please ensure the file is a valid PDF, JPEG, PNG or APSPDCL XLS.')
            return { uploaded: false, error: err }
        } finally {
            setUploadingBill(false)
        }
    }

    const handleSkipStep4 = () => {
        goToStep(5)
    }

    // --- STEP 5: Finalize ---
    const handleFinish = () => {
        if (onComplete) {
            onComplete()
        } else {
            navigate('/dashboard', { replace: true })
        }
    }

    const handleExitToDashboard = () => {
        navigate('/dashboard', { replace: true })
    }

    return (
        <div className="w-full space-y-6">
            {/* DEMO / TEST Inspection Mode Banner */}
            {isInspection && (
                <div
                    role="status"
                    className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 rounded-xl border border-chart-demo/40 bg-chart-demo/10 px-4 py-3 text-xs text-chart-demo shadow-sm"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full border border-chart-demo/50 bg-chart-demo/20 px-2.5 py-0.5 font-semibold text-[10px] uppercase tracking-wider">
                            DEMO / TEST MODE
                        </span>
                        <span className="font-semibold text-heading">Onboarding Inspection Preview</span>
                        <span className="text-muted hidden md:inline">
                            — Walking through the fresh 5-step setup flow. Real accounts and households are unaffected.
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={handleExitToDashboard}
                        className="text-[11px] font-medium text-chart-demo hover:underline cursor-pointer shrink-0"
                    >
                        Exit to Dashboard
                    </button>
                </div>
            )}

            {/* 5-Step Guided Stepper Header */}
            <div className="rounded-xl border border-border bg-surface p-4 shadow-1">
                <div className="mb-3 flex items-center justify-between text-xs">
                    <span className="font-semibold uppercase tracking-wider text-muted">
                        Guided Setup — Step {currentStep} of {STEPS.length}
                    </span>
                    {(hasHousehold || isInspection) && (
                        <button
                            type="button"
                            onClick={handleExitToDashboard}
                            className="inline-flex items-center gap-1 text-xs text-muted hover:text-text-primary transition-colors cursor-pointer"
                        >
                            <span>Exit to Dashboard</span>
                            <ExternalLink className="size-3" aria-hidden="true" />
                        </button>
                    )}
                </div>

                <div className="grid grid-cols-5 gap-2 sm:gap-3">
                    {STEPS.map((step) => {
                        const Icon = step.icon
                        const isCompleted = step.id < currentStep
                        const isCurrent = step.id === currentStep
                        const isUnlocked = isInspection || isCompleted || isCurrent || hasHousehold

                        return (
                            <button
                                key={step.id}
                                type="button"
                                onClick={() => {
                                    if (isUnlocked) goToStep(step.id)
                                }}
                                disabled={!isUnlocked}
                                className={`group relative flex flex-col items-center gap-1.5 rounded-lg p-2 text-center transition-all ${
                                    isCurrent
                                        ? 'bg-primary/10 text-primary ring-1 ring-primary/40'
                                        : isCompleted
                                          ? 'bg-surface-subtle text-text-primary hover:bg-surface-subtle/80 cursor-pointer'
                                          : 'text-muted opacity-60 cursor-not-allowed'
                                }`}
                            >
                                <div
                                    className={`flex size-7 items-center justify-center rounded-full text-xs font-semibold transition-colors ${
                                        isCompleted
                                            ? 'bg-emerald-500 text-white'
                                            : isCurrent
                                              ? 'bg-primary text-white shadow-sm'
                                              : 'border border-border bg-surface text-muted'
                                    }`}
                                >
                                    {isCompleted ? (
                                        <Check className="size-3.5 stroke-[2.5]" aria-hidden="true" />
                                    ) : (
                                        <Icon className="size-3.5" aria-hidden="true" />
                                    )}
                                </div>
                                <span className="hidden text-[11px] font-medium sm:inline truncate max-w-full">
                                    {step.short}
                                </span>
                            </button>
                        )
                    })}
                </div>
            </div>

            {/* General Error Banner */}
            {generalError && (
                <div
                    role="alert"
                    className="flex items-start gap-2.5 rounded-lg border border-danger/30 bg-danger/10 p-3.5 text-xs text-danger"
                >
                    <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div>{generalError}</div>
                </div>
            )}

            {/* STEP 1: Household Details */}
            {currentStep === 1 && (
                <Card className="w-full border-border bg-surface shadow-2">
                    <CardHeader className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold text-primary">
                                Step 1 of 5
                            </span>
                            <span className="text-xs text-muted">Required</span>
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                            Set up your household
                        </CardTitle>
                        <CardDescription className="text-sm text-muted">
                            Tell SPMS a little about your home so we can personalize your solar and energy insights.
                        </CardDescription>
                    </CardHeader>

                    <form onSubmit={handleStep1Submit} noValidate>
                        <CardContent className="space-y-4">
                            {/* What you'll need supporting guidance */}
                            <div className="rounded-md border border-border/60 bg-surface-alt/40 p-3 text-xs">
                                <div className="flex items-center gap-1.5 font-medium text-heading mb-1.5">
                                    <CheckCircle2 className="size-3.5 text-primary shrink-0" aria-hidden="true" />
                                    <span>What you&apos;ll need</span>
                                </div>
                                <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-muted">
                                    <li className="flex items-center gap-1.5">
                                        <span className="size-1 rounded-full bg-primary/70 shrink-0" aria-hidden="true" />
                                        <span>Household name</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <span className="size-1 rounded-full bg-primary/70 shrink-0" aria-hidden="true" />
                                        <span>Location or city</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <span className="size-1 rounded-full bg-primary/70 shrink-0" aria-hidden="true" />
                                        <span>Local timezone</span>
                                    </li>
                                    <li className="flex items-center gap-1.5">
                                        <span className="size-1 rounded-full bg-primary/70 shrink-0" aria-hidden="true" />
                                        <span>Electricity service number (Step 2)</span>
                                    </li>
                                </ul>
                            </div>

                            <div className="space-y-4">
                                <div className="space-y-1.5">
                                    <label htmlFor="household-label" className="text-xs font-medium text-heading">
                                        Household Name
                                    </label>
                                    <Input
                                        id="household-label"
                                        placeholder="e.g. Primary Residence"
                                        value={label}
                                        onChange={(e) => {
                                            setLabel(e.target.value)
                                            if (errors.label) setErrors((prev) => ({ ...prev, label: undefined }))
                                        }}
                                        invalid={Boolean(errors.label)}
                                        disabled={isSubmitting}
                                        required
                                    />
                                    {errors.label && (
                                        <p role="alert" className="text-xs text-danger">{errors.label}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <label htmlFor="household-location" className="text-xs font-medium text-heading">
                                            Location / City
                                        </label>
                                        <Input
                                            id="household-location"
                                            placeholder="e.g. Hyderabad, Telangana"
                                            value={locationLabel}
                                            onChange={(e) => {
                                                setLocationLabel(e.target.value)
                                                if (errors.location_label) setErrors((prev) => ({ ...prev, location_label: undefined }))
                                            }}
                                            invalid={Boolean(errors.location_label)}
                                            disabled={isSubmitting}
                                            required
                                        />
                                        {errors.location_label && (
                                            <p role="alert" className="text-xs text-danger">{errors.location_label}</p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <label htmlFor="household-timezone" className="text-xs font-medium text-heading">
                                            Timezone
                                        </label>
                                        <Input
                                            id="household-timezone"
                                            value={timezone}
                                            onChange={(e) => {
                                                setTimezone(e.target.value)
                                                if (errors.timezone) setErrors((prev) => ({ ...prev, timezone: undefined }))
                                            }}
                                            invalid={Boolean(errors.timezone)}
                                            disabled={isSubmitting}
                                            required
                                        />
                                        {errors.timezone && (
                                            <p role="alert" className="text-xs text-danger">{errors.timezone}</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </CardContent>

                        <CardFooter className="pt-2 flex items-center justify-between">
                            <span className="text-xs text-muted">Step 1 of 5</span>
                            <Button
                                type="submit"
                                variant="primary"
                                loading={isSubmitting}
                            >
                                Continue to Electricity Service
                                <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            )}

            {/* STEP 2: Electricity Service */}
            {currentStep === 2 && (
                <Card className="w-full border-border bg-surface shadow-2">
                    <CardHeader className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold text-primary">
                                Step 2 of 5
                            </span>
                            <span className="text-xs text-muted">Electricity Service</span>
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                            Electricity Utility Service
                        </CardTitle>
                        <CardDescription className="text-sm text-muted">
                            Add your electricity consumer ID to help verify uploaded bills and keep billing records linked to your household.
                        </CardDescription>
                    </CardHeader>

                    <form onSubmit={handleStep2Submit} noValidate>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <label htmlFor="household-service-number" className="text-xs font-medium text-heading">
                                    Electricity Service Number (Consumer ID)
                                </label>
                                <Input
                                    id="household-service-number"
                                    placeholder="e.g. 1000-2000-300"
                                    value={serviceNumber}
                                    onChange={(e) => {
                                        setServiceNumber(e.target.value)
                                        if (errors.service_number) setErrors((prev) => ({ ...prev, service_number: undefined }))
                                    }}
                                    invalid={Boolean(errors.service_number)}
                                    disabled={isSubmitting}
                                />
                                <p className="text-[11px] text-muted">
                                    Found on your APSPDCL electricity bill. Used to match uploaded electricity bills to your household.
                                </p>
                                {errors.service_number && (
                                    <p role="alert" className="text-xs text-danger">{errors.service_number}</p>
                                )}
                            </div>

                            <div className="rounded-md border border-border/70 bg-surface-subtle p-3 text-xs text-muted space-y-1.5">
                                <div className="flex items-center gap-1.5 font-medium text-text-primary">
                                    <Info className="size-3.5 text-primary shrink-0" aria-hidden="true" />
                                    <span>Why is this needed?</span>
                                </div>
                                <p className="text-[11px] leading-relaxed">
                                    Adding your consumer ID matches uploaded electricity bills to your household and prevents duplicate or foreign statements, supporting accurate tariff evaluation and billing analysis.
                                </p>
                            </div>
                        </CardContent>

                        <CardFooter className="pt-2 flex items-center justify-between">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => goToStep(1)}
                                disabled={isSubmitting}
                            >
                                <ArrowLeft className="mr-1.5 size-4" aria-hidden="true" />
                                Back
                            </Button>

                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={handleSkipStep2}
                                    disabled={isSubmitting}
                                >
                                    Skip for now
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={isSubmitting}
                                >
                                    Continue to Solar
                                    <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </CardFooter>
                    </form>
                </Card>
            )}

            {/* STEP 3: GoodWe Solar Connection (Optional / Skippable) */}
            {currentStep === 3 && (
                <Card className="w-full border-border bg-surface shadow-2">
                    <CardHeader className="space-y-1">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span className="rounded-full bg-amber-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-amber-500">
                                    Step 3 of 5
                                </span>
                                <span className="rounded-full border border-border px-2 py-0.5 text-[10px] text-muted">
                                    Optional — Skippable
                                </span>
                            </div>
                            <span className="text-xs text-muted">GoodWe SEMS</span>
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                            GoodWe Solar Inverter Connection
                        </CardTitle>
                        <CardDescription className="text-sm text-muted">
                            Connect your GoodWe inverter to bring live solar generation data into SPMS and support solar forecasting.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        {/* GoodWe Deferral Notice Callout */}
                        <div className="rounded-md border border-amber-500/20 bg-amber-500/5 p-3 text-xs">
                            <div className="flex items-start gap-2.5">
                                <Sun className="mt-0.5 size-4 shrink-0 text-amber-500" aria-hidden="true" />
                                <div className="space-y-0.5">
                                    <p className="font-medium text-heading">GoodWe Solar Inverter Connection</p>
                                    <p className="text-muted text-[11px] leading-relaxed">
                                        GoodWe connection can be completed later. Live solar data remains unavailable until your inverter is connected.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {solarConnected ? (
                            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4 text-center space-y-2">
                                <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-400">
                                    <CheckCircle2 className="size-6" aria-hidden="true" />
                                </div>
                                <h4 className="font-semibold text-sm text-text-primary">Inverter Successfully Connected</h4>
                                <p className="text-xs text-text-secondary">
                                    Your GoodWe solar inverter {solarInverterSn ? `(SN: ${solarInverterSn})` : ''} is linked and actively streaming telemetry.
                                </p>
                            </div>
                        ) : (
                            <div className="pt-1">
                                <SolarConnectForm onConnected={handleSolarConnected} />
                            </div>
                        )}
                    </CardContent>

                    <CardFooter className="pt-2 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => goToStep(2)}
                        >
                            <ArrowLeft className="mr-1.5 size-4" aria-hidden="true" />
                            Back
                        </Button>

                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={handleSkipStep3}
                            >
                                Skip for now
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                onClick={() => goToStep(4)}
                            >
                                Continue to Bills
                                <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                            </Button>
                        </div>
                    </CardFooter>
                </Card>
            )}

            {/* STEP 4: Bill / History Setup (Optional / Skippable) */}
            {currentStep === 4 && (
                <Card className="w-full border-border bg-surface shadow-2">
                    <CardHeader className="space-y-1">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span className="rounded-full bg-blue-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-blue-500">
                                    Step 4 of 5
                                </span>
                                <span className="rounded-full border border-border px-2 py-0.5 text-[10px] text-muted">
                                    Optional — Skippable
                                </span>
                            </div>
                            <span className="text-xs text-muted">APSPDCL Statements</span>
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                            Electricity Bills & Historical Statements
                        </CardTitle>
                        <CardDescription className="text-sm text-muted">
                            Upload your APSPDCL bill PDF, bill photo, or exported Excel spreadsheet to track energy consumption history.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        <div className="rounded-md border border-blue-500/20 bg-blue-500/5 p-3 text-xs">
                            <div className="flex items-start gap-2.5">
                                <FileText className="mt-0.5 size-4 shrink-0 text-blue-400" aria-hidden="true" />
                                <div className="space-y-0.5">
                                    <p className="font-medium text-heading">Historical Billing Analytics</p>
                                    <p className="text-muted text-[11px] leading-relaxed">
                                        Uploading bills can be completed later. Historical energy cost and net billing analytics will be available once your first bill is processed.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {billsUploadedCount > 0 && (
                            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 flex items-center justify-between text-xs">
                                <div className="flex items-center gap-2 text-emerald-400">
                                    <CheckCircle2 className="size-4 shrink-0" aria-hidden="true" />
                                    <span>{billsUploadedCount} bill statement(s) on record for this household.</span>
                                </div>
                                <span className="text-[11px] text-emerald-500/80 font-medium">Ready</span>
                            </div>
                        )}

                        {billUploadSuccess && (
                            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-400 flex items-center gap-2">
                                <CheckCircle2 className="size-4 shrink-0" />
                                <span>{billUploadSuccess}</span>
                            </div>
                        )}

                        {billUploadError && (
                            <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-400 flex items-center gap-2">
                                <AlertCircle className="size-4 shrink-0" />
                                <span>{billUploadError}</span>
                            </div>
                        )}

                        <div className="pt-1">
                            <BillUploadPanel onUpload={handleBillUpload} busy={uploadingBill} />
                        </div>
                    </CardContent>

                    <CardFooter className="pt-2 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => goToStep(3)}
                        >
                            <ArrowLeft className="mr-1.5 size-4" aria-hidden="true" />
                            Back
                        </Button>

                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={handleSkipStep4}
                            >
                                Skip for now
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                onClick={() => goToStep(5)}
                            >
                                Continue to Review
                                <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                            </Button>
                        </div>
                    </CardFooter>
                </Card>
            )}

            {/* STEP 5: Finish & Review */}
            {currentStep === 5 && (
                <Card className="w-full border-border bg-surface shadow-2">
                    <CardHeader className="space-y-1 text-center pb-2">
                        <div className="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-400">
                            <Sparkles className="size-6" aria-hidden="true" />
                        </div>
                        <div className="inline-block mx-auto mb-1">
                            <span className="rounded-full bg-emerald-500/10 border border-emerald-500/30 px-3 py-0.5 text-xs font-semibold text-emerald-400">
                                Step 5 of 5 — Setup Overview
                            </span>
                        </div>
                        <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                            Ready to Enter Workspace
                        </CardTitle>
                        <CardDescription className="text-sm text-muted">
                            Review your household configuration. Any skipped items can be completed later in their respective workspaces.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-4 pt-2">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            {/* Card 1: Household */}
                            <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-1.5 font-medium text-text-primary">
                                        <Home className="size-3.5 text-primary" aria-hidden="true" />
                                        <span>Household</span>
                                    </div>
                                    <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                                        <CheckCircle2 className="size-3" />
                                        Configured
                                    </span>
                                </div>
                                <p className="text-text-secondary font-medium truncate">{label || 'Primary Residence'}</p>
                                <p className="text-muted text-[11px] truncate">{locationLabel || 'Location not set'} · {timezone}</p>
                                <button
                                    type="button"
                                    onClick={() => goToStep(1)}
                                    className="text-[11px] text-primary hover:underline cursor-pointer pt-1 inline-block"
                                >
                                    Edit Details
                                </button>
                            </div>

                            {/* Card 2: Electricity Service */}
                            <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-1.5 font-medium text-text-primary">
                                        <Zap className="size-3.5 text-primary" aria-hidden="true" />
                                        <span>Electricity Service</span>
                                    </div>
                                    {serviceNumber ? (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                                            <CheckCircle2 className="size-3" />
                                            Configured
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                                            <Clock className="size-3" />
                                            Pending
                                        </span>
                                    )}
                                </div>
                                <p className="text-text-secondary font-medium truncate">
                                    {serviceNumber || 'Service number pending'}
                                </p>
                                <p className="text-muted text-[11px]">APSPDCL Andhra Pradesh</p>
                                <button
                                    type="button"
                                    onClick={() => goToStep(2)}
                                    className="text-[11px] text-primary hover:underline cursor-pointer pt-1 inline-block"
                                >
                                    {serviceNumber ? 'Change Number' : 'Set Service Number'}
                                </button>
                            </div>

                            {/* Card 3: GoodWe Solar */}
                            <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-1.5 font-medium text-text-primary">
                                        <Sun className="size-3.5 text-amber-500" aria-hidden="true" />
                                        <span>GoodWe Solar</span>
                                    </div>
                                    {solarConnected ? (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                                            <CheckCircle2 className="size-3" />
                                            Connected
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                                            <Clock className="size-3" />
                                            Not Connected
                                        </span>
                                    )}
                                </div>
                                <p className="text-text-secondary font-medium">
                                    {solarConnected ? 'Streaming live solar telemetry' : 'Inverter connection pending'}
                                </p>
                                <p className="text-muted text-[11px]">
                                    {solarConnected ? 'Solar forecasts active' : 'Connect anytime from Solar page'}
                                </p>
                                <button
                                    type="button"
                                    onClick={() => goToStep(3)}
                                    className="text-[11px] text-primary hover:underline cursor-pointer pt-1 inline-block"
                                >
                                    {solarConnected ? 'View Connection' : 'Connect Inverter'}
                                </button>
                            </div>

                            {/* Card 4: Bills & History */}
                            <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-1.5 font-medium text-text-primary">
                                        <FileText className="size-3.5 text-blue-400" aria-hidden="true" />
                                        <span>Billing History</span>
                                    </div>
                                    {billsUploadedCount > 0 ? (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                                            <CheckCircle2 className="size-3" />
                                            {billsUploadedCount} On Record
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                                            <Clock className="size-3" />
                                            No Statements
                                        </span>
                                    )}
                                </div>
                                <p className="text-text-secondary font-medium">
                                    {billsUploadedCount > 0 ? `${billsUploadedCount} bill(s) processed` : 'No bills uploaded yet'}
                                </p>
                                <p className="text-muted text-[11px]">
                                    {billsUploadedCount > 0 ? 'Billing analytics enabled' : 'Upload bills anytime from Bills page'}
                                </p>
                                <button
                                    type="button"
                                    onClick={() => goToStep(4)}
                                    className="text-[11px] text-primary hover:underline cursor-pointer pt-1 inline-block"
                                >
                                    {billsUploadedCount > 0 ? 'Upload Another' : 'Upload First Bill'}
                                </button>
                            </div>
                        </div>
                    </CardContent>

                    <CardFooter className="pt-4 flex flex-col gap-2">
                        <Button
                            type="button"
                            variant="primary"
                            size="lg"
                            onClick={handleFinish}
                            className="w-full text-sm font-semibold"
                        >
                            Enter Workspace & Go to Dashboard
                            <ArrowRight className="ml-2 size-4" aria-hidden="true" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => goToStep(4)}
                            className="text-xs text-muted"
                        >
                            <ArrowLeft className="mr-1 size-3.5" aria-hidden="true" />
                            Back to Bills Setup
                        </Button>
                    </CardFooter>
                </Card>
            )}
        </div>
    )
}
