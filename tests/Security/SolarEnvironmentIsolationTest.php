<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Domain\Solar\SolarService;
use App\Integrations\GoodWe\FakeSolarProvider;
use App\Integrations\GoodWe\GoodWeSemsPortalAdapter;
use App\Repositories\SolarReadingRepository;
use App\Support\Config;
use App\Support\Database;
use App\Support\DataEnvironments;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * SolarEnvironmentIsolationTest — Verifies strict isolation between REAL, DEMO, and TEST
 * environments for solar telemetry ingestion, provider selection, and persistence.
 *
 * Requirements:
 *  1. Real profile selects the real provider (GoodWeSemsPortalAdapter) and rejects FakeSolarProvider.
 *  2. Demo profile retains its intended simulated provider (FakeSolarProvider).
 *  3. Test profile remains isolated and respects test configuration.
 *  4. Configuration overlays cannot silently switch a real request into demo mode.
 *  5. Simulated telemetry cannot be persisted through the real profile (two-layer defense-in-depth).
 *  6. Authentication-driven database switching cannot create a provider/database mismatch.
 */
final class SolarEnvironmentIsolationTest
{
    public static function run(): array
    {
        return [
            TestResult::check('1. Real profile selects the real provider and prohibits fake provider', function (): void {
                $savedProfile = Database::currentProfile();
                try {
                    Database::switchTo(DataEnvironments::REAL);

                    $service = new SolarService();
                    $provider = $service->resolveProvider();

                    Assert::true($provider instanceof GoodWeSemsPortalAdapter, 'Real profile must select GoodWeSemsPortalAdapter');
                    Assert::false($provider instanceof FakeSolarProvider, 'Real profile must never select FakeSolarProvider');

                    // Real profile must fail safely if FakeSolarProvider is explicitly injected
                    Assert::throws(RuntimeException::class, function (): void {
                        new SolarService(provider: new FakeSolarProvider());
                    }, 'Injecting FakeSolarProvider into real profile must throw RuntimeException');
                } finally {
                    Database::switchTo($savedProfile);
                }
            }),

            TestResult::check('2. Demo profile retains its intended simulated provider and simulated provenance', function (): void {
                $savedProfile = Database::currentProfile();
                try {
                    Database::switchTo(DataEnvironments::DEMO);

                    $service = new SolarService();
                    $provider = $service->resolveProvider();

                    Assert::true($provider instanceof FakeSolarProvider, 'Demo profile must select FakeSolarProvider');
                    Assert::same('FakeSolarProvider', (new \ReflectionClass($provider))->getShortName());
                } finally {
                    Database::switchTo($savedProfile);
                }
            }),

            TestResult::check('3. Test profile remains isolated and respects test configuration', function (): void {
                $savedProfile = Database::currentProfile();
                try {
                    Database::switchTo(DataEnvironments::TEST);

                    Config::overrideForTesting(['SOLAR_PROVIDER' => 'fake']);
                    $service = new SolarService();
                    $provider = $service->resolveProvider();
                    Assert::true($provider instanceof FakeSolarProvider, 'Test profile with SOLAR_PROVIDER=fake must select FakeSolarProvider');

                    // Test profile accepts injected provider mocks
                    $customProvider = new GoodWeSemsPortalAdapter();
                    $serviceWithMock = new SolarService(provider: $customProvider);
                    Assert::same($customProvider, $serviceWithMock->resolveProvider(), 'Test profile must honor injected provider mock');
                } finally {
                    Database::switchTo($savedProfile);
                }
            }),

            TestResult::check('4. Configuration overlays cannot silently switch a real request into demo mode', function (): void {
                $savedProfile = Database::currentProfile();
                $originalConfig = Config::all();

                try {
                    // Simulate an environmental or config overlay attempting to force demo mode
                    Config::overrideForTesting([
                        'SPMS_DATA_ENVIRONMENT' => 'demo',
                        'SOLAR_PROVIDER' => 'fake',
                    ]);

                    // Authoritative active database profile remains REAL
                    Database::switchTo(DataEnvironments::REAL);

                    $service = new SolarService();

                    // Active REAL profile strictly refuses to select fake provider and selects real adapter
                    $provider = $service->resolveProvider();
                    Assert::true($provider instanceof GoodWeSemsPortalAdapter, 'Authoritative real profile must select GoodWeSemsPortalAdapter despite config overlays');
                    Assert::false($provider instanceof FakeSolarProvider, 'Authoritative real profile must never select FakeSolarProvider');

                    // In real profile, attempting to inject FakeSolarProvider must fail safely
                    Assert::throws(RuntimeException::class, function (): void {
                        new SolarService(provider: new FakeSolarProvider());
                    }, 'Attempting to use FakeSolarProvider in real profile must throw RuntimeException');
                } finally {
                    Config::overrideForTesting($originalConfig);
                    Database::switchTo($savedProfile);
                }
            }),

            TestResult::check('5. Simulated telemetry cannot be persisted through the real profile', function (): void {
                $savedProfile = Database::currentProfile();

                try {
                    Database::switchTo(DataEnvironments::REAL);

                    $repo = new SolarReadingRepository();
                    $now = new DateTimeImmutable('now');

                    $simulatedSnapshot = new NormalizedSolarSnapshot(
                        stationId: 'st-isolation-test',
                        inverterSn: 'GW-ISOLATION-001',
                        measurementTime: $now,
                        receivedAt: $now,
                        powerW: 3450.75,
                        dailyGenerationKwh: 14.820,
                        inverterStatus: 'NORMAL',
                        dataNature: 'SIMULATED'
                    );

                    // Defense-in-depth: Repository level must refuse to insert SIMULATED reading into real profile
                    Assert::throws(RuntimeException::class, function () use ($repo, $simulatedSnapshot): void {
                        $repo->insert(
                            id: '00000000-0000-0000-0000-000000000001',
                            householdId: '00000000-0000-0000-0000-000000000002',
                            snapshot: $simulatedSnapshot,
                            syncBatchId: '00000000-0000-0000-0000-000000000003'
                        );
                    }, 'SolarReadingRepository::insert must throw RuntimeException when attempting to persist SIMULATED data in REAL profile');
                } finally {
                    Database::switchTo($savedProfile);
                }
            }),

            TestResult::check('6. Authentication-driven database switching cannot create a provider/database mismatch', function (): void {
                $savedProfile = Database::currentProfile();

                try {
                    // Scenario A: Service initialized while in demo mode
                    Database::switchTo(DataEnvironments::DEMO);
                    $service = new SolarService();

                    Assert::true($service->resolveProvider() instanceof FakeSolarProvider, 'Initially in demo mode, must resolve FakeSolarProvider');

                    // AuthMiddleware authenticates a real user session and switches profile to REAL
                    Database::switchTo(DataEnvironments::REAL);

                    // The SAME service instance dynamically queries the active database profile and selects the real provider
                    $switchedProvider = $service->resolveProvider();
                    Assert::true($switchedProvider instanceof GoodWeSemsPortalAdapter, 'After switching to real, service must dynamically resolve GoodWeSemsPortalAdapter');
                    Assert::false($switchedProvider instanceof FakeSolarProvider, 'After switching to real, service must NOT resolve FakeSolarProvider');

                    // Scenario B: Service initialized with explicit FakeSolarProvider while in demo/test
                    Database::switchTo(DataEnvironments::DEMO);
                    $fakeService = new SolarService(provider: new FakeSolarProvider());

                    // AuthMiddleware switches to REAL
                    Database::switchTo(DataEnvironments::REAL);

                    // Invoking provider methods on the fake service instance must fail safely
                    Assert::throws(RuntimeException::class, function () use ($fakeService): void {
                        $fakeService->resolveProvider();
                    }, 'Service with explicit FakeSolarProvider must fail safely when database switches to REAL');
                } finally {
                    Database::switchTo($savedProfile);
                }
            }),
        ];
    }
}
