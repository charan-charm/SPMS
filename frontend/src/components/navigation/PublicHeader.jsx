import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router'
import { AnimatePresence, motion } from 'motion/react'
import { Menu, X } from 'lucide-react'
import { BrandMark } from './BrandMark'
import { ThemeSwitch } from './ThemeSwitch'
import { Button } from '../ui/button'
import { DURATION, EASE } from '../../lib/motion'
import { LANDING_NAV } from './public-nav'
import { cn } from '../../lib/utils'

/**
 * PublicHeader (O1) — the shared public-site header for PublicLayout.
 *
 * - Theme-aware BrandMark, section anchor navigation (rendered only on
 *   the landing route, where the anchors exist — never dead links),
 *   Sign In / Get Started toward the existing auth placeholder routes,
 *   and the single ThemeSwitch control.
 * - Sticky with a subtle surface transition: quiet at the top of the
 *   page, gaining a border and a firmer background once scrolled.
 * - Mobile navigation is a disclosure panel: one button with
 *   aria-expanded/aria-controls, Escape closes, navigation closes it
 *   (route changes reset the panel via render-time state comparison).
 * - Motion is limited to the panel open/close (DURATION.expand);
 *   MotionConfig reducedMotion="user" collapses it when requested.
 */

export function PublicHeader() {
    const { pathname } = useLocation()
    const isLanding = pathname === '/'
    const [scrolled, setScrolled] = useState(false)
    const [menuOpen, setMenuOpen] = useState(false)
    const [lastPath, setLastPath] = useState(pathname)

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8)
        onScroll()
        window.addEventListener('scroll', onScroll, { passive: true })
        return () => window.removeEventListener('scroll', onScroll)
    }, [])

    useEffect(() => {
        if (!menuOpen) return undefined
        const onKey = (event) => {
            if (event.key === 'Escape') setMenuOpen(false)
        }
        window.addEventListener('keydown', onKey)
        return () => window.removeEventListener('keydown', onKey)
    }, [menuOpen])

    if (lastPath !== pathname) {
        setLastPath(pathname)
        setMenuOpen(false)
    }

    return (
        <header
            className={cn(
                'fixed top-0 left-0 right-0 z-40 w-full border-b transition-[background-color,border-color,box-shadow] duration-200',
                scrolled
                    ? 'border-border bg-background/95 shadow-1 backdrop-blur-sm'
                    : 'border-transparent bg-background/60 backdrop-blur-sm',
            )}
        >
            {/* Header bar: logo on left, actions on right, with navigation truly centered relative to the viewport */}
            <div className="relative flex h-16 w-full items-center justify-between gap-4 px-5 sm:px-6 lg:px-8">
                <Link to="/" aria-label="SPMS home" className="relative z-10 inline-flex shrink-0">
                    <BrandMark variant="full" height={34} />
                </Link>

                {isLanding && (
                    <nav
                        aria-label="Landing sections"
                        className="pointer-events-none absolute inset-x-0 hidden items-center justify-center lg:flex"
                    >
                        <div className="pointer-events-auto flex items-center gap-1">
                            {LANDING_NAV.map((item) => (
                                <a
                                    key={item.id}
                                    href={`#${item.id}`}
                                    className="whitespace-nowrap rounded-md px-2.5 xl:px-3 py-1.5 text-sm font-medium text-body transition-colors hover:bg-surface-alt hover:text-heading"
                                >
                                    {item.label}
                                </a>
                            ))}
                        </div>
                    </nav>
                )}

                <div className="relative z-10 flex items-center gap-2">
                    <ThemeSwitch />
                    <div className="hidden items-center gap-2 sm:flex">
                        <Button variant="tertiary" asChild>
                            <Link to="/login">Sign In</Link>
                        </Button>
                        <Button asChild>
                            <Link to="/register">Get Started</Link>
                        </Button>
                    </div>
                    <button
                        type="button"
                        className="grid size-10 place-items-center rounded-md border border-border bg-surface text-body transition-colors hover:text-primary lg:hidden"
                        aria-label={menuOpen ? 'Close menu' : 'Open menu'}
                        aria-expanded={menuOpen}
                        aria-controls="public-mobile-nav"
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        {menuOpen ? (
                            <X className="size-5" aria-hidden="true" />
                        ) : (
                            <Menu className="size-5" aria-hidden="true" />
                        )}
                    </button>
                </div>
            </div>

            <AnimatePresence>
                {menuOpen && (
                    <motion.div
                        id="public-mobile-nav"
                        initial={{ opacity: 0, height: 0 }}
                        animate={{ opacity: 1, height: 'auto' }}
                        exit={{ opacity: 0, height: 0 }}
                        transition={{
                            duration: DURATION.expand / 1000,
                            ease: EASE.standard,
                        }}
                        className="overflow-hidden border-t border-border bg-background/95 backdrop-blur-sm lg:hidden"
                    >
                        <nav aria-label="Mobile" className="flex w-full flex-col gap-1 px-5 py-3 sm:px-6 lg:px-8">
                            {isLanding &&
                                LANDING_NAV.map((item) => (
                                    <a
                                        key={item.id}
                                        href={`#${item.id}`}
                                        onClick={() => setMenuOpen(false)}
                                        className="rounded-md px-3 py-2.5 text-sm font-medium text-body hover:bg-surface-alt hover:text-heading"
                                    >
                                        {item.label}
                                    </a>
                                ))}
                            <div className="my-1 border-t border-border" role="presentation" />
                            <Button variant="tertiary" className="justify-start" asChild>
                                <Link to="/login">Sign In</Link>
                            </Button>
                            <Button className="mt-1 justify-start" asChild>
                                <Link to="/register">Get Started</Link>
                            </Button>
                        </nav>
                    </motion.div>
                )}
            </AnimatePresence>
        </header>
    )
}
