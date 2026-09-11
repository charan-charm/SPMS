<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Energy\EnergyBalanceService;
use App\Support\AppException;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F4 — Energy Balance classification rules (docs/04 §60, docs/06 §13).
 */
final class EnergyBalanceTest
{
    private static function solar(float $value, string $nature = 'ACTUAL', string $status = 'FRESH', array $extra = []): array
    {
        return array_merge([
            'value_kwh' => $value,
            'nature' => $nature,
            'status' => $status,
        ], $extra);
    }

    private static function consumption(float $value, string $nature = 'ACTUAL', string $status = 'FRESH', array $extra = []): array
    {
        return self::solar($value, $nature, $status, $extra);
    }

    public static function run(): array
    {
        return [
            TestResult::check('Solar exceeds consumption classifies POTENTIAL_SURPLUS', function (): void {
                $result = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(5.0),
                    'consumption' => self::consumption(3.0),
                ]);

                Assert::same('POTENTIAL_SURPLUS', $result['result']['classification']);
                Assert::same(2.0, $result['result']['balance_kwh']);
                Assert::same('POTENTIAL', $result['result']['measurement_context']);
                Assert::same('CLASSIFIED', $result['result']['state']);
            }),

            TestResult::check('Consumption exceeds solar classifies POTENTIAL_DEFICIT', function (): void {
                $result = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(2.0),
                    'consumption' => self::consumption(4.5),
                ]);

                Assert::same('POTENTIAL_DEFICIT', $result['result']['classification']);
                Assert::same(-2.5, $result['result']['balance_kwh']);
            }),

            TestResult::check('Near-equal inputs within tolerance classify BALANCED', function (): void {
                $result = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(4.9),
                    'consumption' => self::consumption(5.0),
                ]);

                Assert::same('BALANCED', $result['result']['classification']);
                Assert::same(-0.1, $result['result']['balance_kwh']);
            }),

            TestResult::check('Exactly equal inputs classify BALANCED (zero balance)', function (): void {
                $result = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(3.0),
                    'consumption' => self::consumption(3.0),
                ]);

                Assert::same('BALANCED', $result['result']['classification']);
                Assert::same(0.0, $result['result']['balance_kwh']);
            }),

            TestResult::check('Small relative difference just outside absolute floor is classified by relative tolerance', function (): void {
                // 5% of 10 kWh = 0.5 kWh tolerance; difference 0.4 kWh → balanced
                $balanced = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(10.0),
                    'consumption' => self::consumption(10.4),
                ]);
                Assert::same('BALANCED', $balanced['result']['classification']);

                // difference 0.6 kWh → beyond tolerance → surplus
                $surplus = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(11.0),
                    'consumption' => self::consumption(10.4),
                ]);
                Assert::same('POTENTIAL_SURPLUS', $surplus['result']['classification']);
            }),

            TestResult::check('Ratified tolerance rule (docs/10 D-040): inclusive boundary and exact floor behavior', function (): void {
                // diff 0.5 kWh; tolerance = max(0.1, 5% × 10.5) = 0.525 → diff ≤ tolerance → balanced (both directions)
                $surplusSide = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(10.5),
                    'consumption' => self::consumption(10.0),
                ]);
                Assert::same('BALANCED', $surplusSide['result']['classification']);

                $deficitSide = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(10.0),
                    'consumption' => self::consumption(10.5),
                ]);
                Assert::same('BALANCED', $deficitSide['result']['classification']);

                // absolute floor active: max input 0.1 kWh → tolerance = max(0.1, 0.005) = 0.1;
                // diff = 0.1 = tolerance → balanced (inclusive <=)
                $floorBoundary = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(0.0),
                    'consumption' => self::consumption(0.1),
                ]);
                Assert::same('BALANCED', $floorBoundary['result']['classification']);
                Assert::same(0.1, $floorBoundary['result']['tolerance']['absolute_kwh']);

                // one step beyond the floor → classified deficit, not balanced
                $beyondFloor = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(0.0),
                    'consumption' => self::consumption(0.11),
                ]);
                Assert::same('POTENTIAL_DEFICIT', $beyondFloor['result']['classification']);
            }),

            TestResult::check('Result nature is the worst of input natures and never upgrades', function (): void {
                $service = new EnergyBalanceService();

                $predicted = $service->classify([
                    'solar' => self::solar(5.0, 'PREDICTED'),
                    'consumption' => self::consumption(3.0),
                ]);
                Assert::same('PREDICTED', $predicted['result']['data_nature']);

                $simulated = $service->classify([
                    'solar' => self::solar(5.0, 'ACTUAL'),
                    'consumption' => self::consumption(3.0, 'SIMULATED'),
                ]);
                Assert::same('SIMULATED', $simulated['result']['data_nature']);
            }),

            TestResult::check('Result status is the worst of input statuses (FRESH < STALE < DEGRADED)', function (): void {
                $service = new EnergyBalanceService();

                $stale = $service->classify([
                    'solar' => self::solar(5.0, 'ACTUAL', 'STALE'),
                    'consumption' => self::consumption(3.0),
                ]);
                Assert::same('STALE', $stale['result']['data_status']);

                $degraded = $service->classify([
                    'solar' => self::solar(5.0, 'ACTUAL', 'FRESH'),
                    'consumption' => self::consumption(3.0, 'ACTUAL', 'DEGRADED'),
                ]);
                Assert::same('DEGRADED', $degraded['result']['data_status']);
            }),

            TestResult::check('Optional confidence and quality propagate worst-of and stay null when absent', function (): void {
                $service = new EnergyBalanceService();

                $without = $service->classify([
                    'solar' => self::solar(5.0),
                    'consumption' => self::consumption(3.0),
                ]);
                Assert::same(null, $without['result']['confidence_level']);
                Assert::same(null, $without['result']['quality']);

                $with = $service->classify([
                    'solar' => self::solar(5.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'HIGH', 'quality' => 'VERIFIED']),
                    'consumption' => self::consumption(3.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'LOW', 'quality' => 'PARTIAL']),
                ]);
                Assert::same('LOW', $with['result']['confidence_level']);
                Assert::same('PARTIAL', $with['result']['quality']);
            }),

            TestResult::check('No note ever claims confirmed grid export or import', function (): void {
                $service = new EnergyBalanceService();

                foreach ([
                    [$service->classify(['solar' => self::solar(9.0), 'consumption' => self::consumption(2.0)]), 'surplus'],
                    [$service->classify(['solar' => self::solar(1.0), 'consumption' => self::consumption(6.0)]), 'deficit'],
                ] as [$result, $label]) {
                    $flat = strtolower(json_encode($result));
                    Assert::false(str_contains($flat, 'confirmed export'), "surplus note must not claim confirmed export ({$label})");
                    Assert::false(str_contains($flat, 'confirmed import'), "note must not claim confirmed import ({$label})");
                    Assert::same('POTENTIAL', $result['result']['measurement_context']);
                }
            }),

            TestResult::check('Analysis type and period are echoed in the result', function (): void {
                $result = (new EnergyBalanceService())->classify(
                    [
                        'solar' => self::solar(5.0),
                        'consumption' => self::consumption(3.0),
                    ],
                    EnergyBalanceService::ANALYSIS_TIME_PERIOD,
                    ['start' => '2026-09-01', 'end' => '2026-09-08', 'resolution' => 'DAY'],
                    'household-42'
                );

                Assert::same('TIME_PERIOD', $result['analysis']['type']);
                Assert::same('2026-09-01', $result['analysis']['period']['start']);
                Assert::same('household-42', $result['scope']['household_id']);
            }),

            TestResult::check('Simulated inputs produce explicit demo note', function (): void {
                $result = (new EnergyBalanceService())->classify([
                    'solar' => self::solar(5.0, 'SIMULATED'),
                    'consumption' => self::consumption(3.0, 'SIMULATED'),
                ]);

                Assert::same('SIMULATED', $result['result']['data_nature']);
                $flat = strtolower(json_encode($result['notes']));
                Assert::true(str_contains($flat, 'simulated'), 'notes must disclose simulated inputs');
            }),

            TestResult::check('unavailableResult never fabricates a classification or zero', function (): void {
                $result = (new EnergyBalanceService())->unavailableResult();

                Assert::same('UNAVAILABLE', $result['result']['state']);
                Assert::same(null, $result['result']['classification']);
                Assert::same(null, $result['result']['balance_kwh']);
                Assert::same('UNAVAILABLE', $result['result']['data_status']);
            }),

            TestResult::check('worstOf throws VALIDATION_ERROR on unknown nature (defensive)', function (): void {
                $service = new EnergyBalanceService();

                Assert::throws(AppException::class, function () use ($service): void {
                    $service->classify([
                        'solar' => self::solar(5.0, 'MADE_UP'),
                        'consumption' => self::consumption(3.0),
                    ]);
                });
            }),
        ];
    }
}
