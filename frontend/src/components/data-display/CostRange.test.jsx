import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CostRange } from './CostRange'

describe('CostRange (F5 — Locked Rule 8 & Formatting)', () => {
    it('suppresses zero-width cost range when low and high are zero (Locked Rule 8)', () => {
        const { container } = render(
            <CostRange low={0} high={0} currency="INR" halfWidthPercent={25} />
        )
        expect(container.firstChild).toBeNull()
    })

    it('suppresses range when bounds are null or undefined', () => {
        const { container: c1 } = render(<CostRange low={null} high={null} />)
        expect(c1.firstChild).toBeNull()

        const { container: c2 } = render(<CostRange />)
        expect(c2.firstChild).toBeNull()
    })

    it('renders positive range bounds with en-IN currency formatting', () => {
        render(
            <CostRange
                low={20.25}
                high={33.75}
                currency="INR"
                halfWidthPercent={25}
                triggers={['Fallback pricing evidence (USER_PROVIDED_RATE)', 'Worst input confidence is LOW']}
            />
        )

        expect(screen.getByText('Estimated range')).toBeInTheDocument()
        expect(screen.getByText('₹20.25 – ₹33.75')).toBeInTheDocument()
        expect(screen.getByText('Disclosed uncertainty band: ±25% around the point estimate.')).toBeInTheDocument()
        expect(screen.getByText('Fallback pricing evidence (USER_PROVIDED_RATE)')).toBeInTheDocument()
        expect(screen.getByText('Worst input confidence is LOW')).toBeInTheDocument()
        expect(screen.getByText('A range is shown because the evidence is uncertain — it is not a guaranteed bill.')).toBeInTheDocument()
    })

    it('supports range object prop shape ({ low, high })', () => {
        render(
            <CostRange
                range={{ low: 15.0, high: 25.0, half_width_percent: 20 }}
                currency="INR"
            />
        )

        expect(screen.getByText('₹15.00 – ₹25.00')).toBeInTheDocument()
        expect(screen.getByText('Disclosed uncertainty band: ±20% around the point estimate.')).toBeInTheDocument()
    })
})
