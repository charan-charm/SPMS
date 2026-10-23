import { useState, useRef, useEffect, useMemo } from 'react'
import {
    X,
    Trash2,
    Send,
    RotateCcw,
    AlertTriangle,
    ShieldCheck,
    ChevronRight,
} from 'lucide-react'
import { Button } from '../../components/ui/button'
import { useAuth } from '../auth/AuthContext'
import { SolarAIAvatar } from './SolarAIAvatar'

/**
 * Human-readable page context labels — never expose route names.
 */
const PAGE_LABELS = {
    dashboard: 'Dashboard',
    solar: 'Solar & GoodWe',
    forecasts: 'Forecasts',
    energy: 'Energy Balance',
    cost: 'Energy Cost',
    insights: 'Insights',
    recommendations: 'Recommendations',
    bills: 'Bills',
    analytics: 'Analytics',
    reports: 'Reports',
    notifications: 'Notifications',
    settings: 'Settings',
}

/**
 * Page-specific suggested questions — contextually meaningful per page.
 */
const PAGE_QUESTIONS = {
    dashboard: [
        'Explain my current energy status',
        'Why do I have a potential surplus?',
        'How accurate is my forecast?',
        'Explain my latest recommendation',
    ],
    solar: [
        'Why is my solar data stale?',
        "Explain today's solar generation",
        'What does my inverter status mean?',
    ],
    forecasts: [
        'How accurate is my forecast?',
        'Why is my forecast confidence low?',
        'Explain my predicted demand',
    ],
    energy: [
        'Explain my current energy balance',
        'Why is there a potential surplus?',
        'What does this balance mean?',
    ],
    'energy-balance': [
        'Explain my current energy balance',
        'Why is there a potential surplus?',
        'What does this balance mean?',
    ],
    cost: [
        'Explain my estimated energy cost',
        'What contributes to this estimate?',
        'Why is the cost shown as estimated?',
    ],
    'energy-cost': [
        'Explain my estimated energy cost',
        'What contributes to this estimate?',
        'Why is the cost shown as estimated?',
    ],
    bills: [
        'Explain my latest bill',
        'What are my fixed charges?',
        'How does this bill affect my energy analysis?',
    ],
    insights: [
        'Explain my latest insight',
        'Why was this insight generated?',
    ],
    recommendations: [
        'Explain my latest recommendation',
        'Why is this recommendation relevant?',
    ],
}

const DEFAULT_QUESTIONS = [
    'Explain my current energy status',
    'How accurate is my forecast?',
    'Explain my latest bill',
    'Why do I have a potential surplus?',
]

/**
 * Maps raw data nature enums to household-friendly labels and semantic styling.
 * Hides raw database enums (ACTUAL, PREDICTED, SIMULATED, STALE) behind plain language.
 */
function formatDataNature(nature) {
    if (!nature) return null
    const upper = String(nature).toUpperCase()
    if (upper === 'ACTUAL') {
        return {
            label: 'Actual reading',
            badgeClass: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/25',
            dotClass: 'bg-emerald-500',
        }
    }
    if (upper === 'PREDICTED') {
        return {
            label: 'Forecast projection',
            badgeClass: 'bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-500/25',
            dotClass: 'bg-blue-500',
        }
    }
    if (upper === 'SIMULATED') {
        return {
            label: 'Simulated data',
            badgeClass: 'bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-500/25',
            dotClass: 'bg-purple-500',
        }
    }
    if (upper === 'STALE') {
        return {
            label: 'Data may be outdated',
            badgeClass: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/25',
            dotClass: 'bg-amber-500',
        }
    }
    if (upper === 'UNAVAILABLE') {
        return {
            label: 'Not enough data to answer this yet',
            badgeClass: 'bg-surface-alt text-muted border-border/80',
            dotClass: 'bg-muted',
        }
    }
    return {
        label: nature,
        badgeClass: 'bg-surface-alt text-muted border-border/80',
        dotClass: 'bg-muted',
    }
}

/**
 * Renders assistant response Markdown safely.
 *
 * Supported: **bold**, *italic*, # headings (h3), bullet lists (- item),
 * numbered lists, paragraphs. No arbitrary HTML is injected.
 */
function MarkdownContent({ content }) {
    if (!content) return null

    const lines = content.split('\n')
    const elements = []
    let listBuffer = []
    let orderedBuffer = []

    const flushList = () => {
        if (listBuffer.length > 0) {
            elements.push(
                <ul key={`ul-${elements.length}`} className="my-1.5 space-y-0.5 pl-4 list-disc">
                    {listBuffer.map((item, i) => (
                        <li key={i} className="text-body leading-relaxed">
                            <InlineMarkdown text={item} />
                        </li>
                    ))}
                </ul>
            )
            listBuffer = []
        }
        if (orderedBuffer.length > 0) {
            elements.push(
                <ol key={`ol-${elements.length}`} className="my-1.5 space-y-0.5 pl-4 list-decimal">
                    {orderedBuffer.map((item, i) => (
                        <li key={i} className="text-body leading-relaxed">
                            <InlineMarkdown text={item} />
                        </li>
                    ))}
                </ol>
            )
            orderedBuffer = []
        }
    }

    lines.forEach((line, idx) => {
        // Headings
        if (/^#{1,3}\s/.test(line)) {
            flushList()
            const text = line.replace(/^#{1,3}\s+/, '')
            elements.push(
                <p key={idx} className="mt-2 mb-0.5 text-xs font-semibold text-heading uppercase tracking-wide">
                    <InlineMarkdown text={text} />
                </p>
            )
            return
        }

        // Bullet list
        if (/^[-*]\s/.test(line)) {
            if (orderedBuffer.length > 0) flushList()
            listBuffer.push(line.replace(/^[-*]\s+/, ''))
            return
        }

        // Numbered list
        if (/^\d+\.\s/.test(line)) {
            if (listBuffer.length > 0) flushList()
            orderedBuffer.push(line.replace(/^\d+\.\s+/, ''))
            return
        }

        flushList()

        // Blank line — spacing
        if (line.trim() === '') {
            if (elements.length > 0) {
                elements.push(<div key={`sp-${idx}`} className="h-1" />)
            }
            return
        }

        // Normal paragraph line
        elements.push(
            <p key={idx} className="leading-relaxed text-body">
                <InlineMarkdown text={line} />
            </p>
        )
    })

    flushList()
    return <div className="space-y-0.5 text-sm">{elements}</div>
}

/** Converts inline **bold** and *italic* to React spans. No dangerouslySetInnerHTML. */
function InlineMarkdown({ text }) {
    if (!text) return null
    // Split on **bold** and *italic* patterns
    const parts = []
    const regex = /(\*\*[^*]+\*\*|\*[^*]+\*)/g
    let last = 0
    let match

    while ((match = regex.exec(text)) !== null) {
        if (match.index > last) {
            parts.push(<span key={last}>{text.slice(last, match.index)}</span>)
        }
        const raw = match[0]
        if (raw.startsWith('**')) {
            parts.push(<strong key={match.index} className="font-semibold text-heading">{raw.slice(2, -2)}</strong>)
        } else {
            parts.push(<em key={match.index} className="italic">{raw.slice(1, -1)}</em>)
        }
        last = match.index + raw.length
    }

    if (last < text.length) {
        parts.push(<span key={last}>{text.slice(last)}</span>)
    }

    return <>{parts}</>
}

export function AssistantDrawer({
    isOpen,
    onClose,
    messages = [],
    loading = false,
    error = null,
    onSendMessage,
    onRetry,
    onClear,
    pageContext = 'dashboard',
}) {
    const [inputValue, setInputValue] = useState('')
    const [respondingMsgId, setRespondingMsgId] = useState(null)
    const prevLoadingRef = useRef(loading)
    const messagesEndRef = useRef(null)
    const scrollContainerRef = useRef(null)
    const prevMsgCountRef = useRef(messages.length)
    const inputRef = useRef(null)
    const { account } = useAuth()

    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'

    const pageLabel = PAGE_LABELS[pageContext] ?? pageContext
    const suggestions = useMemo(
        () => PAGE_QUESTIONS[pageContext] ?? DEFAULT_QUESTIONS,
        [pageContext]
    )

    // Detect response arrival: when loading transitions true -> false, play subtle response confirmation (~650ms)
    useEffect(() => {
        if (prevLoadingRef.current && !loading && messages.length > 0) {
            const lastMsg = messages[messages.length - 1]
            if (lastMsg && lastMsg.role === 'assistant') {
                const timer1 = setTimeout(() => {
                    setRespondingMsgId(lastMsg.id)
                }, 0)
                const timer2 = setTimeout(() => {
                    setRespondingMsgId(null)
                }, 650)
                return () => {
                    clearTimeout(timer1)
                    clearTimeout(timer2)
                }
            }
        }
        prevLoadingRef.current = loading
    }, [loading, messages])

    // Ensure content begins at the top on opening — DO NOT scroll text during opening
    useEffect(() => {
        if (isOpen && scrollContainerRef.current) {
            scrollContainerRef.current.scrollTop = 0
        }
    }, [isOpen])

    // Auto-scroll ONLY when a new message is appended, NEVER during drawer opening
    useEffect(() => {
        if (isOpen && messages.length > prevMsgCountRef.current && typeof messagesEndRef.current?.scrollIntoView === 'function') {
            messagesEndRef.current.scrollIntoView({ behavior: 'smooth' })
        }
        prevMsgCountRef.current = messages.length
    }, [messages.length, isOpen])

    // Focus input on drawer open without jumping or scrolling text
    useEffect(() => {
        if (isOpen) {
            const timer = setTimeout(() => {
                if (typeof inputRef.current?.focus === 'function') {
                    inputRef.current.focus({ preventScroll: true })
                }
            }, 320)
            return () => clearTimeout(timer)
        }
    }, [isOpen])

    const handleSubmit = (e) => {
        e?.preventDefault()
        const text = inputValue.trim()
        if (!text || loading) return
        onSendMessage(text)
        setInputValue('')
    }

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault()
            handleSubmit()
        }
    }

    const handleSuggestionClick = (question) => {
        if (loading) return
        onSendMessage(question)
    }

    // Render-time state adjustment for smooth closing animation
    const [prevIsOpen, setPrevIsOpen] = useState(isOpen)
    const [shouldRender, setShouldRender] = useState(isOpen)
    const [isClosing, setIsClosing] = useState(false)

    if (isOpen !== prevIsOpen) {
        setPrevIsOpen(isOpen)
        if (isOpen) {
            setShouldRender(true)
            setIsClosing(false)
        } else if (shouldRender) {
            setIsClosing(true)
        }
    }

    useEffect(() => {
        if (isClosing) {
            const timer = setTimeout(() => {
                setShouldRender(false)
                setIsClosing(false)
            }, 240)
            return () => clearTimeout(timer)
        }
    }, [isClosing])

    // Keyboard Escape to close
    useEffect(() => {
        if (!isOpen) return
        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault()
                onClose?.()
            }
        }
        window.addEventListener('keydown', handleKeyDown)
        return () => window.removeEventListener('keydown', handleKeyDown)
    }, [isOpen, onClose])

    if (!shouldRender) return null

    return (
        <div
            id="assistant-drawer"
            className={`fixed inset-0 z-50 flex justify-end bg-black/35 backdrop-blur-xs ${isClosing ? 'solarai-backdrop-exit' : 'solarai-backdrop-enter'
                } motion-reduce:animate-none`}
            aria-labelledby="assistant-drawer-title"
            role="dialog"
            aria-modal="true"
        >
            {/* Backdrop click to close */}
            <div className="fixed inset-0" onClick={onClose} aria-hidden="true" />

            {/* Slide-in Drawer Container (~48-50% desktop width up to 800px max, near-full-screen on mobile) */}
            <div
                className={`solarai-drawer-card relative flex h-full w-full sm:w-[80vw] md:w-[60vw] lg:w-[48vw] max-w-[800px] flex-col border-l border-border bg-surface ${isClosing ? 'solarai-drawer-exit' : 'solarai-drawer-enter'
                    } motion-reduce:animate-none`}
            >

                {/* ── Header ─────────────────────────────────────────────── */}
                <div className="flex shrink-0 items-center justify-between border-b border-border px-5 py-4 bg-surface">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="shrink-0 flex items-center justify-center">
                            <SolarAIAvatar size="sm" state="settled" />
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h2 id="assistant-drawer-title" className="font-display text-base font-semibold text-heading truncate">
                                    SolarAI
                                </h2>
                                {isDemo ? (
                                    <span className="rounded-full border border-chart-demo/40 bg-chart-demo/10 px-2 py-0.5 text-[10px] font-semibold text-chart-demo shrink-0">
                                        DEMO SIMULATION
                                    </span>
                                ) : (
                                    <span className="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 shrink-0">
                                        REAL DATA
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-muted truncate">
                                Intelligent SPMS Energy Assistant
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-1 shrink-0">
                        {messages.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={onClear}
                                title="Clear conversation"
                                aria-label="Clear conversation"
                                className="size-8 p-0 text-muted hover:text-danger"
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onClose}
                            title="Close SolarAI assistant"
                            aria-label="Close SolarAI assistant"
                            className="size-8 p-0 text-muted hover:text-heading"
                        >
                            <X className="size-5" />
                        </Button>
                    </div>
                </div>

                {/* ── Context Bar ────────────────────────────────────────── */}
                <div className="flex items-center justify-between border-b border-border/60 bg-surface-alt/60 px-5 py-2 text-xs">
                    <div className="flex items-center gap-1.5 text-muted">
                        <ShieldCheck className="size-3.5 text-emerald-500" aria-hidden="true" />
                        <span>Grounded in your SPMS data</span>
                    </div>
                    <div className="flex items-center gap-1 font-medium text-muted">
                        <span className="rounded bg-surface px-1.5 py-0.5 text-[11px] font-semibold text-heading border border-border">
                            {pageLabel}
                        </span>
                    </div>
                </div>

                {/* ── Message Body ───────────────────────────────────────── */}
                <div ref={scrollContainerRef} className="flex-1 overflow-y-auto p-5 space-y-4">
                    {messages.length === 0 ? (
                        <div className="space-y-5 pt-2">
                            {/* Welcome card — rounded chat card presentation */}
                            <div className="rounded-2xl border border-border/80 bg-surface/90 dark:bg-surface-alt/50 p-5 shadow-xs">
                                <div className="flex items-center gap-4">
                                    <div className="shrink-0 flex items-center justify-center">
                                        <SolarAIAvatar size="lg" state="settled" />
                                    </div>
                                    <div className="text-xs text-body leading-relaxed space-y-1">
                                        <p className="font-semibold text-heading text-sm">
                                            Hi, I&apos;m SolarAI
                                        </p>
                                        <p>
                                            I explain your SPMS data — including solar telemetry, forecasts, energy balance, bills, insights and recommendations.
                                        </p>
                                        <p className="text-muted text-[11px]">
                                            All answers are grounded in your real backend state. I never calculate authoritative numbers or control appliances.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Page-specific suggested questions (max 4) */}
                            <div className="space-y-2.5">
                                <p className="text-xs font-semibold text-heading uppercase tracking-wider">
                                    Suggested Questions
                                </p>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {suggestions.slice(0, 4).map((question) => (
                                        <button
                                            key={question}
                                            type="button"
                                            onClick={() => handleSuggestionClick(question)}
                                            className="flex items-center justify-between text-left rounded-2xl border border-border/80 bg-surface p-3.5 text-xs font-medium text-body transition-all hover:border-primary/50 hover:bg-primary-soft/10 hover:text-heading cursor-pointer shadow-xs"
                                        >
                                            <span className="truncate">{question}</span>
                                            <ChevronRight className="size-3.5 text-muted shrink-0 ml-1.5" />
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : (
                        messages.map((msg) => (
                            <div
                                key={msg.id}
                                className={`flex flex-col ${msg.role === 'user' ? 'items-end' : 'items-start'}`}
                            >
                                {/* Avatar for assistant messages */}
                                {msg.role === 'assistant' && (
                                    <div className="flex items-end gap-2 mb-1 self-start">
                                        <div className="shrink-0 flex items-center justify-center">
                                            <SolarAIAvatar size="sm" state="settled" />
                                        </div>
                                        <span className="text-[10px] text-muted">SolarAI</span>
                                    </div>
                                )}

                                <div
                                    className={`px-4.5 py-3.5 text-sm leading-relaxed transition-all duration-300 ${
                                        msg.id === respondingMsgId ? 'ring-2 ring-primary/40' : ''
                                    } ${
                                        msg.role === 'user'
                                            ? 'max-w-[85%] rounded-2xl rounded-tr-xs bg-primary text-primary-contrast font-medium shadow-xs'
                                            : 'max-w-[92%] rounded-2xl rounded-tl-xs bg-surface border border-border/90 text-body shadow-xs'
                                    }`}
                                >
                                    {msg.role === 'assistant' ? (
                                        <div className="space-y-3">
                                            {/* Rendered Markdown content */}
                                            <MarkdownContent content={msg.content} />

                                            {/* Data nature indicator (end-user friendly, internal provider/confidence hidden) */}
                                            {msg.dataNature && (() => {
                                                const info = formatDataNature(msg.dataNature)
                                                if (!info) return null
                                                return (
                                                    <div className="flex items-center gap-1.5 pt-2 border-t border-border/60">
                                                        <span
                                                            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium border ${info.badgeClass}`}
                                                        >
                                                            <span className={`size-1.5 rounded-full ${info.dotClass}`} aria-hidden="true" />
                                                            {info.label}
                                                        </span>
                                                    </div>
                                                )
                                            })()}

                                            {/* Critical Trust & Limitations Notice */}
                                            {msg.limitations && msg.limitations.length > 0 && (
                                                <div className="rounded-xl bg-amber-500/10 dark:bg-amber-500/15 p-2.5 text-xs border border-amber-500/25 text-amber-900 dark:text-amber-200 space-y-1.5">
                                                    <div className="flex items-center gap-1.5 text-[11px] font-semibold text-amber-800 dark:text-amber-300">
                                                        <AlertTriangle className="size-3.5 text-amber-500 shrink-0" aria-hidden="true" />
                                                        <span>Important Data Notes</span>
                                                    </div>
                                                    <ul className="list-disc list-inside text-[11px] space-y-1 text-body/90 dark:text-muted">
                                                        {msg.limitations.map((lim, i) => (
                                                            <li key={i}>{lim}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <span>{msg.content}</span>
                                    )}
                                </div>
                                <span className="mt-1 px-1 text-[10px] text-muted">
                                    {msg.createdAt
                                        ? new Date(msg.createdAt).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })
                                        : ''}
                                </span>
                            </div>
                        ))
                    )}

                    {/* Loading / Thinking State */}
                    {loading && (
                        <div className="flex items-start gap-2.5">
                            <div className="shrink-0 flex items-center justify-center">
                                <SolarAIAvatar size="sm" state="thinking" />
                            </div>
                            <div className="rounded-2xl rounded-tl-xs border border-border/80 bg-surface px-4.5 py-3 text-xs text-muted shadow-xs flex items-center gap-2">
                                <div className="flex gap-1">
                                    <span className="size-1.5 rounded-full bg-primary animate-bounce [animation-delay:-0.3s]" />
                                    <span className="size-1.5 rounded-full bg-primary animate-bounce [animation-delay:-0.15s]" />
                                    <span className="size-1.5 rounded-full bg-primary animate-bounce" />
                                </div>
                                <span>SolarAI is reading your household data...</span>
                            </div>
                        </div>
                    )}

                    {/* Error Banner */}
                    {error && (
                        <div className="rounded-2xl border border-danger/30 bg-danger/10 p-3.5 text-xs text-danger flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="size-4 shrink-0" />
                                <span>{error}</span>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onRetry}
                                className="shrink-0 h-7 text-xs border-danger/40 text-danger hover:bg-danger/20"
                            >
                                <RotateCcw className="size-3 mr-1" />
                                Retry
                            </Button>
                        </div>
                    )}

                    <div ref={messagesEndRef} />
                </div>

                {/* ── Footer Input Area ──────────────────────────────────── */}
                <div className="shrink-0 border-t border-border bg-surface p-4 space-y-3">
                    {/* Quick suggestion chips after conversation starts */}
                    {messages.length > 0 && !loading && (
                        <div
                            className="flex flex-wrap gap-1.5 pb-1 text-xs"
                            role="group"
                            aria-label="Quick questions"
                        >
                            <span className="text-[11px] text-muted self-center shrink-0">
                                Quick ask:
                            </span>
                            {suggestions.slice(0, 3).map((q) => (
                                <button
                                    key={q}
                                    type="button"
                                    onClick={() => handleSuggestionClick(q)}
                                    className="rounded-full border border-border bg-surface-alt px-2.5 py-1 text-[11px] font-medium text-body hover:border-primary hover:text-heading cursor-pointer transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                                >
                                    {q}
                                </button>
                            ))}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="flex items-center gap-2">
                        <input
                            ref={inputRef}
                            type="text"
                            value={inputValue}
                            onChange={(e) => setInputValue(e.target.value)}
                            onKeyDown={handleKeyDown}
                            disabled={loading}
                            placeholder="Ask about solar, forecasts, bills, or recommendations..."
                            aria-label="Ask SolarAI"
                            className="flex-1 rounded-lg border border-border bg-surface-alt px-3.5 py-2.5 text-sm text-heading placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20 focus:bg-surface focus:outline-none transition-colors"
                        />
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={!inputValue.trim() || loading}
                            loading={loading}
                            aria-label="Send message"
                            className="h-10 px-4 shrink-0"
                        >
                            <Send className="size-4" />
                        </Button>
                    </form>

                    <div className="flex items-center justify-center text-[10px] text-muted px-1">
                        <span>SPMS provides  the numbers&nbsp;•&nbsp;SolarAI explains them&nbsp;•&nbsp;No appliance control</span>
                    </div>
                </div>
            </div>
        </div>
    )
}
