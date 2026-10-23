import { useState, useEffect, useCallback, useRef } from 'react'
import { askAssistant, getAssistantHistory, clearAssistantHistory } from '../../services/api/assistant'
import { useHousehold } from '../household/useHousehold'

/**
 * Hook managing the SPMS Energy Assistant state and API interaction.
 * Enforces strict household-scoped isolation so conversation history
 * and in-flight responses never cross household boundaries.
 */
export function useAssistant({ pageContext = 'dashboard', autoLoad = true, householdId: explicitHouseholdId } = {}) {
    const { household } = useHousehold()
    const activeHouseholdId = explicitHouseholdId !== undefined ? explicitHouseholdId : household?.id

    const [isOpen, setIsOpen] = useState(false)
    const [messages, setMessages] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [hasLoaded, setHasLoaded] = useState(false)

    const lastUserQueryRef = useRef(null)
    const requestGenRef = useRef(0)
    const abortControllerRef = useRef(null)
    const mountedRef = useRef(true)

    // Household-scoped isolation: immediately wipe conversation state on household change
    const prevHouseholdIdRef = useRef(activeHouseholdId)
    useEffect(() => {
        mountedRef.current = true
        if (prevHouseholdIdRef.current !== activeHouseholdId) {
            prevHouseholdIdRef.current = activeHouseholdId
            requestGenRef.current += 1
            abortControllerRef.current?.abort()
            abortControllerRef.current = null
            lastUserQueryRef.current = null
            setMessages([])
            setLoading(false)
            setError(null)
            setHasLoaded(false)
        }
        return () => {
            mountedRef.current = false
        }
    }, [activeHouseholdId])

    // Load recent history asynchronously when opened for the first time for active household
    useEffect(() => {
        if (!isOpen || hasLoaded || !autoLoad || !activeHouseholdId) return

        const currentGen = requestGenRef.current
        const ac = new AbortController()
        abortControllerRef.current = ac

        getAssistantHistory({ limit: 25, signal: ac.signal })
            .then((res) => {
                if (!mountedRef.current || currentGen !== requestGenRef.current) return
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
            .catch((err) => {
                if (!mountedRef.current || currentGen !== requestGenRef.current || err?.name === 'AbortError') return
                setHasLoaded(true)
            })

        return () => {
            ac.abort()
        }
    }, [isOpen, hasLoaded, autoLoad, activeHouseholdId])

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

            // Prepare client history for context strictly from currently visible messages
            const clientHistory = messages.slice(-6).map((m) => ({
                role: m.role,
                content: m.content,
            }))

            const currentGen = requestGenRef.current
            abortControllerRef.current?.abort()
            const ac = new AbortController()
            abortControllerRef.current = ac

            try {
                const effectiveContext = overridePageContext || pageContext
                const res = await askAssistant({
                    message: query,
                    pageContext: effectiveContext,
                    history: clientHistory,
                    signal: ac.signal,
                })

                if (!mountedRef.current || currentGen !== requestGenRef.current) {
                    return // Household changed during flight: discard stale answer
                }

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
                if (!mountedRef.current || currentGen !== requestGenRef.current || err?.name === 'AbortError') {
                    return
                }

                // Preserve internal diagnostics in developer console
                console.error('[SolarAI] Assistant request failed:', err)

                // Friendly, plain-language error message for the household end-user
                let friendlyMsg = 'SolarAI is temporarily unavailable. Please try again in a moment.'
                const rawMsg = err?.data?.message || err?.message || ''
                const isTimeout = err?.code === 'ECONNABORTED' || rawMsg.toLowerCase().includes('timeout')
                const isRateLimit = err?.response?.status === 429 || err?.status === 429

                if (isTimeout) {
                    friendlyMsg = 'The request timed out while preparing your solar insights. Please try again.'
                } else if (isRateLimit) {
                    friendlyMsg = 'SolarAI is receiving many requests right now. Please wait a few seconds and try again.'
                } else if (
                    rawMsg &&
                    !/sql|syntax|exception|stack|table|column|pdo|500|404|http|undefined|fatal|error:/i.test(rawMsg) &&
                    rawMsg.length < 120
                ) {
                    friendlyMsg = rawMsg
                }

                setError(friendlyMsg)
            } finally {
                if (mountedRef.current && currentGen === requestGenRef.current) {
                    setLoading(false)
                }
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
