/**
 * Route inventory (docs/03 §4, docs/11 §9) — shared by the router,
 * navigation config, and foundation placeholders.
 *
 * Layout assignment: public → PublicLayout, authentication → AuthLayout,
 * protected application → AppLayout (see app/router.jsx).
 *
 * Guards are navigation UX only; backend authorization remains
 * authoritative and arrives with the authentication milestones.
 */

export const publicRoutes = [
    { path: '/', name: 'Landing' },
]

export const authRoutes = [
    { path: '/login', name: 'Login' },
    { path: '/register', name: 'Register' },
    { path: '/verify', name: 'Email verification' },
    { path: '/recover', name: 'Account recovery' },
]

export const protectedRoutes = [
    { path: '/dashboard', name: 'Dashboard' },
    { path: '/solar', name: 'Solar' },
    { path: '/energy', name: 'Energy' },
    { path: '/energy/cost', name: 'Energy cost' },
    { path: '/forecasts', name: 'Forecasts' },
    { path: '/insights', name: 'Insights' },
    { path: '/analytics', name: 'Analytics' },
    { path: '/bills', name: 'Bills' },
    { path: '/reports', name: 'Reports' },
    { path: '/notifications', name: 'Notifications' },
    { path: '/settings', name: 'Settings' },
]
