<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\BrevoMailTransport;
use App\Services\Mail\FileMailTransport;
use App\Services\Mail\Mailer;
use App\Support\Config;
use RuntimeException;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * W1.1 — Mail transport selection, Brevo request construction and the
 * hard real-send safety guard (docs/10 D-054).
 *
 * These tests run inside the automated suite, whose runner pins
 * MAIL_TRANSPORT=file and BREVO_ALLOW_REAL_SEND=0. Every Brevo
 * interaction below goes through an injected fake HTTP callable —
 * NO network access, NO Brevo API call, NO real API key. The first
 * check is the standing regression proof that the automated context can
 * never invoke the real Brevo transport.
 */
final class MailTransportTest
{
    /** @var array<int, array{url: string, headers: array<int, string>, body: string}> */
    private static array $captured = [];

    private const FAKE_KEY = 'unit-test-fake-key-not-a-real-secret';

    public static function run(): array
    {
        return [
            TestResult::check('Automated test context is pinned to the file sink with real Brevo sending disabled', function (): void {
                Assert::same('file', Config::get('MAIL_TRANSPORT'), 'the runner must pin MAIL_TRANSPORT=file for every automated run');
                Assert::false(Config::get('BREVO_ALLOW_REAL_SEND') === '1', 'the runner must disable real Brevo sending for every automated run');
            }),

            TestResult::check('Default transport selection is the file sink', function (): void {
                self::override(['MAIL_TRANSPORT' => 'file']);
                try {
                    Assert::same(FileMailTransport::class, self::transportOf(new Mailer())::class);
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('MAIL_TRANSPORT=brevo selects the Brevo transport', function (): void {
                self::override(['MAIL_TRANSPORT' => 'brevo']);
                try {
                    Assert::same(BrevoMailTransport::class, self::transportOf(new Mailer())::class);
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Unknown MAIL_TRANSPORT value is rejected', function (): void {
                self::override(['MAIL_TRANSPORT' => 'carrier-pigeon']);
                try {
                    Assert::throws(RuntimeException::class, static function (): void {
                        new Mailer();
                    });
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Brevo transport refuses to send without the explicit real-send flag — zero API calls', function (): void {
                self::override(['BREVO_ALLOW_REAL_SEND' => '0', 'MAIL_TRANSPORT' => 'brevo']);
                self::$captured = [];
                try {
                    Assert::throws(RuntimeException::class, static function (): void {
                        (new BrevoMailTransport(self::fakeClient()))->send('recipient@example.test', 'Subject', 'Body');
                    });
                    Assert::same(0, self::callCount(), 'no HTTP request may be attempted while the real-send flag is off');
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Brevo transport refuses to send when the flag key is absent entirely', function (): void {
                self::override(['BREVO_ALLOW_REAL_SEND' => '', 'MAIL_TRANSPORT' => 'brevo']);
                self::$captured = [];
                try {
                    Assert::throws(RuntimeException::class, static function (): void {
                        (new BrevoMailTransport(self::fakeClient()))->send('recipient@example.test', 'Subject', 'Body');
                    });
                    Assert::same(0, self::callCount());
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Brevo request carries configured sender, recipient, subject and content', function (): void {
                self::override([
                    'BREVO_ALLOW_REAL_SEND' => '1',
                    'BREVO_API_KEY' => self::FAKE_KEY,
                    'BREVO_SENDER_EMAIL' => 'spms.solarproject@gmail.com',
                    'BREVO_SENDER_NAME' => 'SPMS',
                ]);
                self::$captured = [];
                try {
                    (new BrevoMailTransport(self::fakeClient(201, '{"messageId":"unit-test"}')))
                        ->send('owner-test-inbox@example.test', 'SPMS — Verify your email address', "Welcome to SPMS.\r\n\r\nUse the verification code below to activate your account:\r\n\r\ntoken-value-123");

                    Assert::same(1, self::callCount(), 'exactly one HTTP request');
                    $request = self::$captured[0];

                    Assert::same(BrevoMailTransport::API_ENDPOINT, $request['url']);
                    Assert::contains('api-key: ' . self::FAKE_KEY, implode("\n", $request['headers']));
                    Assert::contains('content-type: application/json', implode("\n", $request['headers']));
                    Assert::contains('accept: application/json', implode("\n", $request['headers']));

                    $payload = Assert::jsonDecodes($request['body']);
                    Assert::same('SPMS', $payload['sender']['name']);
                    Assert::same('spms.solarproject@gmail.com', $payload['sender']['email']);
                    Assert::same('owner-test-inbox@example.test', $payload['to'][0]['email']);
                    Assert::same('SPMS — Verify your email address', $payload['subject']);
                    Assert::contains('token-value-123', $payload['textContent']);
                    Assert::notContains(self::FAKE_KEY, $request['body'], 'the API key must never travel inside the mail payload');
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Brevo API failure is a safe exception: no key, no response internals', function (): void {
                self::override([
                    'BREVO_ALLOW_REAL_SEND' => '1',
                    'BREVO_API_KEY' => self::FAKE_KEY,
                    'BREVO_SENDER_EMAIL' => 'spms.solarproject@gmail.com',
                ]);
                self::$captured = [];
                try {
                    $failure = null;
                    try {
                        (new BrevoMailTransport(self::fakeClient(500, '{"message":"internal-detail-marker"}')))
                            ->send('recipient@example.test', 'Subject', 'Body');
                    } catch (RuntimeException $caught) {
                        $failure = $caught->getMessage();
                    }

                    Assert::true($failure !== null, 'a non-2xx Brevo response must throw');
                    Assert::contains('HTTP 500', $failure ?? '');
                    Assert::notContains(self::FAKE_KEY, $failure ?? '', 'the API key must never appear in an exception');
                    Assert::notContains('internal-detail-marker', $failure ?? '', 'provider response details must never reach users');
                } finally {
                    self::cleanup();
                }
            }),

            TestResult::check('Brevo transport error (no response) fails safely', function (): void {
                self::override([
                    'BREVO_ALLOW_REAL_SEND' => '1',
                    'BREVO_API_KEY' => self::FAKE_KEY,
                    'BREVO_SENDER_EMAIL' => 'spms.solarproject@gmail.com',
                ]);
                try {
                    Assert::throws(RuntimeException::class, static function (): void {
                        (new BrevoMailTransport(self::fakeClient(0, '')))
                            ->send('recipient@example.test', 'Subject', 'Body');
                    });
                } finally {
                    self::cleanup();
                }
            }),
        ];
    }

    /**
     * @return callable(string, array<int, string>, string): array{0: int, 1: string}
     */
    private static function fakeClient(int $status = 201, string $body = '{"messageId":"unit-test"}'): callable
    {
        return static function (string $url, array $headers, string $payload) use ($status, $body): array {
            self::$captured[] = ['url' => $url, 'headers' => $headers, 'body' => $payload];

            return [$status, $body];
        };
    }

    private static function callCount(): int
    {
        return count(self::$captured);
    }

    /**
     * @param array<string, string> $values
     */
    private static function override(array $values): void
    {
        Config::overrideForTesting($values);
    }

    private static function transportOf(Mailer $mailer): object
    {
        $property = new \ReflectionProperty(Mailer::class, 'transport');

        return $property->getValue($mailer);
    }

    private static function cleanup(): void
    {
        self::$captured = [];
        Config::overrideForTesting([
            'MAIL_TRANSPORT' => 'file',
            'BREVO_ALLOW_REAL_SEND' => '0',
            'BREVO_API_KEY' => '',
            'BREVO_SENDER_EMAIL' => '',
            'BREVO_SENDER_NAME' => 'SPMS',
            'BREVO_TIMEOUT' => '10',
        ]);
    }
}
