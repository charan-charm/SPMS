<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Support\Config;
use RuntimeException;

/**
 * W1 — Minimal SMTP transport (docs/10 D-054 production path).
 *
 * Straightforward SMTP client over a stream connection with optional
 * STARTTLS and AUTH LOGIN. All configuration comes from environment
 * config — no credentials are ever hard-coded or logged.
 */
final class SmtpTransport implements MailTransport
{
    public function send(string $toEmail, string $subject, string $textBody, array $headers = []): void
    {
        $host = Config::get('SMTP_HOST') ?? '';
        $port = Config::get('SMTP_PORT', '587') ?? '587';
        $username = Config::get('SMTP_USERNAME') ?? '';
        $password = Config::get('SMTP_PASSWORD') ?? '';
        $encryption = Config::get('SMTP_ENCRYPTION', 'starttls') ?? 'starttls';
        $fromEmail = Config::get('MAIL_FROM_ADDRESS', 'no-reply@spms.local') ?? 'no-reply@spms.local';
        $timeout = 10;

        if ($host === '') {
            throw new RuntimeException('SMTP transport is not configured.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout);

        if ($stream === false) {
            throw new RuntimeException('SMTP connection failed.');
        }

        try {
            stream_set_timeout($stream, $timeout);
            $this->expect($stream, '220');
            $this->command($stream, 'EHLO spms.local', '250');

            if ($encryption === 'starttls') {
                $this->command($stream, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                $this->command($stream, 'EHLO spms.local', '250');
            }

            if ($username !== '') {
                $this->command($stream, 'AUTH LOGIN', '334');
                $this->command($stream, base64_encode($username), '334');
                $this->command($stream, base64_encode($password), '235');
            }

            $this->command($stream, 'MAIL FROM:<' . $fromEmail . '>', '250');
            $this->command($stream, 'RCPT TO:<' . $toEmail . '>', '250');
            $this->command($stream, 'DATA', '354');

            $body = 'From: ' . ($headers['From'] ?? 'SPMS <' . $fromEmail . '>') . "\r\n"
                . 'To: ' . $toEmail . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'MIME-Version: 1.0' . "\r\n"
                . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
                . "\r\n"
                . preg_replace('/^\./m', '..', $textBody)
                . "\r\n.";

            $this->command($stream, $body, '250');
            $this->command($stream, 'QUIT', '221');
        } finally {
            fclose($stream);
        }
    }

    private function command($stream, string $command, string $expect): void
    {
        fwrite($stream, $command . "\r\n");
        $this->expect($stream, $expect);
    }

    private function expect($stream, string $code): void
    {
        $response = '';
        while (($line = fgets($stream, 1024)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if (!str_starts_with($response, $code)) {
            throw new RuntimeException('SMTP unexpected response.');
        }
    }
}
