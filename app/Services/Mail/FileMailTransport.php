<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Support\Config;
use RuntimeException;

/**
 * W1 — Development file sink (docs/10 D-054).
 *
 * Writes each message as an .eml file under a dedicated storage/mail
 * directory (never the log directory, never the public web root). This
 * is the local development stand-in for a mail catcher so verification
 * and recovery flows are testable without SMTP.
 */
final class FileMailTransport implements MailTransport
{
    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        array $headers = [],
        ?string $htmlBody = null
    ): void {
        $dir = Config::get('MAIL_FILE_DIR', dirname(__DIR__, 3) . '/storage/mail')
            ?? dirname(__DIR__, 3) . '/storage/mail';

        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Mail sink directory is not writable.');
        }

        if ($htmlBody !== null) {
            // Multipart MIME for dev inspection of the branded HTML template
            $boundary = 'spms_' . bin2hex(random_bytes(8));
            $lines = [
                'From: ' . ($headers['From'] ?? 'SPMS <no-reply@spms.local>'),
                'To: ' . $toEmail,
                'Subject: ' . $subject,
                'Date: ' . gmdate('r'),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                '',
                '--' . $boundary,
                'Content-Type: text/plain; charset=utf-8',
                '',
                $textBody,
                '',
                '--' . $boundary,
                'Content-Type: text/html; charset=utf-8',
                '',
                $htmlBody,
                '',
                '--' . $boundary . '--',
                '',
            ];
        } else {
            $lines = [
                'From: ' . ($headers['From'] ?? 'SPMS <no-reply@spms.local>'),
                'To: ' . $toEmail,
                'Subject: ' . $subject,
                'Date: ' . gmdate('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                '',
                $textBody,
                '',
            ];
        }

        // Full-precision epoch in the filename keeps chronological
        // ordering unambiguous when several mails are delivered within
        // the same second (test sinks and busy dev flows); lexicographic
        // filename sort is chronological.
        $stamp = number_format(microtime(true), 6, '.', '');
        $file = rtrim($dir, '/\\') . '/' . $stamp . '-' . bin2hex(random_bytes(4)) . '.eml';

        if (file_put_contents($file, implode("\r\n", $lines), LOCK_EX) === false) {
            throw new RuntimeException('Mail sink write failed.');
        }
    }
}
