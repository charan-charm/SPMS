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
                // D-071 adds the registered service number to the own-household view.
                Assert::same(['id', 'label', 'timezone', 'location_label', 'service_number', 'status'], array_keys($public));
                Assert::same(null, $public['service_number']);
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

            TestResult::check('W3.1 withProfile changes current context only — identity, status and history are preserved', function (): void {
                $household = Household::create('Home', 'Asia/Kolkata', 'City', self::now());

                // Null arguments keep current values; partial modification works.
                $edited = $household->withProfile(null, 'Europe/Berlin', null, null, self::now());
                Assert::same($household->id(), $edited->id(), 'profile modification never re-keys the household');
                Assert::same(Household::STATUS_ACTIVE, $edited->status(), 'status is not modifiable here');
                Assert::same('Home', $edited->label());
                Assert::same('Europe/Berlin', $edited->timezone());
                Assert::same('City', $edited->locationLabel());
                Assert::same($household->createdAt(), $edited->createdAt(), 'created_at is historical truth');

                // Explicit empty strings clear the nullable display fields.
                $cleared = $edited->withProfile('', null, '', null, self::now());
                Assert::same(null, $cleared->label());
                Assert::same(null, $cleared->locationLabel());
                Assert::same('Europe/Berlin', $cleared->timezone());

                // D-071: the service number is stored canonical, cleared
                // by '' like the other optional fields, and never coerced
                // from near-misses.
                $registered = $household->withProfile(null, null, null, '123-456-789', self::now());
                Assert::same('123456789', $registered->serviceNumber(), 'decorative separators fold, content stays');
                Assert::same('Home', $registered->label(), 'registering a service number never disturbs other fields');
                $reregistered = $registered->withProfile(null, null, null, '123456789', self::now());
                Assert::same('123456789', $reregistered->serviceNumber());
                Assert::same(null, $registered->withProfile(null, null, null, '', self::now())->serviceNumber(), "'' clears it honestly");

                // Same field semantics as creation — validated, never coerced.
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, 'Not/AZone/Nope', null, null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, '', null, null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(str_repeat('l', 121), null, null, null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, str_repeat('Z', 65), null, null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, null, str_repeat('L', 191), null, self::now());
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, null, null, 'ab@#$%', self::now(), );
                });
                Assert::throws(\InvalidArgumentException::class, static function () use ($household): void {
                    $household->withProfile(null, null, null, '12', self::now());
                });

                // Lifecycle is independent of profile editing.
                $pending = $household->withStatus(Household::STATUS_DELETION_PENDING, self::now());
                Assert::same(Household::STATUS_DELETION_PENDING, $pending->withProfile('New', null, null, null, self::now())->status());
            }),

            TestResult::check('W3.1 onboarding readiness derives facts and never invents numbers', function (): void {
                $none = \App\Domain\Household\OnboardingReadiness::derive(true, null);
                Assert::same(true, $none['email_verified']);
                Assert::same(false, $none['timezone_set']);
                Assert::same(false, $none['location_provided']);
                Assert::same('UNAVAILABLE', $none['solar']);
                Assert::same('UNAVAILABLE', $none['verified_bills']);
                Assert::same('LIMITED', $none['analysis']);

                $withLocation = \App\Domain\Household\OnboardingReadiness::derive(
                    false,
                    Household::create('H', 'UTC', 'Anantapur', self::now())
                );
                Assert::same(false, $withLocation['email_verified']);
                Assert::same(true, $withLocation['timezone_set']);
                Assert::same(true, $withLocation['location_provided']);

                // Whitespace is not a meaningful location.
                $blankLocation = \App\Domain\Household\OnboardingReadiness::derive(
                    true,
                    Household::create('H', 'UTC', '   ', self::now())
                );
                Assert::same(false, $blankLocation['location_provided']);

                foreach ($none as $key => $value) {
                    Assert::true(is_bool($value) || is_string($value), $key . ' must stay non-numeric');
                }
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
