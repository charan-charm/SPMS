import { CapabilitiesSection } from '../../features/landing/CapabilitiesSection'
import { FinalCtaSection } from '../../features/landing/FinalCtaSection'
import { HeroSection } from '../../features/landing/HeroSection'
import { HouseholdsSection } from '../../features/landing/HouseholdsSection'
import { HowItWorksSection } from '../../features/landing/HowItWorksSection'
import { RecommendationsSection } from '../../features/landing/RecommendationsSection'
import { SolarVsUsageSection } from '../../features/landing/SolarVsUsageSection'
import { PublicLandingLeaves } from '../../components/decorative/PublicLandingLeaves'

/**
 * LandingPage (O1 — original composition, corrected hero) — the public
 * root route. Composes the approved section flow:
 * hero → capabilities → solar-vs-consumption → recommendations →
 * how it works → built for households → final CTA.
 *
 * PublicLandingLeaves provides the fixed-position botanical corner frame
 * that persists as a decorative background layer while scrolling.
 */
export function LandingPage() {
    return (
        <>
            {/* Fixed viewport botanical corner frame — background layer */}
            <PublicLandingLeaves />

            <HeroSection />
            <CapabilitiesSection />
            <SolarVsUsageSection />
            <RecommendationsSection />
            <HowItWorksSection />
            <HouseholdsSection />
            <FinalCtaSection />
        </>
    )
}