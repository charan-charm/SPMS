<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * W1 — Email transport abstraction (docs/10 D-054).
 *
 * Production uses SMTP/provider configuration; development uses a local
 * file sink. Verification codes may appear ONLY inside the delivered
 * mail itself — never in application logs or error messages.
 */
interface MailTransport
{
    /**
     * Send an email. HTML body is optional — when provided, email clients
     * that support HTML will render it; others fall back to textBody.
     *
     * @param array<string, string> $headers additional mail headers
     */
    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        array $headers = [],
        ?string $htmlBody = null
    ): void;
}
