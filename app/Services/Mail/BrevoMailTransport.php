<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Support\Config;
use RuntimeException;

/**
 * W1.1 — Brevo transactional email transport (docs/10 D-054).
 *
 * Sends transactional authentication mail over the Brevo HTTPS API
 * (POST https://api.brevo.com/v3/smtp/email). The API key lives only in
 * backend configuration, is never logged, and never appears in
 * exception messages or API responses.
 *
 * HARD SAFETY GUARD: real sending requires the explicit
 * BREVO_ALLOW_REAL_SEND flag. The automated test suite pins that flag
 * off (tests/run-tests.php), so no automated run can ever produce a
 * Brevo API request — regardless of the developer's local .env. The
 * only sanctioned path that enables real delivery is the dedicated
 * manual integration entry point (scripts/manual-brevo-server.php),
 * which sets the flag inside its own process only.
 */
final class BrevoMailTransport implements MailTransport
{
    public const API_ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** @var callable|null(array{0:string,1:array<int,string>,2:string}):array{0:int,1:string} */
    private $httpClient;

    public function __construct(?callable $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function send(string $toEmail, string $subject, string $textBody, array $headers = []): void
    {
        if (Config::get('BREVO_ALLOW_REAL_SEND') !== '1') {
            throw new RuntimeException('Brevo transport is disabled: real sending requires the explicit BREVO_ALLOW_REAL_SEND flag.');
        }

        $apiKey = Config::get('BREVO_API_KEY') ?? '';
        $senderEmail = Config::get('BREVO_SENDER_EMAIL') ?? '';
        $senderName = Config::get('BREVO_SENDER_NAME', 'SPMS') ?? 'SPMS';

        if ($apiKey === '' || $senderEmail === '') {
            throw new RuntimeException('Brevo transport is not configured.');
        }

        $payload = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'textContent' => $textBody,
        ];

        [$status] = $this->post(
            self::API_ENDPOINT,
            [
                'api-key: ' . $apiKey,
                'accept: application/json',
                'content-type: application/json',
            ],
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Brevo API request failed (HTTP ' . $status . ').');
        }
    }

    /**
     * Bounded HTTPS POST. Returns [statusCode, responseBody]. The
     * api-key header exists only inside this call — it is never
     * written to logs, events, or error messages.
     *
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    private function post(string $url, array $headers, string $body): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url, $headers, $body);
        }

        $timeout = (int) (Config::get('BREVO_TIMEOUT', '10') ?? '10');

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Brevo API request could not be initialized.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($status === 0) {
            throw new RuntimeException('Brevo API request failed (transport error).');
        }

        return [$status, $responseBody];
    }
}
