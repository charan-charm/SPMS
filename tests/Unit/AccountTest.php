<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Account\Account;
use App\Domain\Auth\PasswordPolicy;
use App\Support\AppException;
use App\Support\Security\TokenHasher;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * W1 — Account identity, password policy and token hashing unit tests
 * (docs/10 D-049, D-051, D-053).
 */
final class AccountTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Registration defaults are fixed server-side: USER, REAL, PENDING_VERIFICATION', function (): void {
                $account = Account::register('  USER@Example.COM ', 'hash-value', new \DateTimeImmutable('2026-09-12 10:00:00', new \DateTimeZone('UTC')));

                Assert::same('user@example.com', $account->email(), 'email must be normalized lowercase');
                Assert::same(Account::STATUS_PENDING_VERIFICATION, $account->status());
                Assert::same(Account::ROLE_USER, $account->role());
                Assert::same(Account::MODE_REAL, $account->mode());
                Assert::false($account->canAuthenticate(), 'pending accounts cannot authenticate');
            }),

            TestResult::check('Account public representation never exposes password material', function (): void {
                $account = Account::register('a@b.com', '$2y$10$secret-hash-value', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

                $public = $account->toPublicArray();
                $encoded = json_encode($public) ?: '';

                Assert::false(isset($public['password_hash']));
                Assert::notContains('$2y$10$', $encoded);
                Assert::notContains('hash', strtolower($encoded));
                Assert::same(['id', 'email', 'status', 'role', 'mode', 'email_verified'], array_keys($public));
            }),

            TestResult::check('Account model rejects unknown status/role/mode values', function (): void {
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    Account::register('x@y.com', 'h', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->withStatus('MAGIC', null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                });
            }),

            TestResult::check('Active accounts authenticate; every other state does not', function (): void {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $pending = Account::register('a@b.com', 'h', $now);
                $active = $pending->withStatus(Account::STATUS_ACTIVE, $now->format('Y-m-d H:i:s'), $now);
                $disabled = $active->withStatus(Account::STATUS_DISABLED, null, $now);

                Assert::false($pending->canAuthenticate());
                Assert::true($active->canAuthenticate());
                Assert::false($disabled->canAuthenticate());
            }),

            TestResult::check('Password policy enforces the ratified 12-character minimum', function (): void {
                Assert::throws(AppException::class, static function (): void {
                    PasswordPolicy::validate('short12pass');
                });
                Assert::throws(AppException::class, static function (): void {
                    PasswordPolicy::validate(null);
                });

                $validated = PasswordPolicy::validate('exactly-12-chars');
                Assert::same('exactly-12-chars', $validated);

                $error = null;
                try {
                    PasswordPolicy::validate('tooshort');
                } catch (AppException $e) {
                    $error = $e;
                }
                Assert::true($error !== null);
                Assert::same('VALIDATION_ERROR', $error->errorCode());
                Assert::same(422, $error->httpStatus());
                Assert::same('password', $error->context()['field']);
            }),

            TestResult::check('Password hashing uses PHP password_hash and verifies round-trip', function (): void {
                $hash = PasswordPolicy::hash('correct horse battery staple');

                Assert::true($hash !== 'correct horse battery staple', 'hash is never the plaintext');
                Assert::true(strlen($hash) >= 60, 'PHP password_hash output shape');
                Assert::true(str_starts_with($hash, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$'));

                Assert::true(PasswordPolicy::verify('correct horse battery staple', $hash));
                Assert::false(PasswordPolicy::verify('wrong password entirely', $hash));
            }),

            TestResult::check('Token generation is opaque, unique, and stores only SHA-256 hashes', function (): void {
                $a = TokenHasher::generate();
                $b = TokenHasher::generate();

                Assert::same(64, strlen($a), '256-bit entropy as 64 hex chars');
                Assert::same(64, strlen($b));
                Assert::true($a !== $b, 'tokens are unique');
                Assert::true(ctype_xdigit($a));

                $hash = TokenHasher::hash($a);
                Assert::same(64, strlen($hash));
                Assert::same(hash('sha256', $a), $hash);
                Assert::true($hash !== $a, 'stored representation is never the raw token');
            }),
        ];
    }
}
