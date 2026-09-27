<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Solar\DTO\SolarCredentials;
use App\Integrations\GoodWe\GoodWeSemsPortalAdapter;
use App\Support\Logger;
use Tests\Support\Assert;
use Tests\TestResult;

final class GoodWeModernInverterDiscoveryTest
{
    public static function run(): array
    {
        return [
            // 1. Gateway host resolution: hk.semsportal.com resolves to hk-gateway.semsportal.com
            TestResult::check('GoodWe resolveModernGatewayCandidates resolves hk.semsportal.com to hk-gateway.semsportal.com', function (): void {
                $adapter = new GoodWeSemsPortalAdapter();
                $candidates = $adapter->resolveModernGatewayCandidates('https://hk.semsportal.com');

                Assert::same('https://hk-gateway.semsportal.com', $candidates[0]);
                Assert::same('https://semsplus.goodwe.com', $candidates[1]);

                // Also check other regional semsportal domains
                $euCandidates = $adapter->resolveModernGatewayCandidates('https://eu.semsportal.com');
                Assert::same('https://eu-gateway.semsportal.com', $euCandidates[0]);
            }),

            // 2. Modern endpoint uses the gateway host
            TestResult::check('GoodWe modern inverter discovery targets the modern gateway host', function (): void {
                $requestedUrls = [];

                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl) use (&$requestedUrls): array {
                    $requestedUrls[] = ($overrideBaseUrl ?? '') . $endpoint;
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        return [
                            'code' => '00000',
                            'msg' => 'Successful',
                            'data' => [
                                'stationInverters' => [
                                    [
                                        'stationId' => 'st-hk-01',
                                        'stationName' => 'HK Solar Plant',
                                        'inverters' => [
                                            [
                                                'inverterSn' => 'GW-HK-001',
                                                'inverterName' => 'GoodWe HK Gateway Inverter',
                                                'inverterStatus' => 5,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ];
                    }
                    throw new \RuntimeException("Unexpected call: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $result = $adapter->queryStationInverters('{"token":"dummy","uid":"u1"}', 'st-hk-01', 'https://hk.semsportal.com');

                Assert::same(['https://hk-gateway.semsportal.com/web/sems/sems-report/api/station/inverter/list'], $requestedUrls);
                Assert::same('modern', $result['source']);
                Assert::same('GW-HK-001', $result['inverters'][0]['sn']);
            }),

            // 3. Legacy /api routes continue using hk.semsportal.com
            TestResult::check('GoodWe legacy fallback routes continue using the legacy regional host', function (): void {
                $requestedUrls = [];

                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl) use (&$requestedUrls): array {
                    $requestedUrls[] = ($overrideBaseUrl ?? '') . $endpoint;
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        // Fail modern discovery so fallback triggers
                        throw new \RuntimeException('HTTP 404 Not Found on modern endpoint');
                    }
                    if ($endpoint === '/api/v2/PowerStation/GetMonitorDetailByPowerstationId') {
                        return [
                            'code' => 0,
                            'hasError' => false,
                            'data' => [
                                'info' => ['powerstation_name' => 'Legacy HK Plant'],
                                'inverter' => [
                                    [
                                        'sn' => 'GW-LEGACY-HK-01',
                                        'name' => 'GoodWe Legacy Inverter',
                                        'status' => 1,
                                        'pac' => 2000.0,
                                        'capacity' => 5.0,
                                    ],
                                ],
                            ],
                        ];
                    }
                    throw new \RuntimeException("Unexpected call: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $result = $adapter->queryStationInverters('{"token":"dummy","uid":"u1"}', 'st-hk-02', 'https://hk.semsportal.com');

                Assert::same('legacy', $result['source']);
                Assert::same('GW-LEGACY-HK-01', $result['inverters'][0]['sn']);
                // Verify legacy call was sent to https://hk.semsportal.com
                Assert::same('https://hk.semsportal.com/api/v2/PowerStation/GetMonitorDetailByPowerstationId', end($requestedUrls));
            }),

            // 4. semsplus.goodwe.com remains a modern fallback
            TestResult::check('GoodWe semsplus.goodwe.com remains the modern fallback candidate', function (): void {
                $adapter = new GoodWeSemsPortalAdapter();
                $candidates = $adapter->resolveModernGatewayCandidates('https://hk.semsportal.com');

                Assert::true(in_array('https://semsplus.goodwe.com', $candidates, true));
                Assert::same('https://semsplus.goodwe.com', $candidates[count($candidates) - 1]);
            }),

            // 5. Arbitrary/non-matching hosts are NOT blindly transformed
            TestResult::check('GoodWe resolveModernGatewayCandidates does not blindly transform non-matching hosts', function (): void {
                $adapter = new GoodWeSemsPortalAdapter();

                $nonMatching = $adapter->resolveModernGatewayCandidates('https://custom-solar.local');
                Assert::same(['https://semsplus.goodwe.com'], $nonMatching);

                $wwwMatching = $adapter->resolveModernGatewayCandidates('https://www.semsportal.com');
                Assert::same(['https://semsplus.goodwe.com'], $wwwMatching);

                $apiMatching = $adapter->resolveModernGatewayCandidates('https://api.semsportal.com');
                Assert::same(['https://semsplus.goodwe.com'], $apiMatching);
            }),

            // 6. Modern gateway returns inverter -> modern inverter is normalized & legacy is NOT called
            TestResult::check('GoodWe modern gateway success normalizes inverter and skips legacy fallback', function (): void {
                $calls = [];

                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl) use (&$calls): array {
                    $calls[] = [
                        'endpoint' => $endpoint,
                        'host' => $overrideBaseUrl,
                    ];
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        return [
                            'code' => '00000',
                            'msg' => 'Successful',
                            'data' => [
                                'stationInverters' => [
                                    [
                                        'stationId' => 'st-gw-01',
                                        'stationName' => 'Gateway Solar House',
                                        'inverters' => [
                                            [
                                                'inverterSn' => 'GW-NORMAL-001',
                                                'inverterName' => 'GoodWe GW5000',
                                                'inverterStatus' => 5,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ];
                    }
                    throw new \RuntimeException("Legacy should not be called: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $result = $adapter->queryStationInverters('{"token":"dummy","uid":"u1"}', 'st-gw-01', 'https://hk.semsportal.com');

                Assert::same(1, count($calls));
                Assert::same('/web/sems/sems-report/api/station/inverter/list', $calls[0]['endpoint']);
                Assert::same('https://hk-gateway.semsportal.com', $calls[0]['host']);
                Assert::same('modern', $result['source']);
                Assert::same('GW-NORMAL-001', $result['inverters'][0]['sn']);
                Assert::same(5, $result['inverters'][0]['status']);
                Assert::same('NORMAL', $adapter->mapInverterStatus($result['inverters'][0]['status'], 0.0));
            }),

            // 7. Modern gateway fails -> semsplus.goodwe.com is attempted
            TestResult::check('GoodWe queryStationInverters attempts secondary modern candidate if primary gateway fails', function (): void {
                $hostsAttempted = [];

                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl) use (&$hostsAttempted): array {
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        $hostsAttempted[] = $overrideBaseUrl;
                        if ($overrideBaseUrl === 'https://hk-gateway.semsportal.com') {
                            throw new \RuntimeException('Gateway connection timed out');
                        }
                        if ($overrideBaseUrl === 'https://semsplus.goodwe.com') {
                            return [
                                'code' => '00000',
                                'msg' => 'Successful',
                                'data' => [
                                    'stationInverters' => [
                                        [
                                            'stationId' => 'st-fb-01',
                                            'stationName' => 'Fallback Solar House',
                                            'inverters' => [
                                                [
                                                    'inverterSn' => 'GW-FALLBACK-002',
                                                    'inverterName' => 'GoodWe Portal Inverter',
                                                    'inverterStatus' => 5,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ];
                        }
                    }
                    throw new \RuntimeException("Unexpected call: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $result = $adapter->queryStationInverters('{"token":"dummy","uid":"u1"}', 'st-fb-01', 'https://hk.semsportal.com');

                Assert::same([
                    'https://hk-gateway.semsportal.com',
                    'https://semsplus.goodwe.com',
                ], $hostsAttempted);
                Assert::same('modern', $result['source']);
                Assert::same('GW-FALLBACK-002', $result['inverters'][0]['sn']);
            }),

            // 8. Both modern candidates fail -> legacy inverter fallback executes
            TestResult::check('GoodWe queryStationInverters executes legacy fallback when all modern candidates fail', function (): void {
                $calls = [];

                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl) use (&$calls): array {
                    $calls[] = [
                        'endpoint' => $endpoint,
                        'host' => $overrideBaseUrl,
                    ];
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        throw new \RuntimeException('Modern endpoint unavailable');
                    }
                    if ($endpoint === '/api/v2/PowerStation/GetMonitorDetailByPowerstationId') {
                        return [
                            'code' => 0,
                            'hasError' => false,
                            'data' => [
                                'info' => ['powerstation_name' => 'Legacy Rooftop Plant'],
                                'inverter' => [
                                    [
                                        'sn' => 'GW-LEGACY-888',
                                        'name' => 'GoodWe Legacy Inverter',
                                        'status' => 1,
                                        'pac' => 1800.0,
                                        'capacity' => 5.0,
                                    ],
                                ],
                            ],
                        ];
                    }
                    throw new \RuntimeException("Unexpected call: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $result = $adapter->queryStationInverters('{"token":"dummy","uid":"u1"}', 'st-102', 'https://hk.semsportal.com');

                Assert::same(3, count($calls));
                // Primary modern
                Assert::same('/web/sems/sems-report/api/station/inverter/list', $calls[0]['endpoint']);
                Assert::same('https://hk-gateway.semsportal.com', $calls[0]['host']);
                // Secondary modern
                Assert::same('/web/sems/sems-report/api/station/inverter/list', $calls[1]['endpoint']);
                Assert::same('https://semsplus.goodwe.com', $calls[1]['host']);
                // Legacy fallback on legacy regional host
                Assert::same('/api/v2/PowerStation/GetMonitorDetailByPowerstationId', $calls[2]['endpoint']);
                Assert::same('https://hk.semsportal.com', $calls[2]['host']);

                Assert::same('legacy', $result['source']);
                Assert::same('GW-LEGACY-888', $result['inverters'][0]['sn']);
            }),

            // 9. Both modern + legacy return no inverter -> clean existing NO_INVERTER_FOUND behavior
            TestResult::check('GoodWe testConnection produces clean NO_INVERTER_FOUND when all modern and legacy discovery return empty', function (): void {
                $transport = function (string $endpoint, array $body, ?string $token, ?string $overrideBaseUrl): array {
                    if ($endpoint === '/api/v2/Common/CrossLogin') {
                        return [
                            'code' => 0,
                            'hasError' => false,
                            'data' => [
                                'token' => 'mock-token',
                                'uid' => 'mock-uid',
                                'api' => 'https://hk.semsportal.com',
                            ],
                        ];
                    }
                    if ($endpoint === '/web/sems/sems-report/api/station/inverter/list') {
                        return [
                            'code' => '00000',
                            'data' => ['stationInverters' => []],
                        ];
                    }
                    if ($endpoint === '/api/v2/PowerStation/GetMonitorDetailByPowerstationId') {
                        return [
                            'code' => 0,
                            'hasError' => false,
                            'data' => [
                                'info' => ['powerstation_name' => 'Empty Plant'],
                                'inverter' => [],
                            ],
                        ];
                    }
                    throw new \RuntimeException("Unexpected call: {$endpoint}");
                };

                $adapter = new GoodWeSemsPortalAdapter(transport: $transport);
                $creds = new SolarCredentials('user@example.com', 'mypassword', 'st-empty-001');
                $result = $adapter->testConnection($creds);

                Assert::same(false, $result->isSuccess());
                Assert::same('STATION_NOT_FOUND', $result->status());
                Assert::same('NO_INVERTER_FOUND', $result->errorCode());
                Assert::same('Power station found but no associated inverters were reported.', $result->errorMessage());
            }),

            // 10. Secret logging test: token, password, and X-Signature remain redacted
            TestResult::check('GoodWe diagnostic logging excludes token, password, and X-Signature', function (): void {
                Assert::true(Logger::isSensitiveKey('token'));
                Assert::true(Logger::isSensitiveKey('raw_token'));
                Assert::true(Logger::isSensitiveKey('session_token'));
                Assert::true(Logger::isSensitiveKey('password'));
                Assert::true(Logger::isSensitiveKey('pwd'));
                Assert::true(Logger::isSensitiveKey('x-signature'));
                Assert::true(Logger::isSensitiveKey('signature'));
            }),

            // Extra Regression: Signature regression test against wire-parity vector
            TestResult::check('GoodWe signature algorithm produces expected sha256 lowercase-hex and base64 format', function (): void {
                $sessionJson = '{"uid":"1122334455667788990","timestamp":0,"token":"t-abc","client":"semsPlusWeb","version":"","language":"en"}';
                $fixedTime = 1700000000000;

                $signature = GoodWeSemsPortalAdapter::encodeSignature($sessionJson, $fixedTime);
                $expectedRawHash = hash('sha256', "1700000000000@1122334455667788990@t-abc");
                $decoded = base64_decode($signature, true);

                Assert::same("{$expectedRawHash}@1700000000000", $decoded);
                Assert::same('ZDgyNDM5ODJiOGVmYWRmZTUxNThjNDdhMjNmZWUwNGNlZDE1Yzg5NmMzN2Q4ODI2ODBjZjhhMjI4MjA4ODIxMEAxNzAwMDAwMDAwMDAw', $signature);
            }),

            // Extra Regression: Modern parser test maps data.stationInverters[0].inverters[] faithfully
            TestResult::check('GoodWe parseModernInverters maps stationInverters and inverter records faithfully', function (): void {
                $adapter = new GoodWeSemsPortalAdapter();

                $mockPayload = [
                    'stationInverters' => [
                        [
                            'stationId' => '06343cdf-e9b6-41a8-bb7f-16bf3ea0a6a9',
                            'stationName' => 'eswar',
                            'stationAddress' => null,
                            'inverters' => [
                                [
                                    'inverterSn' => '53000N3A258L5685',
                                    'inverterName' => 'GW5000-DNS',
                                    'inverterStatus' => 5,
                                ],
                                [
                                    'inverterSn' => '',
                                    'inverterName' => 'Ghost Inverter',
                                    'inverterStatus' => 1,
                                ],
                            ],
                        ],
                    ],
                ];

                $parsed = $adapter->parseModernInverters($mockPayload, '06343cdf-e9b6-41a8-bb7f-16bf3ea0a6a9');

                Assert::same('eswar', $parsed['station_name']);
                Assert::same(1, count($parsed['inverters']));
                Assert::same('53000N3A258L5685', $parsed['inverters'][0]['sn']);
                Assert::same('GW5000-DNS', $parsed['inverters'][0]['name']);
                Assert::same(5, $parsed['inverters'][0]['status']);
            }),

            // Extra Regression: Status normalization test
            TestResult::check('GoodWe mapInverterStatus normalizes modern status 5 to NORMAL', function (): void {
                $adapter = new GoodWeSemsPortalAdapter();

                Assert::same('NORMAL', $adapter->mapInverterStatus(5, 0.0));
                Assert::same('NORMAL', $adapter->mapInverterStatus(5, 2500.0));
                Assert::same('NORMAL', $adapter->mapInverterStatus(1, 1500.0));
                Assert::same('WAITING', $adapter->mapInverterStatus(1, 0.0));
                Assert::same('WAITING', $adapter->mapInverterStatus(0, 0.0));
                Assert::same('WAITING', $adapter->mapInverterStatus(2, 0.0));
                Assert::same('OFFLINE', $adapter->mapInverterStatus(-1, 0.0));
                Assert::same('OFFLINE', $adapter->mapInverterStatus(1005, 0.0));
            }),
        ];
    }
}
