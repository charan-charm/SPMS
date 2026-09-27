<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\AppException;
use App\Support\Config;
use JsonException;

/**
 * SPMS O4 — Authenticated Reversible Secret Encryption (docs/10 D-061 §3).
 *
 * Uses AES-256-GCM (AEAD) for encrypting external provider credentials
 * (such as GoodWe SEMS account passwords) at rest.
 *
 * Security properties:
 *  - 256-bit key resolved from SPMS_INTEGRATION_KEY environment variable.
 *  - Unique 96-bit (12-byte) IV generated per encryption via random_bytes().
 *  - 128-bit (16-byte) authentication tag prevents ciphertext tampering.
 *  - Associated Data (AAD) binds the ciphertext to the household_id,
 *    preventing ciphertext translocation between households.
 *  - Fails closed on any tampering, authentication tag mismatch, or corrupted payload.
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private static ?string $testingKey = null;

    /**
     * Encrypt plaintext with AES-256-GCM and bind to associated data.
     *
     * @param string $plaintext Data to encrypt
     * @param string $aad       Associated data (e.g. household_id)
     * @return string           Base64-encoded encrypted payload
     */
    public static function encrypt(string $plaintext, string $aad = ''): string
    {
        $key = self::getKey();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new AppException('ENCRYPTION_FAILED', 'Failed to encrypt sensitive secret.', 500);
        }

        $payload = json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return base64_encode($payload);
    }

    /**
     * Decrypt an AES-256-GCM payload and verify its associated data.
     *
     * @param string $payload Base64-encoded payload from encrypt()
     * @param string $aad     Associated data (must match what was passed to encrypt)
     * @return string         Decrypted plaintext
     * @throws AppException   If authentication tag verification fails or data is corrupted
     */
    public static function decrypt(string $payload, string $aad = ''): string
    {
        $key = self::getKey();

        try {
            $decodedJson = base64_decode($payload, true);
            if ($decodedJson === false) {
                throw new AppException('DECRYPTION_FAILED', 'Malformed encrypted payload.', 422);
            }

            $decoded = json_decode($decodedJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['iv'], $decoded['tag'], $decoded['ct'])) {
                throw new AppException('DECRYPTION_FAILED', 'Invalid encrypted payload structure.', 422);
            }

            $iv = base64_decode((string) $decoded['iv'], true);
            $tag = base64_decode((string) $decoded['tag'], true);
            $ciphertext = base64_decode((string) $decoded['ct'], true);

            if ($iv === false || strlen($iv) !== self::IV_BYTES
                || $tag === false || strlen($tag) !== self::TAG_BYTES
                || $ciphertext === false
            ) {
                throw new AppException('DECRYPTION_FAILED', 'Corrupted cryptographic parameters.', 422);
            }

            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );

            if ($plaintext === false) {
                throw new AppException('DECRYPTION_FAILED', 'Decryption failed: authentication tag verification failed.', 422);
            }

            return $plaintext;
        } catch (JsonException) {
            throw new AppException('DECRYPTION_FAILED', 'Malformed JSON in encrypted payload.', 422);
        }
    }

    /**
     * Alias for encrypt() for explicit string intent.
     */
    public static function encryptString(string $plaintext, string $aad = ''): string
    {
        return self::encrypt($plaintext, $aad);
    }

    /**
     * Alias for decrypt() for explicit string intent.
     */
    public static function decryptString(string $payload, string $aad = ''): string
    {
        return self::decrypt($payload, $aad);
    }

    /**
     * Override encryption key for testing purposes only.
     */
    public static function overrideKeyForTesting(?string $key): void
    {
        self::$testingKey = $key;
    }

    /**
     * Resolve the active 32-byte (256-bit) encryption key.
     */
    private static function getKey(): string
    {
        if (self::$testingKey !== null) {
            return self::normalizeKey(self::$testingKey);
        }

        $configured = (string) Config::get('SPMS_INTEGRATION_KEY', '');
        if ($configured === '') {
            throw new AppException(
                'CONFIGURATION_ERROR',
                'SPMS_INTEGRATION_KEY must be configured in environment for integration secrets.',
                500
            );
        }

        return self::normalizeKey($configured);
    }

    /**
     * Normalize key to exactly 32 bytes (supports either 64-character hex or 32 raw bytes).
     */
    private static function normalizeKey(string $raw): string
    {
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $bin = hex2bin($raw);
            if ($bin !== false && strlen($bin) === 32) {
                return $bin;
            }
        }

        if (strlen($raw) === 32) {
            return $raw;
        }

        throw new AppException(
            'CONFIGURATION_ERROR',
            'SPMS_INTEGRATION_KEY must be exactly 32 bytes (or 64 hex characters).',
            500
        );
    }
}
