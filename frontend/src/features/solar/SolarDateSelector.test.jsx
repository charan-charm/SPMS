import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { SolarDateSelector } from './SolarDateSelector'

describe('SolarDateSelector — Custom SPMS Date Dropdown', () => {
    const mockDays = [
        {
            date: '2026-10-05',
            date_label: '05 Oct 2026',
            is_today: true,
            generation_kwh: 4.10,
            status_label: 'Today / Live',
            status: 'LIVE',
        },
        {
            date: '2026-10-04',
            date_label: '04 Oct 2026',
            is_today: false,
            generation_kwh: 11.12,
            status_label: 'Complete',
            status: 'COMPLETE',
        },
        {
            date: '2026-10-03',
            date_label: '03 Oct 2026',
            is_today: false,
            generation_kwh: 14.85,
            status_label: 'Complete',
            status: 'COMPLETE',
        },
    ]

    it('renders trigger button with currently selected date and status', () => {
        render(
            <SolarDateSelector
                availableDays={mockDays}
                selectedDate="2026-10-04"
            />
        )

        const btn = screen.getByRole('button', { name: /Select Date/i })
        expect(btn).toBeInTheDocument()
        expect(btn).toHaveTextContent(/04 Oct 2026 · Complete/i)
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('opens floating dropdown when trigger button is clicked and displays recorded days', () => {
        render(
            <SolarDateSelector
                availableDays={mockDays}
                selectedDate="2026-10-05"
            />
        )

        const btn = screen.getByRole('button', { name: /Select Date/i })
        fireEvent.click(btn)

        const listbox = screen.getByRole('listbox', { name: /Available Recorded Dates/i })
        expect(listbox).toBeInTheDocument()
        expect(screen.getByText('3 days recorded')).toBeInTheDocument()

        const options = screen.getAllByRole('option')
        expect(options).toHaveLength(3)
        expect(options[0]).toHaveTextContent(/05 Oct 2026/i)
        expect(options[0]).toHaveTextContent(/4.10 kWh/i)
        expect(options[1]).toHaveTextContent(/04 Oct 2026/i)
        expect(options[1]).toHaveTextContent(/11.12 kWh/i)
        expect(options[2]).toHaveTextContent(/03 Oct 2026/i)
        expect(options[2]).toHaveTextContent(/14.85 kWh/i)
    })

    it('highlights currently selected date with aria-selected=true and Check icon', () => {
        render(
            <SolarDateSelector
                availableDays={mockDays}
                selectedDate="2026-10-04"
            />
        )

        fireEvent.click(screen.getByRole('button', { name: /Select Date/i }))

        const options = screen.getAllByRole('option')
        expect(options[0]).toHaveAttribute('aria-selected', 'false')
        expect(options[1]).toHaveAttribute('aria-selected', 'true')
        expect(options[2]).toHaveAttribute('aria-selected', 'false')
    })

    it('invokes onSelectDate with date string and closes dropdown when an option is clicked', () => {
        const onSelectDate = vi.fn()
        render(
            <SolarDateSelector
                availableDays={mockDays}
                selectedDate="2026-10-05"
                onSelectDate={onSelectDate}
            />
        )

        fireEvent.click(screen.getByRole('button', { name: /Select Date/i }))

        const option = screen.getByRole('option', { name: /03 Oct 2026/i })
        fireEvent.click(option)

        expect(onSelectDate).toHaveBeenCalledWith('2026-10-03')
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('closes dropdown when clicking outside', () => {
        render(
            <div>
                <span data-testid="outside">Outside area</span>
                <SolarDateSelector
                    availableDays={mockDays}
                    selectedDate="2026-10-05"
                />
            </div>
        )

        fireEvent.click(screen.getByRole('button', { name: /Select Date/i }))
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        fireEvent.mouseDown(screen.getByTestId('outside'))
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('closes dropdown when pressing Escape key', () => {
        render(
            <SolarDateSelector
                availableDays={mockDays}
                selectedDate="2026-10-05"
            />
        )

        fireEvent.click(screen.getByRole('button', { name: /Select Date/i }))
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        fireEvent.keyDown(screen.getByRole('button', { name: /Select Date/i }), { key: 'Escape' })
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('supports string date arrays gracefully', () => {
        render(
            <SolarDateSelector
                availableDays={['2026-10-04', '2026-10-03']}
                selectedDate="2026-10-04"
            />
        )

        const btn = screen.getByRole('button', { name: /Select Date/i })
        expect(btn).toHaveTextContent(/04 Oct 2026/i)

        fireEvent.click(btn)
        expect(screen.getByRole('listbox')).toBeInTheDocument()
        expect(screen.getByText('2 days recorded')).toBeInTheDocument()
    })
})
