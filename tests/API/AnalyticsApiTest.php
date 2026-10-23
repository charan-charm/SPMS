<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\AnalyticsController;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * F10 — Analytics API Tests.
 *
 * Tests cover:
 * 1. Authentication guard (both endpoints)
 * 2. Household-without-data returns empty datasets (no fabrication)
 * 3. Solar-only household: bill data UNAVAILABLE
 * 4. Bills-only household: solar data UNAVAILABLE
 * 5. Mixed household: overlap computed correctly with correct data_nature
 * 6. Household isolation: cannot see another household's data
 * 7. Date range filtering respects from_month / to_month params
 * 8. Summary endpoint returns correct availability flags
 */
final class AnalyticsApiTest
{
    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function controller(): AnalyticsController
    {
        AuthTesting::configure();
        return new AnalyticsController();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Insert a minimal VERIFIED bill for a household with a specific billing period.
     */
    private static function insertVerifiedBill(
        PDO $pdo,
        string $householdId,
        string $periodStart,
        string $periodEnd,
        float $consumptionKwh,
        float $billAmount
    ): string {
        $id  = self::uuid();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pdo->prepare(<<<'SQL'
            INSERT INTO bills (
                id, household_id, status,
                billing_period_start, billing_period_end,
                consumption_kwh, bill_amount,
                source,
                verified_at, created_at, updated_at
            ) VALUES (
                :id, :hid, :status,
                :ps, :pe,
                :ckwh, :ba,
                :src,
                :verified_at, :created_at, :updated_at
            )
        SQL)->execute([
            'id'          => $id,
            'hid'         => $householdId,
            'status'      => BillStatus::VERIFIED,
            'ps'          => $periodStart,
            'pe'          => $periodEnd,
            'ckwh'        => number_format($consumptionKwh, 3, '.', ''),
            'ba'          => number_format($billAmount, 2, '.', ''),
            'src'         => 'ELECTRICITY_BILL',
            'verified_at' => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return $id;
    }

    /**
     * Insert a minimal solar_reading with daily_generation_kwh for a specific date.
     */
    private static function insertSolarReading(
        PDO $pdo,
        string $householdId,
        string $measurementTime,
        float $dailyGenerationKwh,
        float $powerW = 0.0,
        string $dataNature = 'ACTUAL'
    ): void {
        $id  = self::uuid();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pdo->prepare(<<<'SQL'
            INSERT INTO solar_readings (
                id, household_id, station_id, inverter_sn,
                measurement_time, received_at,
                power_w, daily_generation_kwh,
                inverter_status, data_nature, data_status, data_quality,
                sync_batch_id, created_at
            ) VALUES (
                :id, :hid, :station_id, :inverter_sn,
                :mt, :ra,
                :pw, :dg,
                'Normal', :nature, 'FRESH', 'GOOD',
                :batch, :created_at
            )
            ON DUPLICATE KEY UPDATE daily_generation_kwh = VALUES(daily_generation_kwh), power_w = VALUES(power_w), data_nature = VALUES(data_nature)
        SQL)->execute([
            'id'          => $id,
            'hid'         => $householdId,
            'station_id'  => 'test-station-01',
            'inverter_sn' => 'SN-TEST-001',
            'mt'          => $measurementTime,
            'ra'          => $now,
            'pw'          => number_format($powerW, 2, '.', ''),
            'dg'          => number_format($dailyGenerationKwh, 3, '.', ''),
            'nature'      => $dataNature,
            'batch'       => 'test-batch-' . bin2hex(random_bytes(4)),
            'created_at'  => $now,
        ]);
    }

    /**
     * Clean up test bills and solar_readings for a household.
     */
    private static function cleanup(PDO $pdo, string $householdId): void
    {
        $pdo->prepare('DELETE FROM bills WHERE household_id = :hid')->execute(['hid' => $householdId]);
        $pdo->prepare('DELETE FROM solar_readings WHERE household_id = :hid')->execute(['hid' => $householdId]);
    }

    // -------------------------------------------------------------------------

    public static function run(): array
    {
        return [

            TestResult::check('GET /api/v1/analytics/monthly requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->monthly(Request::create('GET', '/api/v1/analytics/monthly'));
                    Assert::true(false, 'Expected UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/analytics/summary requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->summary(Request::create('GET', '/api/v1/analytics/summary'));
                    Assert::true(false, 'Expected UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('Account without household returns 404 on analytics', function (): void {
                RequestId::set(RequestId::new());
                $orphan = AuthTesting::activateAccount('orphan-ana-');
                $req    = AuthTesting::authed(Request::create('GET', '/api/v1/analytics/monthly'), $orphan);

                try {
                    self::controller()->monthly($req);
                    Assert::true(false, 'Expected HOUSEHOLD_NOT_FOUND');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('Empty household: monthly returns all UNAVAILABLE points, no fabrication', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-01&to_month=2026-03'),
                        $fixture['account']
                    );
                    $res = self::controller()->monthly($req);

                    Assert::same(200, $res->status());
                    $data = $res->data();

                    Assert::true(isset($data['points']), 'points key must be present');
                    Assert::true(isset($data['summary']), 'summary key must be present');

                    // Verify no fabricated values exist
                    foreach ($data['points'] as $point) {
                        Assert::same('UNAVAILABLE', $point['solar_nature'], 'empty solar must be UNAVAILABLE');
                        Assert::same('UNAVAILABLE', $point['bill_nature'],  'empty bills must be UNAVAILABLE');
                        Assert::same(null,          $point['solar_kwh'],    'solar_kwh must be null when no data');
                        Assert::same(null,          $point['consumption_kwh'], 'consumption_kwh must be null when no data');
                        Assert::same(null,          $point['net_kwh'],      'net_kwh must be null when no data');
                    }

                    $summary = $data['summary'];
                    Assert::same(0, $summary['solar_months_with_data']);
                    Assert::same(0, $summary['bill_months_with_data']);
                    Assert::same(null, $summary['total_solar_kwh']);
                    Assert::same(null, $summary['total_consumption_kwh']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Solar-only household: bill columns remain UNAVAILABLE, solar is ACTUAL', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    // Insert 5 kWh solar across 2 days in 2026-06
                    self::insertSolarReading($pdo, $hid, '2026-06-10 10:00:00', 5.0);
                    self::insertSolarReading($pdo, $hid, '2026-06-10 14:00:00', 5.0); // same day — deduplicated by MAX
                    self::insertSolarReading($pdo, $hid, '2026-06-11 10:00:00', 3.5);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-06&to_month=2026-06'),
                        $fixture['account']
                    );
                    $res  = self::controller()->monthly($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());

                    $point = null;
                    foreach ($data['points'] as $p) {
                        if ($p['month'] === '2026-06') {
                            $point = $p;
                            break;
                        }
                    }

                    Assert::true($point !== null, '2026-06 point must exist');
                    Assert::same('ACTUAL', $point['solar_nature']);
                    Assert::same(8.5, round((float) $point['solar_kwh'], 1));
                    Assert::same('UNAVAILABLE', $point['bill_nature']);
                    Assert::same(null, $point['consumption_kwh']);
                    Assert::same(null, $point['net_kwh']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Bills-only household: solar columns remain UNAVAILABLE, bill is VERIFIED', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    self::insertVerifiedBill($pdo, $hid, '2026-05-01', '2026-05-31', 200.0, 1500.0);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-05&to_month=2026-05'),
                        $fixture['account']
                    );
                    $res  = self::controller()->monthly($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());

                    $point = null;
                    foreach ($data['points'] as $p) {
                        if ($p['month'] === '2026-05') {
                            $point = $p;
                            break;
                        }
                    }

                    Assert::true($point !== null, '2026-05 point must exist');
                    Assert::same('UNAVAILABLE', $point['solar_nature']);
                    Assert::same(null, $point['solar_kwh']);
                    Assert::same('VERIFIED', $point['bill_nature']);
                    Assert::same(200.0, (float) $point['consumption_kwh']);
                    Assert::same(null, $point['net_kwh']); // no solar → no net
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Overlap month: net_kwh computed, data_nature set correctly', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    // 2026-07: 10 kWh solar, 150 kWh consumed — net = −140 kWh
                    self::insertSolarReading($pdo, $hid, '2026-07-15 12:00:00', 10.0);
                    self::insertVerifiedBill($pdo, $hid, '2026-07-01', '2026-07-31', 150.0, 1200.0);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-07&to_month=2026-07'),
                        $fixture['account']
                    );
                    $res  = self::controller()->monthly($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());

                    $point = null;
                    foreach ($data['points'] as $p) {
                        if ($p['month'] === '2026-07') {
                            $point = $p;
                            break;
                        }
                    }

                    Assert::true($point !== null, '2026-07 point must exist');
                    Assert::same('ACTUAL',   $point['solar_nature']);
                    Assert::same('VERIFIED', $point['bill_nature']);
                    Assert::same(10.0, round((float) $point['solar_kwh'], 1));
                    Assert::same(150.0, (float) $point['consumption_kwh']);

                    // net = 10 − 150 = −140
                    Assert::true(abs((float) $point['net_kwh'] - (-140.0)) < 0.01, 'net_kwh must be −140.0');
                    Assert::same('ACTUAL', $point['net_nature']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Household isolation: household B cannot see household A data', function (): void {
                RequestId::set(RequestId::new());
                $pdo      = self::pdo();
                $fixtureA = AuthTesting::householdFixture();
                $hidA     = $fixtureA['household_id'];
                $fixtureB = AuthTesting::householdFixture();
                $hidB     = $fixtureB['household_id'];

                try {
                    self::cleanup($pdo, $hidA);
                    self::cleanup($pdo, $hidB);

                    // A has solar; B has nothing
                    self::insertSolarReading($pdo, $hidA, '2026-04-10 12:00:00', 20.0);

                    // Query as household B
                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-04&to_month=2026-04'),
                        $fixtureB['account']
                    );
                    $res  = self::controller()->monthly($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());

                    foreach ($data['points'] as $point) {
                        Assert::same(null, $point['solar_kwh'], 'B must not see A solar data');
                    }
                } finally {
                    self::cleanup($pdo, $hidA);
                    self::cleanup($pdo, $hidB);
                }
            }),

            TestResult::check('Summary endpoint reflects actual data availability', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/summary'),
                        $fixture['account']
                    );

                    // Empty state
                    $res  = self::controller()->summary($req);
                    $data = $res->data();
                    Assert::same(200, $res->status());
                    Assert::same(false, $data['has_solar_data'], 'empty household must have no solar data');
                    Assert::same(false, $data['has_bill_data'],  'empty household must have no bill data');

                    // Add data
                    self::insertSolarReading($pdo, $hid, '2026-03-05 10:00:00', 7.0);
                    self::insertVerifiedBill($pdo, $hid, '2026-03-01', '2026-03-31', 100.0, 800.0);

                    $res2  = self::controller()->summary($req);
                    $data2 = $res2->data();
                    Assert::same(true,  $data2['has_solar_data']);
                    Assert::same(true,  $data2['has_bill_data']);
                    Assert::same('2026-03', $data2['solar']['first_month']);
                    Assert::same('2026-03', $data2['bills']['first_month']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('GET /api/v1/analytics/daily requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->daily(Request::create('GET', '/api/v1/analytics/daily'));
                    Assert::true(false, 'Expected UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/analytics/day-curve requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->dayCurve(Request::create('GET', '/api/v1/analytics/day-curve?date=2026-01-30'));
                    Assert::true(false, 'Expected UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('Daily solar aggregation computes daily max, peak power, and detects gaps without fabricating days', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    // Day 1: normal day (2 readings, 5 min apart)
                    self::insertSolarReading($pdo, $hid, '2026-05-10 10:00:00', 5.2, 1200.0);
                    self::insertSolarReading($pdo, $hid, '2026-05-10 10:05:00', 8.5, 2100.0);

                    // Day 2: gap day (10:00 to 14:00 gap)
                    self::insertSolarReading($pdo, $hid, '2026-05-11 10:00:00', 4.0, 1500.0);
                    self::insertSolarReading($pdo, $hid, '2026-05-11 14:00:00', 12.0, 2400.0);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/daily?range=all'),
                        $fixture['account']
                    );
                    $res  = self::controller()->daily($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());
                    Assert::true(isset($data['points']), 'points must be present');
                    Assert::same(2, count($data['points']), 'exactly 2 days with data');

                    $p1 = $data['points'][0];
                    Assert::same('2026-05-10', $p1['date']);
                    Assert::same(8.5, $p1['solar_kwh']);
                    Assert::same(2100.0, (float)$p1['peak_power_w']);
                    Assert::same('ACTUAL', $p1['data_nature']);
                    Assert::same(false, $p1['has_gap']);

                    $p2 = $data['points'][1];
                    Assert::same('2026-05-11', $p2['date']);
                    Assert::same(12.0, $p2['solar_kwh']);
                    Assert::same(2400.0, (float)$p2['peak_power_w']);
                    Assert::same(true, $p2['has_gap']);
                    Assert::same(true, $p2['gap_warning'] !== null);

                    $summary = $data['summary'];
                    Assert::same(2, $summary['days_with_data']);
                    Assert::same(20.5, $summary['total_kwh']);
                    Assert::same(2400.0, (float)$summary['peak_power_w']);
                    Assert::same(1, $summary['days_with_gaps']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Day-curve retrieves 5-minute readings and discloses gaps honestly', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    // Day with readings and exactly 1 4-hour gap (10:00 and 10:05 are contiguous, then 14:05)
                    self::insertSolarReading($pdo, $hid, '2026-01-30 10:00:00', 3.0, 1500.0);
                    self::insertSolarReading($pdo, $hid, '2026-01-30 10:05:00', 3.8, 1650.0);
                    self::insertSolarReading($pdo, $hid, '2026-01-30 14:05:00', 8.0, 1800.0);

                    $req = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/day-curve?date=2026-01-30'),
                        $fixture['account']
                    );
                    $res  = self::controller()->dayCurve($req);
                    $data = $res->data();

                    Assert::same(200, $res->status());
                    Assert::same('2026-01-30', $data['date']);
                    Assert::same(3, $data['metrics']['reading_count']);
                    Assert::same(8.0, $data['metrics']['daily_kwh']);
                    Assert::same(1800.0, (float)$data['metrics']['peak_power_w']);
                    Assert::same(true, $data['metrics']['has_gap']);
                    Assert::same(1, $data['metrics']['gap_count']);

                    // Verify readings
                    Assert::same(3, count($data['readings']));
                    Assert::same('10:00', $data['readings'][0]['time']);
                    Assert::same(1.5, $data['readings'][0]['power_kw']);
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

            TestResult::check('Analytics summary, monthly, daily, and day-curve return SIMULATED data_nature for simulated telemetry', function (): void {
                RequestId::set(RequestId::new());
                $pdo     = self::pdo();
                $fixture = AuthTesting::householdFixture();
                $hid     = $fixture['household_id'];

                try {
                    self::cleanup($pdo, $hid);

                    // Insert simulated readings
                    self::insertSolarReading($pdo, $hid, '2026-06-15 12:00:00', 14.2, 2800.0, 'SIMULATED');

                    // 1. Summary
                    $reqSummary = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/summary'),
                        $fixture['account']
                    );
                    $resSummary = self::controller()->summary($reqSummary);
                    $dataSummary = $resSummary->data();
                    Assert::same('SIMULATED', $dataSummary['solar']['data_nature'], 'Summary solar must be SIMULATED');

                    // 2. Monthly
                    $reqMonthly = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/monthly?from_month=2026-06&to_month=2026-06'),
                        $fixture['account']
                    );
                    $resMonthly = self::controller()->monthly($reqMonthly);
                    $dataMonthly = $resMonthly->data();
                    Assert::same('SIMULATED', $dataMonthly['points'][0]['solar_nature'], 'Monthly point solar_nature must be SIMULATED');

                    // 3. Daily
                    $reqDaily = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/daily?range=all'),
                        $fixture['account']
                    );
                    $resDaily = self::controller()->daily($reqDaily);
                    $dataDaily = $resDaily->data();
                    Assert::same('SIMULATED', $dataDaily['points'][0]['data_nature'], 'Daily point data_nature must be SIMULATED');

                    // 4. Day-curve
                    $reqCurve = AuthTesting::authed(
                        Request::create('GET', '/api/v1/analytics/day-curve?date=2026-06-15'),
                        $fixture['account']
                    );
                    $resCurve = self::controller()->dayCurve($reqCurve);
                    $dataCurve = $resCurve->data();
                    Assert::same('SIMULATED', $dataCurve['metrics']['data_nature'], 'Day-curve metrics data_nature must be SIMULATED');
                } finally {
                    self::cleanup($pdo, $hid);
                }
            }),

        ];
    }
}

