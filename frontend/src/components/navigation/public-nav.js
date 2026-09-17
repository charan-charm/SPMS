/**
 * Public site navigation model (O1) — the landing-page section anchors
 * shared by PublicHeader and PublicFooter. Kept outside the component
 * files so fast-refresh boundaries stay clean (one module, one job).
 *
 * Every entry here must point at a real section id. Energy Outlook has
 * been removed from the landing page, so it is not listed.
 */
export const LANDING_NAV = [
    { id: 'capabilities', label: 'Capabilities' },
    { id: 'solar-usage', label: 'Solar vs home' },
    { id: 'recommendations', label: 'Recommendations' },
    { id: 'how-it-works', label: 'How it works' },
]