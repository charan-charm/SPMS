import { useTheme } from '../../hooks/useTheme'
import { cn } from '../../lib/utils'

/**
 * SPMS brand components (docs/04 §2 — logo identity unchanged in name
 * only; imagery is the owner-approved artwork, F3 final branding).
 *
 * All logo rendering uses the owner-approved image assets copied into
 * the frontend public asset strategy — no inline SVG, no CSS filters,
 * no recoloring, no cropping. Image paths stay web-relative
 * (`/images/...`); no filesystem paths in browser-facing code.
 *
 * Theme awareness (single theme foundation — useTheme):
 * - variant="full" → logo-light.png in the light theme,
 *   logo-dark.png in the dark theme; `resolvedTheme` re-renders the
 *   image immediately when the theme switches. Used by the app header,
 *   mobile drawer, public and auth layouts.
 * - SpmsMark → logo-compact.png, the approved emblem-only compact
 *   asset. It is theme-neutral; no separate compact dark asset exists,
 *   so contexts that require theme-specific branding use variant="full"
 *   instead. No dark compact asset was invented or generated.
 *
 * Both components preserve the previous public API (variant, className,
 * height/size props) so existing consumers continue to work unchanged.
 */

const ASSET_BASE = '/images/branding/logos'

export function SpmsMark({ className, size = 28 }) {
    return (
        <img
            src={`${ASSET_BASE}/logo-compact.png`}
            alt="SPMS — Solar Power Management System"
            height={size}
            width={size}
            className={cn('h-auto shrink-0 object-contain', className)}
            style={{ height: size }}
        />
    )
}

export function BrandMark({ variant = 'mark', className, height = 32 }) {
    const { resolvedTheme } = useTheme()

    if (variant === 'full') {
        const src =
            resolvedTheme === 'dark'
                ? `${ASSET_BASE}/logo-dark.png`
                : `${ASSET_BASE}/logo-light.png`

        return (
            <img
                src={src}
                alt="SPMS — Solar Power Management System"
                height={height}
                width={Math.round(height * 3)}
                className={cn('w-auto object-contain', className)}
                style={{ height }}
            />
        )
    }

    return <SpmsMark className={className} size={height} />
}
