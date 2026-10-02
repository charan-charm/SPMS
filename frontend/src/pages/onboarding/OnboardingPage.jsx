import { useCallback } from 'react'
import { useNavigate, useSearchParams, Navigate } from 'react-router'
import { useAuth } from '../../features/auth/useAuth'
import { useHousehold } from '../../features/household/useHousehold'
import { GuidedOnboarding } from '../../features/onboarding/GuidedOnboarding'
import { DemoOnboardingNotice } from '../../components/onboarding/DemoOnboardingNotice'
import { LoadingState } from '../../components/feedback/LoadingState'

export function OnboardingPage() {
    const { account } = useAuth()
    const { isLoading, hasHousehold, status: householdStatus } = useHousehold()
    const [searchParams] = useSearchParams()
    const navigate = useNavigate()

    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'
    const isInspection = isDemo && (
        searchParams.get('preview') === 'demo' ||
        searchParams.get('preview') === 'true' ||
        searchParams.get('inspect') === 'true'
    )

    const handleComplete = useCallback(() => {
        navigate('/dashboard', { replace: true })
    }, [navigate])

    // Strict safety rail: REAL accounts with an active household cannot inspect or access onboarding
    if (!isDemo && hasHousehold) {
        return <Navigate to="/dashboard" replace />
    }

    // Wait until household context is known before branching into setup or notice
    if ((isLoading || householdStatus === 'idle') && !hasHousehold && !isInspection) {
        return (
            <div className="flex flex-col items-center justify-center gap-3 py-12" aria-busy="true">
                <LoadingState label="Checking household status..." />
            </div>
        )
    }

    // DEMO/TEST-only inspection path: opens clean 5-step walkthrough without mutating data
    if (isInspection) {
        return <GuidedOnboarding onComplete={handleComplete} isInspection={true} />
    }

    if (isDemo) {
        return <DemoOnboardingNotice onComplete={handleComplete} />
    }

    return <GuidedOnboarding onComplete={handleComplete} />
}
