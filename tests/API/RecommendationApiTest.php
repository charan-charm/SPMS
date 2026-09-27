<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\RecommendationController;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Repositories\RecommendationRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class RecommendationApiTest
{
    private static function controller(): RecommendationController
    {
        AuthTesting::configure();

        return new RecommendationController();
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        return [
            TestResult::check('GET /api/v1/recommendations requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->index(Request::create('GET', '/api/v1/recommendations'));
                    Assert::true(false, 'Expected AppException for unauthenticated index request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/recommendations/generate requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->generate(Request::create('POST', '/api/v1/recommendations/generate'));
                    Assert::true(false, 'Expected AppException for unauthenticated generate request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/recommendations/{id}/dismiss requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->dismiss(
                        Request::create('POST', '/api/v1/recommendations/test-rec/dismiss'),
                        ['id' => 'test-rec']
                    );
                    Assert::true(false, 'Expected AppException for unauthenticated dismiss request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/recommendations returns list and metadata for household', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];
                $account = $fixture['account'];

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $hhId,
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Shift Load to Noon',
                    summary: 'High surplus available',
                    action: 'Run washing machine',
                    reason: 'Surplus',
                    timingWindow: '12:00 - 14:00',
                    potentialImpact: 'Potential 2 kWh savings',
                    impactJson: ['potential_kwh_shifted' => 2.0],
                    evidenceJson: ['surplus' => 2.0],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/recommendations'), $account);
                $response = self::controller()->index($req);

                Assert::same(200, $response->status());
                $data = $response->data();
                Assert::true(isset($data['recommendations']));
                Assert::true(count($data['recommendations']) >= 1);
                Assert::same($recId, $data['recommendations'][0]['id']);
                Assert::true(isset($data['active_count']));
            }),

            TestResult::check('GET /api/v1/recommendations/{id} returns single recommendation', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];
                $account = $fixture['account'];

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $hhId,
                    type: RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_MEDIUM,
                    title: 'Charge EV Now',
                    summary: 'Solar surplus',
                    action: 'Plug in EV',
                    reason: 'Free surplus',
                    timingWindow: '11:00 - 15:00',
                    potentialImpact: 'Potential 5 kWh charging',
                    impactJson: ['type' => 'SOLAR_SELF_CONSUMPTION'],
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                $req = AuthTesting::authed(Request::create('GET', "/api/v1/recommendations/{$recId}"), $account);
                $response = self::controller()->show($req, ['id' => $recId]);

                Assert::same(200, $response->status());
                $data = $response->data();
                Assert::true(isset($data['recommendation']));
                Assert::same($recId, $data['recommendation']['id']);
                Assert::same(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $data['recommendation']['type']);
            }),

            TestResult::check('POST /api/v1/recommendations/{id}/dismiss updates recommendation to DISMISSED', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];
                $account = $fixture['account'];

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $hhId,
                    type: RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Evening Deficit Preparation',
                    summary: 'Deficit expected',
                    action: 'Pre-cool rooms',
                    reason: 'Zero evening solar',
                    timingWindow: '15:00 - 17:00',
                    potentialImpact: 'Estimated 2 kWh grid avoidance',
                    impactJson: null,
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                $req = AuthTesting::authed(Request::create('POST', "/api/v1/recommendations/{$recId}/dismiss"), $account);
                $response = self::controller()->dismiss($req, ['id' => $recId]);

                Assert::same(200, $response->status());
                $data = $response->data();
                Assert::true(isset($data['recommendation']));
                Assert::same(Recommendation::STATUS_DISMISSED, $data['recommendation']['status']);
                Assert::true($data['recommendation']['dismissed_at'] !== null);
            }),

            TestResult::check('Cross-household access is strictly rejected with 404', function (): void {
                RequestId::set(RequestId::new());
                // User A and User B
                $fixtureA = AuthTesting::householdFixture();
                $fixtureB = AuthTesting::householdFixture();

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                // Save recommendation for Household A
                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $fixtureA['household_id'],
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Household A Rec',
                    summary: 'Private to A',
                    action: 'Do load shift',
                    reason: 'Secret',
                    timingWindow: '12:00 - 14:00',
                    potentialImpact: null,
                    impactJson: null,
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                // User B tries to view Household A's recommendation
                $reqView = AuthTesting::authed(Request::create('GET', "/api/v1/recommendations/{$recId}"), $fixtureB['account']);
                try {
                    self::controller()->show($reqView, ['id' => $recId]);
                    Assert::true(false, 'Expected 404 for cross-household show request');
                } catch (AppException $e) {
                    Assert::same(404, $e->httpStatus());
                }

                // User B tries to dismiss Household A's recommendation
                $reqDismiss = AuthTesting::authed(Request::create('POST', "/api/v1/recommendations/{$recId}/dismiss"), $fixtureB['account']);
                try {
                    self::controller()->dismiss($reqDismiss, ['id' => $recId]);
                    Assert::true(false, 'Expected 404 for cross-household dismiss request');
                } catch (AppException $e) {
                    Assert::same(404, $e->httpStatus());
                }
            }),
        ];
    }
}
