<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Forecast\ForecastConfidence;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastSnapshot;
use App\Domain\Household\Household;
use App\Repositories\ForecastRepository;
use App\Repositories\HouseholdRepository;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\TestResult;

final class ForecastPersistenceTest
{
    public static function run(): array
    {
        return [
            TestResult::check('household_forecasts table exists with correct columns', function (): void {
                $pdo = Database::connection();
                $stmt = $pdo->query('SHOW TABLES LIKE "household_forecasts"');
                Assert::same('household_forecasts', $stmt->fetchColumn(), 'household_forecasts table must exist');

                $colStmt = $pdo->query('DESCRIBE household_forecasts');
                $columns = $colStmt->fetchAll(\PDO::FETCH_COLUMN);

                Assert::true(in_array('id', $columns, true));
                Assert::true(in_array('household_id', $columns, true));
                Assert::true(in_array('forecast_type', $columns, true));
                Assert::true(in_array('horizon', $columns, true));
                Assert::true(in_array('predicted_value', $columns, true));
                Assert::true(in_array('uncertainty_lower', $columns, true));
                Assert::true(in_array('uncertainty_upper', $columns, true));
                Assert::true(in_array('confidence', $columns, true));
                Assert::true(in_array('data_nature', $columns, true));
                Assert::true(in_array('data_quality', $columns, true));
                Assert::true(in_array('points_json', $columns, true));
                Assert::true(in_array('limitations_json', $columns, true));
                Assert::true(in_array('provenance_json', $columns, true));
            }),

            TestResult::check('ForecastRepository saves and queries latest snapshot by household and horizon', function (): void {
                $pdo = Database::connection();
                $households = new HouseholdRepository($pdo);
                $forecasts = new ForecastRepository($pdo);

                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $household = Household::create('Forecast Test House', 'Asia/Kolkata', 'Chennai', $now);
                $households->insert($household);
                $hhId = $household->id();

                $forecastId = self::uuid();
                $forecastData = [
                    'id' => $forecastId,
                    'household_id' => $hhId,
                    'forecast_type' => 'COMBINED',
                    'horizon' => ForecastHorizon::DAY_AHEAD,
                    'forecast_start' => '2026-09-22 00:00:00',
                    'forecast_end' => '2026-09-22 23:59:59',
                    'resolution' => 'HOURLY',
                    'predicted_value' => 24.500,
                    'uncertainty_lower' => 19.600,
                    'uncertainty_upper' => 29.400,
                    'confidence' => ForecastConfidence::MODERATE,
                    'data_nature' => ForecastSnapshot::NATURE_PREDICTED,
                    'data_quality' => ForecastSnapshot::QUALITY_GOOD,
                    'points_json' => json_encode(['points' => [['hour' => 12, 'w' => 3500]]]),
                    'limitations_json' => json_encode(['Sample limitation']),
                    'provenance_json' => json_encode(['model' => 'v1']),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ];

                $forecasts->save($forecastData);

                $latest = $forecasts->findLatest($hhId, 'COMBINED', ForecastHorizon::DAY_AHEAD);
                Assert::true($latest !== null, 'Must find latest saved forecast');
                Assert::same($forecastId, $latest['id']);
                Assert::same('24.500', $latest['predicted_value']);
                Assert::same('19.600', $latest['uncertainty_lower']);
                Assert::same('29.400', $latest['uncertainty_upper']);
                Assert::same('MODERATE', $latest['confidence']);
                Assert::same('PREDICTED', $latest['data_nature']);

                // Verify isolation: query with foreign household returns null
                $foreign = $forecasts->findLatest(self::uuid(), 'COMBINED', ForecastHorizon::DAY_AHEAD);
                Assert::true($foreign === null, 'Foreign household must not see another household forecast');
            }),
        ];
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
