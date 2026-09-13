<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Logger;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * AI foundation (docs/10 D-066) — centralized credential-key
 * classification. AWS-shaped names must never be emitted through
 * structured logging, whatever their exact spelling.
 */
final class LoggerRedactionTest
{
    public static function run(): array
    {
        return [
            TestResult::check('AWS/credential-shaped keys are redacted regardless of exact spelling', function (): void {
                foreach ([
                    'aws_access_key_id',
                    'aws_secret_access_key',
                    'AWS_SESSION_TOKEN',
                    'aws_bearer_token_bedrock',
                    'bedrock_api_key',
                    'authorization',
                    'api_key',
                    'password',
                    'passwd',
                    'secret',
                    'token',
                    'credential',
                    'session_cookie',
                    'x-amz-security-token',
                ] as $key) {
                    Assert::true(
                        Logger::isSensitiveKey($key, 'value-' . $key),
                        $key . ' must classify as sensitive'
                    );
                }
            }),

            TestResult::check('logged context with AWS-shaped keys emits only redaction markers', function (): void {
                $dir = sys_get_temp_dir() . '/spms-log-redact-' . bin2hex(random_bytes(4));
                $logger = new Logger($dir, 'debug');

                $secret = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));
                $logger->error('ai.failure', [
                    'aws_access_key_id' => $secret,
                    'aws_secret_access_key' => str_repeat('s', 40),
                    'nested' => ['aws_session_token' => 'session-token-value'],
                    'provider' => 'bedrock',
                    'correlation_id' => 'corr-1',
                ]);

                $line = (string) file_get_contents($dir . '/app-' . gmdate('Y-m-d') . '.log');
                Assert::notContains($secret, $line, 'access key id value must never reach disk');
                Assert::notContains(str_repeat('s', 40), $line, 'secret key value must never reach disk');
                Assert::notContains('session-token-value', $line, 'session token value must never reach disk');
                Assert::contains('[REDACTED]', $line);
                Assert::contains('"provider":"bedrock"', $line, 'safe metadata survives redaction');
                Assert::contains('corr-1', $line, 'correlation ids survive redaction');
            }),

            TestResult::check('legacy exact-match behavior and plural usage counters are preserved', function (): void {
                // Legacy sensitive spellings still classify.
                Assert::true(Logger::isSensitiveKey('password', 'x'));
                Assert::true(Logger::isSensitiveKey('token', 'x'));
                // Token COUNTS (numeric, plural) are measures, not secrets.
                Assert::false(Logger::isSensitiveKey('input_tokens', 123));
                Assert::false(Logger::isSensitiveKey('output_tokens', 45.0));
                // A string under a plural-token name is still redacted (no numeric assumption).
                Assert::true(Logger::isSensitiveKey('input_tokens', 'not-a-number'));
                // Singular 'token' even with a number stays redacted (defensive).
                Assert::true(Logger::isSensitiveKey('token', 5));
                Assert::false(Logger::isSensitiveKey('model', 'nvidia.nemotron-nano-12b-v2'));
                Assert::false(Logger::isSensitiveKey('region', 'ap-south-1'));
            }),
        ];
    }
}
