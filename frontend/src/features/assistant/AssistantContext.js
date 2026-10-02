import { createContext, useContext } from 'react'

/**
 * AssistantContext — provides access to the SolarAI assistant state
 * to any page within the AppLayout without prop-drilling.
 *
 * Shape: { sendMessage: fn, open: fn, isOpen: bool }
 */
export const AssistantContext = createContext(null)

/**
 * useAssistantContext — consume the SolarAI assistant context.
 *
 * Pages use this to programmatically open SolarAI with a seeded question:
 *   const { askSolarAI } = useAssistantContext()
 *   askSolarAI('Why is my forecast confidence low?')
 *
 * Returns null-safe helpers — safe to call even if context is unavailable.
 */
export function useAssistantContext() {
    const ctx = useContext(AssistantContext)
    return {
        askSolarAI: ctx?.askSolarAI ?? (() => {}),
        openSolarAI: ctx?.openSolarAI ?? (() => {}),
        isOpen: ctx?.isOpen ?? false,
    }
}
