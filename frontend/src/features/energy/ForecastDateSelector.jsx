import { useState, useRef, useEffect, useCallback, useMemo } from 'react'
import { Calendar, ChevronDown, CheckCircle2, Check } from 'lucide-react'

function formatDisplayDate(dateStr) {
    if (!dateStr || dateStr === 'today') return 'Today (Live)'
    try {
        const [y, m, d] = dateStr.split('-').map(Number)
        const date = new Date(y, m - 1, d)
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    } catch {
        return dateStr
    }
}

/**
 * ForecastDateSelector (SPMS Energy — Section 9 & 11).
 *
 * Custom SPMS-styled interactive date selector replacing native browser dropdowns:
 * - Viewport containment: right-aligned, responsive max-width to prevent overflow.
 * - Keyboard accessible (Escape, Enter, Tab).
 * - Displays date, validation status, and canonical accuracy badge.
 * - Selecting date closes dropdown and smoothly scrolls to graph.
 */
export function ForecastDateSelector({
    availableDates = [],
    selectedDate = 'today',
    onSelectDate = null,
    performanceHistory = [],
}) {
    const [isOpen, setIsOpen] = useState(false)
    const containerRef = useRef(null)

    // Build lookup for quick accuracy access
    const historyMap = useMemo(() => {
        const map = new Map()
        if (Array.isArray(performanceHistory)) {
            for (const item of performanceHistory) {
                if (item?.date) {
                    map.set(item.date, item)
                }
            }
        }
        return map
    }, [performanceHistory])

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

    const handleSelect = (d) => {
        if (onSelectDate) {
            onSelectDate(d)
        }
        setIsOpen(false)
    }

    const isHistoricalSelected = selectedDate && selectedDate !== 'today' && selectedDate !== 'yesterday'
    const displayLabel = isHistoricalSelected
        ? formatDisplayDate(selectedDate)
        : `Select Day (${availableDates.length} days)…`

    return (
        <div className="relative inline-block text-left" ref={containerRef} onKeyDown={handleKeyDown}>
            <button
                type="button"
                id="forecast-date-selector-btn"
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                aria-label="Select Validation Day"
                onClick={() => setIsOpen((prev) => !prev)}
                className={`inline-flex items-center gap-2 rounded-lg border px-2.5 py-1 text-xs font-medium transition-all shadow-sm focus:outline-none focus:ring-1 focus:ring-primary ${
                    isHistoricalSelected
                        ? 'border-primary/50 bg-primary/10 text-primary shadow-xs'
                        : 'border-border bg-surface text-heading hover:bg-muted/15'
                }`}
            >
                <Calendar className="size-3.5 text-muted shrink-0" />
                <span className="truncate max-w-[130px] sm:max-w-[160px] font-sans">
                    {displayLabel}
                </span>
                <ChevronDown
                    className={`size-3.5 text-muted transition-transform duration-200 shrink-0 ${
                        isOpen ? 'rotate-180 text-primary' : ''
                    }`}
                />
            </button>

            {isOpen && (
                <div
                    role="listbox"
                    aria-label="Available Validation Days"
                    className="absolute right-0 left-auto sm:right-0 sm:left-auto z-50 mt-1.5 w-72 max-w-[calc(100vw-2rem)] max-h-72 overflow-y-auto rounded-xl border border-border bg-surface p-1.5 shadow-xl backdrop-blur-md focus:outline-none animate-in fade-in-50 zoom-in-95 duration-100"
                >
                    <div className="flex items-center justify-between px-2.5 py-1.5 border-b border-border/50 text-[10px] font-semibold text-muted tracking-wider uppercase">
                        <span>Select Validation Day</span>
                        <span>{availableDates.length} completed</span>
                    </div>

                    <div className="py-1 space-y-0.5">
                        {availableDates.map((dateStr) => {
                            const isSelected = selectedDate === dateStr
                            const histItem = historyMap.get(dateStr)
                            const acc = histItem?.accuracy_pct !== undefined && histItem?.accuracy_pct !== null
                                ? `${parseFloat(histItem.accuracy_pct).toFixed(1)}%`
                                : null

                            return (
                                <button
                                    key={dateStr}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => handleSelect(dateStr)}
                                    className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded-lg text-xs transition-colors text-left ${
                                        isSelected
                                            ? 'bg-primary/15 text-primary font-semibold border border-primary/30'
                                            : 'text-heading hover:bg-muted/10'
                                    }`}
                                >
                                    <div className="flex items-center gap-2">
                                        {isSelected ? (
                                            <Check className="size-3.5 text-primary shrink-0" />
                                        ) : (
                                            <CheckCircle2 className="size-3 text-emerald-500/80 shrink-0" />
                                        )}
                                        <span className="font-sans font-medium">{formatDisplayDate(dateStr)}</span>
                                    </div>

                                    <div className="flex items-center gap-1.5 text-[11px] font-mono">
                                        {acc && (
                                            <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                                {acc}
                                            </span>
                                        )}
                                        <span className="text-[10px] text-muted font-sans">✓ Validated</span>
                                    </div>
                                </button>
                            )
                        })}
                    </div>
                </div>
            )}
        </div>
    )
}
