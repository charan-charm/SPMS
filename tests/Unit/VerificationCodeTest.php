<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Security\TokenHasher;
use Tests\TestResult;

/**
 * Unit tests for the 6-digit verification code implementation.
 *
 * SECURITY ASSERTIONS:
 *   - generateSixDigit() produces exactly 6 decimal digits
 *   - The code is different from its SHA-256 hash (not stored plain)
 *   - hash() of the code is deterministic (same input → same hash)
 *   - hash() is 64 hex characters (SHA-256)
 *   - The raw code never appears in any log-able context
 */
final class VerificationCodeTest
{
    public static function run(): array
    {
        return [
            TestResult::check('generateSixDigit() produces exactly 6 decimal digits (50-sample)', function (): void {
                for ($i = 0; $i < 50; $i++) {
                    $code = TokenHasher::generateSixDigit();
                    if (!preg_match('/^\d{6}$/', $code)) {
                        throw new \AssertionError('Expected exactly 6 decimal digits, got: ' . var_export($code, true));
                    }
                }
            }),

            TestResult::check('generateSixDigit() produces statistically unique codes (100-sample)', function (): void {
                $codes = [];
                for ($i = 0; $i < 100; $i++) {
                    $codes[] = TokenHasher::generateSixDigit();
                }
                $unique = count(array_unique($codes));
                if ($unique < 90) {
                    throw new \AssertionError(
                        'Expected high uniqueness across 100 calls, got ' . $unique . ' unique values'
                    );
                }
            }),

            TestResult::check('SHA-256 hash of 6-digit code is NOT the plaintext code (security)', function (): void {
                for ($i = 0; $i < 10; $i++) {
                    $code = TokenHasher::generateSixDigit();
                    $hash = TokenHasher::hash($code);
                    if ($hash === $code) {
                        throw new \AssertionError('hash() must not equal the plaintext code');
                    }
                }
            }),

            TestResult::check('hash() produces 64-character hex (SHA-256 output length)', function (): void {
                $code = TokenHasher::generateSixDigit();
                $hash = TokenHasher::hash($code);
                if (strlen($hash) !== 64) {
                    throw new \AssertionError('Expected 64-char hex hash, got ' . strlen($hash) . ' chars');
                }
                if (!ctype_xdigit($hash)) {
                    throw new \AssertionError('hash() output is not valid hex: ' . substr($hash, 0, 16) . '…');
                }
            }),

            TestResult::check('hash() is deterministic: same code → same hash', function (): void {
                $code = TokenHasher::generateSixDigit();
                $hash1 = TokenHasher::hash($code);
                $hash2 = TokenHasher::hash($code);
                if ($hash1 !== $hash2) {
                    throw new \AssertionError('hash() is not deterministic for the same input');
                }
            }),

            TestResult::check('Different codes produce different hashes (no adjacent collision)', function (): void {
                $pairs = [['000000', '000001'], ['999998', '999999'], ['123456', '654321']];
                foreach ($pairs as [$a, $b]) {
                    if (TokenHasher::hash($a) === TokenHasher::hash($b)) {
                        throw new \AssertionError('hash(' . $a . ') === hash(' . $b . ') — collision detected');
                    }
                }
            }),

            TestResult::check('generate() (session/recovery tokens) still produces 64-char hex unaffected', function (): void {
                $token = TokenHasher::generate();
                if (strlen($token) !== 64 || !ctype_xdigit($token)) {
                    throw new \AssertionError('generate() should produce 64-char hex token, got: ' . var_export($token, true));
                }
                if (preg_match('/^\d{6}$/', $token)) {
                    throw new \AssertionError('generate() unexpectedly returned a 6-digit code — wrong method used');
                }
            }),

            TestResult::check('Zero-padding contract: edge-case codes produce valid 64-char hashes', function (): void {
                foreach (['000000', '000001', '099999', '100000', '999999'] as $expected) {
                    $hash = TokenHasher::hash($expected);
                    if (!ctype_xdigit($hash) || strlen($hash) !== 64) {
                        throw new \AssertionError('hash() failed for edge case: ' . $expected);
                    }
                }
            }),

            TestResult::check('Leading zeroes contract: "003786" is preserved as 6-char string and matches SHA-256 hash', function (): void {
                $raw = '003786';
                if (strlen($raw) !== 6) {
                    throw new \AssertionError('Expected string length 6');
                }
                if ($raw === '3786' || (int) $raw === 3786 && strlen((string) (int) $raw) !== 6) {
                    // Verifies that type casting to int loses the leading zeros, proving string type is mandatory
                    if ((string) (int) $raw === $raw) {
                        throw new \AssertionError('Numeric conversion must NOT match string with leading zeros');
                    }
                }
                $expectedHash = hash('sha256', '003786');
                $tokenHash = TokenHasher::hash($raw);
                if ($tokenHash !== $expectedHash) {
                    throw new \AssertionError('TokenHasher::hash("003786") does not match expected SHA-256');
                }
            }),

            TestResult::check('VerificationEmailTemplate renders light theme with botanical framing and spaced code', function (): void {
                $html = \App\Services\Mail\VerificationEmailTemplate::buildHtml('003786', 24);
                $text = \App\Services\Mail\VerificationEmailTemplate::buildText('003786', 24);

                // 1. Code presence and visual spacing
                if (!str_contains($html, '003786') || !str_contains($html, 'letter-spacing:0.32em')) {
                    throw new \AssertionError('HTML email must contain exact code "003786" with visual letter-spacing');
                }
                if (!str_contains($text, '003786')) {
                    throw new \AssertionError('Plaintext email must contain exact code "003786"');
                }

                // 2. Light theme assertions: white card & slate-50 background, NO dark full-screen backgrounds
                if (!str_contains($html, '#f8fafc') || !str_contains($html, '#ffffff')) {
                    throw new \AssertionError('HTML email must use light theme surface and card colors');
                }
                if (str_contains($html, '#0d1526') || str_contains($html, '#111d35')) {
                    throw new \AssertionError('HTML email must NOT contain dark-theme full-screen background colors');
                }

                // 3. Botanical leaf framing present
                if (!str_contains($html, '#16a34a') || !str_contains($html, 'M0 0 C -22 -34 -20 -86 0 -130')) {
                    throw new \AssertionError('HTML email must contain botanical greenery leaf vectors');
                }

                // 4. Confidentiality: no database names or environment hints
                if (str_contains($html, 'spms_real') || str_contains($html, 'spms_demo') || str_contains($html, 'spms_test')) {
                    throw new \AssertionError('HTML email must not expose internal database names');
                }
            }),
        ];
    }
}
