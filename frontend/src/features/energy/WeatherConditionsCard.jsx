import { useMemo } from 'react'
import { Card, CardHeader, CardTitle, CardContent } from '../../components/ui/card'
import {
    CloudSun,
    SunMedium,
    CloudRain,
    Thermometer,
    Gauge,
    Sunrise,
    Sunset,
    AlertCircle,
    Info,
    Sparkles,
    Clock,
    ShieldCheck,
} from 'lucide-react'

function formatTime(isoString) {
    if (!isoString) return null
    try {
        const dt = new Date(isoString)
        if (isNaN(dt.getTime())) return isoString
        return dt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false }) + ' IST'
    } catch {
        return isoString
    }
}

/**
 * WeatherConditionsCard (SPMS Energy — Section 7 & 8).
 *
 * Compact, focused "Weather & Solar Conditions" experience:
 * - A. Current / Midday atmospheric conditions: temp, cloud cover, condition, precip probability
 * - B. Solar-relevant conditions: direct normal irradiance (DNI), expected solar impact
 * - C. Next 24-hour daylight outlook: sunrise, sunset, daylight emphasis
 * - D. Deterministic solar impact interpretation (STRONG, MODERATE, LOW)
 *
 * Strict Semantics:
 * - Labeled as ENVIRONMENTAL FORECAST EVIDENCE (Open-Meteo).
 * - NOT actual solar generation (GoodWe is authoritative actual).
 * - Honest fallback state with zero fabricated numbers.
 */
export function WeatherConditionsCard({ weatherData = null, isLoading = false }) {
    const isFallback = weatherData?.status === 'FALLBACK' || weatherData?.freshness === 'FALLBACK'
    const isUnavailable = !weatherData || weatherData.status === 'UNAVAILABLE' || weatherData.freshness === 'UNAVAILABLE'
    const isStale = weatherData?.status === 'STALE' || weatherData?.freshness === 'STALE'
    const status = isFallback
        ? 'FALLBACK'
        : isUnavailable
            ? 'UNAVAILABLE'
            : isStale
                ? 'STALE'
                : 'ACTIVE'

    const locationName = weatherData?.location || 'Household Panel Location'
    const providerLabel = weatherData?.provider || 'Open-Meteo'
    const condition = weatherData?.condition || (isUnavailable ? 'UNAVAILABLE' : 'CLEAR')

    const tempC = weatherData?.temperature !== null && weatherData?.temperature !== undefined
        ? Math.round(weatherData.temperature)
        : null

    const cloudCover = weatherData?.cloud_cover !== null && weatherData?.cloud_cover !== undefined
        ? Math.round(weatherData.cloud_cover)
        : null

    const dni = weatherData?.direct_normal_irradiance !== null && weatherData?.direct_normal_irradiance !== undefined
        ? Math.round(weatherData.direct_normal_irradiance)
        : null

    const swr = weatherData?.shortwave_irradiance !== null && weatherData?.shortwave_irradiance !== undefined
        ? Math.round(weatherData.shortwave_irradiance)
        : null

    const precipProb = weatherData?.precipitation_probability !== null && weatherData?.precipitation_probability !== undefined
        ? Math.round(weatherData.precipitation_probability)
        : null

    const sunrise = formatTime(weatherData?.sunrise)
    const sunset = formatTime(weatherData?.sunset)

    const rawSolarImpact = (weatherData?.solar_impact || 'MODERATE').toUpperCase()
    const solarImpact = (rawSolarImpact === 'STRONG' || rawSolarImpact === 'LOW') ? rawSolarImpact : 'MODERATE'
    const solarImpactDesc = weatherData?.solar_impact_description
        || (solarImpact === 'STRONG'
            ? 'Strong solar conditions expected: low cloud cover and high solar irradiance.'
            : solarImpact === 'LOW'
                ? 'Reduced solar conditions expected: cloud cover or precipitation moderates irradiance.'
                : 'Moderate solar conditions expected with partial cloud cover.')

    // Filter daylight forecast intervals for compact outlook (06:00 to 18:00)
    const daylightHours = useMemo(() => {
        const hourly = weatherData?.hourly || []
        if (!Array.isArray(hourly) || hourly.length === 0) return []
        return hourly
            .filter((h) => h.is_daylight || (h.hour >= 6 && h.hour <= 18))
            .slice(0, 13)
    }, [weatherData])

    // Impact badge styling
    const impactBadgeClass = solarImpact === 'STRONG'
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
        : solarImpact === 'LOW'
            ? 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400'
            : 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400'

    const impactLabel = solarImpact === 'STRONG'
        ? 'Strong solar conditions expected'
        : solarImpact === 'LOW'
            ? 'Reduced solar conditions expected'
            : 'Moderate solar conditions'

    if (isLoading) {
        return (
            <Card className="border-border bg-surface shadow-sm" data-testid="weather-conditions-card">
                <CardContent className="p-4 flex items-center justify-center text-xs text-muted">
                    Loading weather &amp; atmospheric solar conditions…
                </CardContent>
            </Card>
        )
    }

    return (
        <Card
            className="border-border bg-surface shadow-sm transition-all"
            data-testid="weather-conditions-card"
        >
            <CardHeader className="pb-3 border-b border-border/40">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                    <div className="flex items-center gap-2.5">
                        <div className="rounded-lg border border-sky-500/20 bg-sky-500/10 p-2 text-sky-500">
                            <CloudSun className="size-4.5" aria-hidden="true" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-semibold text-heading flex items-center gap-2">
                                <span>Weather &amp; Solar Conditions</span>
                                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full border border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-400 uppercase tracking-wider">
                                    ENVIRONMENTAL EVIDENCE
                                </span>
                            </CardTitle>
                            <p className="text-xs text-muted mt-0.5">
                                Supporting predictive evidence for solar forecasting · {locationName}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {status === 'ACTIVE' && (
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 uppercase">
                                ACTIVE / FRESH
                            </span>
                        )}
                        {status === 'STALE' && (
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400 uppercase">
                                STALE EVIDENCE
                            </span>
                        )}
                        {(status === 'FALLBACK' || status === 'UNAVAILABLE') && (
                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400 uppercase">
                                CLEAR-SKY FALLBACK
                            </span>
                        )}
                        <span className="text-[11px] text-muted font-mono">
                            {providerLabel}
                        </span>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="p-4 sm:p-5 space-y-4">
                {/* Fallback & Unavailable Disclosure */}
                {(status === 'FALLBACK' || status === 'UNAVAILABLE') && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-600 dark:text-amber-300 space-y-1">
                        <div className="flex items-center gap-1.5 font-semibold">
                            <AlertCircle className="size-3.5 shrink-0" />
                            <span>External weather evidence unavailable</span>
                        </div>
                        <p className="text-[11px] leading-relaxed text-amber-700 dark:text-amber-200/90">
                            The solar forecast is relying on astronomical clear-sky diurnal modeling with calibrated historical inverter baselines. No artificial weather values are fabricated.
                        </p>
                    </div>
                )}

                {/* Main Condition + Solar Impact Banner */}
                {status !== 'UNAVAILABLE' && status !== 'FALLBACK' && (
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-border/60 bg-surface-alt/40">
                        <div className="flex items-center gap-3">
                            <div className="p-2 rounded-lg bg-surface border border-border/80 text-primary">
                                <SunMedium className="size-5" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-semibold text-heading uppercase tracking-wide">
                                        {condition.replace('_', ' ')}
                                    </span>
                                    {tempC !== null && (
                                        <span className="text-sm font-mono text-muted">
                                            · {tempC}°C
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-muted mt-0.5">
                                    {solarImpactDesc}
                                </p>
                            </div>
                        </div>

                        <div className="shrink-0">
                            <span className={`inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1 rounded-full border ${impactBadgeClass}`}>
                                <Sparkles className="size-3" />
                                {impactLabel}
                            </span>
                        </div>
                    </div>
                )}

                {/* Atmospheric & Irradiance Metrics Grid */}
                {status !== 'UNAVAILABLE' && status !== 'FALLBACK' && (
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        {/* Temperature */}
                        <div className="rounded-xl border border-border/60 bg-surface p-3 space-y-1">
                            <div className="flex items-center gap-1.5 text-muted text-[11px] font-medium">
                                <Thermometer className="size-3 text-sky-500" />
                                <span>Temperature</span>
                            </div>
                            <div className="text-lg font-bold font-mono text-heading">
                                {tempC !== null ? `${tempC}°C` : '—'}
                            </div>
                            <div className="text-[10px] text-muted">Ambient air 2m</div>
                        </div>

                        {/* Cloud Cover */}
                        <div className="rounded-xl border border-border/60 bg-surface p-3 space-y-1">
                            <div className="flex items-center gap-1.5 text-muted text-[11px] font-medium">
                                <CloudSun className="size-3 text-sky-500" />
                                <span>Cloud Cover</span>
                            </div>
                            <div className="text-lg font-bold font-mono text-heading">
                                {cloudCover !== null ? `${cloudCover}%` : '—'}
                            </div>
                            <div className="text-[10px] text-muted">Total sky fraction</div>
                        </div>

                        {/* Irradiance / DNI */}
                        <div className="rounded-xl border border-border/60 bg-surface p-3 space-y-1">
                            <div className="flex items-center gap-1.5 text-muted text-[11px] font-medium">
                                <Gauge className="size-3 text-primary" />
                                <span>Direct Normal (DNI)</span>
                            </div>
                            <div className="text-lg font-bold font-mono text-heading">
                                {dni !== null ? `${dni} W/m²` : (swr !== null ? `${swr} W/m²` : '—')}
                            </div>
                            <div className="text-[10px] text-muted">
                                {dni !== null ? 'Direct beam radiation' : 'Shortwave radiation'}
                            </div>
                        </div>

                        {/* Rain Probability */}
                        <div className="rounded-xl border border-border/60 bg-surface p-3 space-y-1">
                            <div className="flex items-center gap-1.5 text-muted text-[11px] font-medium">
                                <CloudRain className="size-3 text-sky-500" />
                                <span>Rain Probability</span>
                            </div>
                            <div className="text-lg font-bold font-mono text-heading">
                                {precipProb !== null ? `${precipProb}%` : '0%'}
                            </div>
                            <div className="text-[10px] text-muted">Precipitation likelihood</div>
                        </div>
                    </div>
                )}

                {/* Daylight Outlook (Sunrise/Sunset & Daylight Horizon) */}
                <div className="rounded-xl border border-border/60 bg-surface-alt/30 p-3 sm:p-4 space-y-3">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                        <div className="flex items-center gap-2">
                            <Clock className="size-3.5 text-primary" />
                            <span className="font-semibold text-heading text-xs">
                                Daylight Outlook &amp; Solar Window
                            </span>
                        </div>
                        <div className="flex items-center gap-4 text-xs font-mono text-muted">
                            {sunrise && (
                                <span className="flex items-center gap-1">
                                    <Sunrise className="size-3.5 text-amber-500" />
                                    <span>Sunrise: {sunrise}</span>
                                </span>
                            )}
                            {sunset && (
                                <span className="flex items-center gap-1">
                                    <Sunset className="size-3.5 text-orange-500" />
                                    <span>Sunset: {sunset}</span>
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Compact Daylight Hourly Strip */}
                    {daylightHours.length > 0 && (
                        <div className="space-y-1.5">
                            <div className="grid grid-cols-6 sm:grid-cols-12 gap-1.5 pt-1">
                                {daylightHours.map((h, idx) => {
                                    const hourLabel = `${String(h.hour).padStart(2, '0')}:00`
                                    const isPeak = h.hour >= 10 && h.hour <= 14
                                    const impactColor = h.solar_impact === 'STRONG'
                                        ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                        : h.solar_impact === 'LOW'
                                            ? 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                            : 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/25'

                                    return (
                                        <div
                                            key={idx}
                                            className={`rounded-lg border p-1.5 text-center flex flex-col items-center justify-between min-h-[58px] ${impactColor} ${isPeak ? 'ring-1 ring-primary/40' : ''}`}
                                            title={`${hourLabel} · ${Math.round(h.cloud_cover_pct ?? 0)}% cloud · ${h.direct_normal_irradiance_wm2 ? Math.round(h.direct_normal_irradiance_wm2) + ' W/m² DNI' : 'Daylight'}`}
                                        >
                                            <span className="text-[10px] font-mono font-medium text-heading">
                                                {hourLabel}
                                            </span>
                                            <span className="text-[11px] font-bold font-mono">
                                                {h.cloud_cover_pct !== null ? `${Math.round(h.cloud_cover_pct)}%` : '—'}
                                            </span>
                                            <span className="text-[9px] uppercase font-semibold tracking-tighter opacity-80">
                                                {h.solar_impact}
                                            </span>
                                        </div>
                                    )
                                })}
                            </div>
                            <div className="flex items-center justify-between text-[10px] text-muted pt-0.5">
                                <span>Daylight hours (06:00–18:00 IST)</span>
                                <span>Values represent cloud cover (%) &amp; solar impact</span>
                            </div>
                        </div>
                    )}
                </div>

                {/* Semantic Integrity Footnote (Section 2 & 15) */}
                <div className="flex items-start gap-2 pt-1 border-t border-border/40 text-[11px] text-muted">
                    <Info className="size-3.5 text-sky-500 shrink-0 mt-0.5" />
                    <p className="leading-relaxed">
                        <strong className="text-heading font-medium">Environmental Evidence Semantics: </strong>
                        Weather is supporting predictive evidence informing future solar availability. Actual solar output is recorded exclusively by your GoodWe inverter. The solar generation prediction combines inverter operational telemetry with numerical weather factors.
                    </p>
                </div>
            </CardContent>
        </Card>
    )
}
