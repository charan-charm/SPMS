import { useState } from 'react'
import { useHousehold } from '../../features/household/useHousehold'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { ShieldCheck, Zap, Home, CheckCircle2, AlertCircle, Eye, EyeOff, LoaderCircle } from 'lucide-react'

/**
 * Mask service number preserving identifier privacy:
 * Displays leading dots and only the last 4 characters.
 */
function maskServiceNumber(num) {
    if (!num || typeof num !== 'string') return 'Not configured'
    const trimmed = num.trim()
    if (trimmed.length <= 4) return '••••'
    return '•'.repeat(trimmed.length - 4) + trimmed.slice(-4)
}

export function SettingsPage() {
    const { household, updateHousehold, refreshHousehold } = useHousehold()

    const [isEditing, setIsEditing] = useState(false)
    const [serviceNumberInput, setServiceNumberInput] = useState('')
    const [confirmed, setConfirmed] = useState(false)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null)
    const [reveal, setReveal] = useState(false)

    const handleStartEdit = () => {
        setServiceNumberInput('')
        setConfirmed(false)
        setError(null)
        setSuccessMessage(null)
        setIsEditing(true)
    }

    const handleCancelEdit = () => {
        setIsEditing(false)
        setServiceNumberInput('')
        setConfirmed(false)
        setError(null)
    }

    const handleSave = async (e) => {
        e.preventDefault()
        setError(null)
        setSuccessMessage(null)

        const trimmed = serviceNumberInput.trim()
        if (!trimmed) {
            setError('Please enter a valid service number.')
            return
        }

        if (!confirmed) {
            setError('Please check the confirmation box before saving.')
            return
        }

        setSaving(true)
        try {
            await updateHousehold({ service_number: trimmed })
            await refreshHousehold()
            setSuccessMessage('Household electricity service number updated successfully. Bill ownership records have been refreshed.')
            setIsEditing(false)
            setServiceNumberInput('')
            setConfirmed(false)
        } catch (err) {
            const msg = err?.data?.message || err?.message || 'Failed to update service number. Please verify the format.'
            setError(msg)
        } finally {
            setSaving(false)
        }
    }

    const currentServiceNumber = household?.service_number || null

    return (
        <div className="mx-auto max-w-4xl space-y-8 px-4 py-8">
            <div>
                <h1 className="font-display text-2xl font-bold tracking-tight text-heading">Household Settings</h1>
                <p className="mt-1 text-sm text-muted">
                    Manage your registered household profile, electricity service identifier, and billing credentials.
                </p>
            </div>

            {successMessage && (
                <div className="flex items-center gap-3 rounded-lg border border-primary/30 bg-primary/10 p-4 text-sm text-primary">
                    <CheckCircle2 className="size-5 shrink-0" />
                    <span>{successMessage}</span>
                </div>
            )}

            {/* Household Identity Card */}
            <Card className="border-border bg-surface">
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <Home className="size-5 text-primary" />
                        <CardTitle className="text-lg">Household Profile</CardTitle>
                    </div>
                    <CardDescription>
                        Basic residential information established during onboarding.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <span className="text-xs font-medium text-muted">Household Label</span>
                            <p className="mt-0.5 font-medium text-heading">{household?.label || 'Primary Residence'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-muted">Timezone</span>
                            <p className="mt-0.5 font-medium text-heading">{household?.timezone || 'Asia/Kolkata'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-muted">Location</span>
                            <p className="mt-0.5 font-medium text-heading">{household?.location_label || 'Not specified'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-muted">Account Status</span>
                            <div className="mt-0.5 flex items-center gap-1.5">
                                <span className="inline-block size-2 rounded-full bg-emerald-500" />
                                <span className="text-sm font-medium text-heading">Active</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Electricity Service Number / Meter Settings Card */}
            <Card className="border-border bg-surface">
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Zap className="size-5 text-amber-500" />
                            <CardTitle className="text-lg">Registered Electricity Service Number</CardTitle>
                        </div>
                        {!isEditing && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleStartEdit}
                                id="btn-edit-service-number"
                            >
                                Correct Service Number
                            </Button>
                        )}
                    </div>
                    <CardDescription>
                        Your unique utility USC or electricity consumer number printed on your bill. SPMS uses this to verify bill ownership and protect your energy data.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {!isEditing ? (
                        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface-alt p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <span className="text-xs font-medium text-muted">Registered Service Number</span>
                                    <div className="mt-1 flex items-center gap-3">
                                        <span className="font-mono text-lg font-semibold tracking-wider text-heading">
                                            {reveal && currentServiceNumber
                                                ? currentServiceNumber
                                                : maskServiceNumber(currentServiceNumber)}
                                        </span>
                                        {currentServiceNumber && (
                                            <button
                                                type="button"
                                                onClick={() => setReveal(!reveal)}
                                                className="text-muted hover:text-heading"
                                                title={reveal ? 'Mask number' : 'Reveal full number'}
                                            >
                                                {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <div className="text-right">
                                    <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                        <ShieldCheck className="size-3.5" />
                                        Verified Anchor
                                    </span>
                                </div>
                            </div>
                            <p className="text-xs text-muted">
                                If you made a typo during onboarding, click &quot;Correct Service Number&quot; above to update it.
                                Any unverified bills with matching service numbers will automatically have ownership reconciled.
                            </p>
                        </div>
                    ) : (
                        <form onSubmit={handleSave} className="space-y-5 rounded-lg border border-warning/40 bg-warning/5 p-5">
                            <div className="flex items-start gap-3">
                                <AlertCircle className="mt-0.5 size-5 shrink-0 text-warning" />
                                <div>
                                    <h4 className="text-sm font-semibold text-heading">Correct Registered Service Number</h4>
                                    <p className="text-xs text-muted">
                                        Enter the authoritative service number as it appears on your utility statement.
                                        This value will overwrite the existing registered number.
                                    </p>
                                </div>
                            </div>

                            {error && (
                                <div className="flex items-center gap-2 rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
                                    <AlertCircle className="size-4 shrink-0" />
                                    <span>{error}</span>
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="new-service-number" className="text-sm font-medium text-heading">
                                    Authoritative Service Number
                                </Label>
                                <Input
                                    id="new-service-number"
                                    type="text"
                                    value={serviceNumberInput}
                                    onChange={(e) => setServiceNumberInput(e.target.value)}
                                    placeholder="e.g. 10002000300"
                                    className="font-mono"
                                    autoFocus
                                    required
                                />
                                <span className="block text-xs text-muted">
                                    Utility service number (4–24 alphanumeric characters, punctuation is automatically stripped).
                                </span>
                            </div>

                            <div className="rounded-md border border-border bg-surface p-3">
                                <label className="flex items-start gap-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={confirmed}
                                        onChange={(e) => setConfirmed(e.target.checked)}
                                        className="mt-1 size-4 rounded border-border text-primary focus:ring-primary"
                                        id="confirm-service-number-update"
                                    />
                                    <span className="text-xs text-body">
                                        <strong>I confirm</strong> that this is the correct electricity service number for my household.
                                        I understand that updating this identity will re-evaluate ownership validation on any unverified bills.
                                    </span>
                                </label>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleCancelEdit}
                                    disabled={saving}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={saving || !confirmed || !serviceNumberInput.trim()}
                                    id="btn-save-service-number"
                                >
                                    {saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                                    Confirm &amp; Update
                                </Button>
                            </div>
                        </form>
                    )}

                    <div className="rounded-lg border border-border/60 bg-surface-alt/50 p-4 text-xs text-muted">
                        <div className="flex items-center gap-2 font-medium text-heading">
                            <ShieldCheck className="size-4 text-primary" />
                            <span>Privacy &amp; Data Protection</span>
                        </div>
                        <p className="mt-1">
                            Your electricity service number is treated as confidential user data. It is never published in logs,
                            disclosed to other users, or used for purposes other than verifying document ownership for your household.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    )
}
