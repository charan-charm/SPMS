import { AnimatePresence, motion } from 'motion/react'
import { Sun, Moon, Monitor } from 'lucide-react'
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '../ui/dropdown-menu'
import { useTheme } from '../../hooks/useTheme'
import { DURATION, EASE } from '../../lib/motion'
import { cn } from '../../lib/utils'

/**
 * ThemeToggle (F3 refinement) — compact animated Sun ↔ Moon interaction.
 *
 * - Crossfades between sun and moon (~250ms) via the existing Motion
 *   infrastructure and tokens; MotionConfig reducedMotion="user" at the
 *   app root disables the rotation/scale movement when reduced motion
 *   is preferred (opacity crossfade remains).
 * - Light / Dark / System options stay available through the accessible
 *   dropdown; the selection is marked by text, never color alone.
 * - Uses the single F1/F3 theme foundation — no second mechanism.
 */
export function ThemeToggle({ className }) {
    const { theme, resolvedTheme, setTheme } = useTheme()

    const options = [
        { value: 'light', label: 'Light', icon: Sun },
        { value: 'dark', label: 'Dark', icon: Moon },
        { value: 'system', label: 'System', icon: Monitor },
    ]

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Color theme: ${theme}. Change theme`}
                    title="Change theme"
                    className={cn(
                        'inline-flex size-10 cursor-pointer items-center justify-center rounded-md',
                        'border border-border bg-surface text-body transition-colors duration-150',
                        'hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                        className,
                    )}
                >
                    <span className="relative grid size-4 place-items-center">
                        <AnimatePresence initial={false}>
                            {resolvedTheme === 'dark' ? (
                                <motion.span
                                    key="moon"
                                    initial={{ opacity: 0, rotate: -60, scale: 0.5 }}
                                    animate={{ opacity: 1, rotate: 0, scale: 1 }}
                                    exit={{ opacity: 0, rotate: 60, scale: 0.5 }}
                                    transition={{ duration: DURATION.theme / 1000, ease: EASE.standard }}
                                    className="absolute inset-0 grid place-items-center"
                                >
                                    <Moon className="size-4" aria-hidden="true" />
                                </motion.span>
                            ) : (
                                <motion.span
                                    key="sun"
                                    initial={{ opacity: 0, rotate: 60, scale: 0.5 }}
                                    animate={{ opacity: 1, rotate: 0, scale: 1 }}
                                    exit={{ opacity: 0, rotate: -60, scale: 0.5 }}
                                    transition={{ duration: DURATION.theme / 1000, ease: EASE.standard }}
                                    className="absolute inset-0 grid place-items-center"
                                >
                                    <Sun className="size-4" aria-hidden="true" />
                                </motion.span>
                            )}
                        </AnimatePresence>
                    </span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {options.map((option) => {
                    const Icon = option.icon
                    const selected = theme === option.value
                    return (
                        <DropdownMenuItem
                            key={option.value}
                            onSelect={() => setTheme(option.value)}
                            className={selected ? 'font-semibold text-heading' : undefined}
                        >
                            <Icon className="mr-2 size-4 text-muted" aria-hidden="true" />
                            {option.label}
                            {selected && (
                                <span className="ml-auto text-xs font-semibold text-primary">Selected</span>
                            )}
                        </DropdownMenuItem>
                    )
                })}
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled className="text-muted">
                    System follows your device preference
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    )
}
