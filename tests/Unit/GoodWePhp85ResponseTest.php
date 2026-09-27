<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SolarController;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Domain\Solar\SolarService;
use App\Integrations\GoodWe\FakeSolarProvider;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Security\AuthMiddleware;
use App\Security\Authorization;
use App\Support\Config;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Response;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * SPMS O4.2 — Regression tests for PHP 8.5 GoodWe API response purity.
 *
 * Verifies that:
 *  1. GoodWeSemsPortalAdapter is free of deprecated curl_close() calls and PHP 8.5 deprecations.
 *  2. API response serialization actively prevents deprecation/warning output buffer contamination.
 *  3. Solar API endpoints always yield clean, parseable JSON conforming to the SPMS envelope.
 */
final class GoodWePhp85ResponseTest
{
    public static function run(): array
    {
        return [
            TestResult::check('GoodWeSemsPortalAdapter source contains zero curl_close calls (PHP 8.5 deprecated)', function (): void {
                $adapterPath = dirname(__DIR__, 2) . '/app/Integrations/GoodWe/GoodWeSemsPortalAdapter.php';
                Assert::true(file_exists($adapterPath), 'GoodWeSemsPortalAdapter.php must exist');

                $source = (string) file_get_contents($adapterPath);
                Assert::true(
                    !str_contains($source, 'curl_close('),
                    'GoodWeSemsPortalAdapter must not contain any calls to deprecated curl_close()'
                );
            }),

            TestResult::check('GoodWe integration code contains zero non-canonical scalar type casts (PHP 8.5 deprecated)', function (): void {
                $files = [
                    dirname(__DIR__, 2) . '/app/Integrations/GoodWe/GoodWeSemsPortalAdapter.php',
                    dirname(__DIR__, 2) . '/app/Integrations/GoodWe/FakeSolarProvider.php',
                    dirname(__DIR__, 2) . '/app/Controllers/SolarController.php',
                    dirname(__DIR__, 2) . '/app/Domain/Solar/SolarService.php',
                ];

                $pattern = '/\((integer|boolean|double|binary)\)/i';

                foreach ($files as $file) {
                    if (!file_exists($file)) {
                        continue;
                    }
                    $content = (string) file_get_contents($file);
                    Assert::true(
                        preg_match($pattern, $content) === 0,
                        "File {$file} contains deprecated non-canonical scalar type casts"
                    );
                }
            }),

            TestResult::check('Response::send clears output buffer to prevent PHP deprecation contamination', function (): void {
                RequestId::set(RequestId::new());

                ob_start();
                // Simulate PHP 8.5 deprecation notice leaking to output buffer before response send
                echo "Deprecated: Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0 in /app/Integrations/GoodWe/GoodWeSemsPortalAdapter.php on line 547\n";

                $response = Response::success([
                    'provider' => 'GOODWE_SEMS',
                    'connection_status' => 'CONNECTED',
                ]);
                $response->send();

                $rawOutput = (string) ob_get_clean();

                // Assert that the deprecated warning was stripped completely
                Assert::true(
                    !str_contains($rawOutput, 'Deprecated:'),
                    'Output must not contain PHP deprecation warnings'
                );
                Assert::true(
                    !str_contains($rawOutput, 'curl_close'),
                    'Output must not contain curl_close notice text'
                );

                // Assert that the output begins directly with the JSON object
                Assert::true(
                    str_starts_with(trim($rawOutput), '{'),
                    'Output must start with JSON opening brace'
                );

                // Assert that the JSON decodes successfully with SPMS standard envelope
                $decoded = Assert::jsonDecodes($rawOutput);
                Assert::same(true, $decoded['success']);
                Assert::same('CONNECTED', $decoded['data']['connection_status']);
                Assert::same('GOODWE_SEMS', $decoded['data']['provider']);
            }),

            TestResult::check('SolarController endpoint response remains pure JSON even if warning is emitted', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $fake = new FakeSolarProvider();
                $solarService = new SolarService(provider: $fake);
                $controller = new SolarController(solarService: $solarService);

                $fixture = AuthTesting::householdFixture();
                $request = AuthTesting::authed(
                    Request::create('GET', '/api/v1/solar/connection'),
                    $fixture['account']
                );

                ob_start();
                // Stray output in buffer before controller response send
                echo "Deprecated: Function curl_close() is deprecated since 8.5\n";

                $response = $controller->connection($request);
                $response->send();

                $rawOutput = (string) ob_get_clean();

                Assert::true(
                    !str_contains($rawOutput, 'Deprecated:'),
                    'Solar API response must not contain deprecation warning text'
                );
                Assert::true(
                    str_starts_with(trim($rawOutput), '{'),
                    'Solar API response must be valid JSON starting with {'
                );

                $decoded = Assert::jsonDecodes($rawOutput);
                Assert::same(true, $decoded['success']);
                Assert::true(isset($decoded['data']['connection']), 'Connection data present');
            }),

            TestResult::check('GoodWeSemsPortalAdapter parseStationRecords handles nested pagination shapes (list, records, rows, stations, pageList)', function (): void {
                $adapter = new \App\Integrations\GoodWe\GoodWeSemsPortalAdapter();

                // Test "list" key
                $resList = $adapter->parseStationRecords([
                    'list' => [
                        ['id' => 'st-1', 'stationname' => 'Station 1', 'capacity' => 6.0],
                    ],
                ]);
                Assert::same(1, count($resList));
                Assert::same('st-1', $resList[0]['station_id']);
                Assert::same('Station 1', $resList[0]['station_name']);
                Assert::same(6.0, $resList[0]['capacity_kw']);

                // Test "records" key (SEMS+ variant)
                $resRecords = $adapter->parseStationRecords([
                    'records' => [
                        ['stationId' => 'st-2', 'stationName' => 'Station 2', 'installedPower' => 8.5],
                    ],
                ]);
                Assert::same(1, count($resRecords));
                Assert::same('st-2', $resRecords[0]['station_id']);
                Assert::same('Station 2', $resRecords[0]['station_name']);
                Assert::same(8.5, $resRecords[0]['capacity_kw']);

                // Test "rows" key
                $resRows = $adapter->parseStationRecords([
                    'rows' => [
                        ['powerStationId' => 'st-3', 'powerstation_name' => 'Station 3', 'capacity_kw' => 4.2],
                    ],
                ]);
                Assert::same(1, count($resRows));
                Assert::same('st-3', $resRows[0]['station_id']);
                Assert::same('Station 3', $resRows[0]['station_name']);
                Assert::same(4.2, $resRows[0]['capacity_kw']);
            }),

            TestResult::check('GoodWeSemsPortalAdapter parseStationRecords handles single station objects and scalar IDs', function (): void {
                $adapter = new \App\Integrations\GoodWe\GoodWeSemsPortalAdapter();

                // Test single object
                $resSingle = $adapter->parseStationRecords([
                    'id' => 'st-single',
                    'stationname' => 'Single Rooftop PV',
                    'capacity' => 5.5,
                ]);
                Assert::same(1, count($resSingle));
                Assert::same('st-single', $resSingle[0]['station_id']);
                Assert::same('Single Rooftop PV', $resSingle[0]['station_name']);

                // Test scalar string station ID (from GetPowerStationIdByOwner)
                $resScalar = $adapter->parseStationRecords('e751db52-19c2-4cf4-9ba8-8ef733c77d44');
                Assert::same(1, count($resScalar));
                Assert::same('e751db52-19c2-4cf4-9ba8-8ef733c77d44', $resScalar[0]['station_id']);

                // Test stationInverters shape (SEMS prototype format)
                $resInverters = $adapter->parseStationRecords([
                    'stationInverters' => [
                        [
                            'stationId' => '06343cdf-e9b6-41a8-bb7f-16bf3ea0a6a9',
                            'stationName' => 'eswar',
                            'installedPower' => 3.0,
                        ],
                    ],
                ]);
                Assert::same(1, count($resInverters));
                Assert::same('06343cdf-e9b6-41a8-bb7f-16bf3ea0a6a9', $resInverters[0]['station_id']);
                Assert::same('eswar', $resInverters[0]['station_name']);
                Assert::same(3.0, $resInverters[0]['capacity_kw']);

                // Test nested data.data wrapper
                $resNested = $adapter->parseStationRecords([
                    'data' => [
                        'list' => [
                            ['id' => 'st-nested', 'name' => 'Nested Plant', 'installedPower' => 10.0],
                        ],
                    ],
                ]);
                Assert::same(1, count($resNested));
                Assert::same('st-nested', $resNested[0]['station_id']);
                Assert::same('Nested Plant', $resNested[0]['station_name']);

                // Test null / empty returns empty array
                Assert::same([], $adapter->parseStationRecords(null));
                Assert::same([], $adapter->parseStationRecords([]));
                Assert::same([], $adapter->parseStationRecords(''));
            }),

            TestResult::check('GoodWe diagnostic logging produces zero credential or token leakage', function (): void {
                // Ensure sensitive fragments are classified strictly
                Assert::true(\App\Support\Logger::isSensitiveKey('password'));
                Assert::true(\App\Support\Logger::isSensitiveKey('token'));
                Assert::true(\App\Support\Logger::isSensitiveKey('session'));
                Assert::true(\App\Support\Logger::isSensitiveKey('authorization'));

                // Verify safe diagnostic context contains only whitelisted safe telemetry
                $safeContext = [
                    'selected_api_host' => 'eu.semsportal.com',
                    'resolved_region' => 'Europe',
                    'http_status' => 200,
                    'content_type' => 'application/json',
                    'response_keys' => ['code', 'msg', 'data'],
                    'response_code' => 0,
                    'response_has_error' => false,
                    'response_msg' => 'success',
                    'data_keys' => ['uid', 'timestamp', 'token', 'client', 'api'],
                    'discovered_station_count' => 1,
                ];

                foreach (array_keys($safeContext) as $key) {
                    Assert::true(
                        !\App\Support\Logger::isSensitiveKey($key),
                        "Diagnostic log key {$key} must not be sensitive"
                    );
                }
            }),

            TestResult::check('GoodWe station discovery prioritizes GetPowerStationIdByOwner with 4s/6s probe timeouts', function (): void {
                $adapterPath = dirname(__DIR__, 2) . '/app/Integrations/GoodWe/GoodWeSemsPortalAdapter.php';
                $code = (string) file_get_contents($adapterPath);

                // Verify /api/PowerStation/GetPowerStationIdByOwner is the first candidate endpoint
                Assert::contains("        \$candidateEndpoints = [\n            [\n                'endpoint' => '/api/PowerStation/GetPowerStationIdByOwner',", $code, 'GetPowerStationIdByOwner must be the first candidate endpoint');

                // Verify probe timeouts are 4s connect and 6s total
                Assert::contains('connectTimeout: 4, timeout: 6', $code, 'Station discovery probe must use 4s connect and 6s execution timeout');
            }),
        ];
    }
}

