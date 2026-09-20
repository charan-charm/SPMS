import { useNavigate } from 'react-router'
import { useAuth } from '../../features/auth/useAuth'
import { useHousehold } from '../../features/household/useHousehold'
import { RealOnboardingForm } from '../../components/onboarding/RealOnboardingForm'
import { DemoOnboardingNotice } from '../../components/onboarding/DemoOnboardingNotice'
import { LoadingState } from '../../components/feedback/LoadingState'

export function OnboardingPage() {
    const { account } = useAuth()
    const { isLoading, hasHousehold } = useHousehold()
    const navigate = useNavigate()

    const isDemo = account?.mode === 'DEMO_TEST' || account?.environment === 'DEMO'

    const handleComplete = () => {
        navigate('/dashboard', { replace: true })
    }

    if (isLoading && !hasHousehold) {
        return (
            <div className="flex flex-col items-center justify-center gap-3 py-12" aria-busy="true">
                <LoadingState label="Checking household status..." />
            </div>
        )
    }

    if (isDemo) {
        return <DemoOnboardingNotice onComplete={handleComplete} />
    }

    return <RealOnboardingForm onComplete={handleComplete} />
}
