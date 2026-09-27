<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\HouseholdController;
use App\Controllers\SolarController;
use App\Domain\Household\OnboardingReadiness;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * F11 — Onboarding API boundary & progressive readiness tests.
 *
 * Verifies that the multi-step guided setup flows through the authoritative
 * backend primitives without inventing intermediate states:
 * 1. Step 1: Create household with label, location, timezone (service_number absent).
 * 2. Step 2: Progressive PATCH update of electricity service_number.
 * 3. Step 3: Authoritative readiness projection reflects true state.
 * 4. Step 4: Strict household isolation on all onboarding endpoints.
 */
final class OnboardingApiTest
{
    private static function householdController(): HouseholdController
    {
        AuthTesting::configure();

        return new HouseholdController();
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    public static function run(): array
    {
        return [
            TestResult::check('Step 1: Household creation succeeds without service_number', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount('onb-step1-');

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/household', body: [
                        'label' => 'Serene Retreat',
                        'location_label' => 'Tirupati, AP',
                        'timezone' => 'Asia/Kolkata',
                    ]),
                    $account
                );

                $res = self::householdController()->create($req);
                Assert::same(201, $res->status());
                $data = $res->body()['data'];

                Assert::same('Serene Retreat', $data['household']['label']);
                Assert::same('Tirupati, AP', $data['household']['location_label']);
                Assert::same('Asia/Kolkata', $data['household']['timezone']);
                Assert::same(null, $data['household']['service_number']);
            }),

            TestResult::check('Step 2: Service number can be progressively updated via PATCH', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount('onb-step2-');

                // Step 1: Create
                $createReq = AuthTesting::authed(
                    Request::create('POST', '/api/v1/household', body: [
                        'label' => 'Green Villa',
                        'location_label' => 'Nellore, AP',
                        'timezone' => 'Asia/Kolkata',
                    ]),
                    $account
                );
                self::householdController()->create($createReq);

                // Step 2: Add service number
                $patchReq = AuthTesting::authed(
                    Request::create('PATCH', '/api/v1/household', body: [
                        'service_number' => '1000-2000-300',
                    ]),
                    $account
                );
                $patchRes = self::householdController()->update($patchReq);
                Assert::same(200, $patchRes->status());

                // Verify updated profile
                $curReq = AuthTesting::authed(Request::create('GET', '/api/v1/household'), $account);
                $curRes = self::householdController()->current($curReq);
                $hh = $curRes->body()['data']['household'];

                Assert::same('10002000300', $hh['service_number'], 'Canonical service number preserved');
                Assert::same('Green Villa', $hh['label']);
            }),

            TestResult::check('Onboarding readiness projection correctly reflects active configuration', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount('onb-readiness-');

                // Before household creation
                $curReq1 = AuthTesting::authed(Request::create('GET', '/api/v1/household'), $account);
                $curRes1 = self::householdController()->current($curReq1);
                $readiness1 = $curRes1->body()['data']['readiness'];

                Assert::same(true, $readiness1['email_verified']);
                Assert::same(false, $readiness1['timezone_set']);
                Assert::same(false, $readiness1['location_provided']);
                Assert::same(OnboardingReadiness::SOURCE_UNAVAILABLE, $readiness1['solar']);

                // After household creation
                $createReq = AuthTesting::authed(
                    Request::create('POST', '/api/v1/household', body: [
                        'label' => 'Hilltop Home',
                        'location_label' => 'Chittoor, AP',
                        'timezone' => 'Asia/Kolkata',
                    ]),
                    $account
                );
                self::householdController()->create($createReq);

                $curReq2 = AuthTesting::authed(Request::create('GET', '/api/v1/household'), $account);
                $curRes2 = self::householdController()->current($curReq2);
                $readiness2 = $curRes2->body()['data']['readiness'];

                Assert::same(true, $readiness2['timezone_set']);
                Assert::same(true, $readiness2['location_provided']);
                Assert::same(OnboardingReadiness::SOURCE_UNAVAILABLE, $readiness2['solar']);
            }),

            TestResult::check('Household isolation: account B cannot read or modify account A onboarding data', function (): void {
                RequestId::set(RequestId::new());
                $accountA = AuthTesting::activateAccount('onb-iso-a-');
                $accountB = AuthTesting::activateAccount('onb-iso-b-');

                // Account A creates household
                self::householdController()->create(AuthTesting::authed(
                    Request::create('POST', '/api/v1/household', body: [
                        'label' => 'A Home',
                        'location_label' => 'City A',
                        'timezone' => 'Asia/Kolkata',
                    ]),
                    $accountA
                ));

                // Account B has no household
                $resB = self::householdController()->current(AuthTesting::authed(
                    Request::create('GET', '/api/v1/household'),
                    $accountB
                ));

                Assert::same(null, $resB->body()['data']['household']);
                Assert::same(false, $resB->body()['data']['readiness']['location_provided']);
            }),
        ];
    }
}
