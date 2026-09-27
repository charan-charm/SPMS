import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

vi.mock('../../services/api/notifications', () => ({
    notificationsApi: {
        list: vi.fn(),
        markRead: vi.fn(),
        markAllRead: vi.fn(),
    },
}))

import { notificationsApi } from '../../services/api/notifications'
import { NotificationsPage } from './NotificationsPage'

function renderComponent() {
    return render(
        <MemoryRouter>
            <NotificationsPage />
        </MemoryRouter>
    )
}

function sampleNotifications() {
    return [
        {
            id: 'notif-1',
            type: 'SOLAR',
            severity: 'SUCCESS',
            title: 'GoodWe Inverter Connected',
            message: 'Inverter station 10294 connected.',
            is_read: false,
            read_at: null,
            related_resource_type: 'SOLAR',
            related_resource_id: '10294',
            created_at: '2026-09-25 10:00:00',
        },
        {
            id: 'notif-2',
            type: 'BILL',
            severity: 'WARNING',
            title: 'Bill Processing Alert',
            message: 'Bill requires manual verification.',
            is_read: true,
            read_at: '2026-09-25 11:30:00',
            related_resource_type: 'BILL',
            related_resource_id: 'bill-123',
            created_at: '2026-09-25 09:00:00',
        },
    ]
}

describe('NotificationsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('shows loading spinner initially', async () => {
        notificationsApi.list.mockReturnValue(new Promise(() => {}))

        renderComponent()

        expect(screen.getByText(/Loading notifications/i)).toBeInTheDocument()
    })

    it('renders empty state when no notifications exist', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: [],
            unread_count: 0,
            total_count: 0,
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('No notifications yet')).toBeInTheDocument()
        })
    })

    it('renders notifications list with severity and type badges', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('GoodWe Inverter Connected')).toBeInTheDocument()
        })

        expect(screen.getByText('Bill Processing Alert')).toBeInTheDocument()
        expect(screen.getByText('Success')).toBeInTheDocument()
        expect(screen.getByText('Warning')).toBeInTheDocument()
        expect(screen.getByText('SOLAR')).toBeInTheDocument()
        expect(screen.getByText('BILL')).toBeInTheDocument()
        expect(screen.getByText('1 unread')).toBeInTheDocument()
    })

    it('renders related resource navigation link', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('View Solar Workspace')).toBeInTheDocument()
            expect(screen.getByText('View Statements')).toBeInTheDocument()
        })
    })

    it('shows error banner when fetching fails and allows retry', async () => {
        notificationsApi.list.mockRejectedValueOnce(new Error('Network error'))

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText(/Network error/i)).toBeInTheDocument()
        })

        notificationsApi.list.mockResolvedValueOnce({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })

        fireEvent.click(screen.getByRole('button', { name: /Try again/i }))

        await waitFor(() => {
            expect(screen.getByText('GoodWe Inverter Connected')).toBeInTheDocument()
        })
    })

    it('calls markRead when Mark read button is clicked', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })
        notificationsApi.markRead.mockResolvedValue({ status: 'OK', id: 'notif-1', is_read: true })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('GoodWe Inverter Connected')).toBeInTheDocument()
        })

        const markReadBtn = screen.getByRole('button', { name: /Mark read/i })
        fireEvent.click(markReadBtn)

        await waitFor(() => {
            expect(notificationsApi.markRead).toHaveBeenCalledWith('notif-1')
        })
    })

    it('calls markAllRead when Mark all as read button is clicked', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })
        notificationsApi.markAllRead.mockResolvedValue({ status: 'OK', marked_count: 1 })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('Mark all as read')).toBeInTheDocument()
        })

        fireEvent.click(screen.getByText('Mark all as read'))

        await waitFor(() => {
            expect(notificationsApi.markAllRead).toHaveBeenCalledTimes(1)
        })
    })

    it('switches to unread filter tab and requests unread only', async () => {
        notificationsApi.list.mockResolvedValue({
            notifications: sampleNotifications(),
            unread_count: 1,
            total_count: 2,
        })

        renderComponent()

        await waitFor(() => {
            expect(screen.getByText('All (2)')).toBeInTheDocument()
        })

        const unreadTab = screen.getByText('Unread (1)')
        fireEvent.click(unreadTab)

        await waitFor(() => {
            expect(notificationsApi.list).toHaveBeenCalledWith(
                expect.objectContaining({ unread_only: true })
            )
        })
    })
})
