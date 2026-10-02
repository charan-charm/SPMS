import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { ThemeProvider } from '../../app/theme/ThemeProvider'
import { PublicLayout } from '../../layouts/PublicLayout'
import { LandingPage } from '../../pages/public/LandingPage'

/**
 * O1 landing acceptance (original composition, corrected hero) — the
 * page renders, the approved sections are present in order, the
 * header/footer chrome carries real navigation and auth actions,
 * illustrative content is labeled, and the honesty boundaries
 * ("no guarantees / no appliance control") stay visible. Structural
 * only: no brittle assertions on styling internals.
 */

function renderLanding(route = '/') {
    return render(
        <ThemeProvider>
            <MemoryRouter initialEntries={[route]}>
                <Routes>
                    <Route element={<PublicLayout />}>
                        <Route path="/" element={<LandingPage />} />
                        <Route path="/settings" element={<div>placeholder public page</div>} />
                    </Route>
                </Routes>
            </MemoryRouter>
        </ThemeProvider>,
    )
}

describe('SPMS landing page (O1)', () => {
    it('renders the hero with the product headline and a single h1', () => {
        renderLanding()

        const h1 = screen.getByRole('heading', { level: 1 })
        expect(h1).toHaveTextContent(/make more of the solar energy you generate/i)
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
        expect(screen.getAllByText(/solar power management system/i).length).toBeGreaterThanOrEqual(2)
    })

    it('presents the approved sections in order, with Energy Outlook removed', () => {
        const { container } = renderLanding()

        const sections = Array.from(container.querySelectorAll('main section[id]')).map(
            (node) => node.id,
        )
        expect(sections).toEqual([
            'capabilities',
            'solar-usage',
            'recommendations',
            'how-it-works',
            'built-for-households',
        ])

        for (const id of sections) {
            const section = container.querySelector(`section#${id}`)
            const labelId = section.getAttribute('aria-labelledby')
            expect(labelId).toBeTruthy()
            const heading = document.getElementById(labelId)
            expect(heading?.tagName).toBe('H2')
        }

        expect(container.querySelector('section#outlook')).toBeNull()
        const text = container.textContent.toLowerCase()
        expect(text).not.toContain('tomorrow')
        expect(text).not.toContain('in two days')
        expect(text).not.toContain('in three days')
    })

    it('keeps the hero text-first with no product card and no upload affordance', () => {
        const { container } = renderLanding()

        const hero = container.querySelector('section[aria-labelledby="hero-heading"]')
        expect(hero).toBeTruthy()
        expect(within(hero).getByRole('heading', { level: 1 })).toBeInTheDocument()
        expect(within(hero).getByRole('link', { name: /get started/i })).toBeInTheDocument()
        expect(within(hero).getByRole('link', { name: /sign in/i })).toBeInTheDocument()

        const text = container.textContent.toLowerCase()
        expect(text).not.toContain('how spms reads a day')
        expect(container.querySelector('main input[type="file"]')).toBeNull()
        expect(text).not.toContain('choose file')
    })

    it('carries primary CTAs (Get Started → /register, Sign In → /login)', () => {
        const { container } = renderLanding()

        const getStarted = container.querySelectorAll('a[href="/register"]')
        const signIn = container.querySelectorAll('a[href="/login"]')
        expect(getStarted.length).toBeGreaterThanOrEqual(2)
        expect(signIn.length).toBeGreaterThanOrEqual(1)

        expect(screen.getAllByRole('link', { name: /get started/i }).length).toBeGreaterThanOrEqual(2)
    })

    it('keeps the hero solar/leaf ambience purely decorative and non-interactive', () => {
        const { container } = renderLanding()

        const ambience = container.querySelector('[data-hero-ambience]')
        expect(ambience).toBeTruthy()
        expect(ambience).toHaveAttribute('aria-hidden', 'true')
        expect(ambience.querySelectorAll('a, button, input, [tabindex]')).toHaveLength(0)
    })

    it('links header/footer navigation to sections that exist on the page and centers desktop nav', () => {
        const { container } = renderLanding()

        const header = container.querySelector('header')
        const nav = within(header).getByRole('navigation', { name: /landing sections/i })
        expect(nav.className).toContain('absolute')
        expect(nav.className).toContain('inset-x-0')
        expect(nav.className).toContain('justify-center')

        for (const link of nav.querySelectorAll('a[href^="#"]')) {
            const targetId = link.getAttribute('href').slice(1)
            expect(container.querySelector(`section#${targetId}`)).toBeTruthy()
        }
    })

    it('hides landing anchor navigation on non-landing public routes', () => {
        const { container } = renderLanding('/settings')

        const header = container.querySelector('header')
        expect(
            within(header).queryByRole('navigation', { name: /landing sections/i }),
        ).not.toBeInTheDocument()
        expect(within(header).getByRole('link', { name: /get started/i })).toBeInTheDocument()
        expect(container).toHaveTextContent(/placeholder public page/i)
    })

    it('mobile menu button toggles the disclosure navigation', async () => {
        const user = userEvent.setup()
        renderLanding()

        const button = screen.getByRole('button', { name: /open menu/i })
        expect(button).toHaveAttribute('aria-expanded', 'false')
        await user.click(button)

        expect(button).toHaveAttribute('aria-expanded', 'true')
        expect(screen.getByRole('navigation', { name: /mobile/i })).toBeInTheDocument()
        expect(screen.getAllByText('Get Started').length).toBeGreaterThanOrEqual(1)
    })

    it('labels illustrative visuals honestly and keeps chart semantics accessible', () => {
        renderLanding()

        expect(screen.getAllByText(/illustrative/i).length).toBeGreaterThanOrEqual(3)

        const chart = screen.getByRole('img', { name: /illustrative example chart/i })
        expect(chart).toBeInTheDocument()
    })

    it('never promises savings or appliance control, and says so plainly', () => {
        const { container } = renderLanding()
        const text = container.textContent.toLowerCase()

        expect(text).toContain('never controls')
        expect(text).not.toMatch(/(?<!no )guaranteed savings/)
        expect(text).not.toMatch(/eliminate .*(bill|surplus)|perfect forecast|autonomously controls/)
    })

    it('keeps heading order coherent (h1 → h2 sections → h3 cards)', () => {
        const { container } = renderLanding()
        const levels = Array.from(container.querySelectorAll('main h1, main h2, main h3')).map(
            (node) => Number(node.tagName[1]),
        )
        expect(levels[0]).toBe(1)
        for (let i = 1; i < levels.length; i += 1) {
            expect(levels[i] - levels[i - 1]).toBeLessThanOrEqual(1)
        }
    })

    it('presents the single-card solar vs household graph without SolarDayArc', () => {
        const { container } = renderLanding()

        const solarSection = container.querySelector('section#solar-usage')
        expect(solarSection).toBeTruthy()
        // SolarDayArc is removed from the landing page entirely
        expect(solarSection.textContent).not.toMatch(/solar day from .*sunrise to .*sunset/i)

        // Interactive graph header is present
        expect(within(solarSection).getByRole('heading', { name: /solar vs household energy/i })).toBeInTheDocument()
        expect(within(solarSection).getByText(/solar production vs household consumption/i)).toBeInTheDocument()

        const sectionHeadings = Array.from(
            container.querySelectorAll('main section > h2, main section header h2'),
        ).map((node) => node.textContent.toLowerCase())
        expect(sectionHeadings.some((t) => /solar day arc/.test(t))).toBe(false)
    })

    it('keeps the solar-vs-home graph, its legend and the bill context, with clearly illustrative labels and no network calls', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
            throw new Error('landing page must not call the network')
        })
        const { container } = renderLanding()

        const solarSection = container.querySelector('section#solar-usage')
        expect(within(solarSection).getByRole('heading', { name: /solar vs household energy/i })).toBeInTheDocument()
        expect(solarSection).toHaveTextContent(/solar — example/i)
        expect(solarSection).toHaveTextContent(/consumption — example/i)
        expect(solarSection).not.toHaveTextContent(/solar — actual/i)
        expect(solarSection).not.toHaveTextContent(/consumption — actual/i)
        expect(solarSection).toHaveTextContent(/electricity-bill information/i)

        expect(fetchSpy).not.toHaveBeenCalled()
        fetchSpy.mockRestore()
    })

    it('shows the recommendation showcase and the built-for-households section with honest evidence structure', () => {
        const { container } = renderLanding()

        const recommendations = container.querySelector('section#recommendations')
        expect(recommendations).toBeTruthy()
        expect(recommendations.textContent).toMatch(/example recommendation/i)
        expect(recommendations).toHaveTextContent(/shifting flexible household energy use toward the higher-solar period/i)
        expect(recommendations).toHaveTextContent(/solar outlook/i)
        expect(recommendations).toHaveTextContent(/household demand/i)
        expect(recommendations).toHaveTextContent(/energy situation/i)
        expect(recommendations).toHaveTextContent(/advisory guidance areas/i)
        expect(recommendations).toHaveTextContent(/improving solar self-use when potential surplus is identified/i)
        expect(recommendations).toHaveTextContent(/never controls or schedules appliances/i)
        expect(recommendations).not.toHaveTextContent(/guaranteed savings/i)
        expect(recommendations).not.toHaveTextContent(/guaranteed surplus/i)
        expect(recommendations).not.toHaveTextContent(/guaranteed grid export/i)

        const households = container.querySelector('section#built-for-households')
        expect(households).toBeTruthy()
        expect(households.textContent).toMatch(/no appliance control/i)
    })

    it('renders the five process steps', () => {
        const { container } = renderLanding()

        const how = container.querySelector('section#how-it-works')
        expect(how).toBeTruthy()
        for (const label of ['Connect', 'Understand', 'Analyze', 'Forecast', 'Recommend']) {
            expect(within(how).getByRole('heading', { name: label })).toBeInTheDocument()
        }
    })
})