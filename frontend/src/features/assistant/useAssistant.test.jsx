import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useAssistant } from './useAssistant'
import * as assistantApi from '../../services/api/assistant'
import * as householdHook from '../household/useHousehold'

vi.mock('../../services/api/assistant', () => ({
    askAssistant: vi.fn(),
    getAssistantHistory: vi.fn(),
    clearAssistantHistory: vi.fn(),
}))

vi.mock('../household/useHousehold', () => ({
    useHousehold: vi.fn(),
}))

describe('useAssistant — Household State Isolation Regression Tests', () => {
    let mockHouseholdState

    beforeEach(() => {
        vi.clearAllMocks()
        mockHouseholdState = {
            household: { id: 'hh-scen01', label: 'Household 1' },
        }
        householdHook.useHousehold.mockImplementation(() => mockHouseholdState)
        assistantApi.getAssistantHistory.mockResolvedValue({
            data: {
                messages: [
                    {
                        id: 'msg-h1-1',
                        role: 'user',
                        content: 'What is my surplus in HH1?',
                    },
                    {
                        id: 'msg-h1-2',
                        role: 'assistant',
                        content: 'HH1 has 4.2 kWh surplus.',
                    },
                ],
            },
        })
        assistantApi.askAssistant.mockResolvedValue({
            data: {
                answer: 'Response for current household',
                context_domains: ['solar'],
            },
        })
    })

    it('loads history when opened for the active household', async () => {
        const { result } = renderHook(() => useAssistant({ autoLoad: true }))

        expect(result.current.messages).toEqual([])

        await act(async () => {
            result.current.setIsOpen(true)
        })

        expect(assistantApi.getAssistantHistory).toHaveBeenCalledWith(
            expect.objectContaining({ limit: 25 })
        )
        expect(result.current.messages).toHaveLength(2)
        expect(result.current.messages[0].content).toBe('What is my surplus in HH1?')
    })

    it('clears messages and resets flags immediately upon household switch', async () => {
        const { result, rerender } = renderHook(() => useAssistant({ autoLoad: true }))

        // Open and load messages for HH1
        await act(async () => {
            result.current.setIsOpen(true)
        })
        expect(result.current.messages).toHaveLength(2)

        // Switch household to HH2
        mockHouseholdState = {
            household: { id: 'hh-scen02', label: 'Household 2' },
        }
        act(() => {
            rerender()
        })

        // Messages must be cleared immediately
        expect(result.current.messages).toEqual([])
        expect(result.current.loading).toBe(false)
        expect(result.current.error).toBeNull()
    })

    it('discards late in-flight response from prior household so it does not repopulate new household', async () => {
        let resolveAsk
        assistantApi.askAssistant.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveAsk = resolve
                })
        )

        const { result, rerender } = renderHook(() => useAssistant({ autoLoad: true }))

        // Send query in HH1
        act(() => {
            result.current.sendMessage('Slow question for HH1')
        })

        expect(result.current.loading).toBe(true)
        expect(result.current.messages).toHaveLength(1)
        expect(result.current.messages[0].content).toBe('Slow question for HH1')

        // Switch to HH2 while request is still pending
        mockHouseholdState = {
            household: { id: 'hh-scen02', label: 'Household 2' },
        }
        act(() => {
            rerender()
        })

        // State is reset for HH2
        expect(result.current.messages).toEqual([])
        expect(result.current.loading).toBe(false)

        // Now late HH1 response resolves
        await act(async () => {
            resolveAsk({
                data: {
                    answer: 'Old HH1 answer that should NOT appear in HH2',
                },
            })
        })

        // Verify messages in HH2 remain empty and are not contaminated by late HH1 answer
        expect(result.current.messages).toEqual([])
        expect(result.current.loading).toBe(false)
    })

    it('does not send prior household history when asking in new household', async () => {
        const { result, rerender } = renderHook(() => useAssistant({ autoLoad: true }))

        // Load HH1 messages
        await act(async () => {
            result.current.setIsOpen(true)
        })
        expect(result.current.messages).toHaveLength(2)

        // Switch to HH2
        mockHouseholdState = {
            household: { id: 'hh-scen02', label: 'Household 2' },
        }
        act(() => {
            rerender()
        })

        // Now ask in HH2
        await act(async () => {
            await result.current.sendMessage('First query in HH2')
        })

        // The API call history argument must NOT contain HH1 messages
        expect(assistantApi.askAssistant).toHaveBeenCalledWith(
            expect.objectContaining({
                message: 'First query in HH2',
                history: [], // Cleared: no HH1 messages leaked
            })
        )
    })

    it('sanitizes technical errors into friendly user messages while preserving diagnostics in console.error', async () => {
        const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
        const technicalError = new Error('SQLSTATE[HY000]: General error: 500 Internal Server Error')
        assistantApi.askAssistant.mockRejectedValue(technicalError)

        const { result } = renderHook(() => useAssistant({ autoLoad: true }))

        await act(async () => {
            await result.current.sendMessage('Break the server')
        })

        // Internal technical error must NOT leak to user
        expect(result.current.error).toBe('SolarAI is temporarily unavailable. Please try again in a moment.')
        expect(result.current.error).not.toContain('SQLSTATE')
        expect(result.current.error).not.toContain('500')

        // Developer diagnostics preserved in console
        expect(consoleErrorSpy).toHaveBeenCalledWith(
            '[SolarAI] Assistant request failed:',
            technicalError
        )

        consoleErrorSpy.mockRestore()
    })

    it('formats timeout errors into a friendly user message', async () => {
        const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
        const timeoutError = new Error('timeout of 15000ms exceeded')
        timeoutError.code = 'ECONNABORTED'
        assistantApi.askAssistant.mockRejectedValue(timeoutError)

        const { result } = renderHook(() => useAssistant({ autoLoad: true }))

        await act(async () => {
            await result.current.sendMessage('Slow query')
        })

        expect(result.current.error).toBe(
            'The request timed out while preparing your solar insights. Please try again.'
        )

        consoleErrorSpy.mockRestore()
    })
})
