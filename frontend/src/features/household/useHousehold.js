import { useContext } from 'react'
import { HouseholdContext } from './HouseholdContext'

const fallbackValue = {
    household: null,
    membership: null,
    readiness: null,
    status: 'idle',
    error: null,
    isLoading: false,
    hasHousehold: false,
    refreshHousehold: async () => null,
    createHousehold: async () => ({}),
    ensureDemo: async () => ({}),
    updateHousehold: async () => ({}),
}

export function useHousehold() {
    const context = useContext(HouseholdContext)
    return context || fallbackValue
}
