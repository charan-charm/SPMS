import { useState, useRef, useEffect, useCallback, useMemo } from 'react'
import { Calendar, ChevronDown, Check } from 'lucide-react'
import { cn } from '../../lib/utils'
import { HORIZONS } from './forecastHorizonConstants'

/**
 * ForecastHorizonSelector
 *
 * Custom SPMS-styled interactive dropdown selector for forecast horizons:
 * - Replaces native browser OS dropdown menus with an on-theme glassmorphic popover.
 * - Matches SPMS visual hierarchy (emerald accents, dark/light surface tokens, badges).
 * - Displays horizon name, temporal badge (24h, 7d, 30d), and concise description.
 * - Keyboard accessible (Escape to close, Tab, Enter).
 * - Closes on option selection or click-outside.
 * - Maintains an accessible screen-reader/automated test select for 100% compatibility.
 */
export function ForecastHorizonSelector({
    value = 'DAY_AHEAD',
    onChange = null,
    disabled = false,
    className = '',
    id = 'forecast-horizon-select',
    dataTestId = 'forecast-horizon-select',
}) {
    const [isOpen, setIsOpen] = useState(false)
    const containerRef = useRef(null)

    const currentHorizon = useMemo(() => {
        return HORIZONS.find((h) => h.id === value) || HORIZONS[0]
    }, [value])

    // Close on click outside
    useEffect(() => {
        function handleClickOutside(event) {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setIsOpen(false)
            }
        }
        if (isOpen) {
            document.addEventListener('mousedown', handleClickOutside)
        }
        return () => {
            document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [isOpen])

    // Keyboard handlers
    const handleKeyDown = useCallback((e) => {
        if (e.key === 'Escape') {
            setIsOpen(false)
        }
    }, [])

    const handleSelect = (horizonId) => {
        if (onChange && horizonId !== value) {
            onChange(horizonId)
        }
        setIsOpen(false)
    }

    return (
        <div
            className={cn('relative inline-block text-left', className)}
            ref={containerRef}
            onKeyDown={handleKeyDown}
        >
            {/* Accessible native select for screen readers and automated test tools */}
            <select
                id={id}
                data-testid={dataTestId}
                aria-label="Forecast Horizon"
                value={value}
                onChange={(e) => handleSelect(e.target.value)}
                disabled={disabled}
                className="sr-only"
                tabIndex={-1}
            >
                {HORIZONS.map((h) => (
                    <option key={h.id} value={h.id}>
                        {h.shortLabel}
                    </option>
                ))}
            </select>

            {/* Custom SPMS interactive dropdown trigger */}
            <button
                type="button"
                id="forecast-horizon-trigger"
                data-testid="forecast-horizon-trigger"
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                aria-controls="forecast-horizon-popover"
                aria-label={`Select horizon: ${currentHorizon.label}`}
                disabled={disabled}
                onClick={() => setIsOpen((prev) => !prev)}
                className={cn(
                    'inline-flex items-center gap-2 rounded-xl border border-border/80 bg-surface/90 px-3 py-1.5',
                    'text-xs font-semibold text-text-primary shadow-xs transition-all cursor-pointer select-none',
                    'hover:border-emerald-500/50 hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-emerald-500/30',
                    disabled && 'opacity-50 cursor-not-allowed pointer-events-none',
                    isOpen && 'border-emerald-500 ring-2 ring-emerald-500/20 bg-surface text-emerald-400'
                )}
            >
                <Calendar className="h-3.5 w-3.5 text-emerald-400 shrink-0" aria-hidden="true" />
                <span className="font-sans font-semibold tracking-tight">
                    {currentHorizon.label}
                </span>
                <ChevronDown
                    className={cn(
                        'h-3.5 w-3.5 text-text-muted transition-transform duration-200 shrink-0',
                        isOpen && 'rotate-180 text-emerald-400'
                    )}
                    aria-hidden="true"
                />
            </button>

            {/* Custom SPMS floating popover menu */}
            {isOpen && (
                <div
                    id="forecast-horizon-popover"
                    role="listbox"
                    aria-label="Forecast Horizons"
                    className="absolute right-0 top-full z-50 mt-1.5 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-border/90 bg-surface/98 backdrop-blur-md p-1.5 shadow-xl ring-1 ring-black/10 focus:outline-none animate-in fade-in-0 zoom-in-95 duration-100"
                >
                    <div className="flex items-center justify-between px-2.5 py-1.5 border-b border-border/50 text-[10px] font-semibold text-text-muted tracking-wider uppercase select-none">
                        <span>Forecast Horizon</span>
                        <span className="font-mono text-[10px] text-emerald-400/90 font-medium">3 options</span>
                    </div>

                    <div className="py-1 space-y-1">
                        {HORIZONS.map((h) => {
                            const isSelected = h.id === value
                            return (
                                <button
                                    key={h.id}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    data-testid={`forecast-horizon-option-${h.id}`}
                                    onClick={() => handleSelect(h.id)}
                                    className={cn(
                                        'w-full flex items-center justify-between px-2.5 py-2 rounded-lg text-xs transition-all text-left cursor-pointer group',
                                        isSelected
                                            ? 'bg-emerald-500/15 text-emerald-300 font-semibold border border-emerald-500/30 shadow-xs'
                                            : 'text-text-primary hover:bg-surface-subtle hover:text-text-primary'
                                    )}
                                >
                                    <div className="flex items-center gap-2.5 min-w-0">
                                        {isSelected ? (
                                            <Check className="h-3.5 w-3.5 text-emerald-400 shrink-0" aria-hidden="true" />
                                        ) : (
                                            <span className="h-3.5 w-3.5 shrink-0 flex items-center justify-center" aria-hidden="true">
                                                <span className="h-1.5 w-1.5 rounded-full bg-border-strong group-hover:bg-text-muted transition-colors" />
                                            </span>
                                        )}
                                        <div className="min-w-0">
                                            <div
                                                className={cn(
                                                    'font-sans text-xs',
                                                    isSelected ? 'font-semibold text-emerald-300' : 'font-medium text-text-primary'
                                                )}
                                            >
                                                {h.label}
                                            </div>
                                            <div className="text-[11px] text-text-muted font-normal mt-0.5 truncate">
                                                {h.description}
                                            </div>
                                        </div>
                                    </div>

                                    <span
                                        className={cn(
                                            'text-[10px] font-mono font-medium px-1.5 py-0.5 rounded border shrink-0 ml-2 transition-colors',
                                            isSelected
                                                ? 'bg-emerald-500/25 text-emerald-200 border-emerald-500/40'
                                                : 'bg-surface text-text-muted border-border/80 group-hover:border-border'
                                        )}
                                    >
                                        {h.badge}
                                    </span>
                                </button>
                            )
                        })}
                    </div>
                </div>
            )}
        </div>
    )
}
