import { AlertTriangle, FileText, WifiOff, Clock, Activity } from 'lucide-react'
import { Card, CardContent } from '../../components/ui/card'
import { QualityIndicator } from '../../components/data-display/QualityIndicator'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { Link } from 'react-router'
import { getRecommendationCategory } from './recommendationCategories'

export function RecommendationLimitationsCard({ notice, recommendation }) {
    const item = notice || recommendation
    if (!item) {
        return null
    }

    const {
        title,
        summary,
        action,
        reason,
        timing_window,
        limitations = [],
        quality,
        data_nature,
    } = item

    const category = getRecommendationCategory(item)

    const isNoBills = limitations.includes('NO_VERIFIED_BILLS') || (title && title.includes('Bills'))
    const isStaleSolar =
        quality === 'STALE' ||
        limitations.includes('STALE_SOLAR_TELEMETRY') ||
        limitations.includes('STALE_SOLAR_READINGS') ||
        (title && (title.includes('Solar data is out of date') || title.includes('Telemetry Stale') || title.includes('Inverter Telemetry Stale')))
    const isWeatherUnavailable =
        limitations.some((l) => {
            const low = String(l).toLowerCase()
            return low.includes('weather') || low.includes('geocoding') || low.includes('open-meteo') || low.includes('network error')
        }) || (title && title.toLowerCase().includes('weather'))

    let displayTitle = title
    let displayBody = reason || summary
    let displayAction = action

    if (isStaleSolar) {
        displayTitle = 'Solar data is out of date'
        displayBody =
            'Your inverter has not sent a fresh reading recently. Recommendations that depend on live solar data may be less reliable.'
        displayAction = 'Check connection'
    } else if (isWeatherUnavailable) {
        displayTitle = 'Weather data unavailable'
        displayBody =
            'This recommendation is currently based on the expected solar pattern rather than live weather conditions.'
        displayAction = null
    }

    return (
        <Card className="border-warning/40 bg-warning/5 text-warning-foreground">
            <CardContent className="flex flex-col gap-3 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="flex items-center gap-2 font-semibold text-warning">
                        {isNoBills ? (
                            <FileText className="size-5 shrink-0" aria-hidden="true" />
                        ) : isStaleSolar ? (
                            <WifiOff className="size-5 shrink-0" aria-hidden="true" />
                        ) : (
                            <AlertTriangle className="size-5 shrink-0" aria-hidden="true" />
                        )}
                        <span>{displayTitle}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span
                            data-testid="recommendation-category-badge"
                            className="inline-flex items-center gap-1 rounded border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[11px] font-semibold text-rose-400"
                        >
                            <Activity className="size-3" aria-hidden="true" />
                            <span>{category.label}</span>
                        </span>
                        <span className="rounded bg-warning/20 px-2 py-0.5 text-xs font-semibold text-warning">
                            Data Quality Notice
                        </span>
                        {/* Never show 'Actual' beside a stale-data warning */}
                        {data_nature && !isStaleSolar && <DataNatureBadge nature={data_nature} />}
                        {quality && <QualityIndicator level={quality} />}
                    </div>
                </div>

                <p className="text-sm text-body">{displayBody}</p>

                {isStaleSolar && (
                    <div className="rounded border border-warning/30 bg-surface/70 p-2.5 text-xs text-heading flex items-center justify-between">
                        <span>Check connection to resume live telemetry</span>
                        <Link
                            to="/solar"
                            className="font-semibold text-primary hover:underline"
                        >
                            Check connection →
                        </Link>
                    </div>
                )}

                {displayAction && !isStaleSolar && (
                    <div className="rounded border border-warning/30 bg-surface/70 p-2.5 text-xs text-heading">
                        <span className="font-semibold text-warning">Action to unlock: </span>
                        <span>{displayAction}</span>
                        {isNoBills && (
                            <div className="mt-1.5">
                                <Link
                                    to="/bills"
                                    className="font-semibold text-primary hover:underline"
                                >
                                    Go to Bills Workspace →
                                </Link>
                            </div>
                        )}
                        {(title?.includes('Inverter') || limitations.includes('DEGRADED')) && (
                            <div className="mt-1.5 flex flex-wrap items-center gap-3">
                                <Link
                                    to="/solar"
                                    className="font-semibold text-primary hover:underline"
                                >
                                    Check Inverter Connection →
                                </Link>
                                <Link
                                    to="/solar"
                                    className="font-medium text-primary hover:underline"
                                >
                                    Connect Live Inverter
                                </Link>
                            </div>
                        )}
                    </div>
                )}

                {/* Optional "View details" disclosure for raw technical errors without cluttering primary UI */}
                {isWeatherUnavailable && limitations.length > 0 && (
                    <details className="text-[11px] text-muted">
                        <summary className="cursor-pointer text-muted hover:text-heading font-medium">
                            View details
                        </summary>
                        <p className="mt-1 font-mono text-[10px] text-warning/90 bg-surface/80 p-2 rounded border border-border">
                            {limitations.join('; ')}
                        </p>
                    </details>
                )}

                {timing_window && (
                    <div className="flex items-center gap-1.5 text-xs text-muted">
                        <Clock className="size-3.5" aria-hidden="true" />
                        <span>Status window: {timing_window}</span>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}

