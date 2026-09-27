<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\EnergyCostController;
use App\Domain\Energy\EnergyCostService;
use App\Support\AppException;
use App\Support\Request;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F5 — Energy Cost Analysis rules (docs/01 §19, docs/05 §12, docs/10 D-042).
 *
 * Covers the authoritative service calculation and the controller
 * validation boundary (field-specific 422s) without HTTP.
 */
final class EnergyCostTest
{
    private static function energy(float $value, string $nature = 'ACTUAL', string $status = 'FRESH', array $extra = []): array
    {
        return array_merge([
            'value_kwh' => $value,
            'nature' => $nature,
            'status' => $status,
        ], $extra);
    }

    private static function pricing(string $basis = EnergyCostService::BASIS_USER_PROVIDED_RATE, float $rate = 8.5, string $currency = 'INR', array $extra = []): array
    {
        $pricing = array_merge([
            'basis' => $basis,
            'currency' => $currency,
        ], $extra);

        if ($basis !== EnergyCostService::BASIS_INSUFFICIENT_EVIDENCE) {
            $pricing['unit_rate'] = $rate;
        }

        return $pricing;
    }

    private static function estimate(array $overrides = []): array
    {
        $inputs = array_merge([
            'solar' => self::energy(2.0),
            'consumption' => self::energy(4.5),
            'pricing' => self::pricing(),
        ], $overrides);

        return (new EnergyCostService())->estimate($inputs);
    }

    private static function assertValidationField(callable $callback, string $expectedField): void
    {
        try {
            $callback();
            Assert::true(false, 'Expected AppException was not thrown');
        } catch (AppException $e) {
            Assert::same('VALIDATION_ERROR', $e->errorCode());
            Assert::same(422, $e->httpStatus());
            Assert::same($expectedField, $e->context()['field'] ?? null);
        }
    }

    public static function run(): array
    {
        return [
            TestResult::check('Variable cost equals potential grid-dependent energy × declared unit rate', function (): void {
                $result = self::estimate()['result'];

                Assert::same(EnergyCostService::RESULT_STATE_ESTIMATED, $result['state']);
                Assert::same(2.5, self::estimate()['energy_position']['grid_dependent_kwh']);
                Assert::same(21.25, $result['estimated_variable_cost']);
            }),

            TestResult::check('Currency is preserved as explicit data', function (): void {
                $result = self::estimate()['result'];

                Assert::same('INR', $result['currency']);
                Assert::same('INR', self::estimate()['inputs']['pricing']['currency']);
            }),

            TestResult::check('Fixed charges are never multiplied by kWh and remain separate', function (): void {
                $data = self::estimate([
                    'fixed_charge' => ['amount' => 150.0, 'currency' => 'INR', 'label' => 'Fixed customer charge'],
                ]);

                Assert::same(21.25, $data['result']['estimated_variable_cost']);
                Assert::same(150.0, $data['result']['fixed_charge']['amount']);
                Assert::same('SEPARATE_NOT_INCLUDED', $data['result']['fixed_charge']['treatment']);
                $notes = strtolower(json_encode($data['notes']));
                Assert::true(str_contains($notes, 'not multiplied'), 'Fixed-charge note must state it is not multiplied');
                Assert::true(str_contains($notes, 'not included'), 'Fixed-charge note must state it is not included');
            }),

            TestResult::check('Range is emitted when fallback pricing evidence is used', function (): void {
                $result = self::estimate()['result'];

                Assert::same(15.94, $result['range']['low']);
                Assert::same(26.56, $result['range']['high']);
                Assert::same(25.0, $result['range']['half_width_percent']);
                $triggers = json_encode($result['range']['triggers']);
                Assert::true(str_contains($triggers, 'USER_PROVIDED_RATE'), 'Fallback basis must be disclosed as a range trigger');
            }),

            TestResult::check('Range is emitted for LOW confidence', function (): void {
                $result = self::estimate([
                    'solar' => self::energy(2.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'LOW']),
                ])['result'];

                Assert::same('LOW', $result['confidence_level']);
                Assert::same(25.0, $result['range']['half_width_percent']);
            }),

            TestResult::check('Range is emitted for VERY_LOW confidence with the widest factor', function (): void {
                $result = self::estimate([
                    'solar' => self::energy(2.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'VERY_LOW']),
                ])['result'];

                Assert::same('VERY_LOW', $result['confidence_level']);
                Assert::same(12.75, $result['range']['low']);
                Assert::same(29.75, $result['range']['high']);
            }),

            TestResult::check('Prediction uncertainty (non-ACTUAL nature) requires a range', function (): void {
                $predicted = self::estimate([
                    'solar' => self::energy(2.0, 'PREDICTED'),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ])['result'];

                Assert::same(15.0, $predicted['range']['half_width_percent']);

                $estimated = self::estimate([
                    'solar' => self::energy(2.0, 'ESTIMATED'),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ])['result'];

                Assert::same(10.0, $estimated['range']['half_width_percent']);

                $simulated = self::estimate([
                    'solar' => self::energy(2.0, 'SIMULATED'),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ])['result'];

                Assert::same(20.0, $simulated['range']['half_width_percent']);
            }),

            TestResult::check('Strong evidence, actual inputs and high confidence give a disclosed point estimate', function (): void {
                $result = self::estimate([
                    'solar' => self::energy(2.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'HIGH']),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ])['result'];

                Assert::same(null, $result['range']);
                Assert::same(1, $result['evidence_level']);
                $notes = json_encode(self::estimate([
                    'solar' => self::energy(2.0, 'ACTUAL', 'FRESH', ['confidence_level' => 'HIGH']),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ])['notes']);
                Assert::true(str_contains($notes, 'point estimate'), 'Point-estimate case must be disclosed');
            }),

            TestResult::check('Insufficient pricing evidence does not fabricate zero', function (): void {
                $data = self::estimate(['pricing' => self::pricing(EnergyCostService::BASIS_INSUFFICIENT_EVIDENCE)]);

                Assert::same(EnergyCostService::RESULT_STATE_INSUFFICIENT_EVIDENCE, $data['result']['state']);
                Assert::same(null, $data['result']['estimated_variable_cost']);
                Assert::same(null, $data['result']['range']);
                $notes = json_encode($data['notes']);
                Assert::true(str_contains($notes, 'never fabricates'), 'Insufficient-evidence notes must state no fabrication');
            }),

            TestResult::check('Historical verified cost remains distinct from the future estimate', function (): void {
                $estimate = self::estimate()['result'];
                $historical = (new EnergyCostService())->historicalResult()['result'];

                Assert::same(EnergyCostService::COST_TYPE_FUTURE_ESTIMATE, $estimate['cost_type']);
                Assert::same(EnergyCostService::COST_TYPE_HISTORICAL_VERIFIED, $historical['cost_type']);
                Assert::same(EnergyCostService::RESULT_STATE_UNAVAILABLE, $historical['state']);
                Assert::same(null, $historical['amount']);
                Assert::same(null, $historical['currency']);
            }),

            TestResult::check('POTENTIAL position never becomes MEASURED; cost stays ESTIMATED', function (): void {
                $data = self::estimate();

                Assert::same('POTENTIAL', $data['energy_position']['measurement_context']);
                Assert::same('ESTIMATED', $data['result']['measurement_context']);
                $notes = strtolower(json_encode($data['notes']));
                Assert::true(str_contains($notes, 'never a measured grid import'), 'Position note must forbid measured reinterpretation');
            }),

            TestResult::check('Predicted inputs keep the result PREDICTED', function (): void {
                $result = self::estimate([
                    'solar' => self::energy(2.0, 'PREDICTED'),
                    'consumption' => self::energy(4.5, 'PREDICTED'),
                ])['result'];

                Assert::same('PREDICTED', $result['data_nature']);
            }),

            TestResult::check('Estimated inputs keep the result ESTIMATED', function (): void {
                $result = self::estimate([
                    'solar' => self::energy(2.0, 'ESTIMATED'),
                    'consumption' => self::energy(4.5, 'ESTIMATED'),
                ])['result'];

                Assert::same('ESTIMATED', $result['data_nature']);
            }),

            TestResult::check('Simulated inputs keep the result SIMULATED', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(2.0, 'SIMULATED'),
                    'consumption' => self::energy(4.5, 'SIMULATED'),
                ]);

                Assert::same('SIMULATED', $data['result']['data_nature']);
                $notes = strtolower(json_encode($data['notes']));
                Assert::true(str_contains($notes, 'simulated'), 'Simulated labeling must reach the notes');
            }),

            TestResult::check('Actual inputs are floored to ESTIMATED for a future cost result', function (): void {
                $result = self::estimate()['result'];

                Assert::same('ESTIMATED', $result['data_nature']);
            }),

            TestResult::check('Worst-of nature/status/quality/confidence semantics', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(2.0, 'PREDICTED', 'FRESH', ['quality' => 'GOOD', 'confidence_level' => 'HIGH']),
                    'consumption' => self::energy(4.5, 'ESTIMATED', 'DEGRADED', ['quality' => 'POOR', 'confidence_level' => 'VERY_LOW']),
                ]);

                Assert::same('PREDICTED', $data['result']['data_nature']);
                Assert::same('DEGRADED', $data['result']['data_status']);
                Assert::same('POOR', $data['result']['quality']);
                Assert::same('VERY_LOW', $data['result']['confidence_level']);
            }),

            TestResult::check('Invalid value_kwh is rejected with field context', function (): void {
                $controller = new EnergyCostController();

                $payload = static fn (mixed $value): array => [
                    'solar_generation' => ['value_kwh' => $value, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'pricing' => self::pricing(),
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $payload(-1.0))),
                    'solar_generation.value_kwh'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $payload('3.0'))),
                    'solar_generation.value_kwh'
                );

                $consumptionPayload = static fn (mixed $value): array => [
                    'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => ['value_kwh' => $value, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'pricing' => self::pricing(),
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $consumptionPayload(-2.5))),
                    'household_consumption.value_kwh'
                );
            }),

            TestResult::check('Invalid enums are rejected with field context', function (): void {
                $controller = new EnergyCostController();
                $base = static fn (array $consumption): array => [
                    'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => $consumption,
                    'pricing' => self::pricing(),
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $base(['value_kwh' => 4.5, 'data_nature' => 'MEASURED', 'data_status' => 'FRESH']))),
                    'household_consumption.data_nature'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $base(['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'UNAVAILABLE']))),
                    'household_consumption.data_status'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $base(['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH', 'quality' => 'PERFECT']))),
                    'household_consumption.quality'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $base(['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH', 'confidence_level' => 'CERTAIN']))),
                    'household_consumption.confidence_level'
                );
            }),

            TestResult::check('Invalid pricing basis is rejected', function (): void {
                $controller = new EnergyCostController();

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: [
                        'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'pricing' => self::pricing('CHEAPEST_GUESS'),
                    ])),
                    'pricing.basis'
                );
            }),

            TestResult::check('Invalid currency is rejected — currency is explicit data (INR in v1)', function (): void {
                $controller = new EnergyCostController();

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: [
                        'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'pricing' => self::pricing(currency: 'USD'),
                    ])),
                    'pricing.currency'
                );
            }),

            TestResult::check('Invalid or missing unit rate is rejected', function (): void {
                $controller = new EnergyCostController();
                $body = static fn (array $pricing): array => [
                    'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'pricing' => $pricing,
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['basis' => 'TARIFF_EVIDENCE', 'currency' => 'INR']))),
                    'pricing.unit_rate'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['basis' => 'TARIFF_EVIDENCE', 'unit_rate' => 0, 'currency' => 'INR']))),
                    'pricing.unit_rate'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['basis' => 'INSUFFICIENT_EVIDENCE', 'unit_rate' => 8.5, 'currency' => 'INR']))),
                    'pricing.unit_rate'
                );
            }),

            TestResult::check('Missing pricing information is rejected', function (): void {
                $controller = new EnergyCostController();

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: [
                        'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    ])),
                    'pricing'
                );
            }),

            TestResult::check('Invalid fixed charge is rejected', function (): void {
                $controller = new EnergyCostController();
                $body = static fn (array $fixedCharge): array => [
                    'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'pricing' => self::pricing(),
                    'fixed_charge' => $fixedCharge,
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['amount' => -5.0, 'currency' => 'INR']))),
                    'fixed_charge.amount'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['amount' => 150.0]))),
                    'fixed_charge.currency'
                );
            }),

            TestResult::check('Invalid period is rejected', function (): void {
                $controller = new EnergyCostController();
                $body = static fn (array $period): array => [
                    'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    'pricing' => self::pricing(),
                    'period' => $period,
                ];

                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['start' => 'not-a-date']))),
                    'period.start'
                );
                self::assertValidationField(
                    static fn (): mixed => $controller->estimate(Request::create('POST', '/api/v1/energy/cost/estimate', body: $body(['start' => '2026-09-08', 'end' => '2026-09-01']))),
                    'period.start'
                );
            }),

            TestResult::check('BALANCED within tolerance yields zero material deficit and zero variable cost', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(5.0),
                    'consumption' => self::energy(5.1),
                ]);

                Assert::same('BALANCED', $data['energy_position']['classification']);
                Assert::same(-0.1, $data['energy_position']['balance_kwh']);
                Assert::same(0.0, $data['energy_position']['grid_dependent_kwh']);
                Assert::same(0.0, $data['result']['estimated_variable_cost']);
                Assert::same(0.0, $data['result']['range']['low']);
                Assert::same(0.0, $data['result']['range']['high']);
                Assert::same(25.0, $data['result']['range']['half_width_percent']);
                Assert::true(str_contains($data['result']['explanation'], 'BALANCED within the disclosed tolerance'), 'Balanced explanation must state the tolerance semantics');
                Assert::true(str_contains($data['energy_position']['balanced_note'] ?? '', 'no material potential deficit'), 'Balanced position must disclose the residual treatment');
            }),

            TestResult::check('Inclusive tolerance boundary is balanced with zero cost', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(19.0),
                    'consumption' => self::energy(20.0),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ]);

                $tolerance = $data['energy_position']['tolerance']['applied_kwh'];
                Assert::same(1.0, $tolerance);
                Assert::same('BALANCED', $data['energy_position']['classification']);
                Assert::same(0.0, $data['energy_position']['grid_dependent_kwh']);
                Assert::same(0.0, $data['result']['estimated_variable_cost']);
            }),

            TestResult::check('Deficit just outside tolerance produces normal estimated cost', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(18.9),
                    'consumption' => self::energy(20.0),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ]);

                Assert::same('POTENTIAL_DEFICIT', $data['energy_position']['classification']);
                Assert::same(1.1, $data['energy_position']['grid_dependent_kwh']);
                Assert::same(9.35, $data['result']['estimated_variable_cost']);
            }),

            TestResult::check('Range constants remain the ratified v1 defaults (widest-wins factors)', function (): void {
                $ref = new \ReflectionClass(EnergyCostService::class);

                Assert::same(['HISTORICAL_BILL_RELATIONSHIP' => 15.0, 'USER_PROVIDED_RATE' => 25.0], $ref->getConstant('RANGE_FACTOR_BASIS'));
                Assert::same(['ESTIMATED' => 10.0, 'PREDICTED' => 15.0, 'SIMULATED' => 20.0], $ref->getConstant('RANGE_FACTOR_NATURE'));
                Assert::same(['LOW' => 25.0, 'VERY_LOW' => 40.0], $ref->getConstant('RANGE_FACTOR_CONFIDENCE'));
            }),

            TestResult::check('STALE status does not add a range factor and is not upgraded', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(1.2),
                    'consumption' => self::energy(4.8, 'ACTUAL', 'STALE'),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ]);

                Assert::same('STALE', $data['result']['data_status']);
                Assert::same(null, $data['result']['range']);
            }),

            TestResult::check('DEGRADED status does not add a range factor and is not upgraded', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(1.2),
                    'consumption' => self::energy(4.8, 'ACTUAL', 'DEGRADED'),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ]);

                Assert::same('DEGRADED', $data['result']['data_status']);
                Assert::same(null, $data['result']['range']);
            }),

            TestResult::check('POOR quality does not add a range factor and is not upgraded', function (): void {
                $data = self::estimate([
                    'solar' => self::energy(1.2),
                    'consumption' => self::energy(4.8, 'ACTUAL', 'FRESH', ['quality' => 'POOR']),
                    'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                ]);

                Assert::same('POOR', $data['result']['quality']);
                Assert::same(null, $data['result']['range']);
            }),

            TestResult::check('Valid period and household scope are echoed', function (): void {
                $data = (new EnergyCostService())->estimate(
                    [
                        'solar' => self::energy(2.0),
                        'consumption' => self::energy(4.5),
                        'pricing' => self::pricing(EnergyCostService::BASIS_TARIFF_EVIDENCE),
                    ],
                    ['start' => '2026-09-01', 'end' => '2026-09-30', 'resolution' => 'MONTH'],
                    'household-1'
                );

                Assert::same('2026-09-01', $data['analysis']['period']['start']);
                Assert::same('MONTH', $data['analysis']['period']['resolution']);
                Assert::same('household-1', $data['scope']['household_id']);
            }),

            TestResult::check('POTENTIAL_SURPLUS yields zero material deficit and zero variable cost with fixed charges separate', function (): void {
                $data = (new EnergyCostService())->estimate([
                    'solar' => self::energy(28.302, 'PREDICTED', 'FRESH', ['confidence_level' => 'LOW']),
                    'consumption' => self::energy(5.758, 'PREDICTED', 'FRESH', ['confidence_level' => 'LOW']),
                    'pricing' => self::pricing(EnergyCostService::BASIS_USER_PROVIDED_RATE, 7.50),
                    'fixed_charge' => ['amount' => 30.0, 'currency' => 'INR', 'label' => 'APSPDCL monthly fixed charge'],
                ], ['start' => '2026-09-26T00:00:00Z', 'end' => '2026-09-26T23:59:59Z'], 'real-hh-kodumur');

                Assert::same('POTENTIAL_SURPLUS', $data['energy_position']['classification']);
                Assert::same(22.544, $data['energy_position']['balance_kwh']);
                Assert::same(0.0, $data['energy_position']['grid_dependent_kwh']);
                Assert::same(0.0, $data['result']['estimated_variable_cost']);
                Assert::same(0.0, $data['result']['range']['low']);
                Assert::same(0.0, $data['result']['range']['high']);
                Assert::same(25.0, $data['result']['range']['half_width_percent']);
                Assert::same(30.0, $data['result']['fixed_charge']['amount']);
                Assert::same('SEPARATE_NOT_INCLUDED', $data['result']['fixed_charge']['treatment']);
                Assert::same('PREDICTED', $data['result']['data_nature']);
                Assert::same(EnergyCostService::MEASUREMENT_CONTEXT_ESTIMATED, $data['result']['measurement_context']);
            }),

            TestResult::check('Cost estimate bounds are strictly non-negative', function (): void {
                $data = (new EnergyCostService())->estimate([
                    'solar' => self::energy(1.0, 'PREDICTED', 'FRESH', ['confidence_level' => 'LOW']),
                    'consumption' => self::energy(5.0, 'PREDICTED', 'FRESH', ['confidence_level' => 'LOW']),
                    'pricing' => self::pricing(EnergyCostService::BASIS_USER_PROVIDED_RATE, 7.50),
                ]);

                Assert::true($data['result']['estimated_variable_cost'] >= 0.0);
                Assert::true($data['result']['range']['low'] >= 0.0);
                Assert::true($data['result']['range']['high'] >= 0.0);
            }),
        ];
    }
}
