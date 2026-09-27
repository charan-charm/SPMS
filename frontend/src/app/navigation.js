import { LayoutDashboard, Sun, Zap, TrendingUp, Lightbulb, Sparkles, BarChart3, FileText, ClipboardList, Bell, Settings } from 'lucide-react'

/**
 * Centralized application navigation configuration (docs/03 §5, docs/04 §10).
 *
 * Single source of truth for the authenticated application navigation.
 * Sidebar, mobile drawer, and header context all consume this config —
 * navigation is never duplicated manually across components.
 *
 * Paths match the approved route inventory (routes.js). Guards are
 * navigation UX only; backend authorization remains authoritative.
 */

export const NAV_GROUPS = [
    {
        label: 'Main',
        items: [
            { path: '/dashboard', name: 'Dashboard', icon: LayoutDashboard },
        ],
    },
    {
        label: 'Energy',
        items: [
            { path: '/solar', name: 'Solar', icon: Sun },
            { path: '/energy', name: 'Energy', icon: Zap },
            { path: '/forecasts', name: 'Forecasts', icon: TrendingUp },
        ],
    },
    {
        label: 'Intelligence',
        items: [
            { path: '/recommendations', name: 'Recommendations', icon: Sparkles },
            { path: '/insights', name: 'Insights', icon: Lightbulb },
            { path: '/analytics', name: 'Analytics', icon: BarChart3 },
        ],
    },
    {
        label: 'Output',
        items: [
            { path: '/bills', name: 'Bills', icon: FileText },
            { path: '/reports', name: 'Reports', icon: ClipboardList },
            { path: '/notifications', name: 'Notifications', icon: Bell },
        ],
    },
    {
        label: 'System',
        items: [
            { path: '/settings', name: 'Settings', icon: Settings },
        ],
    },
]

export const NAV_ITEMS = NAV_GROUPS.flatMap((group) => group.items)

export function findNavItem(pathname) {
    return NAV_ITEMS.find((item) => item.path === pathname) ?? null
}
