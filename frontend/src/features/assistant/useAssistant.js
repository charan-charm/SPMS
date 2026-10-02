import { useState, useEffect, useCallback, useRef } from 'react'
import { askAssistant, getAssistantHistory, clearAssistantHistory } from '../../services/api/assistant'

/**
 * Hook managing the SPMS Energy Assistant state and API interaction.
 */
export function useAssistant({ pageContext = 'dashboard', autoLoad = true } = {}) {
    const [isOpen, setIsOpen] = useState(false)
    const [messages, setMessages] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [hasLoaded, setHasLoaded] = useState(false)
    const lastUserQueryRef = useRef(null)

    // Load recent history asynchronously when opened for the first time
    useEffect(() => {
        let isMounted = true
        if (isOpen && !hasLoaded && autoLoad) {
            getAssistantHistory({ limit: 25 })
                .then((res) => {
                    if (!isMounted) return
                    if (res?.data?.messages && Array.isArray(res.data.messages)) {
                        const formatted = res.data.messages.map((m) => ({
                            id: m.id || String(Math.random()),
                            role: m.role,
                            content: m.content,
                            provider: m.provider,
                            model: m.model,
                            contextDomains: m.context_domains || [],
                            dataNature: m.data_nature || 'PREDICTED',
                            confidence: m.confidence || 'MODERATE',
                            limitations: m.limitations || [],
                            fallbackUsed: Boolean(m.fallback_used),
                            createdAt: m.created_at || new Date().toISOString(),
                        }))
                        setMessages(formatted)
                    }
                    setHasLoaded(true)
                })
                .catch(() => {
                    if (isMounted) {
                        setHasLoaded(true)
                    }
                })
        }
        return () => {
            isMounted = false
        }
    }, [isOpen, hasLoaded, autoLoad])

    const sendMessage = useCallback(
        async (queryText, overridePageContext) => {
            const query = (queryText || '').trim()
            if (!query || loading) return

            setError(null)
            lastUserQueryRef.current = query

            const userMsgId = `user-${Date.now()}`
            const userMsg = {
                id: userMsgId,
                role: 'user',
                content: query,
                createdAt: new Date().toISOString(),
            }

            setMessages((prev) => [...prev, userMsg])
            setLoading(true)

            // Prepare client history for context
            const clientHistory = messages.slice(-6).map((m) => ({
                role: m.role,
                content: m.content,
            }))

            try {
                const effectiveContext = overridePageContext || pageContext
                const res = await askAssistant({
                    message: query,
                    pageContext: effectiveContext,
                    history: clientHistory,
                })

                const payload = res?.data || {}
                const assistantMsg = {
                    id: `assistant-${Date.now()}`,
                    role: 'assistant',
                    content: payload.answer || 'No response received from assistant.',
                    provider: payload.provider || 'gemini',
                    model: payload.model || '',
                    contextDomains: payload.context_domains || [],
                    dataNature: payload.data_nature || 'PREDICTED',
                    confidence: payload.confidence || 'MODERATE',
                    limitations: payload.limitations || [],
                    fallbackUsed: Boolean(payload.fallback_used),
                    createdAt: new Date().toISOString(),
                }

                setMessages((prev) => [...prev, assistantMsg])
            } catch (err) {
                const errorMsg =
                    err?.message ||
                    err?.data?.message ||
                    'Unable to retrieve answer from Energy Assistant. Please try again.'
                setError(errorMsg)
            } finally {
                setLoading(false)
            }
        },
        [loading, messages, pageContext]
    )

    const retryLast = useCallback(() => {
        if (lastUserQueryRef.current) {
            setError(null)
            sendMessage(lastUserQueryRef.current)
        }
    }, [sendMessage])

    const clearHistory = useCallback(async () => {
        try {
            await clearAssistantHistory()
            setMessages([])
            setError(null)
        } catch {
            setError('Failed to clear conversation history.')
        }
    }, [])

    return {
        isOpen,
        setIsOpen,
        toggleOpen: () => setIsOpen((prev) => !prev),
        messages,
        loading,
        error,
        sendMessage,
        retryLast,
        clearHistory,
    }
}
