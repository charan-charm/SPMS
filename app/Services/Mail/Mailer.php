<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Support\Config;
use RuntimeException;

/**
 * W1 — Transport selection (docs/10 D-054).
 *
 * MAIL_TRANSPORT selects the implementation: "file" (development sink,
 * the default), "smtp" (production/provider), or "brevo" (Brevo
 * transactional email API — W1.1). Credentials come only from
 * configuration. Failures propagate — callers fail safely and
 * visibly instead of silently dropping authentication mail.
 */
final class Mailer
{
    private readonly MailTransport $transport;

    public function __construct(?MailTransport $transport = null)
    {
        if ($transport !== null) {
            $this->transport = $transport;
            return;
        }

        $selected = Config::get('MAIL_TRANSPORT', 'file') ?? 'file';

        $this->transport = match ($selected) {
            'smtp' => new SmtpTransport(),
            'file' => new FileMailTransport(),
            'brevo' => new BrevoMailTransport(),
            default => throw new RuntimeException('Unknown mail transport: ' . $selected),
        };
    }

    /**
     * Send an email. When htmlBody is provided, HTML-capable clients render
     * it; textBody serves as the plain-text fallback for all other clients.
     */
    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null
    ): void {
        $this->transport->send($toEmail, $subject, $textBody, [], $htmlBody);
    }
}
