import { useState, useRef, useEffect, useId } from 'react'
import { ChevronLeft, ChevronRight, ChevronDown, Calendar, Check } from 'lucide-react'

/**
 * Custom accessible SPMS Month Selector.
 *
 * Closed state: [ ← ] [ September 2026 ▼ ] [ → ]
 * Open state: Completely opaque floating listbox with checkmark, hover states, keyboard navigation,
 * click-outside dismiss, and visible focus management.
 *
 * Visual Invariant: NO transparency, NO backdrop-blur. 100% solid background in both light and dark modes
 * to prevent visual bleed-through, liquid artifacts, or rendering glitches over background elements.
 */
export function ReportMonthSelector({
    selectedMonth,
    availableMonths = [],
    onMonthChange,
    disabled = false,
    canPrevious = false,
    canNext = false,
    onPrevious,
    onNext,
    className = '',
}) {
    const [isOpen, setIsOpen] = useState(false)
    const [focusedIndex, setFocusedIndex] = useState(-1)
    const containerRef = useRef(null)
    const triggerRef = useRef(null)
    const listboxRef = useRef(null)
    const listboxId = useId()

    // Find current selected month object
    const currentSelection = availableMonths.find((m) => m.month === selectedMonth) || {
        month: selectedMonth || '',
        label: selectedMonth || 'Select Month',
    }

    // Close on click outside
    useEffect(() => {
        if (!isOpen) return

        function handlePointerDown(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setIsOpen(false)
            }
        }

        document.addEventListener('pointerdown', handlePointerDown)
        return () => {
            document.removeEventListener('pointerdown', handlePointerDown)
        }
    }, [isOpen])

    const openDropdown = () => {
        if (disabled) return
        const selectedIdx = availableMonths.findIndex((m) => m.month === selectedMonth)
        setFocusedIndex(selectedIdx >= 0 ? selectedIdx : 0)
        setIsOpen(true)
    }

    const toggleDropdown = () => {
        if (disabled) return
        if (!isOpen) {
            openDropdown()
        } else {
            setIsOpen(false)
        }
    }

    const handleKeyDown = (e) => {
        if (disabled) return

        if (!isOpen) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                openDropdown()
            }
            return
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault()
                setFocusedIndex((prev) => (prev < availableMonths.length - 1 ? prev + 1 : 0))
                break
            case 'ArrowUp':
                e.preventDefault()
                setFocusedIndex((prev) => (prev > 0 ? prev - 1 : availableMonths.length - 1))
                break
            case 'Home':
                e.preventDefault()
                setFocusedIndex(0)
                break
            case 'End':
                e.preventDefault()
                setFocusedIndex(availableMonths.length - 1)
                break
            case 'Enter':
            case ' ':
                e.preventDefault()
                if (focusedIndex >= 0 && focusedIndex < availableMonths.length) {
                    const chosen = availableMonths[focusedIndex]
                    if (chosen && !chosen.disabled) {
                        onMonthChange(chosen.month)
                        setIsOpen(false)
                        triggerRef.current?.focus()
                    }
                }
                break
            case 'Escape':
                e.preventDefault()
                setIsOpen(false)
                triggerRef.current?.focus()
                break
            case 'Tab':
                setIsOpen(false)
                break
            default:
                break
        }
    }

    return (
        <div ref={containerRef} className={`relative inline-flex items-center ${className}`}>
            {/* Opaque container pill */}
            <div className="flex items-center rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-1 transition-colors">
                {/* Previous Month Button */}
                <button
                    type="button"
                    onClick={onPrevious}
                    disabled={!canPrevious || disabled}
                    title="Previous Month"
                    aria-label="Previous Month"
                    className="p-1.5 rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                >
                    <ChevronLeft className="size-4" />
                </button>

                {/* Main Month Trigger Button */}
                <button
                    ref={triggerRef}
                    type="button"
                    onClick={toggleDropdown}
                    onKeyDown={handleKeyDown}
                    disabled={disabled}
                    aria-haspopup="listbox"
                    aria-expanded={isOpen}
                    aria-controls={listboxId}
                    aria-label={`Select report month, currently ${currentSelection.label}`}
                    className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-900 dark:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:opacity-50"
                >
                    <Calendar className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
                    <span className="tracking-tight">{currentSelection.label}</span>
                    <ChevronDown
                        className={`size-3.5 text-slate-400 dark:text-slate-500 transition-transform duration-200 shrink-0 ${
                            isOpen ? 'rotate-180 text-slate-900 dark:text-slate-100' : ''
                        }`}
                    />
                </button>

                {/* Next Month Button */}
                <button
                    type="button"
                    onClick={onNext}
                    disabled={!canNext || disabled}
                    title="Next Month"
                    aria-label="Next Month"
                    className="p-1.5 rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                >
                    <ChevronRight className="size-4" />
                </button>
            </div>

            {/* Completely Opaque Floating Custom Listbox */}
            {isOpen && (
                <div
                    id={listboxId}
                    ref={listboxRef}
                    role="listbox"
                    aria-label="Available report months"
                    tabIndex={-1}
                    className="absolute top-full right-0 mt-2 z-[60] min-w-[240px] max-h-64 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xl p-1.5 space-y-0.5 focus:outline-none animate-in fade-in-50 duration-150"
                >
                    <div className="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-800 mb-1 flex items-center justify-between">
                        <span>Select Report Month</span>
                        <span className="font-mono text-[9px] lowercase opacity-80">calendar period</span>
                    </div>

                    {availableMonths.length === 0 ? (
                        <div className="p-3 text-center text-xs text-slate-400 dark:text-slate-500">No historical months available</div>
                    ) : (
                        availableMonths.map((m, idx) => {
                            const isSelected = m.month === selectedMonth
                            const isFocused = idx === focusedIndex
                            const isFuture = m.disabled || false

                            return (
                                <button
                                    key={m.month}
                                    role="option"
                                    type="button"
                                    aria-selected={isSelected}
                                    disabled={isFuture}
                                    onClick={() => {
                                        if (!isFuture) {
                                            onMonthChange(m.month)
                                            setIsOpen(false)
                                            triggerRef.current?.focus()
                                        }
                                    }}
                                    onMouseEnter={() => setFocusedIndex(idx)}
                                    className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs text-left transition-colors cursor-pointer ${
                                        isSelected
                                            ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 font-semibold'
                                            : isFocused
                                                ? 'bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100'
                                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60'
                                    } ${isFuture ? 'opacity-40 cursor-not-allowed' : ''}`}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="truncate">{m.label}</span>
                                        {m.is_current_month && (
                                            <span className="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300">
                                                Current
                                            </span>
                                        )}
                                    </div>

                                    {isSelected && <Check className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0 ml-2" />}
                                </button>
                            )
                        })
                    )}
                </div>
            )}
        </div>
    )
}
