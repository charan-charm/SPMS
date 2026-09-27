import { useState } from 'react'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { solarApi } from '../../services/api/solar'
import { CheckCircle2, AlertCircle, Eye, EyeOff, ShieldCheck, Zap, Radio, SlidersHorizontal } from 'lucide-react'

/**
 * SolarConnectForm (SPMS O4.5 — docs/10 D-059).
 *
 * Provides streamlined GoodWe SEMS+ account connection with automated station
 * and inverter discovery. Eliminates manual station UUID entry for homeowners.
 *
 * Props:
 *   onConnected(connection) — called on successful save with the new connection object.
 *   onConnecting(promise)   — optional; when provided, the parent wraps the connect
 *                             operation with the SPMS loading experience. The promise
 *                             must resolve with { connection } on success. On multi-
 *                             station discovery, the promise rejects so the loading
 *                             stops and the form displays station selection. On auth
 *                             failure, the promise rejects and the form displays the
 *                             error. This prop is intentionally generic — the parent
 *                             (SolarPage) owns the loading UI; this component owns
 *                             only the form and its local feedback states.
 */
export function SolarConnectForm({ onConnected, onConnecting }) {
    const [account, setAccount] = useState('')
    const [password, setPassword] = useState('')
    const [showPassword, setShowPassword] = useState(false)

    // Discovered station state
    const [discoveredStations, setDiscoveredStations] = useState([])
    const [selectedStationId, setSelectedStationId] = useState('')

    // Optional advanced inverter override
    const [showAdvanced, setShowAdvanced] = useState(false)
    const [inverterSn, setInverterSn] = useState('')

    const [isTesting, setIsTesting] = useState(false)
    const [testResult, setTestResult] = useState(null)
    const [testError, setTestError] = useState(null)

    const [isSubmitting, setIsSubmitting] = useState(false)
    const [submitError, setSubmitError] = useState(null)

    const canSubmit = account.trim() !== '' && password.trim() !== ''
    // Prevent all form interaction while either operation is active.
    const isBusy = isTesting || isSubmitting

    const handleTest = async (e) => {
        if (e) e.preventDefault()
        if (!canSubmit || isBusy) return

        setIsTesting(true)
        setTestResult(null)
        setTestError(null)

        try {
            const resp = await solarApi.testConnection({
                account: account.trim(),
                password,
                station_id: selectedStationId.trim() || undefined,
                inverter_sn: inverterSn.trim() || undefined,
            })
            const result = resp.test_result
            setTestResult(result)

            if (result.stations && result.stations.length > 1) {
                setDiscoveredStations(result.stations)
                if (!selectedStationId && result.stations[0]?.station_id) {
                    setSelectedStationId(result.stations[0].station_id)
                }
            } else if (result.station_id) {
                setSelectedStationId(result.station_id)
            }

            if (!result.success && result.status !== 'MULTIPLE_STATIONS') {
                setTestError(result.error_message || 'Connection test failed.')
            }
        } catch (err) {
            setTestError(err.message || 'Unable to communicate with solar verification service.')
        } finally {
            setIsTesting(false)
        }
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        if (!canSubmit || isBusy) return

        setIsSubmitting(true)
        setSubmitError(null)

        const connectPromise = solarApi
            .saveConnection({
                account: account.trim(),
                password,
                station_id: selectedStationId.trim() || undefined,
                inverter_sn: inverterSn.trim() || undefined,
            })
            .then((resp) => {
                if (onConnected) {
                    onConnected(resp.connection)
                }
                return { connection: resp.connection }
            })
            .catch(async (err) => {
                // Multiple stations: trigger discovery so user can pick, then re-throw
                // so the parent loading state stops and the form becomes visible again.
                if (err.message && err.message.toLowerCase().includes('multiple power stations')) {
                    try {
                        const disc = await solarApi.discoverStations({
                            account: account.trim(),
                            password,
                        })
                        if (disc.stations && disc.stations.length > 0) {
                            setDiscoveredStations(disc.stations)
                            if (!selectedStationId) {
                                setSelectedStationId(disc.stations[0].station_id)
                            }
                        }
                    } catch {
                        // Fallback to error display
                    }
                    setSubmitError(
                        'Multiple power stations found. Please select which station to connect below.'
                    )
                } else {
                    setSubmitError(err.message || 'Failed to save solar connection.')
                }
                // Re-throw so the parent's `onConnecting` promise rejects and stops
                // the loading state, returning the user to the form.
                throw err
            })
            .finally(() => {
                setIsSubmitting(false)
            })

        // If the parent supports the loading experience, delegate control.
        // Otherwise the promise's rejection has already been handled inside
        // the .catch() above (setSubmitError) — suppress it here so the
        // unhandled-rejection monitor stays clean.
        if (onConnecting) {
            onConnecting(connectPromise)
        } else {
            connectPromise.catch(() => {})
        }
    }

    return (
        <Card className="max-w-xl mx-auto border-border bg-surface">
            <CardHeader>
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                        <Zap className="size-5" />
                    </div>
                    <div>
                        <CardTitle className="text-xl font-bold text-heading">
                            Connect GoodWe Solar Inverter
                        </CardTitle>
                        <CardDescription className="text-sm text-muted mt-1 leading-relaxed">
                            Enter your SEMS+ account credentials. Your power station and inverter
                            will be discovered automatically.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <form onSubmit={handleSubmit}>
                <CardContent className="space-y-5">
                    {/* Security information — quieter treatment; form remains primary focus */}
                    <div className="rounded-md border border-border/50 bg-muted/10 p-3 text-[11px] text-muted leading-relaxed flex items-start gap-2">
                        <ShieldCheck className="size-3.5 shrink-0 text-muted mt-0.5" />
                        <span>
                            <span className="font-medium text-body">Encrypted storage: </span>
                            Credentials are encrypted with AEAD AES-256-GCM and bound to your
                            household. Plaintext passwords never appear in responses or logs.
                        </span>
                    </div>

                    {/* Account field */}
                    <div className="space-y-2">
                        <Label htmlFor="solar-account" className="text-[13px] font-semibold text-heading">
                            SEMS+ Account / Email
                        </Label>
                        <Input
                            id="solar-account"
                            type="text"
                            placeholder="e.g. user@example.com"
                            value={account}
                            onChange={(e) => setAccount(e.target.value)}
                            required
                            autoComplete="username"
                            disabled={isBusy}
                            className="text-heading placeholder:text-muted/60"
                        />
                    </div>

                    {/* Password field */}
                    <div className="space-y-2">
                        <Label htmlFor="solar-password" className="text-[13px] font-semibold text-heading">
                            SEMS+ Password
                        </Label>
                        <div className="relative">
                            <Input
                                id="solar-password"
                                type={showPassword ? 'text' : 'password'}
                                placeholder="Enter your SEMS+ password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                required
                                autoComplete="current-password"
                                disabled={isBusy}
                                className="pr-10 text-heading placeholder:text-muted/60"
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                disabled={isBusy}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-heading transition-colors disabled:pointer-events-none"
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                            </button>
                        </div>
                    </div>

                    {/* Multiple station selection */}
                    {discoveredStations.length > 1 && (
                        <div className="space-y-2 rounded-lg border border-border/80 bg-muted/10 p-3.5">
                            <div className="flex items-center gap-1.5 text-xs font-semibold text-heading">
                                <Radio className="size-3.5 text-primary" />
                                Select Power Station
                            </div>
                            <p className="text-[11px] text-muted">
                                Multiple power stations were found on this SEMS+ account. Select which
                                station to connect:
                            </p>
                            <div className="space-y-2 pt-1">
                                {discoveredStations.map((station) => {
                                    const isSelected = selectedStationId === station.station_id
                                    return (
                                        <button
                                            key={station.station_id}
                                            type="button"
                                            onClick={() => setSelectedStationId(station.station_id)}
                                            disabled={isBusy}
                                            className={`w-full flex items-center justify-between p-2.5 rounded-md border text-left text-xs transition-colors ${
                                                isSelected
                                                    ? 'border-primary bg-primary/10 text-heading font-medium'
                                                    : 'border-border/60 bg-surface hover:bg-muted/30 text-muted'
                                            }`}
                                        >
                                            <div>
                                                <div className="text-heading font-medium">{station.station_name}</div>
                                                {station.capacity_kw && (
                                                    <div className="text-[11px] text-muted">
                                                        {station.capacity_kw} kW Rated
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex size-4 items-center justify-center rounded-full border border-border">
                                                {isSelected && <div className="size-2 rounded-full bg-primary" />}
                                            </div>
                                        </button>
                                    )
                                })}
                            </div>
                        </div>
                    )}

                    {/* Advanced inverter options */}
                    <div>
                        <button
                            type="button"
                            onClick={() => setShowAdvanced(!showAdvanced)}
                            disabled={isBusy}
                            className="inline-flex items-center gap-1.5 text-xs text-muted hover:text-heading transition-colors pt-1 disabled:pointer-events-none"
                        >
                            <SlidersHorizontal className="size-3.5" />
                            {showAdvanced ? 'Hide Advanced Options' : 'Advanced Inverter Options (Optional)'}
                        </button>

                        {showAdvanced && (
                            <div className="mt-2 space-y-2 rounded-md border border-border/50 bg-muted/10 p-3">
                                <Label htmlFor="solar-inverter-sn" className="text-[13px] font-semibold text-heading">
                                    Inverter Serial Number (Optional)
                                </Label>
                                <Input
                                    id="solar-inverter-sn"
                                    type="text"
                                    placeholder="e.g. 96000DNS..."
                                    value={inverterSn}
                                    onChange={(e) => setInverterSn(e.target.value)}
                                    disabled={isBusy}
                                    className="text-heading placeholder:text-muted/60"
                                />
                                <p className="text-[11px] text-muted">
                                    Leave blank to auto-discover your primary inverter.
                                </p>
                            </div>
                        )}
                    </div>

                    {/* Test connection success result */}
                    {testResult && testResult.success && (
                        <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-xs text-emerald-950 dark:text-emerald-200">
                            <div className="flex items-center gap-2 font-semibold text-emerald-900 dark:text-emerald-100 mb-1">
                                <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                                Discovery Successful ({testResult.status})
                            </div>
                            <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-muted text-[11px] mt-1.5">
                                <div>
                                    Station:{' '}
                                    <span className="text-heading font-medium">
                                        {testResult.station_name || 'Discovered'}
                                    </span>
                                </div>
                                <div>
                                    Inverter:{' '}
                                    <span className="text-heading font-medium">
                                        {testResult.inverter_model || 'GoodWe Inverter'}
                                    </span>
                                </div>
                                <div>
                                    Serial:{' '}
                                    <span className="text-heading font-mono">
                                        {testResult.inverter_sn || 'Discovered'}
                                    </span>
                                </div>
                                <div>
                                    Rated:{' '}
                                    <span className="text-heading font-medium">
                                        {testResult.rated_power_kw
                                            ? `${testResult.rated_power_kw} kW`
                                            : 'Standard'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Error feedback — credentials deliberately not shown */}
                    {(testError || submitError) && (
                        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-950 dark:text-rose-200">
                            <div className="flex items-center gap-2 font-semibold text-rose-900 dark:text-rose-100">
                                <AlertCircle className="size-4 text-rose-600 dark:text-rose-400 shrink-0" />
                                Connection Notice
                            </div>
                            <div className="mt-1 text-muted text-[11px] leading-relaxed">
                                {testError || submitError}
                            </div>
                        </div>
                    )}
                </CardContent>

                <CardFooter className="flex flex-col sm:flex-row items-center justify-end gap-3 pt-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={handleTest}
                        disabled={!canSubmit || isBusy}
                        loading={isTesting}
                        className="w-full sm:w-auto"
                    >
                        {isTesting ? 'Discovering Stations...' : 'Test Connection'}
                    </Button>

                    <Button
                        id="solar-connect-btn"
                        type="submit"
                        disabled={!canSubmit || isBusy}
                        loading={isSubmitting}
                        className="w-full sm:w-auto"
                    >
                        {isSubmitting ? 'Connecting...' : 'Connect Inverter'}
                    </Button>
                </CardFooter>
            </form>
        </Card>
    )
}
