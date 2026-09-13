<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\HouseholdController;
use App\Domain\Household\Household;
use App\Repositories\HouseholdRepository;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3.1 — Household profile completion + derived onboarding readiness
 * (docs/10 D-065).
 *
 * PATCH /api/v1/household runs on the caller's own server-derived
 * context; the readiness projection on GET is derived from
 * authoritative facts only — never stored, never numeric, and honest
 * about capabilities that do not exist in this system yet.
 */
final class HouseholdProfileTest
{
    private static function controller(): HouseholdController
    {
        AuthTesting::configure();

        return new HouseholdController();
    }

    private static function request(string $method, array $body = [], ?\App\Domain\Account\Account $as = null): Request
    {
        $request = Request::create($method, '/api/v1/household', body: $body, ipAddress: AuthTesting::uniqueIpAddress());

        return $as === null ? $request : AuthTesting::authed($request, $as);
    }

    private static function data(Request $request): array
    {
        return self::controller()->current($request)->body()['data'];
    }

    private static function expectFailure(string $message, callable $call, string $code, int $status, ?string $field = null): void
    {
        try {
            $call();
            Assert::true(false, $message);
        } catch (AppException $e) {
            Assert::same($code, $e->errorCode());
            Assert::same($status, $e->httpStatus());

            if ($field !== null) {
                Assert::same($field, $e->context()['field'] ?? null, 'field context must name ' . $field);
            }
        }
    }

    public static function run(): array
    {
        return [
            TestResult::check('PATCH /api/v1/household requires authentication', function (): void {
                RequestId::set(RequestId::new());

                self::expectFailure(
                    'Expected 401 for anonymous household PATCH',
                    fn () => self::controller()->update(self::request('PATCH', ['timezone' => 'UTC'])),
                    'UNAUTHENTICATED',
                    401
                );
            }),

            TestResult::check('A household PATCH without an active household is an honest 404 and creates nothing', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount();

                self::expectFailure(
                    'Expected 404 when no active household exists',
                    fn () => self::controller()->update(self::request('PATCH', ['timezone' => 'UTC'], $account)),
                    'NOT_FOUND',
                    404
                );

                $data = self::data(self::request('GET', as: $account));
                Assert::same(null, $data['household'], 'PATCH must never create a household implicitly');
            }),

            TestResult::check('PATCH updates the current profile only — id, status, membership and history are untouched', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-patch-');
                $repository = new HouseholdRepository();

                $before = $repository->findById($fixture['household_id']);
                Assert::true($before !== null);

                $response = self::controller()->update(self::request('PATCH', [
                    'timezone' => 'Europe/Berlin',
                    'location_label' => 'Anantapur, Andhra Pradesh',
                ], $fixture['account']));

                Assert::same(200, $response->status());
                $data = $response->body()['data'];
                Assert::same($fixture['household_id'], $data['household']['id'], 'id is immutable through profile modification');
                Assert::same('ACTIVE', $data['household']['status'], 'status is not addressable here');
                Assert::same('OWNER', $data['membership']['role'], 'membership role is untouched');
                Assert::same('Europe/Berlin', $data['household']['timezone']);
                Assert::same('Anantapur, Andhra Pradesh', $data['household']['location_label']);
                Assert::same($before->label(), $data['household']['label'], 'unlisted fields keep their current value (PATCH is partial)');

                $after = $repository->findById($fixture['household_id']);
                Assert::true($after !== null);
                Assert::same($before->createdAt(), $after->createdAt(), 'created_at is historical truth, never rewritten');
                Assert::true($after->updatedAt() >= $before->updatedAt(), 'updated_at moves appropriately');
                Assert::same(Household::STATUS_ACTIVE, $after->status());

                // The v1 single-household creation rule is unaffected.
                self::expectFailure(
                    'Expected 409 for a second household after a profile edit',
                    fn () => self::controller()->create(self::request('POST', ['timezone' => 'UTC'], $fixture['account'])),
                    'HOUSEHOLD_EXISTS',
                    409
                );
            }),

            TestResult::check('PATCH rejects invalid values with field-scoped 422s and writes nothing partial', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-invalid-');

                $cases = [
                    'unknown status field' => [['status' => 'DELETED'], 'status'],
                    'unknown household_id scope selector' => [['household_id' => 'any', 'timezone' => 'UTC'], 'household_id'],
                    'unknown role field' => [['role' => 'OWNER'], 'role'],
                    'invalid timezone identifier' => [['timezone' => 'Mars/Olympus'], 'timezone'],
                    'timezone over 64 characters' => [['timezone' => str_repeat('Z', 65)], 'timezone'],
                    'empty timezone' => [['timezone' => ''], 'timezone'],
                    'null timezone (not nullable)' => [['timezone' => null], 'timezone'],
                    'non-string timezone' => [['timezone' => 123], 'timezone'],
                    'label over 120 characters' => [['label' => str_repeat('l', 121)], 'label'],
                    'whitespace-only label' => [['label' => '   '], 'label'],
                    'array label' => [['label' => ['a']], 'label'],
                    'location_label over 190 characters' => [['location_label' => str_repeat('L', 191)], 'location_label'],
                    'integer location_label' => [['location_label' => 42], 'location_label'],
                ];

                foreach ($cases as $label => [$body, $field]) {
                    self::expectFailure(
                        'Expected 422 for ' . $label,
                        fn () => self::controller()->update(self::request('PATCH', $body, $fixture['account'])),
                        'VALIDATION_ERROR',
                        422,
                        $field
                    );
                }

                // Failed attempts consumed nothing: the profile is exactly as before.
                $data = self::data(self::request('GET', as: $fixture['account']));
                Assert::same('Asia/Kolkata', $data['household']['timezone']);
                Assert::same(null, $data['household']['location_label']);
                Assert::same(Household::STATUS_ACTIVE, $data['household']['status']);
            }),

            TestResult::check('PATCH can clear nullable fields and an empty PATCH is a stable no-op', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-clear-');

                self::controller()->update(self::request('PATCH', ['location_label' => 'Chennai'], $fixture['account']));
                $response = self::controller()->update(self::request('PATCH', ['location_label' => null], $fixture['account']));
                Assert::same(null, $response->body()['data']['household']['location_label'], 'explicit null clears the label');

                $noop = self::controller()->update(self::request('PATCH', [], $fixture['account']));
                Assert::same(200, $noop->status());
                Assert::same('Asia/Kolkata', $noop->body()['data']['household']['timezone'], 'empty PATCH changes nothing');
            }),

            TestResult::check('The caller can never reach another household through profile modification', function (): void {
                RequestId::set(RequestId::new());
                $a = AuthTesting::householdFixture('w31-a-');
                $b = AuthTesting::householdFixture('w31-b-');
                $repository = new HouseholdRepository();

                $bBefore = $repository->findById($b['household_id']);
                Assert::true($bBefore !== null);

                // Claiming B's id is an unknown-field rejection, not a scope.
                self::expectFailure(
                    'Expected 422 when PATCH carries a foreign household_id',
                    fn () => self::controller()->update(self::request('PATCH', ['household_id' => $b['household_id'], 'timezone' => 'UTC'], $a['account'])),
                    'VALIDATION_ERROR',
                    422,
                    'household_id'
                );

                // Even a fully valid PATCH moves only A's own row.
                self::controller()->update(self::request('PATCH', ['timezone' => 'Europe/Berlin'], $a['account']));

                $bAfter = $repository->findById($b['household_id']);
                Assert::true($bAfter !== null);
                Assert::same($bBefore->timezone(), $bAfter->timezone(), 'foreign household untouched');
                Assert::same($bBefore->label(), $bAfter->label(), 'foreign household untouched');
                Assert::same($bBefore->updatedAt(), $bAfter->updatedAt(), 'foreign household not even timestamped');
            }),

            TestResult::check('DELETION_PENDING and DELETED households never receive an active profile modification', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-state-');
                $repository = new HouseholdRepository();

                foreach ([Household::STATUS_DELETION_PENDING, Household::STATUS_DELETED] as $state) {
                    $household = $repository->findById($fixture['household_id']);
                    Assert::true($household !== null);
                    $repository->updateStatus($household->withStatus(
                        $state,
                        new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                    ));

                    self::expectFailure(
                        'Expected 404 for ' . $state . ' household PATCH',
                        fn () => self::controller()->update(self::request('PATCH', ['timezone' => 'UTC'], $fixture['account'])),
                        'NOT_FOUND',
                        404
                    );

                    $data = self::data(self::request('GET', as: $fixture['account']));
                    Assert::same(null, $data['household'], $state . ' household stays out of active context');
                    Assert::same(false, $data['readiness']['timezone_set'], $state . ' household contributes no readiness');
                }

                $household = $repository->findById($fixture['household_id']);
                $repository->updateStatus($household->withStatus(Household::STATUS_ACTIVE, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
                Assert::same('Asia/Kolkata', self::data(self::request('GET', as: $fixture['account']))['household']['timezone']);
            }),

            TestResult::check('Readiness is derived from authoritative facts — no household', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount();

                $data = self::data(self::request('GET', as: $account));
                Assert::same(null, $data['household']);
                Assert::same(true, $data['readiness']['email_verified']);
                Assert::same(false, $data['readiness']['timezone_set']);
                Assert::same(false, $data['readiness']['location_provided']);
            }),

            TestResult::check('Readiness follows the household fields exactly — timezone set, location absent then present', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-read-');

                $readiness = self::data(self::request('GET', as: $fixture['account']))['readiness'];
                Assert::same(true, $readiness['timezone_set']);
                Assert::same(false, $readiness['location_provided']);

                self::controller()->update(self::request('PATCH', ['location_label' => 'Anantapur'], $fixture['account']));
                Assert::same(true, self::data(self::request('GET', as: $fixture['account']))['readiness']['location_provided']);
            }),

            TestResult::check('Solar and verified bills report UNAVAILABLE; analysis reports LIMITED — honestly, not as zeros', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-honest-');

                $readiness = self::data(self::request('GET', as: $fixture['account']))['readiness'];
                Assert::same('UNAVAILABLE', $readiness['solar'], 'no integration exists yet — never CONNECTED, never fake-zero');
                Assert::same('UNAVAILABLE', $readiness['verified_bills'], 'no bill persistence exists yet (D-042 honesty)');
                Assert::same('LIMITED', $readiness['analysis']);
            }),

            TestResult::check('The readiness projection is fixed-shape and contains no fabricated numbers', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('w31-shape-');

                $readiness = self::data(self::request('GET', as: $fixture['account']))['readiness'];

                Assert::same(
                    ['email_verified', 'timezone_set', 'location_provided', 'solar', 'verified_bills', 'analysis'],
                    array_keys($readiness),
                    'projection is exactly the ratified capability set'
                );

                foreach ($readiness as $key => $value) {
                    Assert::true(
                        is_bool($value) || in_array($value, ['UNAVAILABLE', 'LIMITED'], true),
                        $key . ' must be boolean or a ratified state string — no numbers, scores or percentages'
                    );
                }

                $raw = json_encode(self::controller()->current(self::request('GET', as: $fixture['account']))->body()) ?: '';
                Assert::notContains('percent', strtolower($raw));
                Assert::notContains('progress', strtolower($raw));
            }),
        ];
    }
}
