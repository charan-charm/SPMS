import { describe, expect, it, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ReportMonthSelector } from './ReportMonthSelector'

describe('ReportMonthSelector', () => {
    const months = [
        { month: '2026-09', label: 'September 2026', is_current_month: false },
        { month: '2026-08', label: 'August 2026', is_current_month: false },
        { month: '2026-07', label: 'July 2026', is_current_month: false },
    ]

    it('renders closed state with previous button, current selection, and next button', () => {
        render(
            <ReportMonthSelector
                selectedMonth="2026-09"
                availableMonths={months}
                onMonthChange={vi.fn()}
                canPrevious={true}
                canNext={false}
            />
        )

        expect(screen.getByRole('button', { name: /Select report month, currently September 2026/i })).toBeInTheDocument()
        expect(screen.getByLabelText('Previous Month')).toBeEnabled()
        expect(screen.getByLabelText('Next Month')).toBeDisabled()
    })

    it('opens listbox when trigger button is clicked and displays options with checkmark on selected', () => {
        render(
            <ReportMonthSelector
                selectedMonth="2026-09"
                availableMonths={months}
                onMonthChange={vi.fn()}
                canPrevious={true}
                canNext={true}
            />
        )

        const trigger = screen.getByRole('button', { name: /Select report month/i })
        expect(trigger).toHaveAttribute('aria-expanded', 'false')

        fireEvent.click(trigger)

        expect(trigger).toHaveAttribute('aria-expanded', 'true')
        expect(screen.getByRole('listbox')).toBeInTheDocument()
        const options = screen.getAllByRole('option')
        expect(options).toHaveLength(3)

        // Selected option has aria-selected="true"
        expect(options[0]).toHaveAttribute('aria-selected', 'true')
        expect(options[1]).toHaveAttribute('aria-selected', 'false')
    })

    it('selects option on click and calls onMonthChange then closes menu', () => {
        const handleMonthChange = vi.fn()
        render(
            <ReportMonthSelector
                selectedMonth="2026-09"
                availableMonths={months}
                onMonthChange={handleMonthChange}
                canPrevious={true}
                canNext={true}
            />
        )

        const trigger = screen.getByRole('button', { name: /Select report month/i })
        fireEvent.click(trigger)

        const augustOption = screen.getByRole('option', { name: /August 2026/i })
        fireEvent.click(augustOption)

        expect(handleMonthChange).toHaveBeenCalledWith('2026-08')
        expect(screen.queryByRole('listbox')).toBeNull()
    })

    it('supports keyboard navigation: ArrowDown, ArrowUp, Enter, and Escape', () => {
        const handleMonthChange = vi.fn()
        render(
            <ReportMonthSelector
                selectedMonth="2026-09"
                availableMonths={months}
                onMonthChange={handleMonthChange}
                canPrevious={true}
                canNext={true}
            />
        )

        const trigger = screen.getByRole('button', { name: /Select report month/i })

        // Open with ArrowDown
        fireEvent.keyDown(trigger, { key: 'ArrowDown' })
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        // Close with Escape
        fireEvent.keyDown(trigger, { key: 'Escape' })
        expect(screen.queryByRole('listbox')).toBeNull()

        // Open with Enter
        fireEvent.keyDown(trigger, { key: 'Enter' })
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        // Navigate down to August (index 1) and press Enter
        fireEvent.keyDown(trigger, { key: 'ArrowDown' })
        fireEvent.keyDown(trigger, { key: 'Enter' })

        expect(handleMonthChange).toHaveBeenCalledWith('2026-08')
        expect(screen.queryByRole('listbox')).toBeNull()
    })

    it('closes on click outside', () => {
        render(
            <div>
                <div data-testid="outside">Outside area</div>
                <ReportMonthSelector
                    selectedMonth="2026-09"
                    availableMonths={months}
                    onMonthChange={vi.fn()}
                />
            </div>
        )

        const trigger = screen.getByRole('button', { name: /Select report month/i })
        fireEvent.click(trigger)
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        fireEvent.pointerDown(screen.getByTestId('outside'))
        expect(screen.queryByRole('listbox')).toBeNull()
    })
})
