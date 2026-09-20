import { useState } from 'react'
import { Sun, AlertCircle, ArrowRight, CheckCircle2 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '../ui/card'
import { Input } from '../ui/input'
import { Button } from '../ui/button'
import { useHousehold } from '../../features/household/useHousehold'

export function RealOnboardingForm({ onComplete }) {
    const { createHousehold } = useHousehold()

    const [label, setLabel] = useState('')
    const [locationLabel, setLocationLabel] = useState('')
    const [timezone, setTimezone] = useState('Asia/Kolkata')
    const [serviceNumber, setServiceNumber] = useState('')

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})
    const [generalError, setGeneralError] = useState(null)

    const handleSubmit = async (e) => {
        e.preventDefault()

        const newErrors = {}
        if (!label.trim()) {
            newErrors.label = 'Household name is required.'
        }
        if (!locationLabel.trim()) {
            newErrors.location_label = 'Location or city is required.'
        }
        if (!timezone.trim()) {
            newErrors.timezone = 'Timezone is required.'
        }
        if (!serviceNumber.trim()) {
            newErrors.service_number = 'Electricity service number is required.'
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors)
            return
        }

        setErrors({})
        setGeneralError(null)
        setSubmitting(true)

        try {
            await createHousehold({
                label: label.trim(),
                location_label: locationLabel.trim(),
                timezone: timezone.trim(),
                service_number: serviceNumber.trim(),
            })
            if (onComplete) {
                onComplete()
            }
        } catch (err) {
            setGeneralError(err.message || 'Failed to complete household setup. Please try again.')
        } finally {
            setSubmitting(false)
        }
    }

    return (
        <Card className="w-full border-border bg-surface shadow-2">
            <CardHeader className="space-y-1">
                <CardTitle className="font-display text-2xl font-semibold tracking-tight text-heading">
                    Set up your household
                </CardTitle>
                <CardDescription className="text-sm text-muted">
                    Tell SPMS a little about your home so we can personalize your solar and energy insights.
                </CardDescription>
            </CardHeader>

            <form onSubmit={handleSubmit} noValidate>
                <CardContent className="space-y-4">
                    {generalError && (
                        <div
                            role="alert"
                            className="flex items-start gap-2.5 rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger"
                        >
                            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <div>{generalError}</div>
                        </div>
                    )}

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
                                <span>Electricity service number</span>
                            </li>
                        </ul>
                    </div>

                    {/* Form Fields: matching auth input hierarchy */}
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
                                disabled={submitting}
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
                                    disabled={submitting}
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
                                    disabled={submitting}
                                    required
                                />
                                {errors.timezone && (
                                    <p role="alert" className="text-xs text-danger">{errors.timezone}</p>
                                )}
                            </div>
                        </div>

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
                                disabled={submitting}
                                required
                            />
                            <p className="text-[11px] text-muted">
                                Found on your electricity utility bill. Used to cross-match and verify your uploaded bills.
                            </p>
                            {errors.service_number && (
                                <p role="alert" className="text-xs text-danger">{errors.service_number}</p>
                            )}
                        </div>
                    </div>

                    {/* Step 3: GoodWe Connection Deferral Notice */}
                    <div className="rounded-md border border-primary/20 bg-primary-soft/25 p-3 text-xs">
                        <div className="flex items-start gap-2.5">
                            <Sun className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                            <div className="space-y-0.5">
                                <p className="font-medium text-heading">GoodWe Solar Inverter Connection</p>
                                <p className="text-muted text-[11px] leading-relaxed">
                                    GoodWe connection can be completed later. Live solar data remains unavailable until your inverter is connected.
                                </p>
                            </div>
                        </div>
                    </div>
                </CardContent>

                <CardFooter className="pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        loading={submitting}
                        className="w-full"
                    >
                        Complete setup & enter workspace
                        <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                    </Button>
                </CardFooter>
            </form>
        </Card>
    )
}
