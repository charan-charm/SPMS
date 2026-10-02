/**
 * SolarAIAvatar — canonical 3D SolarAI character.
 *
 * Renders the 3D SolarAI solar companion floating directly in the SPMS interface.
 * Features:
 *   - Transparent background: Character floats naturally in both Light and Dark themes
 *   - Subtle, realistic micro-motion (idle float, thinking tilt, greeting, response settle)
 *   - Restrained solar rim & contact depth shadow (no loud neon halos or circular discs)
 *   - GPU-accelerated: Uses CSS transform only
 *   - Accessible: Fully respects prefers-reduced-motion
 *
 * Sizes:
 *   xs (20px) — compact inline icon
 *   sm (24px) — header button, FAB, message avatar, contextual action buttons
 *   md (36px) — drawer header, compact cards
 *   lg (56px) — drawer welcome card (primary character presentation)
 *   xl (72px) — high-prominence showcases
 *
 * States:
 *   idle       — subtle subconscious breathing float (~4.2s)
 *   thinking   — calm curious tilt & slight float (~2.6s)
 *   greeting   — gentle welcoming upward float & soft settle (~0.95s one-shot)
 *   responding — small confirmation settle (~0.6s one-shot)
 *   error      — gentle concerned tilt
 */
import './solarai-avatar.css'

const SIZE_PX = {
    xs: 20,
    sm: 24,
    md: 36,
    lg: 56,
    xl: 72,
}

const STATE_CLASS = {
    idle:       'solarai-av-idle',
    thinking:   'solarai-av-thinking',
    greeting:   'solarai-av-greeting',
    responding: 'solarai-av-responding',
    error:      'solarai-av-error',
    // Drawer welcome card states: one-shot entrance then fully static
    enter:      'solarai-av-enter',
    settled:    'solarai-av-settled',
}

export function SolarAIAvatar({
    size = 'sm',
    state = 'idle',
    className = '',
}) {
    const dim = SIZE_PX[size] ?? (typeof size === 'number' ? size : 24)
    const stateClass = STATE_CLASS[state] ?? STATE_CLASS.idle

    return (
        <div
            className={`solarai-av-container ${className}`}
            style={{ width: dim, height: dim, minWidth: dim }}
            aria-hidden="true"
        >
            <div
                className={`solarai-av ${stateClass}`}
                style={{ width: dim, height: dim }}
            >
                <img
                    src="/images/branding/solarai-avatar.png"
                    width={dim}
                    height={dim}
                    alt=""
                    className="block w-full h-full object-contain select-none pointer-events-none"
                    draggable="false"
                />
            </div>
        </div>
    )
}
