import { Sparkles, Sun } from 'lucide-react'
import { cn } from '../../lib/utils'

/**
 * Compact segmented switch for selecting the SPMS mode (Demo vs. Real).
 *
 * Requirements:
 * - Demo selected by default.
 * - Compact and premium design using existing SPMS design tokens.
 * - Smooth 150–200ms transitions.
 * - Keyboard accessible with arrow key navigation.
 * - Proper ARIA semantics (radiogroup / radio).
 * - Exact supporting text per active mode.
 *
 * @param {{
 *   value: 'DEMO' | 'REAL',
 *   onChange: (mode: 'DEMO' | 'REAL') => void,
 *   className?: string
 * }} props
 */
export function AuthModeToggle({ value = 'DEMO', onChange, className }) {
    const isDemo = value === 'DEMO'

    const handleKeyDown = (e) => {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault()
            onChange('DEMO')
        } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault()
            onChange('REAL')
        }
    }

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-center justify-between">
                <span id="spms-mode-label" className="text-xs font-semibold text-heading">
                    SPMS Mode
                </span>
            </div>

            {/* Glass segmented control track */}
            <div
                role="radiogroup"
                aria-labelledby="spms-mode-label"
                aria-describedby="spms-mode-desc"
                onKeyDown={handleKeyDown}
                className="relative flex items-center rounded-xl border border-border/70 bg-surface-hover/60 p-1 backdrop-blur-md shadow-[inset_0_1px_2px_rgba(0,0,0,0.04)]"
            >
                {/* Physical sliding pill background */}
                <div
                    aria-hidden="true"
                    className={cn(
                        'pointer-events-none absolute top-1 bottom-1 left-1 w-[calc(50%-4px)] rounded-lg',
                        'transition-transform duration-250 ease-out motion-reduce:transition-none',
                        isDemo
                            ? 'translate-x-0 bg-surface/95 border border-primary/30 shadow-sm shadow-primary/10'
                            : 'translate-x-full bg-surface/95 border border-warning/30 shadow-sm shadow-warning/10'
                    )}
                />

                {/* Demo option */}
                <button
                    type="button"
                    role="radio"
                    id="spms-mode-demo"
                    aria-checked={isDemo}
                    tabIndex={isDemo ? 0 : -1}
                    onClick={() => onChange('DEMO')}
                    className={cn(
                        'relative z-10 flex flex-1 items-center justify-center gap-1.5 rounded-lg py-1.5 px-3 text-xs font-medium',
                        'transition-colors duration-200 select-none cursor-pointer',
                        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus',
                        isDemo
                            ? 'text-heading font-semibold'
                            : 'text-muted hover:text-heading'
                    )}
                >
                    <Sparkles
                        className={cn(
                            'size-3.5 transition-colors duration-200',
                            isDemo ? 'text-primary drop-shadow-[0_0_8px_rgba(34,197,94,0.35)]' : 'text-muted'
                        )}
                        aria-hidden="true"
                    />
                    <span>Demo</span>
                </button>

                {/* Real option */}
                <button
                    type="button"
                    role="radio"
                    id="spms-mode-real"
                    aria-checked={!isDemo}
                    tabIndex={!isDemo ? 0 : -1}
                    onClick={() => onChange('REAL')}
                    className={cn(
                        'relative z-10 flex flex-1 items-center justify-center gap-1.5 rounded-lg py-1.5 px-3 text-xs font-medium',
                        'transition-colors duration-200 select-none cursor-pointer',
                        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus',
                        !isDemo
                            ? 'text-heading font-semibold'
                            : 'text-muted hover:text-heading'
                    )}
                >
                    <Sun
                        className={cn(
                            'size-3.5 transition-colors duration-200',
                            !isDemo ? 'text-warning drop-shadow-[0_0_8px_rgba(245,158,11,0.35)]' : 'text-muted'
                        )}
                        aria-hidden="true"
                    />
                    <span>Real</span>
                </button>
            </div>

            {/* Supporting description */}
            <p
                id="spms-mode-desc"
                className="min-h-[2.25rem] text-[11px] text-muted leading-relaxed transition-opacity duration-150 pt-0.5"
                aria-live="polite"
            >
                {isDemo
                    ? 'Explore SPMS using prepared demonstration data. No GoodWe connection required.'
                    : 'Use your actual household and connect your GoodWe solar system.'}
            </p>
        </div>
    )
}
