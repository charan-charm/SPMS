import { useCallback, useEffect, useRef, useState } from 'react'
import { solarApi } from '../../services/api/solar'
import { getForecasts, getForecastValidation, getForecastPerformance } from '../../services/api/forecast'
import { getWeather } from '../../services/api/weather'
import { LoadingState } from '../../components/feedback/LoadingState'
import { ErrorState } from '../../components/feedback/ErrorState'
import { CurrentSolarCard } from '../../features/energy/CurrentSolarCard'
import { WeatherConditionsCard } from '../../features/energy/WeatherConditionsCard'
import { SolarForecastCard } from '../../features/energy/SolarForecastCard'
import { ForecastEnergyBalanceCard } from '../../features/energy/ForecastEnergyBalanceCard'
import { ForecastVsActualChart } from '../../features/energy/ForecastVsActualChart'
import { ForecastPerformanceCard } from '../../features/energy/ForecastPerformanceCard'
import { ForecastInputsCard } from '../../features/energy/ForecastInputsCard'
import { RecentForecastValidationsTable } from '../../features/energy/RecentForecastValidationsTable'
import { RefreshCw, Zap } from 'lucide-react'
import { Button } from '../../components/ui/button'

/**
 * EnergyBalancePage (SPMS Energy — Final Data-Truth & Solar Forecast Validation System).
 *
 * Page Information Hierarchy:
 * 1. Today's actual solar generation (CurrentSolarCard)
 * 2. Today's forecast vs actual daylight validation (ForecastVsActualChart)
 * 3. Weather & Solar Conditions (WeatherConditionsCard) — Environmental Evidence
 * 4. Tomorrow's solar generation forecast (SolarForecastCard) — Predicted Future Solar
 * 5. Forecast energy balance & daily household demand baseline (ForecastEnergyBalanceCard)
 * 6. Forecast validation & capacity-normalized performance (ForecastPerformanceCard)
 * 7. Forecast inputs & model provenance (ForecastInputsCard)
 * 8. Historical validation table (RecentForecastValidationsTable)
 *
 * Lifecycle & Refresh:
 * - Controlled background telemetry polling (60s, pauses when hidden).
 * - Automatic forecast target-date mapping.
 * - Viewport containment and smooth scroll to graph on date selection.
 */
export function EnergyBalancePage() {
    const mountedRef = useRef(true)
    const isPollingRef = useRef(false)
    const lastPollTimeRef = useRef(0)

    const [isLoading, setIsLoading] = useState(true)
    const [isRefreshing, setIsRefreshing] = useState(false)
    const [solarLatest, setSolarLatest] = useState(null)
    const [forecastData, setForecastData] = useState(null)
    const [weatherData, setWeatherData] = useState(null)
    const [validationToday, setValidationToday] = useState(null)
    const [validationDataCache, setValidationDataCache] = useState({})
    const [performanceSummary, setPerformanceSummary] = useState(null)
    const [selectedDate, setSelectedDate] = useState('today')
    const [isLoadingDate, setIsLoadingDate] = useState(false)
    const [error, setError] = useState(null)

    const fetchAllData = useCallback(async () => {
        const [solarRes, forecastRes, valTodayRes, perfRes, weatherRes] = await Promise.allSettled([
            solarApi.getLatest().catch(() => ({ latest: null })),
            getForecasts({ horizon: 'DAY_AHEAD' }).catch(() => ({ data: null })),
            getForecastValidation().catch(() => ({ data: null })),
            getForecastPerformance().catch(() => ({ data: null })),
            getWeather().catch(() => ({ data: null })),
        ])

        if (!mountedRef.current) return

        if (solarRes.status === 'fulfilled' && solarRes.value?.latest) {
            setSolarLatest(solarRes.value.latest)
        }

        if (forecastRes.status === 'fulfilled' && forecastRes.value?.data) {
            setForecastData(forecastRes.value.data)
        }

        if (weatherRes.status === 'fulfilled' && weatherRes.value?.data) {
            setWeatherData(weatherRes.value.data)
        }

        if (valTodayRes.status === 'fulfilled' && valTodayRes.value?.data) {
            setValidationToday(valTodayRes.value.data)
            setValidationDataCache((prev) => ({ ...prev, today: valTodayRes.value.data }))
        }

        if (perfRes.status === 'fulfilled' && perfRes.value?.data) {
            setPerformanceSummary(perfRes.value.data)
        }

        lastPollTimeRef.current = Date.now()
    }, [])

    const handleManualRefresh = useCallback(async () => {
        if (!mountedRef.current) return
        setIsRefreshing(true)
        setError(null)
        try {
            await fetchAllData()
        } catch (err) {
            if (mountedRef.current) {
                setError(err?.message || 'Failed to load solar forecast and validation data.')
            }
        } finally {
            if (mountedRef.current) {
                setIsRefreshing(false)
            }
        }
    }, [fetchAllData])

    // Controlled automatic background polling for live GoodWe telemetry (Section 3)
    useEffect(() => {
        lastPollTimeRef.current = Date.now()

        const pollLatestTelemetry = async () => {
            if (document.hidden || isPollingRef.current || !mountedRef.current) return
            isPollingRef.current = true
            try {
                const [solarRes, valTodayRes] = await Promise.allSettled([
                    solarApi.getLatest().catch(() => ({ latest: null })),
                    getForecastValidation().catch(() => ({ data: null })),
                ])
                if (!mountedRef.current) return

                if (solarRes.status === 'fulfilled' && solarRes.value?.latest) {
                    setSolarLatest(solarRes.value.latest)
                }
                if (valTodayRes.status === 'fulfilled' && valTodayRes.value?.data) {
                    setValidationToday(valTodayRes.value.data)
                    setValidationDataCache((prev) => ({ ...prev, today: valTodayRes.value.data }))
                }
                lastPollTimeRef.current = Date.now()
            } catch {
                // Silently tolerate background poll failure without breaking view
            } finally {
                isPollingRef.current = false
            }
        }

        const intervalId = setInterval(pollLatestTelemetry, 60000)

        const handleVisibilityChange = () => {
            if (!document.hidden && Date.now() - lastPollTimeRef.current >= 60000) {
                pollLatestTelemetry()
            }
        }
        document.addEventListener('visibilitychange', handleVisibilityChange)

        return () => {
            clearInterval(intervalId)
            document.removeEventListener('visibilitychange', handleVisibilityChange)
        }
    }, [])

    // Select validation date with smooth scroll to graph (Section 9 & 11)
    const handleSelectDate = useCallback(async (dateStr) => {
        if (!dateStr) return
        setSelectedDate(dateStr)

        // Smooth scroll to Forecast vs Actual validation metrics + graph respecting fixed header navbar
        try {
            const targetEl = document.getElementById('validation-summary-result') || document.getElementById('forecast-vs-actual-section')
            if (targetEl) {
                const headerOffset = 76
                const top = targetEl.getBoundingClientRect().top + window.pageYOffset - headerOffset
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
            }
        } catch {
            // Silently ignore scrolling errors in unsupported test environments
        }

        if (dateStr === 'today') {
            return
        }

        // Check if already in cache
        if (validationDataCache[dateStr]) {
            return
        }

        setIsLoadingDate(true)
        try {
            const res = await getForecastValidation({ date: dateStr })
            if (res?.data && mountedRef.current) {
                setValidationDataCache((prev) => ({
                    ...prev,
                    [dateStr]: res.data,
                }))
            }
        } catch (err) {
            console.error(`Failed to load validation for date ${dateStr}:`, err)
        } finally {
            if (mountedRef.current) {
                setIsLoadingDate(false)
            }
        }
    }, [validationDataCache])

    useEffect(() => {
        mountedRef.current = true
        let active = true

        const init = async () => {
            try {
                await fetchAllData()
            } catch (err) {
                if (active && mountedRef.current) {
                    setError(err?.message || 'Failed to load solar forecast and validation data.')
                }
            } finally {
                if (active && mountedRef.current) {
                    setIsLoading(false)
                }
            }
        }

        init()

        return () => {
            active = false
            mountedRef.current = false
        }
    }, [fetchAllData])

    if (isLoading) {
        return <LoadingState label="Loading solar generation &amp; validation pipeline…" />
    }

    if (error && !forecastData && !solarLatest) {
        return (
            <ErrorState
                title="Energy overview unavailable"
                message={error}
                retryLabel="Try again"
                onRetry={handleManualRefresh}
            />
        )
    }

    const activeValidation = selectedDate === 'today'
        ? validationToday
        : (validationDataCache[selectedDate] || null)

    const availableDates = performanceSummary?.available_dates || []

    return (
        <div className="space-y-6 max-w-6xl mx-auto" data-testid="energy-page">
            {/* Page Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-display font-bold text-heading flex items-center gap-2.5">
                        <Zap className="size-6 text-primary" />
                        Energy &amp; Solar Forecasting
                    </h1>
                    <p className="text-sm text-muted mt-1">
                        Authentic GoodWe solar generation, predictive modeling, verified household demand baseline, and empirical forecast validation.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={handleManualRefresh}
                        disabled={isRefreshing}
                        className="gap-1.5 text-xs text-muted hover:text-heading"
                    >
                        <RefreshCw className={`size-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
                        {isRefreshing ? 'Syncing…' : 'Refresh'}
                    </Button>
                </div>
            </div>

            {/* 1. CURRENT SOLAR — Actual GoodWe Telemetry */}
            <CurrentSolarCard solarLatest={solarLatest} />

            {/* 2. FORECAST VS ACTUAL VISUALIZATION — Line Graph & Today's Overlay (Section 16) */}
            <ForecastVsActualChart
                validationData={activeValidation}
                validationToday={validationToday}
                selectedDate={selectedDate}
                onSelectDate={handleSelectDate}
                availableDates={availableDates}
                isLoadingDay={isLoadingDate}
                performanceHistory={performanceSummary?.history || []}
            />

            {/* 3. WEATHER & SOLAR CONDITIONS — Environmental Supporting Evidence (Section 7 & 8) */}
            <WeatherConditionsCard weatherData={weatherData || forecastData?.weather} />

            {/* 4. SOLAR FORECAST — Tomorrow Horizon Outlook (Section 16) */}
            <SolarForecastCard forecastData={forecastData} />

            {/* 4. FORECAST ENERGY BALANCE — Supported Daily Household Demand Baseline & Potential Balance */}
            <ForecastEnergyBalanceCard forecastData={forecastData} />

            {/* 5. FORECAST PERFORMANCE & VALIDATION — Calculated Metrics */}
            <ForecastPerformanceCard
                performanceSummary={performanceSummary}
                activeValidation={activeValidation}
            />

            {/* 5. FORECAST INPUTS & MODEL PROVENANCE — Verifiable Factors (Collapsed by default) */}
            <ForecastInputsCard
                forecastData={forecastData}
                solarLatest={solarLatest}
            />

            {/* 6. RECENT COMPLETED VALIDATIONS — Historical Table Audit Trail */}
            <RecentForecastValidationsTable
                performanceHistory={performanceSummary?.history || []}
                selectedDate={selectedDate}
                onSelectDate={handleSelectDate}
            />
        </div>
    )
}
