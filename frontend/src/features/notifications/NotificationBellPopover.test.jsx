import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { NotificationBellPopover } from './NotificationBellPopover'
import { AuthContext } from '../auth/AuthContext'

vi.mock('../../services/api/notifications', () => ({
    notificationsApi: {
        list: vi.fn(),
        markRead: vi.fn(),
        markAllRead: vi.fn(),
    },
}))

import { notificationsApi } from '../../services/api/notifications'

function sampleNotifications() {
    return [
        {
            id: 'notif-1',
            type: 'SOLAR',
            severity: 'SUCCESS',
            title: 'GoodWe Inverter Connected',
            message: 'Inverter station 10294 connected successfully.',
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
            message: 'APSPDCL bill requires manual verification.',
            is_read: true,
            read_at: '2026-09-25 11:30:00',
            related_resource_type: 'BILL',
            related_resource_id: 'bill-123',
            created_at: '2026-09-25 09:00:00',
        },
    ]
}

function renderPopover(authOverrides = {}) {
    const authValue = {
        account: { email: 'test@example.com', role: 'OWNER', mode: 'REAL' },
        isAuthenticated: true,
        status: 'authenticated',
        logout: vi.fn(),
        ...authOverrides,
    }

    return render(
        <MemoryRouter>
            <AuthContext.Provider value={authValue}>
                <NotificationBellPopover />
            </AuthContext.Provider>
        </MemoryRouter>
    )
}

describe('NotificationBellPopover', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    describe('Unread badge and trigger state', () => {
        it('shows unread badge and accessible label when unread notifications exist', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: 'Notifications (1 unread)' })
            expect(trigger).toBeInTheDocument()
            expect(trigger).toHaveAttribute('aria-expanded', 'false')
            expect(trigger).toHaveAttribute('aria-haspopup', 'dialog')

            const badge = screen.getByTestId('unread-badge')
            expect(badge).toHaveTextContent('1')
        })

        it('does not display badge when unread_count is 0', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: [sampleNotifications()[1]],
                unread_count: 0,
                total_count: 1,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: 'Notifications' })
            expect(trigger).toBeInTheDocument()
            expect(screen.queryByTestId('unread-badge')).not.toBeInTheDocument()
        })

        it('caps badge display at 99+ when unread_count exceeds 99', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 120,
                total_count: 150,
            })

            renderPopover()

            const badge = await screen.findByTestId('unread-badge')
            expect(badge).toHaveTextContent('99+')
        })
    })

    describe('Popover rendering & interaction', () => {
        it('opens popover on click and renders recent notifications with severity, title, message, and time', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)

            expect(trigger).toHaveAttribute('aria-expanded', 'true')
            const dialog = screen.getByRole('dialog', { name: 'Notifications' })
            expect(dialog).toBeInTheDocument()

            // Header unread pill
            expect(screen.getByText('1 unread')).toBeInTheDocument()

            // Notification item 1 (unread, SUCCESS)
            expect(screen.getByText('GoodWe Inverter Connected')).toBeInTheDocument()
            expect(screen.getByText('Inverter station 10294 connected successfully.')).toBeInTheDocument()
            expect(screen.getByText('Success')).toBeInTheDocument()
            expect(screen.getByTestId('unread-dot')).toBeInTheDocument()

            // Notification item 2 (read, WARNING)
            expect(screen.getByText('Bill Processing Alert')).toBeInTheDocument()
            expect(screen.getByText('APSPDCL bill requires manual verification.')).toBeInTheDocument()
            expect(screen.getByText('Warning')).toBeInTheDocument()
        })

        it('toggles popover closed when clicking trigger again', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)
            expect(screen.getByRole('dialog', { name: 'Notifications' })).toBeInTheDocument()

            fireEvent.click(trigger)
            expect(screen.queryByRole('dialog', { name: 'Notifications' })).not.toBeInTheDocument()
            expect(trigger).toHaveAttribute('aria-expanded', 'false')
        })
    })

    describe('Mark individual notification as read', () => {
        it('allows marking an individual unread notification as read', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })
            notificationsApi.markRead.mockResolvedValue({
                status: 'OK',
                id: 'notif-1',
                is_read: true,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)

            const markReadButton = screen.getByRole('button', { name: 'Mark notification as read' })
            expect(markReadButton).toBeInTheDocument()

            fireEvent.click(markReadButton)

            await waitFor(() => {
                expect(notificationsApi.markRead).toHaveBeenCalledWith('notif-1')
            })

            // Unread dot and mark-read button disappear
            await waitFor(() => {
                expect(screen.queryByTestId('unread-dot')).not.toBeInTheDocument()
                expect(screen.queryByRole('button', { name: 'Mark notification as read' })).not.toBeInTheDocument()
            })
        })
    })

    describe('Empty state', () => {
        it('displays empty state when there are no notifications', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: [],
                unread_count: 0,
                total_count: 0,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: 'Notifications' })
            fireEvent.click(trigger)

            expect(screen.getByText('No notifications')).toBeInTheDocument()
            expect(screen.getByText(/You're all caught up with alerts and activity/i)).toBeInTheDocument()
        })
    })

    describe('View all notifications action', () => {
        it('links to /notifications and closes popover when clicked', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)

            const viewAllLink = screen.getByRole('link', { name: /View all notifications/i })
            expect(viewAllLink).toHaveAttribute('href', '/notifications')

            fireEvent.click(viewAllLink)

            // Popover should close
            expect(screen.queryByRole('dialog', { name: 'Notifications' })).not.toBeInTheDocument()
        })
    })

    describe('Accessibility & close behavior', () => {
        it('closes popover on Escape key press and restores focus to trigger', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)

            expect(screen.getByRole('dialog', { name: 'Notifications' })).toBeInTheDocument()

            // Press Escape
            fireEvent.keyDown(document, { key: 'Escape', code: 'Escape' })

            expect(screen.queryByRole('dialog', { name: 'Notifications' })).not.toBeInTheDocument()
            expect(document.activeElement).toBe(trigger)
        })

        it('closes popover when clicking outside', async () => {
            notificationsApi.list.mockResolvedValue({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            renderPopover()

            const trigger = await screen.findByRole('button', { name: /Notifications/i })
            fireEvent.click(trigger)

            expect(screen.getByRole('dialog', { name: 'Notifications' })).toBeInTheDocument()

            // Click outside
            fireEvent.pointerDown(document.body)

            expect(screen.queryByRole('dialog', { name: 'Notifications' })).not.toBeInTheDocument()
        })
    })

    describe('Error state', () => {
        it('renders error message and retry button if fetching fails', async () => {
            notificationsApi.list.mockRejectedValue(new Error('Network connectivity issue'))

            renderPopover()

            const trigger = await screen.findByRole('button', { name: 'Notifications' })
            fireEvent.click(trigger)

            expect(await screen.findByText('Network connectivity issue')).toBeInTheDocument()

            notificationsApi.list.mockResolvedValueOnce({
                notifications: sampleNotifications(),
                unread_count: 1,
                total_count: 2,
            })

            const retryButton = screen.getByRole('button', { name: 'Try again' })
            fireEvent.click(retryButton)

            await waitFor(() => {
                expect(screen.getByText('GoodWe Inverter Connected')).toBeInTheDocument()
            })
        })
    })
})
