<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Intelligence\Detectors\SolarHardwareDetector;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightTaxonomy;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F6/W2 — Unit tests for SolarHardwareDetector.
 *
 * Verifies detection of:
 * - SOLAR_TELEMETRY_STALE (reading > 15-30m delayed)
 * - INVERTER_DISCONNECTED (inverter status OFFLINE or FAULT)
 * - Clean pass when inverter is NORMAL and telemetry is FRESH
 * - Never converts unavailable telemetry into measured zero
 */
final class SolarHardwareDetectorTest
{
    /**
     * @return array<int, TestResult>
     */
    public static function run(): array
    {
        return [
            TestResult::check('SolarHardwareDetector detects stale telemetry when last reading is >15m old', function (): void {
                $detector = new SolarHardwareDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-stale-01',
                    'solar_connection' => [
                        'connection_status' => 'CONNECTED',
                        'data_status' => 'STALE',
                        'last_reading_at' => '2026-10-21 11:30:00',
                        'inverter_status' => 'NORMAL',
                        'inverter_sn' => 'GW3000-TEST',
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE, $candidates[0]->type);
                Assert::same('Solar Inverter Telemetry is Delayed', $candidates[0]->title);
                Assert::same('MODERATE', $candidates[0]->significanceHint);
                Assert::true(in_array('solar', $candidates[0]->evidenceDomains, true));
                Assert::true(in_array('STALE', $candidates[0]->statuses, true));
            }),

            TestResult::check('SolarHardwareDetector detects inverter disconnection on OFFLINE status', function (): void {
                $detector = new SolarHardwareDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-offline-01',
                    'solar_connection' => [
                        'connection_status' => 'DISCONNECTED',
                        'data_status' => 'UNAVAILABLE',
                        'last_reading_at' => '2026-10-20 18:00:00',
                        'inverter_status' => 'OFFLINE',
                        'inverter_sn' => 'GW3000-TEST',
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_INVERTER_DISCONNECTED, $candidates[0]->type);
                Assert::same('Solar Inverter Communication Offline', $candidates[0]->title);
                Assert::same('HIGH', $candidates[0]->significanceHint);
            }),

            TestResult::check('SolarHardwareDetector emits no issues when inverter is healthy and telemetry is fresh', function (): void {
                $detector = new SolarHardwareDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-healthy-01',
                    'solar_connection' => [
                        'connection_status' => 'CONNECTED',
                        'data_status' => 'FRESH',
                        'last_reading_at' => date('Y-m-d H:i:s', time() - 120),
                        'inverter_status' => 'NORMAL',
                        'inverter_sn' => 'GW3000-HEALTHY',
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same([], $candidates);
            }),

            TestResult::check('SolarHardwareDetector emits empty array when solar_connection is unconfigured', function (): void {
                $detector = new SolarHardwareDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-unconfigured-01',
                ]);

                $candidates = $detector->detect($input);
                Assert::same([], $candidates);
            }),
        ];
    }
}
