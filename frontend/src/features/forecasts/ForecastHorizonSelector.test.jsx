import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ForecastHorizonSelector } from './ForecastHorizonSelector'
import { HORIZONS } from './forecastHorizonConstants'

describe('ForecastHorizonSelector — Custom SPMS Horizon Dropdown', () => {
    it('1. renders trigger button with currently selected horizon label', () => {
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        expect(trigger).toBeInTheDocument()
        expect(trigger).toHaveTextContent('Day-Ahead · 24h')
        expect(trigger).toHaveAttribute('aria-expanded', 'false')
        expect(trigger).toHaveAttribute('aria-haspopup', 'listbox')
    })

    it('2. provides an accessible hidden select for screen readers and automation', () => {
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} />)

        const select = screen.getByLabelText('Forecast Horizon')
        expect(select).toBeInTheDocument()
        expect(select).toHaveValue('DAY_AHEAD')
        expect(select).toHaveClass('sr-only')
    })

    it('3. opens floating popover when trigger button is clicked and displays all options', () => {
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        fireEvent.click(trigger)

        expect(trigger).toHaveAttribute('aria-expanded', 'true')
        const popover = screen.getByRole('listbox', { name: 'Forecast Horizons' })
        expect(popover).toBeInTheDocument()

        // Verify all 3 options are rendered
        HORIZONS.forEach((h) => {
            const opt = screen.getByTestId(`forecast-horizon-option-${h.id}`)
            expect(opt).toBeInTheDocument()
            expect(opt).toHaveTextContent(h.label)
            expect(opt).toHaveTextContent(h.badge)
            expect(opt).toHaveTextContent(h.description)
        })

        // DAY_AHEAD is selected
        const dayAheadOpt = screen.getByTestId('forecast-horizon-option-DAY_AHEAD')
        expect(dayAheadOpt).toHaveAttribute('aria-selected', 'true')

        const sevenDayOpt = screen.getByTestId('forecast-horizon-option-SEVEN_DAY')
        expect(sevenDayOpt).toHaveAttribute('aria-selected', 'false')
    })

    it('4. invokes onChange and closes popover when an option is clicked', () => {
        const handleChange = vi.fn()
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={handleChange} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        fireEvent.click(trigger)

        const sevenDayOpt = screen.getByTestId('forecast-horizon-option-SEVEN_DAY')
        fireEvent.click(sevenDayOpt)

        expect(handleChange).toHaveBeenCalledWith('SEVEN_DAY')
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('5. does not invoke onChange if the already selected option is clicked', () => {
        const handleChange = vi.fn()
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={handleChange} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        fireEvent.click(trigger)

        const dayAheadOpt = screen.getByTestId('forecast-horizon-option-DAY_AHEAD')
        fireEvent.click(dayAheadOpt)

        expect(handleChange).not.toHaveBeenCalled()
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('6. closes popover when clicking outside', () => {
        render(
            <div>
                <span data-testid="outside-element">Outside Area</span>
                <ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} />
            </div>
        )

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        fireEvent.click(trigger)
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        fireEvent.mouseDown(screen.getByTestId('outside-element'))
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('7. closes popover when pressing Escape key', () => {
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        fireEvent.click(trigger)
        expect(screen.getByRole('listbox')).toBeInTheDocument()

        fireEvent.keyDown(trigger, { key: 'Escape' })
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('8. respects disabled state on both trigger button and native select', () => {
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={vi.fn()} disabled={true} />)

        const trigger = screen.getByTestId('forecast-horizon-trigger')
        expect(trigger).toBeDisabled()

        const select = screen.getByLabelText('Forecast Horizon')
        expect(select).toBeDisabled()
    })

    it('9. invokes onChange when native select is changed directly (accessibility & testing)', () => {
        const handleChange = vi.fn()
        render(<ForecastHorizonSelector value="DAY_AHEAD" onChange={handleChange} />)

        const select = screen.getByLabelText('Forecast Horizon')
        fireEvent.change(select, { target: { value: 'MONTHLY' } })

        expect(handleChange).toHaveBeenCalledWith('MONTHLY')
    })
})
