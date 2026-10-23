import { useState, useRef, useEffect, useCallback, useMemo } from 'react'
import { Calendar, ChevronDown, CheckCircle2, Check, Activity } from 'lucide-react'

function formatDisplayDate(dateStr) {
    if (!dateStr) return ''
    try {
        const [y, m, d] = dateStr.split('-').map(Number)
        const date = new Date(y, m - 1, d)
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    } catch {
        return dateStr
    }
}

/**
 * SolarDateSelector
 *
 * Custom SPMS-styled interactive date selector replacing native browser dropdowns:
 * - Matches the Energy page ForecastDateSelector aesthetic and UX.
 * - Viewport containment: right-aligned, responsive max-width to prevent overflow.
 * - Displays date, generation total (kWh), and status (Live / Complete).
 * - Keyboard accessible (Escape, Enter, Tab).
 * - Closes automatically on selection or outside click.
 */
export function SolarDateSelector({
    availableDays = [],
    selectedDate = '',
    onSelectDate = null,
    className = '',
}) {
    const [isOpen, setIsOpen] = useState(false)
    const containerRef = useRef(null)

    // Normalize available days into uniform objects
    const normalizedDays = useMemo(() => {
        if (!Array.isArray(availableDays)) return []
        return availableDays.map((day) => {
            if (typeof day === 'string') {
                const dt = new Date(day)
                const isToday = !isNaN(dt.getTime()) && dt.toDateString() === new Date().toDateString()
                return {
                    date: day,
                    date_label: formatDisplayDate(day),
                    is_today: isToday,
                    generation_kwh: null,
                    status_label: isToday ? 'Today / Live' : 'Complete',
                }
            }
            return {
                ...day,
                date_label: day.date_label || formatDisplayDate(day.date),
                status_label: day.status_label || (day.is_today ? 'Today / Live' : 'Complete'),
            }
        })
    }, [availableDays])

    // Find currently selected day object
    const selectedDay = useMemo(() => {
        if (!selectedDate && normalizedDays.length > 0) return normalizedDays[0]
        return normalizedDays.find((d) => d.date === selectedDate) || null
    }, [normalizedDays, selectedDate])

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

    const handleSelect = (dateStr) => {
        if (onSelectDate) {
            onSelectDate(dateStr)
        }
        setIsOpen(false)
    }

    const displayLabel = selectedDay
        ? `${selectedDay.date_label} · ${selectedDay.status_label}`
        : (selectedDate ? formatDisplayDate(selectedDate) : 'Select Date…')

    return (
        <div className={`relative inline-block text-left ${className}`} ref={containerRef} onKeyDown={handleKeyDown}>
            <button
                type="button"
                id="solar-date-selector-btn"
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                aria-label="Select Date"
                onClick={() => setIsOpen((prev) => !prev)}
                className={`inline-flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-all shadow-xs focus:outline-none focus:ring-1 focus:ring-primary ${
                    isOpen
                        ? 'border-primary/50 bg-primary/10 text-primary ring-1 ring-primary/30'
                        : 'border-border bg-surface text-heading hover:bg-muted/15'
                }`}
            >
                <Calendar className="size-3.5 text-slate-500 dark:text-slate-400 shrink-0" />
                <span className="truncate max-w-[170px] sm:max-w-[210px] font-sans">
                    {displayLabel}
                </span>
                <ChevronDown
                    className={`size-3.5 text-slate-500 dark:text-slate-400 transition-transform duration-200 shrink-0 ${
                        isOpen ? 'rotate-180 text-primary' : ''
                    }`}
                />
            </button>

            {isOpen && (
                <div
                    role="listbox"
                    aria-label="Available Recorded Dates"
                    className="absolute right-0 left-auto sm:right-0 sm:left-auto z-50 mt-1.5 w-80 max-w-[calc(100vw-2rem)] max-h-80 overflow-y-auto rounded-xl border border-border bg-surface/98 p-1.5 shadow-2xl backdrop-blur-md focus:outline-none animate-in fade-in-50 zoom-in-95 duration-100"
                >
                    <div className="flex items-center justify-between px-2.5 py-1.5 border-b border-border/50 text-[10px] font-semibold text-muted tracking-wider uppercase">
                        <span>Select Recorded Date</span>
                        <span>{normalizedDays.length} days recorded</span>
                    </div>

                    <div className="py-1 space-y-0.5">
                        {normalizedDays.map((day) => {
                            const isSelected = selectedDay?.date === day.date
                            const genKwh = day.generation_kwh !== undefined && day.generation_kwh !== null
                                ? parseFloat(day.generation_kwh)
                                : null

                            return (
                                <button
                                    key={day.date}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => handleSelect(day.date)}
                                    className={`w-full flex items-center justify-between px-2.5 py-2 rounded-lg text-xs transition-colors text-left ${
                                        isSelected
                                            ? 'bg-primary/15 text-primary font-semibold border border-primary/30'
                                            : 'text-heading hover:bg-muted/10'
                                    }`}
                                >
                                    <div className="flex items-center gap-2 min-w-0">
                                        {isSelected ? (
                                            <Check className="size-3.5 text-primary shrink-0" />
                                        ) : day.is_today ? (
                                            <Activity className="size-3.5 text-sky-500 shrink-0" />
                                        ) : (
                                            <CheckCircle2 className="size-3.5 text-emerald-500/80 shrink-0" />
                                        )}
                                        <span className="font-sans font-medium truncate">
                                            {day.date_label || day.date}
                                        </span>
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0 ml-2 font-mono text-[11px]">
                                        {genKwh !== null && (
                                            <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                                {genKwh.toFixed(2)} kWh
                                            </span>
                                        )}
                                        <span
                                            className={`text-[10px] font-sans px-1.5 py-0.5 rounded border ${
                                                day.is_today
                                                    ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/30'
                                                    : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30'
                                            }`}
                                        >
                                            {day.status_label || (day.is_today ? 'Live' : 'Complete')}
                                        </span>
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
