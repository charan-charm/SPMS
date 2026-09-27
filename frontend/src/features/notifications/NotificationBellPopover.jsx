import { useState, useRef, useEffect, useCallback } from 'react'
import { Link } from 'react-router'
import {
    Bell,
    Check,
    CheckCircle2,
    AlertTriangle,
    AlertCircle,
    Info,
    Inbox,
    LoaderCircle,
    ArrowRight,
    Sun,
    Receipt,
    Lightbulb,
    Sparkles,
    ShieldAlert,
} from 'lucide-react'
import { useNotifications } from './useNotifications'

/**
 * Helper to render relative or formatted time string.
 */
function formatTimeAgo(dateString) {
    if (!dateString) return ''
    try {
        const date = new Date(dateString.includes('T') ? dateString : dateString.replace(' ', 'T'))
        if (isNaN(date.getTime())) return dateString
        const now = new Date()
        const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

        if (diffInSeconds < 60) return 'Just now'
        const diffInMinutes = Math.floor(diffInSeconds / 60)
        if (diffInMinutes < 60) return `${diffInMinutes}m ago`
        const diffInHours = Math.floor(diffInMinutes / 60)
        if (diffInHours < 24) return `${diffInHours}h ago`
        const diffInDays = Math.floor(diffInHours / 24)
        if (diffInDays < 7) return `${diffInDays}d ago`

        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    } catch {
        return dateString
    }
}

/**
 * Render compact severity badge matching SPMS tokens.
 */
function renderSeverityBadge(severity) {
    switch (severity) {
        case 'SUCCESS':
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                    <CheckCircle2 className="size-2.5" />
                    Success
                </span>
            )
        case 'WARNING':
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                    <AlertTriangle className="size-2.5" />
                    Warning
                </span>
            )
        case 'CRITICAL':
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-rose-500/20 bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-medium text-rose-600 dark:text-rose-400">
                    <AlertCircle className="size-2.5" />
                    Critical
                </span>
            )
        default:
            return (
                <span className="inline-flex items-center gap-1 rounded-full border border-sky-500/20 bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 dark:text-sky-400">
                    <Info className="size-2.5" />
                    Notice
                </span>
            )
    }
}

/**
 * Render notification category icon matching SPMS.
 */
function renderTypeIcon(type) {
    switch (type) {
        case 'SOLAR':
            return <Sun className="size-3.5 text-amber-500" />
        case 'BILL':
            return <Receipt className="size-3.5 text-sky-500" />
        case 'RECOMMENDATION':
            return <Lightbulb className="size-3.5 text-indigo-500" />
        case 'INSIGHT':
            return <Sparkles className="size-3.5 text-purple-500" />
        case 'DATA_QUALITY':
            return <ShieldAlert className="size-3.5 text-amber-500" />
        default:
            return <Bell className="size-3.5 text-muted" />
    }
}

/**
 * Compact notification popover anchored to the SPMS AppHeader bell.
 */
export function NotificationBellPopover() {
    const [isOpen, setIsOpen] = useState(false)
    const { notifications, unreadCount, isLoading, error, markingId, refresh, markAsRead } = useNotifications({
        limit: 5,
    })

    const triggerRef = useRef(null)
    const popoverRef = useRef(null)
    const containerRef = useRef(null)

    // Toggle popover state
    const handleToggle = useCallback(() => {
        setIsOpen((prev) => !prev)
    }, [])

    // Close on click outside
    useEffect(() => {
        if (!isOpen) return

        const handlePointerDown = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setIsOpen(false)
            }
        }

        document.addEventListener('pointerdown', handlePointerDown)
        return () => {
            document.removeEventListener('pointerdown', handlePointerDown)
        }
    }, [isOpen])

    // Close on Escape and return focus to trigger
    useEffect(() => {
        if (!isOpen) return

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault()
                setIsOpen(false)
                triggerRef.current?.focus()
            }
        }

        document.addEventListener('keydown', handleKeyDown)
        return () => {
            document.removeEventListener('keydown', handleKeyDown)
        }
    }, [isOpen])

    const handleMarkAsRead = async (event, id) => {
        event.stopPropagation()
        try {
            await markAsRead(id)
        } catch {
            // error is handled inside hook
        }
    }

    const bellLabel = unreadCount > 0 ? `Notifications (${unreadCount} unread)` : 'Notifications'

    return (
        <div ref={containerRef} className="relative inline-block">
            {/* Bell Trigger Button */}
            <button
                ref={triggerRef}
                type="button"
                aria-label={bellLabel}
                aria-haspopup="dialog"
                aria-expanded={isOpen}
                onClick={handleToggle}
                className="relative inline-flex size-10 cursor-pointer items-center justify-center rounded-md border border-border bg-surface text-body transition-colors duration-150 hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <Bell className="size-4" aria-hidden="true" />

                {/* Unread indicator badge */}
                {unreadCount > 0 && (
                    <span
                        data-testid="unread-badge"
                        aria-hidden="true"
                        className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-white shadow-sm ring-2 ring-surface"
                    >
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {/* Popover Card */}
            {isOpen && (
                <div
                    ref={popoverRef}
                    role="dialog"
                    aria-label="Notifications"
                    tabIndex={-1}
                    className="absolute right-0 top-full mt-2 z-50 w-80 sm:w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-border bg-surface shadow-xl ring-1 ring-border/50 text-body overflow-hidden animate-in fade-in zoom-in-95 duration-150"
                >
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-border/70 px-4 py-3 bg-panel/40">
                        <div className="flex items-center gap-2">
                            <h3 className="font-display text-sm font-semibold text-heading">Notifications</h3>
                            {unreadCount > 0 && (
                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">
                                    {unreadCount} unread
                                </span>
                            )}
                        </div>

                        {isLoading && <LoaderCircle className="size-3.5 animate-spin text-primary" />}
                    </div>

                    {/* Body */}
                    <div className="max-h-[380px] overflow-y-auto divide-y divide-border/40">
                        {error ? (
                            <div className="p-4 text-center space-y-2">
                                <AlertCircle className="size-5 text-rose-500 mx-auto" />
                                <p className="text-xs text-rose-600 dark:text-rose-400">{error}</p>
                                <button
                                    type="button"
                                    onClick={() => refresh()}
                                    className="text-xs font-semibold text-primary hover:underline"
                                >
                                    Try again
                                </button>
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="p-8 text-center space-y-2">
                                <div className="flex size-10 mx-auto items-center justify-center rounded-full bg-panel text-muted">
                                    <Inbox className="size-5" />
                                </div>
                                <p className="text-xs font-semibold text-heading">No notifications</p>
                                <p className="text-[11px] text-muted">You&apos;re all caught up with alerts and activity.</p>
                            </div>
                        ) : (
                            notifications.map((item) => {
                                const isItemMarking = markingId === item.id

                                return (
                                    <div
                                        key={item.id}
                                        className={`group relative flex items-start gap-3 p-3 transition-colors ${
                                            item.is_read
                                                ? 'bg-transparent hover:bg-surface-alt/40 opacity-80'
                                                : 'bg-primary/[0.03] hover:bg-primary/[0.06]'
                                        }`}
                                    >
                                        {/* Icon */}
                                        <div className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border/60 bg-panel mt-0.5">
                                            {renderTypeIcon(item.type)}
                                        </div>

                                        {/* Details */}
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <h4
                                                    className={`truncate text-xs text-heading ${
                                                        item.is_read ? 'font-medium' : 'font-semibold'
                                                    }`}
                                                >
                                                    {item.title}
                                                </h4>
                                                {!item.is_read && (
                                                    <span
                                                        data-testid="unread-dot"
                                                        className="size-1.5 rounded-full bg-primary shrink-0"
                                                        title="Unread"
                                                    />
                                                )}
                                                {renderSeverityBadge(item.severity)}
                                            </div>

                                            <p className="line-clamp-2 text-[11px] leading-relaxed text-muted">
                                                {item.message}
                                            </p>

                                            <div className="flex items-center justify-between pt-0.5 text-[10px] text-muted">
                                                <span>{formatTimeAgo(item.created_at)}</span>
                                            </div>
                                        </div>

                                        {/* Action: Mark as read */}
                                        {!item.is_read && (
                                            <button
                                                type="button"
                                                onClick={(e) => handleMarkAsRead(e, item.id)}
                                                disabled={isItemMarking}
                                                aria-label="Mark notification as read"
                                                title="Mark as read"
                                                className="shrink-0 flex size-6 items-center justify-center rounded border border-border/60 bg-surface text-muted hover:border-primary hover:text-primary hover:bg-panel transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus disabled:opacity-50"
                                            >
                                                {isItemMarking ? (
                                                    <LoaderCircle className="size-3 animate-spin" />
                                                ) : (
                                                    <Check className="size-3 text-emerald-600 dark:text-emerald-400" />
                                                )}
                                            </button>
                                        )}
                                    </div>
                                )
                            })
                        )}
                    </div>

                    {/* Footer: View all link */}
                    <div className="border-t border-border/70 bg-panel/30">
                        <Link
                            to="/notifications"
                            onClick={() => setIsOpen(false)}
                            className="flex w-full items-center justify-center gap-1.5 py-2.5 px-3 text-xs font-semibold text-primary transition-colors hover:bg-surface-alt/60 hover:text-primary-active focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <span>View all notifications</span>
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </div>
                </div>
            )}
        </div>
    )
}
