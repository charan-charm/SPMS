import {
    CloudSun,
    CloudOff,
    Thermometer,
    Cloud,
    CloudRain,
    SunDim,
    MapPin,
    Clock,
    Sparkles,
    AlertTriangle,
    CircleDashed,
    LoaderCircle,
    Info,
} from 'lucide-react'

/**
 * Format relative or time string for weather data freshness.
 */
function formatFreshness(timestamp) {
    if (!timestamp) return null
    try {
        const date = new Date(timestamp.includes('T') ? timestamp : timestamp.replace(' ', 'T'))
        if (isNaN(date.getTime())) return timestamp
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    } catch {
        return timestamp
    }
}

/**
 * F12 — Weather Input Card
 *
 * Visibly surfaces Open-Meteo weather evidence evaluated during F3 solar forecasting.
 * Strictly presents server-authoritative provider data with clear distinction
 * between predictive weather factors and measured household telemetry.
 */
export function WeatherInputCard({ weather = null, isLoading = false }) {
    if (isLoading && !weather) {
        return (
            <div
                data-testid="weather-card-loading"
                className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm space-y-3"
            >
                <div className="flex items-center justify-between border-b border-border/40 pb-2.5">
                    <div className="flex items-center gap-2">
                        <CloudSun className="h-4 w-4 text-emerald-400" />
                        <span className="font-semibold text-text-primary text-xs uppercase tracking-wider">
                            Weather Input
                        </span>
                    </div>
                    <span className="text-[11px] text-text-muted">Open-Meteo</span>
                </div>
                <div className="flex items-center justify-center py-6 text-xs text-text-muted gap-2">
                    <LoaderCircle className="h-4 w-4 animate-spin text-emerald-400" />
                    <span>Loading weather evidence...</span>
                </div>
            </div>
        )
    }

    const isAvailable = Boolean(weather?.available)
    const status = weather?.status || (isAvailable ? 'WEATHER_ENHANCED' : 'UNAVAILABLE')
    const sourceLabel = weather?.source_label || 'Open-Meteo'
    const locationName = weather?.location_name || (weather?.coordinates ? `${weather.coordinates.latitude}, ${weather.coordinates.longitude}` : 'Household Location')
    const freshnessTime = formatFreshness(weather?.retrieved_at)

    const renderStatusBadge = () => {
        switch (status) {
            case 'WEATHER_ENHANCED':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-400">
                        <Sparkles className="h-3 w-3" />
                        Weather-enhanced
                    </span>
                )
            case 'FALLBACK':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-400">
                        <AlertTriangle className="h-3 w-3" />
                        Fallback
                    </span>
                )
            case 'UNAVAILABLE':
            default:
                return (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-subtle px-2 py-0.5 text-[11px] font-semibold text-text-muted">
                        <CircleDashed className="h-3 w-3" />
                        Unavailable
                    </span>
                )
        }
    }

    return (
        <div
            data-testid="weather-input-card"
            className="rounded-2xl border border-border/80 bg-surface/70 p-4 shadow-sm backdrop-blur-sm space-y-3.5 transition-all"
        >
            {/* Header: Title, Source, and Status Badge */}
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-2.5">
                <div className="flex items-center gap-2">
                    {isAvailable ? (
                        <CloudSun className="h-4 w-4 text-emerald-400" />
                    ) : (
                        <CloudOff className="h-4 w-4 text-amber-400" />
                    )}
                    <h3 className="font-semibold text-text-primary text-xs uppercase tracking-wider">
                        Weather Input
                    </h3>
                    <span className="text-[11px] font-medium text-text-muted">
                        Source: <span className="text-text-secondary">{sourceLabel}</span>
                    </span>
                </div>

                <div className="flex items-center gap-2">{renderStatusBadge()}</div>
            </div>

            {/* Location & Freshness Strip */}
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-text-muted">
                <div className="flex items-center gap-1.5 min-w-0">
                    <MapPin className="h-3.5 w-3.5 text-text-muted shrink-0" />
                    <span className="truncate font-medium text-text-secondary">{locationName}</span>
                    {weather?.coordinates && (
                        <span className="text-[10px] text-text-muted font-mono hidden sm:inline">
                            ({weather.coordinates.latitude.toFixed(2)}°, {weather.coordinates.longitude.toFixed(2)}°)
                        </span>
                    )}
                </div>

                {freshnessTime && (
                    <div className="flex items-center gap-1 text-[11px] text-text-muted shrink-0">
                        <Clock className="h-3 w-3" />
                        <span>Retrieved {freshnessTime}</span>
                    </div>
                )}
            </div>

            {/* Weather Metrics or Fallback Banner */}
            {isAvailable ? (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-1">
                    {/* Temperature */}
                    <div className="rounded-xl border border-border/50 bg-surface-subtle/50 p-2.5 space-y-1">
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Temperature</span>
                            <Thermometer className="h-3.5 w-3.5 text-amber-400" />
                        </div>
                        <div className="font-heading text-lg font-bold text-text-primary">
                            {weather?.temperature_c !== null && weather?.temperature_c !== undefined
                                ? `${weather.temperature_c}°C`
                                : '--'}
                        </div>
                    </div>

                    {/* Cloud Cover */}
                    <div className="rounded-xl border border-border/50 bg-surface-subtle/50 p-2.5 space-y-1">
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Cloud Cover</span>
                            <Cloud className="h-3.5 w-3.5 text-sky-400" />
                        </div>
                        <div className="font-heading text-lg font-bold text-text-primary">
                            {weather?.cloud_cover_pct !== null && weather?.cloud_cover_pct !== undefined
                                ? `${weather.cloud_cover_pct}%`
                                : '--'}
                        </div>
                    </div>

                    {/* Precipitation Probability */}
                    <div className="rounded-xl border border-border/50 bg-surface-subtle/50 p-2.5 space-y-1">
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Precipitation</span>
                            <CloudRain className="h-3.5 w-3.5 text-blue-400" />
                        </div>
                        <div className="font-heading text-lg font-bold text-text-primary">
                            {weather?.precipitation_probability_pct !== null && weather?.precipitation_probability_pct !== undefined
                                ? `${weather.precipitation_probability_pct}%`
                                : '--'}
                        </div>
                    </div>

                    {/* Solar Irradiance */}
                    <div className="rounded-xl border border-border/50 bg-surface-subtle/50 p-2.5 space-y-1">
                        <div className="flex items-center justify-between text-[11px] text-text-muted">
                            <span>Solar Radiation</span>
                            <SunDim className="h-3.5 w-3.5 text-emerald-400" />
                        </div>
                        <div className="font-heading text-lg font-bold text-text-primary">
                            {weather?.solar_irradiance_wm2 !== null && weather?.solar_irradiance_wm2 !== undefined
                                ? `${weather.solar_irradiance_wm2} W/m²`
                                : weather?.clear_sky_index !== null && weather?.clear_sky_index !== undefined
                                    ? `CSI ${(weather.clear_sky_index * 100).toFixed(0)}%`
                                    : '--'}
                        </div>
                    </div>
                </div>
            ) : (
                /* Honest Fallback Explanation */
                <div
                    data-testid="weather-fallback-explanation"
                    className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200 flex items-start gap-2.5"
                >
                    <AlertTriangle className="h-4 w-4 text-amber-400 shrink-0 mt-0.5" />
                    <div className="space-y-1">
                        <p className="font-semibold text-amber-300">
                            {status === 'FALLBACK' ? 'Weather Service Fallback Active' : 'Weather Data Unavailable'}
                        </p>
                        <p className="text-text-muted leading-relaxed">
                            {weather?.fallback_explanation ||
                                'External weather data is currently unavailable. Solar forecasts are safely operating using the deterministic clear-sky physical solar pattern model.'}
                        </p>
                    </div>
                </div>
            )}

            {/* Semantic Distinction Disclaimer */}
            <div className="flex items-center gap-1.5 pt-1 text-[11px] text-text-muted border-t border-border/30">
                <Info className="h-3 w-3 shrink-0 text-text-muted/70" />
                <span>
                    Weather data represents predictive atmospheric evidence from Open-Meteo, distinct from measured household telemetry.
                </span>
            </div>
        </div>
    )
}
