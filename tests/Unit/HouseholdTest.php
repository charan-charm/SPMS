<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Household\Household;
use App\Domain\Household\Membership;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * W3 — Household/membership domain semantics (docs/10 D-050; docs/01 §31).
 */
final class HouseholdTest
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-12 10:00:00', new \DateTimeZone('UTC'));
    }

    public static function run(): array
    {
        return [
            TestResult::check('Household creation defaults to ACTIVE with server-assigned identity', function (): void {
                $household = Household::create('Rooftop home', 'Asia/Kolkata', 'Kodumur, AP', self::now());

                Assert::same(Household::STATUS_ACTIVE, $household->status());
                Assert::true($household->isActive());
                Assert::same('Asia/Kolkata', $household->timezone());
                Assert::same(36, strlen($household->id()), 'household id uses the CHAR(36) convention');
                Assert::true(preg_match('/^[0-9a-f-]{36}$/', $household->id()) === 1);
            }),

            TestResult::check('Household label and location are optional display context', function (): void {
                $household = Household::create(null, 'UTC', null, self::now());
                $public = $household->toPublicArray();

                Assert::same(null, $public['label']);
                Assert::same(null, $public['location_label']);
                Assert::same(['id', 'label', 'timezone', 'location_label', 'status'], array_keys($public));
            }),

            TestResult::check('Household model rejects invalid timezone, oversized or blank labels', function (): void {
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Household::create('x', 'Not/AZone/Nope', null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Household::create('x', '', null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Household::create(str_repeat('l', 121), 'UTC', null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Household::create('  ', 'UTC', null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Household::create('ok', 'UTC', str_repeat('L', 191), self::now());
                });
            }),

            TestResult::check('Household lifecycle mirrors docs/01 §31 and rejects unknown states', function (): void {
                $household = Household::create('Home', 'Asia/Kolkata', null, self::now());

                $pending = $household->withStatus(Household::STATUS_DELETION_PENDING, self::now());
                Assert::same(Household::STATUS_DELETION_PENDING, $pending->status());
                Assert::false($pending->isActive(), 'deletion-pending must never resolve as active');

                $deleted = $pending->withStatus(Household::STATUS_DELETED, self::now());
                Assert::same(Household::STATUS_DELETED, $deleted->status());

                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withStatus('SUSPENDED', self::now());
                });
            }),

            TestResult::check('Membership accepts only the v1 OWNER role', function (): void {
                $membership = Membership::create(
                    '11111111-1111-4111-8111-111111111111',
                    '22222222-2222-4222-8222-222222222222',
                    Membership::ROLE_OWNER,
                    self::now()
                );

                Assert::same(Membership::ROLE_OWNER, $membership->role());
                Assert::same([Membership::ROLE_OWNER], Membership::ROLES, 'MEMBER/VIEWER must not be speculatively accepted');

                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Membership::create(
                        '11111111-1111-4111-8111-111111111111',
                        '22222222-2222-4222-8222-222222222222',
                        'MEMBER',
                        self::now()
                    );
                });
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Membership::create('', '22222222-2222-4222-8222-222222222222', 'OWNER', self::now());
                });
            }),

            TestResult::check('Storage rows round-trip through the models', function (): void {
                $household = Household::create('Home', 'Asia/Kolkata', 'City', self::now());
                $row = $household->toStorageRow();
                $restored = Household::fromStorageRow($row);

                Assert::same($household->id(), $restored->id());
                Assert::same('Home', $restored->toPublicArray()['label']);
                Assert::same('Asia/Kolkata', $restored->timezone());
                Assert::same($row['created_at'], $restored->toStorageRow()['created_at']);
            }),
        ];
    }
}
