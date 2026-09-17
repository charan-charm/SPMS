import { useTheme } from '../../hooks/useTheme'
import { cn } from '../../lib/utils'
import logoCompact from '../../../public/images/branding/logos/logo-compact.png'
import logoDark from '../../../public/images/branding/logos/logo-dark.png'
import logoLight from '../../../public/images/branding/logos/logo-light.png'

/**
 * SPMS brand components (docs/04 §2 — logo identity unchanged in name
 * only; imagery is the owner-approved artwork, F3 final branding).
 *
 * All logo rendering uses the owner-approved image assets imported
 * directly into the module bundle so singlefile static builds include
 * them without protocol or path resolution issues.
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

export function SpmsMark({ className, size = 28 }) {
    return (
        <img
            src={logoCompact}
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
        const src = resolvedTheme === 'dark' ? logoDark : logoLight

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
