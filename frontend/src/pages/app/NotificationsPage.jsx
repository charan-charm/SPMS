import { useEffect, useState, useCallback, useRef } from 'react'
import { Link } from 'react-router'
import { notificationsApi } from '../../services/api/notifications'
import {
    Bell,
    CheckCircle2,
    AlertTriangle,
    AlertCircle,
    Info,
    Check,
    CheckCheck,
    RefreshCw,
    LoaderCircle,
    ArrowUpRight,
    Sun,
    Receipt,
    Lightbulb,
    Sparkles,
    ShieldAlert,
    Inbox,
} from 'lucide-react'

export function NotificationsPage() {
    const [loading, setLoading] = useState(true)
    const [notifications, setNotifications] = useState([])
    const [unreadCount, setUnreadCount] = useState(0)
    const [totalCount, setTotalCount] = useState(0)
    const [filter, setFilter] = useState('all') // 'all' | 'unread'
    const [error, setError] = useState(null)
    const [markingAll, setMarkingAll] = useState(false)
    const [markingId, setMarkingId] = useState(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)

    const loadNotifications = useCallback(async (filterMode, signal) => {
        try {
            setError(null)
            const resp = await notificationsApi.list({
                unread_only: filterMode === 'unread',
                limit: 50,
                signal,
            })
            if (!mountedRef.current) return
            setNotifications(resp.notifications || [])
            setUnreadCount(resp.unread_count ?? 0)
            setTotalCount(resp.total_count ?? 0)
        } catch (err) {
            if (err.name !== 'AbortError' && mountedRef.current) {
                setError(err.message || 'Failed to load notifications.')
            }
        } finally {
            if (mountedRef.current) {
                setLoading(false)
            }
        }
    }, [])

    const handleRefresh = async () => {
        setLoading(true)
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        await loadNotifications(filter, abortRef.current.signal)
    }

    useEffect(() => {
        mountedRef.current = true
        abortRef.current?.abort()
        abortRef.current = new AbortController()
        loadNotifications(filter, abortRef.current.signal)
        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [loadNotifications, filter])

    const handleMarkAsRead = async (id) => {
        setMarkingId(id)
        try {
            await notificationsApi.markRead(id)
            setNotifications((prev) =>
                prev.map((n) => (n.id === id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n))
            )
            setUnreadCount((prev) => Math.max(0, prev - 1))
        } catch (err) {
            setError(err.message || 'Failed to mark notification as read.')
        } finally {
            setMarkingId(null)
        }
    }

    const handleMarkAllRead = async () => {
        setMarkingAll(true)
        try {
            await notificationsApi.markAllRead()
            setNotifications((prev) =>
                prev.map((n) => ({ ...n, is_read: true, read_at: new Date().toISOString() }))
            )
            setUnreadCount(0)
            if (filter === 'unread') {
                setNotifications([])
            }
        } catch (err) {
            setError(err.message || 'Failed to mark all as read.')
        } finally {
            setMarkingAll(false)
        }
    }

    const renderSeverityBadge = (severity) => {
        switch (severity) {
            case 'SUCCESS':
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                        <CheckCircle2 className="size-3" />
                        Success
                    </span>
                )
            case 'WARNING':
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                        <AlertTriangle className="size-3" />
                        Warning
                    </span>
                )
            case 'CRITICAL':
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                        <AlertCircle className="size-3" />
                        Critical
                    </span>
                )
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20">
                        <Info className="size-3" />
                        Notice
                    </span>
                )
        }
    }

    const renderTypeIcon = (type) => {
        switch (type) {
            case 'SOLAR':
                return <Sun className="size-4 text-amber-500" />
            case 'BILL':
                return <Receipt className="size-4 text-sky-500" />
            case 'RECOMMENDATION':
                return <Lightbulb className="size-4 text-indigo-500" />
            case 'INSIGHT':
                return <Sparkles className="size-4 text-purple-500" />
            case 'DATA_QUALITY':
                return <ShieldAlert className="size-4 text-amber-500" />
            default:
                return <Bell className="size-4 text-muted" />
        }
    }

    const getResourceLink = (item) => {
        if (!item.related_resource_type) return null

        switch (item.related_resource_type) {
            case 'SOLAR':
                return { to: '/solar', label: 'View Solar Workspace' }
            case 'BILL':
                return { to: '/bills', label: 'View Statements' }
            case 'RECOMMENDATION':
                return { to: '/recommendations', label: 'View Recommendations' }
            case 'INSIGHT':
                return { to: '/insights', label: 'View Insights' }
            default:
                return null
        }
    }

    return (
        <div className="max-w-4xl mx-auto space-y-6 px-4 py-8">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                        <Bell className="size-5" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2.5">
                            <h1 className="font-display text-2xl font-bold text-heading tracking-tight leading-tight">
                                Notifications
                            </h1>
                            {unreadCount > 0 && (
                                <span className="inline-flex items-center justify-center px-2 py-0.5 text-xs font-semibold rounded-full bg-primary text-white">
                                    {unreadCount} unread
                                </span>
                            )}
                        </div>
                        <p className="text-sm text-muted mt-1 leading-snug">
                            Event and alert activity for your household solar connection, billing statements, and energy insights.
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 self-start">
                    {unreadCount > 0 && (
                        <button
                            type="button"
                            onClick={handleMarkAllRead}
                            disabled={markingAll}
                            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-muted hover:text-heading bg-panel/80 hover:bg-panel border border-border/60 rounded-md transition-colors disabled:opacity-50"
                        >
                            {markingAll ? (
                                <LoaderCircle className="size-3.5 animate-spin" />
                            ) : (
                                <CheckCheck className="size-3.5 text-primary" />
                            )}
                            Mark all as read
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-muted hover:text-heading bg-panel/80 hover:bg-panel border border-border/60 rounded-md transition-colors"
                        title="Refresh notifications"
                    >
                        <RefreshCw className="size-3.5" />
                        Refresh
                    </button>
                </div>
            </div>

            {/* Filter Tabs */}
            <div className="flex items-center gap-2 border-b border-border/60 pb-2">
                <button
                    type="button"
                    onClick={() => setFilter('all')}
                    className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
                        filter === 'all'
                            ? 'bg-primary text-white font-semibold'
                            : 'text-muted hover:text-heading hover:bg-panel/60'
                    }`}
                >
                    All ({totalCount})
                </button>
                <button
                    type="button"
                    onClick={() => setFilter('unread')}
                    className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
                        filter === 'unread'
                            ? 'bg-primary text-white font-semibold'
                            : 'text-muted hover:text-heading hover:bg-panel/60'
                    }`}
                >
                    Unread ({unreadCount})
                </button>
            </div>

            {/* Error Banner */}
            {error && (
                <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-950 dark:text-rose-200 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <AlertCircle className="size-4 text-rose-500 shrink-0" />
                        <span>{error}</span>
                    </div>
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="px-2.5 py-1 text-xs font-medium bg-rose-500/20 hover:bg-rose-500/30 rounded transition-colors"
                    >
                        Try again
                    </button>
                </div>
            )}

            {/* Loading State */}
            {loading ? (
                <div className="flex min-h-[300px] items-center justify-center">
                    <div className="flex items-center gap-2.5 text-sm text-muted">
                        <LoaderCircle className="size-5 animate-spin text-primary" />
                        Loading notifications...
                    </div>
                </div>
            ) : notifications.length === 0 ? (
                /* Empty State */
                <div className="rounded-xl border border-dashed border-border/80 bg-panel/30 p-12 text-center space-y-3">
                    <div className="flex size-12 mx-auto items-center justify-center rounded-full bg-surface text-muted">
                        <Inbox className="size-6" />
                    </div>
                    <h3 className="text-sm font-semibold text-heading">
                        {filter === 'unread' ? 'No unread notifications' : 'No notifications yet'}
                    </h3>
                    <p className="text-xs text-muted max-w-sm mx-auto">
                        {filter === 'unread'
                            ? 'All notifications have been reviewed. Switch to the All tab to view history.'
                            : 'Activity events will appear here as your GoodWe inverter synchronizes, bills are processed, and insights are generated.'}
                    </p>
                </div>
            ) : (
                /* Notifications List */
                <div className="space-y-3">
                    {notifications.map((item) => {
                        const resourceLink = getResourceLink(item)
                        const isPendingRead = markingId === item.id

                        return (
                            <div
                                key={item.id}
                                className={`rounded-xl border transition-all p-4.5 space-y-3 ${
                                    item.is_read
                                        ? 'bg-panel/40 border-border/40 opacity-85'
                                        : 'bg-panel border-border/80 shadow-sm ring-1 ring-primary/10'
                                }`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3">
                                        <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface border border-border/60 mt-0.5">
                                            {renderTypeIcon(item.type)}
                                        </div>

                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3
                                                    className={`text-sm text-heading ${
                                                        item.is_read ? 'font-medium' : 'font-semibold'
                                                    }`}
                                                >
                                                    {item.title}
                                                </h3>
                                                {!item.is_read && (
                                                    <span className="size-2 rounded-full bg-primary shrink-0" title="Unread" />
                                                )}
                                                {renderSeverityBadge(item.severity)}
                                                <span className="text-[10px] font-mono uppercase tracking-wider text-muted px-1.5 py-0.5 rounded bg-surface border border-border/40">
                                                    {item.type}
                                                </span>
                                            </div>

                                            <p className="text-xs text-muted leading-relaxed">
                                                {item.message}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0">
                                        {!item.is_read && (
                                            <button
                                                type="button"
                                                onClick={() => handleMarkAsRead(item.id)}
                                                disabled={isPendingRead}
                                                className="flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-muted hover:text-heading bg-surface hover:bg-surface/80 border border-border/60 rounded transition-colors disabled:opacity-50"
                                                title="Mark as read"
                                            >
                                                {isPendingRead ? (
                                                    <LoaderCircle className="size-3 animate-spin" />
                                                ) : (
                                                    <Check className="size-3 text-emerald-600 dark:text-emerald-400" />
                                                )}
                                                Mark read
                                            </button>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-center justify-between pt-2 border-t border-border/30 text-[11px] text-muted">
                                    <span>{item.created_at}</span>

                                    {resourceLink && (
                                        <Link
                                            to={resourceLink.to}
                                            className="inline-flex items-center gap-1 font-medium text-primary hover:text-primary/80 transition-colors"
                                        >
                                            <span>{resourceLink.label}</span>
                                            <ArrowUpRight className="size-3" />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )
                    })}
                </div>
            )}
        </div>
    )
}
