import { useLocation, Link, useNavigate } from 'react-router'
import { CircleDashed, Menu, LogOut, Moon, AlertTriangle, Clock, WifiOff, Sparkles } from 'lucide-react'
import { BrandMark } from './BrandMark'
import { ThemeSwitch } from './ThemeSwitch'
import { findNavItem } from '../../app/navigation'
import { Button } from '../ui/button'
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '../ui/dropdown-menu'
import { useAuth } from '../../features/auth/AuthContext'
import { useSolar } from '../../features/solar/useSolar'
import { NotificationBellPopover } from '../../features/notifications'

/**
 * AppHeader (docs/04 §11) — answers "where am I, what is happening?"
 *
 * The header owns SPMS branding: a stable left-side brand area showing
 * the approved full logo, which is theme-aware — logo-light.png in the
 * light theme, logo-dark.png in the dark theme (no dark compact asset
 * exists, so the emblem-only compact asset is not used here). The
 * wordmark is part of the logo image; the header height, divider and
 * page title placement are unchanged.
 *
 * The connection-status slot derives from backend-authoritative solar
 * connection state, displaying distinct semantic states without fabrication.
 * The account dropdown connects to the authenticated session with
 * real user email and sign-out functionality.
 */
export function AppHeader({ onOpenNavigation }) {
    const location = useLocation()
    const navigate = useNavigate()
    const current = findNavItem(location.pathname)

    const { account, logout } = useAuth()
    const { connectionStatus } = useSolar()

    const handleLogout = async () => {
        if (logout) {
            await logout()
        }
        navigate('/login')
    }

    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'

    const renderSolarStatusBadge = () => {
        switch (connectionStatus) {
            case 'CONNECTED':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300 md:inline-flex"
                        title="Solar inverter connected and active"
                    >
                        <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true" />
                        Solar: Connected
                    </span>
                )
            case 'OFFLINE':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300 md:inline-flex"
                        title="Solar inverter offline (night standby)"
                    >
                        <Moon className="size-3 text-amber-500" aria-hidden="true" />
                        Solar: Offline
                    </span>
                )
            case 'DEGRADED':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300 md:inline-flex"
                        title="Solar telemetry degraded"
                    >
                        <AlertTriangle className="size-3 text-amber-500" aria-hidden="true" />
                        Solar: Degraded
                    </span>
                )
            case 'AUTHENTICATION_ERROR':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-xs font-medium text-rose-700 dark:text-rose-300 md:inline-flex"
                        title="Solar provider authentication error. Re-authentication required."
                    >
                        <AlertTriangle className="size-3 text-rose-500" aria-hidden="true" />
                        Solar: Auth Error
                    </span>
                )
            case 'API_ERROR':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-xs font-medium text-rose-700 dark:text-rose-300 md:inline-flex"
                        title="Solar provider API reported an error"
                    >
                        <AlertTriangle className="size-3 text-rose-500" aria-hidden="true" />
                        Solar: API Error
                    </span>
                )
            case 'TIMEOUT':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-xs font-medium text-rose-700 dark:text-rose-300 md:inline-flex"
                        title="Solar provider request timed out"
                    >
                        <Clock className="size-3 text-rose-500" aria-hidden="true" />
                        Solar: Timeout
                    </span>
                )
            case 'NETWORK_ERROR':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-xs font-medium text-rose-700 dark:text-rose-300 md:inline-flex"
                        title="Network connection to solar provider unreachable"
                    >
                        <WifiOff className="size-3 text-rose-500" aria-hidden="true" />
                        Solar: Network Error
                    </span>
                )
            case 'UNKNOWN':
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-muted md:inline-flex"
                        title="Solar connection status unknown"
                    >
                        <CircleDashed className="size-3.5" aria-hidden="true" />
                        Solar: Unknown
                    </span>
                )
            case 'NOT_CONFIGURED':
            default:
                return (
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-muted md:inline-flex"
                        title="No solar inverter connected yet"
                    >
                        <CircleDashed className="size-3.5" aria-hidden="true" />
                        Solar: Not connected
                    </span>
                )
        }
    }


    return (
        <header className="sticky top-0 z-40 border-b border-border bg-background/90 backdrop-blur-sm">
            <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
                {/* Mobile navigation trigger */}
                <Button
                    variant="icon"
                    className="lg:hidden"
                    onClick={onOpenNavigation}
                    aria-label="Open navigation menu"
                >
                    <Menu className="size-5" aria-hidden="true" />
                </Button>

                {/* Brand — stable left-side branding area; theme-aware
                    full logo (light/dark asset switches with the theme) */}
                <Link
                    to="/dashboard"
                    aria-label="SPMS home"
                    className="hidden items-center sm:inline-flex"
                >
                    <BrandMark variant="full" height={32} />
                </Link>

                <span
                    aria-hidden="true"
                    className="hidden h-6 w-px bg-border sm:block"
                />

                {/* Page / context information (route-derived, no fake data) */}
                <div className="flex min-w-0 items-center gap-2.5">
                    <p className="truncate font-display text-base font-semibold text-heading">
                        {current ? current.name : 'SPMS'}
                    </p>
                    {isDemo && (
                        <div className="flex items-center gap-2">
                            <span className="rounded-full border border-chart-demo/50 bg-chart-demo/10 px-2.5 py-0.5 font-body text-xs font-semibold text-chart-demo">
                                DEMO — Simulated Demonstration Data
                            </span>
                            <Link
                                to="/onboarding?preview=demo"
                                className="hidden sm:inline-flex items-center gap-1 rounded-full border border-chart-demo/40 bg-surface px-2.5 py-0.5 text-[11px] font-medium text-chart-demo hover:bg-chart-demo/15 transition-colors"
                                title="Inspect 5-step onboarding flow in DEMO sandbox"
                            >
                                <Sparkles className="size-3" aria-hidden="true" />
                                <span>Preview Onboarding</span>
                            </Link>
                        </div>
                    )}
                </div>

                <div className="ml-auto flex items-center gap-2">
                    {/* Solar connection status badge derived from backend authority */}
                    {renderSolarStatusBadge()}

                    {/* Notification popover entry with unread count badge & interactive popover */}
                    <NotificationBellPopover />

                    <ThemeSwitch />

                    {/* Account entry — authenticated identity + sign out */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label={account?.email ? `Account menu for ${account.email}` : 'Account menu'}
                                className="inline-flex size-10 cursor-pointer items-center justify-center rounded-md border border-border bg-surface text-body transition-colors duration-150 hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            >
                                <span
                                    aria-hidden="true"
                                    className="grid size-7 place-items-center rounded-full bg-primary-soft text-xs font-bold text-primary-active"
                                >
                                    {account?.email ? account.email.charAt(0).toUpperCase() : 'U'}
                                </span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <div className="px-3 py-2 text-xs">
                                <p className="truncate font-semibold text-heading">{account?.email || 'Signed in user'}</p>
                                <p className="capitalize text-muted">{account?.role ? `${account.role.toLowerCase()} account` : 'Authenticated'}</p>
                            </div>
                            {isDemo && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link
                                            to="/onboarding?preview=demo"
                                            className="cursor-pointer text-chart-demo focus:bg-chart-demo/10 focus:text-chart-demo flex items-center"
                                        >
                                            <Sparkles className="mr-2 size-4" aria-hidden="true" />
                                            Onboarding Preview — DEMO
                                        </Link>
                                    </DropdownMenuItem>
                                </>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={handleLogout}
                                className="cursor-pointer text-danger focus:bg-danger/10 focus:text-danger"
                            >
                                <LogOut className="mr-2 size-4" aria-hidden="true" />
                                Sign out
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>
    )
}
