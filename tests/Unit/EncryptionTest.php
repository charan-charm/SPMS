<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AppException;
use App\Support\Security\Encryption;
use Tests\Support\Assert;
use Tests\TestResult;

final class EncryptionTest
{
    public static function run(): array
    {
        $testKey = str_repeat('k', 32); // 32-byte key
        $testHouseholdId = 'hh-1111-2222-3333-4444';

        return [
            TestResult::check('Encryption encrypts and decrypts accurately with AEAD AES-256-GCM', function () use ($testKey, $testHouseholdId): void {
                Encryption::overrideKeyForTesting($testKey);
                try {
                    $secret = 'GoodWePassword!9876';
                    $encrypted = Encryption::encrypt($secret, $testHouseholdId);

                    Assert::true(is_string($encrypted));
                    Assert::true($encrypted !== $secret);
                    Assert::false(str_contains($encrypted, $secret));

                    $decrypted = Encryption::decrypt($encrypted, $testHouseholdId);
                    Assert::same($secret, $decrypted);
                } finally {
                    Encryption::overrideKeyForTesting(null);
                }
            }),

            TestResult::check('Encryption fails closed when Associated Data (household_id) is mismatched', function () use ($testKey, $testHouseholdId): void {
                Encryption::overrideKeyForTesting($testKey);
                try {
                    $secret = 'SecretPassword123';
                    $encrypted = Encryption::encrypt($secret, $testHouseholdId);

                    // Mismatched household ID (attempting to swap ciphertext across households)
                    Assert::throws(AppException::class, function () use ($encrypted): void {
                        Encryption::decrypt($encrypted, 'foreign-household-id-999');
                    });
                } finally {
                    Encryption::overrideKeyForTesting(null);
                }
            }),

            TestResult::check('Encryption fails closed when ciphertext or tag is tampered', function () use ($testKey, $testHouseholdId): void {
                Encryption::overrideKeyForTesting($testKey);
                try {
                    $secret = 'ConfidentialData';
                    $encrypted = Encryption::encrypt($secret, $testHouseholdId);

                    $rawJson = json_decode((string) base64_decode($encrypted, true), true);
                    $rawJson['ct'] = base64_encode(str_repeat('X', 16)); // Corrupt ciphertext
                    $tampered = base64_encode((string) json_encode($rawJson));

                    Assert::throws(AppException::class, function () use ($tampered, $testHouseholdId): void {
                        Encryption::decrypt($tampered, $testHouseholdId);
                    });
                } finally {
                    Encryption::overrideKeyForTesting(null);
                }
            }),

            TestResult::check('Encryption supports 64-character hex key representation', function () use ($testHouseholdId): void {
                $hexKey = hash('sha256', 'spms-test-key-material');
                Encryption::overrideKeyForTesting($hexKey);
                try {
                    $secret = 'AnotherSecretPassword';
                    $encrypted = Encryption::encrypt($secret, $testHouseholdId);
                    $decrypted = Encryption::decrypt($encrypted, $testHouseholdId);

                    Assert::same($secret, $decrypted);
                } finally {
                    Encryption::overrideKeyForTesting(null);
                }
            }),

            TestResult::check('Encryption rejects invalid key lengths', function (): void {
                Encryption::overrideKeyForTesting('short-key');
                try {
                    Assert::throws(AppException::class, function (): void {
                        Encryption::encrypt('test', 'hh');
                    });
                } finally {
                    Encryption::overrideKeyForTesting(null);
                }
            }),
        ];
    }
}
