import { SolarAIAvatar } from './SolarAIAvatar'

/**
 * SolarAIEntryButton — inline contextual entry point for the SolarAI assistant.
 *
 * Place on any page to give users a context-aware prompt strip that opens
 * the SolarAI drawer with a pre-seeded question relevant to that page.
 *
 * Props:
 *   onAsk(question: string) — should call assistant.sendMessage + open drawer
 *   prompts: Array<string>  — 2–3 suggested questions for this context
 *   compact: bool           — single-row compact variant (for page headers)
 */
export function SolarAIEntryButton({ onAsk, prompts = [], compact = false }) {
    if (!onAsk) return null

    if (compact) {
        // Single-button compact variant — icon-sized avatar supporting the action
        const label = prompts[0] ?? 'Ask SolarAI about this page'
        return (
            <button
                type="button"
                onClick={() => onAsk(label)}
                className="inline-flex items-center gap-2 rounded-lg border border-border/80 bg-surface/80 px-3 py-1.5 text-xs font-medium text-body transition-all hover:border-primary/50 hover:bg-surface-subtle hover:text-heading hover:scale-[1.01] active:scale-95 cursor-pointer shadow-xs"
                aria-label={`Ask SolarAI: ${label}`}
            >
                <SolarAIAvatar size="xs" />
                <span>{label}</span>
            </button>
        )
    }

    // Full card variant with multiple prompts
    return (
        <div className="rounded-xl border border-border/80 bg-surface/60 p-4 space-y-3">
            <div className="flex items-center gap-2.5">
                <SolarAIAvatar size="sm" />
                <div>
                    <p className="text-xs font-semibold text-heading">Ask SolarAI</p>
                    <p className="text-[11px] text-muted">Context-aware explanations from your SPMS data</p>
                </div>
            </div>
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                {prompts.map((q) => (
                    <button
                        key={q}
                        type="button"
                        onClick={() => onAsk(q)}
                        className="text-left rounded-lg border border-border bg-surface px-3 py-2 text-xs font-medium text-body transition-all hover:border-primary/40 hover:bg-surface-subtle hover:text-heading cursor-pointer"
                    >
                        {q}
                    </button>
                ))}
            </div>
        </div>
    )
}
