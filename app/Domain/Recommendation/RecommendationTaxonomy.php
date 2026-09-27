<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * SPMS F7 — Controlled Recommendation Taxonomy (docs/01 §15, docs/02 §2 F7, docs/10 D-072).
 *
 * All recommendations belong to one of 8 controlled types.
 * 7 are actionable household-level recommendations.
 * DATA_LIMITATION is an honest non-actionable limitation/support state.
 */
final class RecommendationTaxonomy
{
    public const TYPE_SHIFT_TO_SOLAR_WINDOW = 'SHIFT_TO_SOLAR_WINDOW';
    public const TYPE_USE_AVAILABLE_SOLAR = 'USE_AVAILABLE_SOLAR';
    public const TYPE_AVOID_DEFICIT_WINDOW = 'AVOID_DEFICIT_WINDOW';
    public const TYPE_EVENING_DEFICIT_PREPARATION = 'EVENING_DEFICIT_PREPARATION';
    public const TYPE_HIGH_DEMAND_REDUCTION = 'HIGH_DEMAND_REDUCTION';
    public const TYPE_COST_AWARE_SHIFT = 'COST_AWARE_SHIFT';
    public const TYPE_SOLAR_DECLINE_RESPONSE = 'SOLAR_DECLINE_RESPONSE';
    public const TYPE_DATA_LIMITATION = 'DATA_LIMITATION';

    public const TYPES = [
        self::TYPE_SHIFT_TO_SOLAR_WINDOW => [
            'name' => 'Shift to Solar Window',
            'actionable' => true,
            'description' => 'Suggest moving flexible household electricity usage into a period where solar availability is expected to be higher than household demand.',
        ],
        self::TYPE_USE_AVAILABLE_SOLAR => [
            'name' => 'Use Available Solar',
            'actionable' => true,
            'description' => 'Potential solar surplus indicates an opportunity to utilize more electricity during the available solar window.',
        ],
        self::TYPE_AVOID_DEFICIT_WINDOW => [
            'name' => 'Avoid Deficit Window',
            'actionable' => true,
            'description' => 'A future deficit window suggests reducing or shifting flexible consumption away from that period where practical.',
        ],
        self::TYPE_EVENING_DEFICIT_PREPARATION => [
            'name' => 'Evening Deficit Preparation',
            'actionable' => true,
            'description' => 'A recurring pattern shows daytime solar availability followed by evening household demand.',
        ],
        self::TYPE_HIGH_DEMAND_REDUCTION => [
            'name' => 'High Demand Reduction',
            'actionable' => true,
            'description' => 'Verified history or forecasts show unusually high or recurring household consumption.',
        ],
        self::TYPE_COST_AWARE_SHIFT => [
            'name' => 'Cost-Aware Shift',
            'actionable' => true,
            'description' => 'A supported cost-impact signal indicates shifting flexible consumption could reduce exposure to estimated variable energy cost.',
        ],
        self::TYPE_SOLAR_DECLINE_RESPONSE => [
            'name' => 'Solar Decline Response',
            'actionable' => true,
            'description' => 'Recent or forecast solar production decline may require adjusting expectations or shifting flexible consumption.',
        ],
        self::TYPE_DATA_LIMITATION => [
            'name' => 'Data Limitation Notice',
            'actionable' => false,
            'description' => 'Informational notice when recommendation quality is materially affected by insufficient, stale, or missing data.',
        ],
    ];

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function isActionable(string $type): bool
    {
        return self::TYPES[$type]['actionable'] ?? false;
    }

    public static function name(string $type): string
    {
        return self::TYPES[$type]['name'] ?? $type;
    }

    public static function description(string $type): string
    {
        return self::TYPES[$type]['description'] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function allTypes(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::allTypes();
    }
}
