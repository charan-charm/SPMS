<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

use App\Support\Config;

Config::initialize($rootPath);

$db = \App\Support\Database::connection();
$hid = '68a5374d-10f0-49a0-b845-03f4acbd5fa8';

echo "--- household_forecasts (latest 10) ---\n";
$stmt = $db->query("SELECT id, horizon, forecast_type, forecast_start, forecast_end, predicted_value, data_nature, created_at FROM household_forecasts WHERE household_id = '{$hid}' ORDER BY created_at DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

echo "\n--- forecast_validations for 2026-10-05 ---\n";
$stmt = $db->query("SELECT id, target_date, predicted_generation_kwh, actual_generation_kwh, accuracy_percent, daylight_hours_validated, validation_status, created_at FROM forecast_validations WHERE household_id = '{$hid}' AND target_date = '2026-10-05'");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

echo "\n--- ForecastService::getForecast DAY_AHEAD ---\n";
$fs = new \App\Domain\Forecast\ForecastService();
$res = $fs->getForecast($hid, \App\Domain\Forecast\ForecastHorizon::DAY_AHEAD, null, false);
echo "ForecastService DAY_AHEAD predicted_value: " . ($res['solar_forecast']['predicted_value'] ?? 'null') . "\n";
echo "ForecastService DAY_AHEAD forecast_id: " . ($res['solar_forecast']['forecast_id'] ?? 'null') . "\n";
echo "ForecastService DAY_AHEAD period: " . json_encode($res['solar_forecast']['period'] ?? []) . "\n";
echo "ForecastService DAY_AHEAD created_at: " . ($res['solar_forecast']['created_at'] ?? 'null') . "\n";

echo "\n--- SolarService::getDailySummaries ---\n";
$solarSvc = new \App\Domain\Solar\SolarService();
$days = $solarSvc->getDailySummaries($hid);
echo "Days count: " . count($days) . "\n";
echo "First 3 days: " . json_encode(array_slice($days, 0, 3), JSON_PRETTY_PRINT) . "\n";

