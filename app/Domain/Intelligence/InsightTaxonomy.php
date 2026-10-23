<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Controlled insight taxonomy (docs/10 D-043).
 *
 * The set of insight types is controlled: every insight in the system must
 * belong to exactly one whitelisted type, and every type belongs to exactly
 * one category. The taxonomy is extensible by adding entries here — never by
 * accepting arbitrary strings from input, storage, or AI output.
 */
final class InsightTaxonomy
{
    public const CATEGORY_SOLAR = 'SOLAR';
    public const CATEGORY_CONSUMPTION = 'CONSUMPTION';
    public const CATEGORY_ENERGY = 'ENERGY';
    public const CATEGORY_COST = 'COST';
    public const CATEGORY_WEATHER = 'WEATHER';
    public const CATEGORY_GENERAL = 'GENERAL';

    public const CATEGORIES = [
        self::CATEGORY_SOLAR,
        self::CATEGORY_CONSUMPTION,
        self::CATEGORY_ENERGY,
        self::CATEGORY_COST,
        self::CATEGORY_WEATHER,
        self::CATEGORY_GENERAL,
    ];

    // Solar
    public const TYPE_SOLAR_PRODUCTION_CHANGE = 'SOLAR_PRODUCTION_CHANGE';
    public const TYPE_SOLAR_PRODUCTION_DECLINE = 'SOLAR_PRODUCTION_DECLINE';
    public const TYPE_SOLAR_PRODUCTION_IMPROVEMENT = 'SOLAR_PRODUCTION_IMPROVEMENT';
    public const TYPE_RECURRING_SOLAR_PATTERN = 'RECURRING_SOLAR_PATTERN';
    public const TYPE_SOLAR_TELEMETRY_STALE = 'SOLAR_TELEMETRY_STALE';
    public const TYPE_INVERTER_DISCONNECTED = 'INVERTER_DISCONNECTED';

    // Weather
    public const TYPE_CLOUDY_PRODUCTION_RISK = 'CLOUDY_PRODUCTION_RISK';
    public const TYPE_WEATHER_PLANNING_WINDOW = 'WEATHER_PLANNING_WINDOW';
    public const TYPE_WEATHER_VARIABILITY_RISK = 'WEATHER_VARIABILITY_RISK';
    public const TYPE_WEATHER_DATA_UNAVAILABLE = 'WEATHER_DATA_UNAVAILABLE';

    // Consumption
    public const TYPE_CONSUMPTION_CHANGE = 'CONSUMPTION_CHANGE';
    public const TYPE_CONSUMPTION_INCREASE = 'CONSUMPTION_INCREASE';
    public const TYPE_CONSUMPTION_DECREASE = 'CONSUMPTION_DECREASE';
    public const TYPE_UNUSUAL_CONSUMPTION_PATTERN = 'UNUSUAL_CONSUMPTION_PATTERN';
    public const TYPE_RECURRING_HIGH_DEMAND = 'RECURRING_HIGH_DEMAND';

    // Energy situation
    public const TYPE_RECURRING_POTENTIAL_SURPLUS = 'RECURRING_POTENTIAL_SURPLUS';
    public const TYPE_RECURRING_POTENTIAL_DEFICIT = 'RECURRING_POTENTIAL_DEFICIT';
    public const TYPE_SOLAR_DEMAND_MISMATCH = 'SOLAR_DEMAND_MISMATCH';
    public const TYPE_DAYTIME_SURPLUS_EVENING_DEFICIT = 'DAYTIME_SURPLUS_EVENING_DEFICIT';

    // Cost impact
    public const TYPE_COST_CHANGE = 'COST_CHANGE';
    public const TYPE_COST_INCREASE = 'COST_INCREASE';
    public const TYPE_COST_DECREASE = 'COST_DECREASE';
    public const TYPE_COST_IMPACT_PATTERN = 'COST_IMPACT_PATTERN';

    // General
    public const TYPE_SIGNIFICANT_CHANGE = 'SIGNIFICANT_CHANGE';
    public const TYPE_SEASONAL_PATTERN = 'SEASONAL_PATTERN';
    public const TYPE_DATA_LIMITATION = 'DATA_LIMITATION';

    /**
     * type => ['category' => self::CATEGORY_*, 'description' => string].
     *
     * @var array<string, array{category: string, description: string}>
     */
    private const TYPES = [
        self::TYPE_SOLAR_PRODUCTION_CHANGE => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Solar production moved between two observed periods without crossing a direction threshold.',
        ],
        self::TYPE_SOLAR_PRODUCTION_DECLINE => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Solar production decreased materially between two observed periods.',
        ],
        self::TYPE_SOLAR_PRODUCTION_IMPROVEMENT => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Solar production increased materially between two observed periods.',
        ],
        self::TYPE_RECURRING_SOLAR_PATTERN => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Solar production stayed within a stable band across at least three observed periods.',
        ],
        self::TYPE_SOLAR_TELEMETRY_STALE => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Inverter telemetry has not updated within the configured freshness threshold; real-time power cannot be verified.',
        ],
        self::TYPE_INVERTER_DISCONNECTED => [
            'category' => self::CATEGORY_SOLAR,
            'description' => 'Solar hardware communication is offline or reporting a fault status.',
        ],
        self::TYPE_CLOUDY_PRODUCTION_RISK => [
            'category' => self::CATEGORY_WEATHER,
            'description' => 'Forecast indicates elevated cloud cover or low solar irradiance during expected production hours.',
        ],
        self::TYPE_WEATHER_PLANNING_WINDOW => [
            'category' => self::CATEGORY_WEATHER,
            'description' => 'Forecast indicates clear skies and strong solar irradiance offering an optimal midday flexible load window.',
        ],
        self::TYPE_WEATHER_VARIABILITY_RISK => [
            'category' => self::CATEGORY_WEATHER,
            'description' => 'Forecast indicates potential precipitation or variable cloud cover that may create fluctuations in solar generation.',
        ],
        self::TYPE_WEATHER_DATA_UNAVAILABLE => [
            'category' => self::CATEGORY_WEATHER,
            'description' => 'Atmospheric weather forecast or location coordinates are unavailable; clear-sky physical baseline applies.',
        ],
        self::TYPE_CONSUMPTION_CHANGE => [
            'category' => self::CATEGORY_CONSUMPTION,
            'description' => 'Household consumption moved between two observed periods without crossing a direction threshold.',
        ],
        self::TYPE_CONSUMPTION_INCREASE => [
            'category' => self::CATEGORY_CONSUMPTION,
            'description' => 'Household consumption increased materially between two observed periods.',
        ],
        self::TYPE_CONSUMPTION_DECREASE => [
            'category' => self::CATEGORY_CONSUMPTION,
            'description' => 'Household consumption decreased materially between two observed periods.',
        ],
        self::TYPE_UNUSUAL_CONSUMPTION_PATTERN => [
            'category' => self::CATEGORY_CONSUMPTION,
            'description' => 'The latest observed period deviates materially from the household recurring baseline.',
        ],
        self::TYPE_RECURRING_HIGH_DEMAND => [
            'category' => self::CATEGORY_CONSUMPTION,
            'description' => 'Consumption stayed consistently high across at least three observed periods.',
        ],
        self::TYPE_RECURRING_POTENTIAL_SURPLUS => [
            'category' => self::CATEGORY_ENERGY,
            'description' => 'The energy position was classified as potential surplus in every observed classified result.',
        ],
        self::TYPE_RECURRING_POTENTIAL_DEFICIT => [
            'category' => self::CATEGORY_ENERGY,
            'description' => 'The energy position was classified as potential deficit in every observed classified result.',
        ],
        self::TYPE_SOLAR_DEMAND_MISMATCH => [
            'category' => self::CATEGORY_ENERGY,
            'description' => 'Observed periods show both potential surplus and potential deficit, so solar supply and household demand do not align.',
        ],
        self::TYPE_DAYTIME_SURPLUS_EVENING_DEFICIT => [
            'category' => self::CATEGORY_ENERGY,
            'description' => 'Observed periods with declared times show potential surplus around midday and potential deficit in the evening.',
        ],
        self::TYPE_COST_CHANGE => [
            'category' => self::CATEGORY_COST,
            'description' => 'Estimated variable cost moved between two observed periods without crossing a direction threshold.',
        ],
        self::TYPE_COST_INCREASE => [
            'category' => self::CATEGORY_COST,
            'description' => 'Estimated variable cost increased materially between two observed periods.',
        ],
        self::TYPE_COST_DECREASE => [
            'category' => self::CATEGORY_COST,
            'description' => 'Estimated variable cost decreased materially between two observed periods.',
        ],
        self::TYPE_COST_IMPACT_PATTERN => [
            'category' => self::CATEGORY_COST,
            'description' => 'Estimated variable cost stayed non-zero across at least three observed periods.',
        ],
        self::TYPE_SIGNIFICANT_CHANGE => [
            'category' => self::CATEGORY_GENERAL,
            'description' => 'A material change was observed in the available evidence without a more specific classification.',
        ],
        self::TYPE_SEASONAL_PATTERN => [
            'category' => self::CATEGORY_GENERAL,
            'description' => 'Evidence repeats across comparable periods of different seasons.',
        ],
        self::TYPE_DATA_LIMITATION => [
            'category' => self::CATEGORY_GENERAL,
            'description' => 'Available evidence is insufficient or degraded, so insight coverage is limited.',
        ],
    ];

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function categoryForType(string $type): ?string
    {
        return self::TYPES[$type]['category'] ?? null;
    }

    public static function descriptionForType(string $type): ?string
    {
        return self::TYPES[$type]['description'] ?? null;
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return array<int, string> */
    public static function typesForCategory(string $category): array
    {
        $types = [];
        foreach (self::TYPES as $type => $meta) {
            if ($meta['category'] === $category) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
