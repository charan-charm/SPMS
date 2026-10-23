import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useNotifications } from './useNotifications'
import { notificationsApi } from '../../services/api/notifications'
import * as authHook from '../auth/useAuth'
import * as householdHook from '../household/useHousehold'

vi.mock('../../services/api/notifications', () => ({
    notificationsApi: {
        list: vi.fn(),
        markRead: vi.fn(),
    },
}))

vi.mock('../auth/useAuth', () => ({
    useAuth: vi.fn(),
}))

vi.mock('../household/useHousehold', () => ({
    useHousehold: vi.fn(),
}))

describe('useNotifications — Household State Isolation Regression Tests', () => {
    let mockAuth
    let mockHousehold

    beforeEach(() => {
        vi.clearAllMocks()
        mockAuth = {
            isAuthenticated: true,
            account: { id: 'acc-1', email: 'user1@example.com' },
        }
        mockHousehold = {
            household: { id: 'hh-1', label: 'Household One' },
        }

        authHook.useAuth.mockImplementation(() => mockAuth)
        householdHook.useHousehold.mockImplementation(() => mockHousehold)

        notificationsApi.list.mockResolvedValue({
            notifications: [
                {
                    id: 'notif-hh1-1',
                    category: 'TARIFF_ALERT',
                    title: 'Peak Tariff Starting',
                    is_read: false,
                    created_at: '2026-10-12T09:00:00Z',
                },
            ],
            unread_count: 1,
            total_count: 1,
        })
    })

    it('fetches notifications on mount for authenticated user and active household', async () => {
        const { result } = renderHook(() => useNotifications())

        await act(async () => {})

        expect(notificationsApi.list).toHaveBeenCalledWith(
            expect.objectContaining({ limit: 5 })
        )
        expect(result.current.notifications).toHaveLength(1)
        expect(result.current.notifications[0].id).toBe('notif-hh1-1')
        expect(result.current.unreadCount).toBe(1)
    })

    it('clears previous household notifications immediately when household changes and reloads active household', async () => {
        const { result, rerender } = renderHook(() => useNotifications())

        await act(async () => {})
        expect(result.current.notifications).toHaveLength(1)
        expect(result.current.unreadCount).toBe(1)

        // Switch to HH2 with new notifications
        mockHousehold = {
            household: { id: 'hh-2', label: 'Household Two' },
        }
        notificationsApi.list.mockResolvedValueOnce({
            notifications: [
                {
                    id: 'notif-hh2-1',
                    category: 'INVERTER_OFFLINE',
                    title: 'Inverter Standby',
                    is_read: false,
                    created_at: '2026-10-12T10:00:00Z',
                },
                {
                    id: 'notif-hh2-2',
                    category: 'DAILY_SUMMARY',
                    title: 'Daily Summary',
                    is_read: true,
                    created_at: '2026-10-12T08:00:00Z',
                },
            ],
            unread_count: 1,
            total_count: 2,
        })

        rerender()

        // Wait for reload
        await act(async () => {})

        expect(result.current.notifications).toHaveLength(2)
        expect(result.current.notifications[0].id).toBe('notif-hh2-1')
        expect(result.current.totalCount).toBe(2)
    })

    it('discards late response from prior household so it does not overwrite new household state', async () => {
        let resolveHH1
        notificationsApi.list.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveHH1 = resolve
                })
        )

        const { result, rerender } = renderHook(() => useNotifications())

        // HH1 request is in-flight
        expect(result.current.isLoading).toBe(true)

        // Switch to HH2
        mockHousehold = {
            household: { id: 'hh-2', label: 'Household Two' },
        }
        notificationsApi.list.mockResolvedValueOnce({
            notifications: [],
            unread_count: 0,
            total_count: 0,
        })

        rerender()

        // Old HH1 request finally completes late
        await act(async () => {
            resolveHH1({
                notifications: [
                    { id: 'stale-hh1', category: 'SURPLUS_DETECTED', is_read: false },
                ],
                unread_count: 1,
                total_count: 1,
            })
        })

        // Ensure stale HH1 data is discarded and does not overwrite HH2 state
        expect(result.current.notifications).toEqual([])
        expect(result.current.unreadCount).toBe(0)
    })

    it('marks a notification as read optimistically and persists via API', async () => {
        notificationsApi.markRead.mockResolvedValue({ success: true })

        const { result } = renderHook(() => useNotifications())
        await act(async () => {})

        expect(result.current.unreadCount).toBe(1)
        expect(result.current.notifications[0].is_read).toBe(false)

        await act(async () => {
            await result.current.markAsRead('notif-hh1-1')
        })

        expect(result.current.notifications[0].is_read).toBe(true)
        expect(result.current.unreadCount).toBe(0)
        expect(notificationsApi.markRead).toHaveBeenCalledWith('notif-hh1-1')
    })
})
