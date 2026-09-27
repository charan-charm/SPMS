import { AlertTriangle, FileText, WifiOff, Clock } from 'lucide-react'
import { Card, CardContent } from '../../components/ui/card'
import { QualityIndicator } from '../../components/data-display/QualityIndicator'
import { DataNatureBadge } from '../../components/data-display/DataNatureBadge'
import { Link } from 'react-router'

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

    const isNoBills = limitations.includes('NO_VERIFIED_BILLS') || (title && title.includes('Bills'));
    const isStaleSolar = limitations.includes('STALE_SOLAR_TELEMETRY') || limitations.includes('STALE_SOLAR_READINGS') || (title && title.includes('Inverter'));

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
                        <span>{title}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="rounded bg-warning/20 px-2 py-0.5 text-xs font-semibold text-warning">
                            Data Quality Notice
                        </span>
                        {data_nature && <DataNatureBadge nature={data_nature} />}
                        {quality && <QualityIndicator level={quality} />}
                    </div>
                </div>

                <p className="text-sm text-body">{reason || summary}</p>

                {action && (
                    <div className="rounded border border-warning/30 bg-surface/70 p-2.5 text-xs text-heading">
                        <span className="font-semibold text-warning">Action to unlock: </span>
                        <span>{action}</span>
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
                        {isStaleSolar && (
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
