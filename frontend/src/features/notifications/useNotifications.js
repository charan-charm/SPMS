import { useState, useEffect, useCallback, useRef } from 'react'
import { notificationsApi } from '../../services/api/notifications'
import { useAuth } from '../auth/useAuth'
import { useHousehold } from '../household/useHousehold'

/**
 * Hook for consuming notification state in the SPMS header and popover.
 * Reuses the existing notificationsApi client with strict household isolation and auth awareness.
 */
export function useNotifications({ limit = 5, autoFetch = true, householdId: explicitHouseholdId } = {}) {
    const { isAuthenticated, account } = useAuth()
    const { household } = useHousehold()
    const activeHouseholdId = explicitHouseholdId !== undefined ? explicitHouseholdId : household?.id
    const activeAccountId = account?.id

    const [notifications, setNotifications] = useState([])
    const [unreadCount, setUnreadCount] = useState(0)
    const [totalCount, setTotalCount] = useState(0)
    const [isLoading, setIsLoading] = useState(false)
    const [error, setError] = useState(null)
    const [markingId, setMarkingId] = useState(null)

    const abortRef = useRef(null)
    const mountedRef = useRef(true)
    const requestGenRef = useRef(0)

    const scopeKey = `${activeAccountId || ''}:${activeHouseholdId || ''}`
    const prevScopeKeyRef = useRef(scopeKey)

    // Household/account change: immediately wipe previous data and invalidate pending requests
    useEffect(() => {
        mountedRef.current = true
        if (prevScopeKeyRef.current !== scopeKey) {
            prevScopeKeyRef.current = scopeKey
            requestGenRef.current += 1
            abortRef.current?.abort()
            setNotifications([])
            setUnreadCount(0)
            setTotalCount(0)
            setError(null)
        }
    }, [scopeKey])

    const fetchNotifications = useCallback(
        async (signal, gen) => {
            if (!isAuthenticated) {
                setNotifications([])
                setUnreadCount(0)
                setTotalCount(0)
                return
            }

            setIsLoading(true)
            setError(null)
            try {
                const data = await notificationsApi.list({ limit, signal })
                if (!mountedRef.current) return
                if (gen !== undefined && gen !== requestGenRef.current) return // Stale response from prior household

                setNotifications(data?.notifications || [])
                setUnreadCount(data?.unread_count ?? 0)
                setTotalCount(data?.total_count ?? 0)
            } catch (err) {
                if (
                    err?.name !== 'AbortError' &&
                    mountedRef.current &&
                    (gen === undefined || gen === requestGenRef.current)
                ) {
                    setError(err?.message || 'Failed to load notifications.')
                }
            } finally {
                if (
                    mountedRef.current &&
                    (gen === undefined || gen === requestGenRef.current)
                ) {
                    setIsLoading(false)
                }
            }
        },
        [isAuthenticated, limit]
    )

    useEffect(() => {
        mountedRef.current = true
        if (!autoFetch || !isAuthenticated) return

        const currentGen = requestGenRef.current
        abortRef.current?.abort()
        const ac = new AbortController()
        abortRef.current = ac
        fetchNotifications(ac.signal, currentGen)

        return () => {
            mountedRef.current = false
            abortRef.current?.abort()
        }
    }, [fetchNotifications, autoFetch, isAuthenticated, scopeKey])

    const markAsRead = useCallback(
        async (id) => {
            setMarkingId(id)
            try {
                // Optimistic local update
                setNotifications((prev) =>
                    prev.map((item) =>
                        item.id === id ? { ...item, is_read: true, read_at: new Date().toISOString() } : item
                    )
                )
                setUnreadCount((prev) => Math.max(0, prev - 1))

                await notificationsApi.markRead(id)
            } catch (err) {
                // On failure, re-fetch to restore accurate server state
                if (mountedRef.current) {
                    fetchNotifications()
                }
                throw err
            } finally {
                if (mountedRef.current) {
                    setMarkingId(null)
                }
            }
        },
        [fetchNotifications]
    )

    return {
        notifications,
        unreadCount,
        totalCount,
        isLoading,
        error,
        markingId,
        refresh: () => {
            abortRef.current?.abort()
            abortRef.current = new AbortController()
            return fetchNotifications(abortRef.current.signal)
        },
        markAsRead,
    }
}
