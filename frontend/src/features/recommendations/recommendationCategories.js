import {
    Sun,
    Scale,
    CloudSun,
    IndianRupee,
    Home,
    Activity,
} from 'lucide-react'

/**
 * SPMS F7 — Purpose-Based Recommendation Categories (PART B).
 *
 * Presentation mapping from existing recommendation types to purpose categories:
 * - Solar Utilization: Shifting flexible demand into available solar generation windows.
 * - Energy Balance: Preparing for evening deficits or managing surplus energy.
 * - Weather-Aware Planning: Atmospheric variations, clouds, and solar yield decline responses.
 * - Energy Cost: Tariff-aware flexible shifting to minimize variable grid electricity expenditure.
 * - Consumption Patterns: Addressing recurring high demand and discretionary household usage.
 * - System & Data Health: Inverter communication, telemetry gaps, and data quality notices.
 */
export const RECOMMENDATION_CATEGORIES = {
    SOLAR_UTILIZATION: {
        id: 'SOLAR_UTILIZATION',
        label: 'Solar Utilization',
        description: 'Maximize daytime solar consumption and self-utilization during peak generation.',
        icon: Sun,
        badgeClass: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    ENERGY_BALANCE: {
        id: 'ENERGY_BALANCE',
        label: 'Energy Balance',
        description: 'Manage forecast energy positions, mitigate deficits, and balance daily demand.',
        icon: Scale,
        badgeClass: 'border-teal-500/30 bg-teal-500/10 text-teal-700 dark:text-teal-400',
    },
    WEATHER_AWARE: {
        id: 'WEATHER_AWARE',
        label: 'Weather-Aware Planning',
        description: 'Plan consumption around atmospheric changes, cloud cover, and solar yield fluctuations.',
        icon: CloudSun,
        badgeClass: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    ENERGY_COST: {
        id: 'ENERGY_COST',
        label: 'Energy Cost',
        description: 'Reduce exposure to variable grid tariff rates and high electricity expenditure.',
        icon: IndianRupee,
        badgeClass: 'border-purple-500/30 bg-purple-500/10 text-purple-700 dark:text-purple-400',
    },
    CONSUMPTION_PATTERNS: {
        id: 'CONSUMPTION_PATTERNS',
        label: 'Consumption Patterns',
        description: 'Address recurring high household demand and consumption moderation.',
        icon: Home,
        badgeClass: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    SYSTEM_HEALTH: {
        id: 'SYSTEM_HEALTH',
        label: 'System & Data Health',
        description: 'Inverter communication status, telemetry freshness, and input data quality notices.',
        icon: Activity,
        badgeClass: 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
}

/**
 * Ordered list of category filter definitions for tab navigation.
 */
export const CATEGORY_FILTERS = [
    { id: 'ALL', label: 'All', icon: null },
    RECOMMENDATION_CATEGORIES.SOLAR_UTILIZATION,
    RECOMMENDATION_CATEGORIES.ENERGY_BALANCE,
    RECOMMENDATION_CATEGORIES.WEATHER_AWARE,
    RECOMMENDATION_CATEGORIES.ENERGY_COST,
    RECOMMENDATION_CATEGORIES.CONSUMPTION_PATTERNS,
    RECOMMENDATION_CATEGORIES.SYSTEM_HEALTH,
]

/**
 * Pure presentation mapping: resolves the justified category for any given recommendation.
 *
 * @param {object|null} recommendation
 * @returns {typeof RECOMMENDATION_CATEGORIES[keyof typeof RECOMMENDATION_CATEGORIES]}
 */
export function getRecommendationCategory(recommendation) {
    if (!recommendation) {
        return RECOMMENDATION_CATEGORIES.SOLAR_UTILIZATION
    }

    const type = String(recommendation.type || '').toUpperCase()

    // 1. Direct rule-type match against canonical taxonomy
    switch (type) {
        case 'SHIFT_TO_SOLAR_WINDOW':
        case 'USE_AVAILABLE_SOLAR':
        case 'STORAGE_OPPORTUNITY':
        case 'SOLAR_SELF_CONSUMPTION':
        case 'EXCESS_SOLAR_DIVERT':
        case 'HIGH_SOLAR_GENERATION_USAGE':
            return RECOMMENDATION_CATEGORIES.SOLAR_UTILIZATION

        case 'AVOID_DEFICIT_WINDOW':
        case 'EVENING_DEFICIT_PREPARATION':
        case 'PEAK_SHAVING':
        case 'BATTERY_CHARGE_TIMING':
        case 'DEFICIT_MITIGATION':
        case 'LOAD_SHIFTING_EVENING':
            return RECOMMENDATION_CATEGORIES.ENERGY_BALANCE

        case 'SOLAR_DECLINE_RESPONSE':
        case 'CLOUDY_PRODUCTION_DECLINE_PREP':
        case 'WEATHER_VARIABLE_LOAD_MANAGEMENT':
        case 'WEATHER_AWARE_PREPARATION':
        case 'CLOUDY_DAY_CONSERVATION':
        case 'HEATWAVE_COOLING_PLAN':
            return RECOMMENDATION_CATEGORIES.WEATHER_AWARE

        case 'COST_AWARE_SHIFT':
        case 'BILL_SAVINGS_TARGET':
        case 'OFF_PEAK_TARIFF_SHIFT':
        case 'SOLAR_EXPORT_OPTIMIZATION':
        case 'TARIFF_SAVINGS':
            return RECOMMENDATION_CATEGORIES.ENERGY_COST

        case 'HIGH_DEMAND_REDUCTION':
        case 'STANDBY_LOAD_AUDIT':
        case 'UNUSUAL_CONSUMPTION_INVESTIGATION':
        case 'PHANTOM_LOAD_AUDIT':
        case 'BASELOAD_ANOMALY':
            return RECOMMENDATION_CATEGORIES.CONSUMPTION_PATTERNS

        case 'DATA_LIMITATION':
        case 'SOLAR_TELEMETRY_STALE_ALERT':
        case 'SOLAR_INVERTER_OFFLINE_ALERT':
        case 'BATTERY_HEALTH_MAINTENANCE':
        case 'INVERTER_EFFICIENCY_CHECK':
        case 'STALE_TELEMETRY_WARNING':
        case 'PANEL_CLEANING_CHECK':
        case 'SYSTEM_DEGRADATION':
            return RECOMMENDATION_CATEGORIES.SYSTEM_HEALTH
    }

    // 2. Weather evidence heuristic: if recommendation has primary weather impact
    if (recommendation.evidence?.weather && type.includes('WEATHER')) {
        return RECOMMENDATION_CATEGORIES.WEATHER_AWARE
    }

    // 3. Fallback semantic pattern matching with correct specificity order
    if (type.includes('LIMITATION') || type.includes('STALE') || type.includes('TELEMETRY') || type.includes('INVERTER') || type.includes('HEALTH') || type.includes('CLEAN') || type.includes('DEGRAD')) {
        return RECOMMENDATION_CATEGORIES.SYSTEM_HEALTH
    }
    if (type.includes('COST') || type.includes('TARIFF') || type.includes('RATE') || type.includes('BILL') || type.includes('EXPORT')) {
        return RECOMMENDATION_CATEGORIES.ENERGY_COST
    }
    if (type.includes('WEATHER') || type.includes('CLOUD') || type.includes('DECLINE')) {
        return RECOMMENDATION_CATEGORIES.WEATHER_AWARE
    }
    if (type.includes('DEFICIT') || type.includes('SURPLUS') || type.includes('BALANCE') || type.includes('PEAK') || type.includes('SHAV')) {
        return RECOMMENDATION_CATEGORIES.ENERGY_BALANCE
    }
    if (type.includes('DEMAND') || type.includes('CONSUMPTION') || type.includes('BASELOAD') || type.includes('PHANTOM')) {
        return RECOMMENDATION_CATEGORIES.CONSUMPTION_PATTERNS
    }
    if (type.includes('SOLAR') || type.includes('SUN') || type.includes('UTILIZ')) {
        return RECOMMENDATION_CATEGORIES.SOLAR_UTILIZATION
    }

    return RECOMMENDATION_CATEGORIES.SOLAR_UTILIZATION
}
