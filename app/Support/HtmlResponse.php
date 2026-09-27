<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HTML page response (server-rendered views).
 *
 * JSON APIs use Response; server-rendered pages use HtmlResponse.
 * Both share the same status/header/correlation handling.
 */
final class HtmlResponse
{
    private int $status;
    private array $headers = [];

    private function __construct(
        private readonly string $html,
        int $status
    ) {
        $this->status = $status;
    }

    public static function html(string $html, int $status = 200): self
    {
        $response = new self($html, $status);

        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['X-Frame-Options'] = 'DENY';
        $response->headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        $response->headers['Content-Security-Policy'] = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            "style-src 'self'",
            "script-src 'self'",
            "connect-src 'self'",
            "font-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        return $response;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->html;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        $headers = $this->headers;

        $requestId = RequestId::current();
        if ($requestId !== null) {
            $headers['X-Request-Id'] = $headers['X-Request-Id'] ?? $requestId;
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_clean();
        }

        if (PHP_SAPI !== 'cli') {
            http_response_code($this->status);

            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                foreach ($headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        echo $this->html;
    }
}
