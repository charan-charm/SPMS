<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * W1 — Email transport abstraction (docs/10 D-054).
 *
 * Production uses SMTP/provider configuration; development uses a local
 * file sink. Raw verification/recovery tokens may appear ONLY inside the
 * delivered mail itself — never in application logs.
 */
interface MailTransport
{
    /**
     * @param array<string, string> $headers additional mail headers
     */
    public function send(string $toEmail, string $subject, string $textBody, array $headers = []): void;
}
