import { useState } from 'react'
import { Link } from 'react-router'
import { useAuth } from '../../features/auth/useAuth'
import { useHousehold } from '../../features/household/useHousehold'
import { useSolar } from '../../features/solar/useSolar'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import {
    ShieldCheck,
    Zap,
    Home,
    CheckCircle2,
    AlertCircle,
    Eye,
    EyeOff,
    LoaderCircle,
    Sun,
    Moon,
    ArrowRight,
    Clock,
    WifiOff,
    FileText,
    Bell,
} from 'lucide-react'

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

/**
 * Format account status with honest lifecycle mapping.
 * Never fabricates 'Active' when status is missing or unauthenticated.
 */
function formatAccountStatus(status) {
    if (!status || typeof status !== 'string') {
        return { label: 'Unknown', dotClass: 'bg-muted' }
    }
    switch (status.toUpperCase()) {
        case 'ACTIVE':
            return { label: 'Active', dotClass: 'bg-emerald-500' }
        case 'PENDING_VERIFICATION':
            return { label: 'Pending Verification', dotClass: 'bg-amber-500' }
        case 'DISABLED':
            return { label: 'Disabled', dotClass: 'bg-rose-500' }
        case 'DELETION_PENDING':
            return { label: 'Deletion Pending', dotClass: 'bg-rose-500' }
        default:
            return { label: status, dotClass: 'bg-muted' }
    }
}

/**
 * Format sync timestamp to readable Asia/Kolkata (IST) format without inventing timestamps.
 */
function formatSyncTimestamp(timestamp) {
    if (!timestamp || typeof timestamp !== 'string') return 'No telemetry synchronized yet'
    try {
        const normalized = timestamp.includes(' ') && !timestamp.includes('T')
            ? timestamp.replace(' ', 'T') + '+05:30'
            : timestamp
        const d = new Date(normalized)
        if (isNaN(d.getTime())) return timestamp
        return d.toLocaleString('en-IN', {
            timeZone: 'Asia/Kolkata',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        }) + ' IST'
    } catch {
        return timestamp
    }
}

function renderSolarStatusBadge(status) {
    switch (status) {
        case 'CONNECTED':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" />
                    Connected
                </span>
            )
        case 'OFFLINE':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <Moon className="size-3 text-amber-500" />
                    Offline (Night / Standby)
                </span>
            )
        case 'DEGRADED':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertCircle className="size-3 text-amber-500" />
                    Degraded
                </span>
            )
        case 'AUTHENTICATION_ERROR':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <AlertCircle className="size-3 text-rose-500" />
                    Auth Error
                </span>
            )
        case 'API_ERROR':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <AlertCircle className="size-3 text-rose-500" />
                    API Error
                </span>
            )
        case 'TIMEOUT':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <Clock className="size-3 text-rose-500" />
                    Timeout
                </span>
            )
        case 'NETWORK_ERROR':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <WifiOff className="size-3 text-rose-500" />
                    Network Error
                </span>
            )
        case 'NOT_CONFIGURED':
        case 'DISCONNECTED':
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-medium text-muted">
                    Not Connected
                </span>
            )
        default:
            return (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-medium text-muted">
                    <AlertCircle className="size-3 text-muted" />
                    {status || 'Unknown'}
                </span>
            )
    }
}

export function SettingsPage() {
    const { account } = useAuth()
    const { household, updateHousehold, refreshHousehold } = useHousehold()
    const { connection, connectionStatus, isLoading: isSolarLoading } = useSolar()

    // Authoritative REAL / DEMO mode detection:
    // Strictly requires explicit DEMO state from the server.
    // If account.mode is explicitly 'REAL', DEMO mode is never shown even if an anomalous environment claim exists.
    const isDemo = account?.mode === 'DEMO_TEST' || (account?.mode !== 'REAL' && account?.environment === 'DEMO')

    // Household Profile edit state
    const [isEditingProfile, setIsEditingProfile] = useState(false)
    const [profileLabelInput, setProfileLabelInput] = useState('')
    const [profileLocationInput, setProfileLocationInput] = useState('')
    const [profileTimezoneInput, setProfileTimezoneInput] = useState('')
    const [profileSaving, setProfileSaving] = useState(false)
    const [profileError, setProfileError] = useState(null)

    // Service number edit state
    const [isEditingServiceNumber, setIsEditingServiceNumber] = useState(false)
    const [serviceNumberInput, setServiceNumberInput] = useState('')
    const [serviceNumberConfirmed, setServiceNumberConfirmed] = useState(false)
    const [serviceNumberSaving, setServiceNumberSaving] = useState(false)
    const [serviceNumberError, setServiceNumberError] = useState(null)
    const [reveal, setReveal] = useState(false)

    // Shared success banner message
    const [successMessage, setSuccessMessage] = useState(null)

    // Profile handlers
    const handleStartEditProfile = () => {
        setProfileLabelInput(household?.label || '')
        setProfileLocationInput(household?.location_label || '')
        setProfileTimezoneInput(household?.timezone || 'Asia/Kolkata')
        setProfileError(null)
        setSuccessMessage(null)
        setIsEditingProfile(true)
    }

    const handleCancelEditProfile = () => {
        setIsEditingProfile(false)
        setProfileError(null)
    }

    const handleSaveProfile = async (e) => {
        e.preventDefault()
        setProfileError(null)
        setSuccessMessage(null)

        const trimmedLabel = profileLabelInput.trim()
        const trimmedLocation = profileLocationInput.trim()
        const trimmedTimezone = profileTimezoneInput.trim()

        if (!trimmedTimezone) {
            setProfileError('Timezone is required.')
            return
        }

        setProfileSaving(true)
        try {
            await updateHousehold({
                label: trimmedLabel,
                location_label: trimmedLocation,
                timezone: trimmedTimezone,
            })
            await refreshHousehold()
            setSuccessMessage('Household profile updated successfully.')
            setIsEditingProfile(false)
        } catch (err) {
            const msg = err?.data?.message || err?.message || 'Failed to update household profile.'
            setProfileError(msg)
        } finally {
            setProfileSaving(false)
        }
    }

    // Service number handlers
    const handleStartEditServiceNumber = () => {
        setServiceNumberInput('')
        setServiceNumberConfirmed(false)
        setServiceNumberError(null)
        setSuccessMessage(null)
        setIsEditingServiceNumber(true)
    }

    const handleCancelEditServiceNumber = () => {
        setIsEditingServiceNumber(false)
        setServiceNumberInput('')
        setServiceNumberConfirmed(false)
        setServiceNumberError(null)
    }

    const handleSaveServiceNumber = async (e) => {
        e.preventDefault()
        setServiceNumberError(null)
        setSuccessMessage(null)

        const trimmed = serviceNumberInput.trim()
        if (!trimmed) {
            setServiceNumberError('Please enter a valid service number.')
            return
        }

        if (!serviceNumberConfirmed) {
            setServiceNumberError('Please check the confirmation box before saving.')
            return
        }

        setServiceNumberSaving(true)
        try {
            await updateHousehold({ service_number: trimmed })
            await refreshHousehold()
            setSuccessMessage('Household electricity service number updated successfully. Bill ownership records have been refreshed.')
            setIsEditingServiceNumber(false)
            setServiceNumberInput('')
            setServiceNumberConfirmed(false)
        } catch (err) {
            const msg = err?.data?.message || err?.message || 'Failed to update service number. Please verify the format.'
            setServiceNumberError(msg)
        } finally {
            setServiceNumberSaving(false)
        }
    }

    const currentServiceNumber = household?.service_number || null
    const accountStatusInfo = formatAccountStatus(account?.status)

    return (
        <div className="mx-auto max-w-4xl space-y-8 px-4 py-8">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="font-display text-2xl font-bold tracking-tight text-heading">Household Settings</h1>
                        {isDemo && (
                            <span
                                data-testid="demo-mode-badge"
                                className="inline-flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400"
                            >
                                <span className="size-1.5 rounded-full bg-amber-500 animate-pulse" />
                                DEMO MODE
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-muted">
                        Manage your registered household profile, electricity service identifier, and solar connection.
                    </p>
                </div>
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
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Home className="size-5 text-primary" />
                            <CardTitle className="text-lg">Household Profile</CardTitle>
                        </div>
                        {!isEditingProfile && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleStartEditProfile}
                                id="btn-edit-profile"
                            >
                                Edit Profile
                            </Button>
                        )}
                    </div>
                    <CardDescription>
                        Basic residential information established during onboarding.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {!isEditingProfile ? (
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
                                    <span className={`inline-block size-2 rounded-full ${accountStatusInfo.dotClass}`} />
                                    <span className="text-sm font-medium text-heading">{accountStatusInfo.label}</span>
                                </div>
                            </div>
                            <div>
                                <span className="text-xs font-medium text-muted">Account Email</span>
                                <p className="mt-0.5 font-medium text-heading">{account?.email || 'Authenticated User'}</p>
                            </div>
                            <div>
                                <span className="text-xs font-medium text-muted">Account Role</span>
                                <p className="mt-0.5 font-medium capitalize text-heading">
                                    {account?.role ? `${account.role.toLowerCase()} account` : 'Standard User'}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <form onSubmit={handleSaveProfile} className="space-y-4 rounded-lg border border-border bg-surface-alt/60 p-4">
                            <div className="flex items-start gap-3">
                                <Home className="mt-0.5 size-5 shrink-0 text-primary" />
                                <div>
                                    <h4 className="text-sm font-semibold text-heading">Edit Household Profile</h4>
                                    <p className="text-xs text-muted">
                                        Update your residence label, region, and operating timezone.
                                    </p>
                                </div>
                            </div>

                            {profileError && (
                                <div className="flex items-center gap-2 rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
                                    <AlertCircle className="size-4 shrink-0" />
                                    <span>{profileError}</span>
                                </div>
                            )}

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="edit-household-label" className="text-xs font-medium text-heading">
                                        Household Name / Label
                                    </Label>
                                    <Input
                                        id="edit-household-label"
                                        type="text"
                                        value={profileLabelInput}
                                        onChange={(e) => setProfileLabelInput(e.target.value)}
                                        placeholder="e.g. Primary Residence"
                                        maxLength={120}
                                    />
                                    <span className="block text-[11px] text-muted">
                                        Optional descriptive label for this residence.
                                    </span>
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="edit-household-location" className="text-xs font-medium text-heading">
                                        Location (City / Region)
                                    </Label>
                                    <Input
                                        id="edit-household-location"
                                        type="text"
                                        value={profileLocationInput}
                                        onChange={(e) => setProfileLocationInput(e.target.value)}
                                        placeholder="e.g. Kurnool, AP"
                                        maxLength={190}
                                    />
                                    <span className="block text-[11px] text-muted">
                                        Geographic region for weather and sunrise alignment.
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="edit-household-timezone" className="text-xs font-medium text-heading">
                                    Timezone (IANA Identifier)
                                </Label>
                                <Input
                                    id="edit-household-timezone"
                                    type="text"
                                    value={profileTimezoneInput}
                                    onChange={(e) => setProfileTimezoneInput(e.target.value)}
                                    placeholder="e.g. Asia/Kolkata"
                                    required
                                />
                                <span className="block text-[11px] text-muted">
                                    Standard IANA timezone identifier (e.g. Asia/Kolkata, UTC).
                                </span>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={handleCancelEditProfile}
                                    disabled={profileSaving}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={profileSaving}
                                    id="btn-save-profile"
                                >
                                    {profileSaving && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                                    Save Profile
                                </Button>
                            </div>
                        </form>
                    )}
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
                        {!isEditingServiceNumber && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleStartEditServiceNumber}
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
                    {!isEditingServiceNumber ? (
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
                        <form onSubmit={handleSaveServiceNumber} className="space-y-5 rounded-lg border border-warning/40 bg-warning/5 p-5">
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

                            {serviceNumberError && (
                                <div className="flex items-center gap-2 rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
                                    <AlertCircle className="size-4 shrink-0" />
                                    <span>{serviceNumberError}</span>
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
                                        checked={serviceNumberConfirmed}
                                        onChange={(e) => setServiceNumberConfirmed(e.target.checked)}
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
                                    onClick={handleCancelEditServiceNumber}
                                    disabled={serviceNumberSaving}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={serviceNumberSaving || !serviceNumberConfirmed || !serviceNumberInput.trim()}
                                    id="btn-save-service-number"
                                >
                                    {serviceNumberSaving && <LoaderCircle className="mr-2 size-4 animate-spin" />}
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

            {/* Solar Inverter Integration Summary Card */}
            <Card className="border-border bg-surface">
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Sun className="size-5 text-amber-500" />
                            <CardTitle className="text-lg">Solar Inverter Integration</CardTitle>
                        </div>
                        <Link
                            to="/solar"
                            id="link-manage-solar"
                            className="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                        >
                            <span>Manage on Solar Page</span>
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </div>
                    <CardDescription>
                        GoodWe SEMS portal integration status and inverter telemetry summary.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {isSolarLoading ? (
                        <div className="flex items-center gap-2.5 py-4 text-sm text-muted">
                            <LoaderCircle className="size-4 animate-spin text-primary" />
                            <span>Checking solar inverter connection...</span>
                        </div>
                    ) : !connection?.configured || connectionStatus === 'NOT_CONFIGURED' ? (
                        <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface-alt p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-medium text-muted">
                                        Not Connected
                                    </span>
                                    <span className="text-xs font-medium text-muted">No Inverter Connected</span>
                                </div>
                                <p className="mt-1 text-xs text-muted">
                                    Connect your GoodWe SEMS inverter on the Solar page to begin synchronizing live solar telemetry.
                                </p>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link to="/solar">Connect Inverter</Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between rounded-lg border border-border bg-surface-alt p-3.5">
                                <div className="flex items-center gap-2.5">
                                    <span className="text-xs font-medium text-muted">Provider:</span>
                                    <span className="text-sm font-semibold text-heading">GoodWe SEMS Portal</span>
                                </div>
                                <div>
                                    {renderSolarStatusBadge(connectionStatus)}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <span className="text-xs font-medium text-muted">Power Station ID</span>
                                    <p className="mt-0.5 font-mono text-sm font-medium text-heading">
                                        {connection?.station_id || 'Not specified'}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs font-medium text-muted">Inverter Serial Number</span>
                                    <p className="mt-0.5 font-mono text-sm font-medium text-heading">
                                        {connection?.inverter_sn || 'Not specified'}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs font-medium text-muted">SEMS Account Identifier</span>
                                    <p className="mt-0.5 font-mono text-sm font-medium text-heading">
                                        {connection?.account_identifier || 'Masked account'}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs font-medium text-muted">Last Successful Telemetry Sync</span>
                                    <p className="mt-0.5 text-sm font-medium text-heading">
                                        {formatSyncTimestamp(connection?.last_successful_sync_at)}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center justify-between border-t border-border/60 pt-3 text-xs text-muted">
                                <span>Credentials and live synchronization are managed securely on the Solar workspace.</span>
                                <Link to="/solar" className="font-medium text-primary hover:underline">
                                    Open Solar Management →
                                </Link>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* System & Data Transparency Card */}
            <Card className="border-border bg-surface">
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="size-5 text-primary" />
                            <CardTitle className="text-lg">System &amp; Data Transparency</CardTitle>
                        </div>
                        <span className="text-xs font-mono text-muted">Version 0.1.0</span>
                    </div>
                    <CardDescription>
                        Data integrity foundations and core system workspaces.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg border border-border bg-surface-alt/60 p-3">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                                Actual
                            </span>
                            <p className="mt-1 text-xs text-muted">
                                Authentic hardware telemetry read directly from your GoodWe inverter.
                            </p>
                        </div>
                        <div className="rounded-lg border border-border bg-surface-alt/60 p-3">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-sky-600 dark:text-sky-400">
                                Verified
                            </span>
                            <p className="mt-1 text-xs text-muted">
                                Authoritative utility statements validated against your registered service number.
                            </p>
                        </div>
                        <div className="rounded-lg border border-border bg-surface-alt/60 p-3">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                                Estimated
                            </span>
                            <p className="mt-1 text-xs text-muted">
                                Algorithmic disaggregations and household demand baseline models.
                            </p>
                        </div>
                        <div className="rounded-lg border border-border bg-surface-alt/60 p-3">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">
                                Predicted
                            </span>
                            <p className="mt-1 text-xs text-muted">
                                Day-ahead solar generation projections assisted by Open-Meteo weather factors.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-3 text-xs text-muted">
                        <div className="flex items-center gap-4">
                            <Link to="/reports" className="inline-flex items-center gap-1 font-medium text-heading hover:text-primary">
                                <FileText className="size-3.5 text-primary" />
                                <span>Monthly Reports &amp; Exports</span>
                            </Link>
                            <Link to="/notifications" className="inline-flex items-center gap-1 font-medium text-heading hover:text-primary">
                                <Bell className="size-3.5 text-primary" />
                                <span>Notifications &amp; Alerts</span>
                            </Link>
                        </div>
                        <span>SPMS · Sustainable Power Management System</span>
                    </div>
                </CardContent>
            </Card>
        </div>
    )
}
